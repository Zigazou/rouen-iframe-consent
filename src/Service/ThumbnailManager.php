<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Tracks, downloads, and removes iframe preview thumbnails.
 */
final class ThumbnailManager {

  /**
   * The database table for thumbnail records.
   *
   * @var string
   */
  private const TABLE = 'rouen_iframe_consent_thumbnail';

  /**
   * The queue name for thumbnail download tasks.
   *
   * @var string
   */
  private const QUEUE = 'rouen_iframe_consent_thumbnail';

  /**
   * Maximum size of downloaded thumbnails in bytes (5 MB).
   *
   * @var int
   */
  private const DEFAULT_MAX_DOWNLOAD_BYTES = 5_242_880;

  /**
   * Maximum number of redirects followed for a thumbnail request.
   *
   * @var int
   */
  private const DEFAULT_MAX_REDIRECTS = 5;

  /**
   * Default connection timeout for thumbnail requests, in seconds.
   *
   * @var int
   */
  private const DEFAULT_CONNECT_TIMEOUT = 5;

  /**
   * Default total timeout for thumbnail requests, in seconds.
   *
   * @var int
   */
  private const DEFAULT_TIMEOUT = 15;

  /**
   * Formatter usage, cached by entity type and bundle for this request.
   *
   * @var array<string, array<string, bool>>
   */
  private array $formatterFields = [];

  /**
   * Creates the thumbnail manager.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly QueueFactory $queueFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly IframeParser $iframeParser,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerChannelInterface $logger,
    private readonly UrlResolverInterface $urlResolver,
    private readonly ResourceFetcherInterface $resourceFetcher,
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RemoteUrlValidator $remoteUrlValidator,
  ) {}

  /**
   * Returns an existing thumbnail without changing persistent state.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the iframe field.
   * @param string $fieldName
   *   The name of the field containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   *
   * @return string|null
   *   The public URL of the thumbnail if ready, or NULL if pending, failed,
   *   or not found.
   */
  public function getThumbnail(
    EntityInterface $entity,
    string $fieldName,
    int $delta,
    ParsedIframe $iframe,
  ): ?string {
    // Only process fieldable entities that are saved and have a UUID.
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
    ) {
      return NULL;
    }

    $keys = $this->recordKeys($entity, $fieldName, $delta);
    $record = $this->loadRecord($keys);

    if ($record === FALSE
      || !hash_equals($record->source_hash, $iframe->getSourceHash())
      || $record->status !== 'ready'
    ) {
      return NULL;
    }

    // Return the public URL of the thumbnail if it exists.
    return $this->thumbnailUrl($record->thumbnail_uri ?? NULL);
  }

  /**
   * Creates or updates a thumbnail record and returns its public URL if ready.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the iframe field.
   * @param string $fieldName
   *   The name of the field containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   *
   * @return string|null
   *   The public URL of the thumbnail if ready, or NULL if pending or failed.
   */
  public function ensureThumbnail(
    EntityInterface $entity,
    string $fieldName,
    int $delta,
    ParsedIframe $iframe,
  ): ?string {
    // Only process fieldable entities that are saved and have a UUID.
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
      || ($entity->getEntityType()->isRevisionable()
        && !$entity->isDefaultRevision())
    ) {
      return NULL;
    }

    $keys = $this->recordKeys($entity, $fieldName, $delta);
    $record = $this->loadRecord($keys);

    if (
      $record !== FALSE
      && hash_equals($record->source_hash, $iframe->getSourceHash())
    ) {
      $url = $this->thumbnailUrl($record->thumbnail_uri ?? NULL);

      if ($url !== NULL || $record->status === 'pending') {
        return $url;
      }

      if ($record->status === 'failed') {
        return NULL;
      }

      $this->database
        ->update(self::TABLE)
        ->fields([
          'status' => 'pending',
          'changed' => time(),
        ])
        ->condition('id', $record->id)
        ->execute();

      $this->queueFactory
        ->get(self::QUEUE)
        ->createItem(['record_id' => (int) $record->id]);

      return NULL;
    }

    if ($record !== FALSE) {
      // Delete the old thumbnail file if it exists.
      $this->deleteFile($record->thumbnail_uri ?? NULL);

      $this->database
        ->update(self::TABLE)
        ->fields([
          'entity_id' => (string) $entity->id(),
          'source_hash' => $iframe->getSourceHash(),
          'iframe_url' => $iframe->thumbnailLookupUrl,
          'thumbnail_uri' => NULL,
          'status' => 'pending',
          'changed' => time(),
        ])
        ->condition('id', $record->id)
        ->execute();

      $record_id = (int) $record->id;
    }
    else {
      // Create a new record for this thumbnail.
      try {
        $record_id = (int) $this->database
          ->insert(self::TABLE)
          ->fields($keys + [
            'entity_id' => (string) $entity->id(),
            'source_hash' => $iframe->getSourceHash(),
            'iframe_url' => $iframe->thumbnailLookupUrl,
            'status' => 'pending',
            'changed' => time(),
          ])
          ->execute();
      }
      catch (IntegrityConstraintViolationException) {
        // Another request created this record after our initial lookup.
        return $this->ensureThumbnail($entity, $fieldName, $delta, $iframe);
      }
    }

    $this->queueFactory
      ->get(self::QUEUE)
      ->createItem(['record_id' => $record_id]);

    return NULL;
  }

  /**
   * Synchronizes tracked thumbnails when a fieldable entity is saved.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was saved.
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   If a database constraint is violated during record creation.
   */
  public function syncEntity(EntityInterface $entity): void {
    // Only process fieldable entities that are saved and have a UUID.
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
      || ($entity->getEntityType()->isRevisionable()
        && !$entity->isDefaultRevision())
    ) {
      return;
    }

    // Synchronize every translation of the entity.
    $langcodes = array_keys($entity->getTranslationLanguages());
    foreach ($langcodes as $langcode) {
      if ($entity->hasTranslation($langcode)) {
        $translation = $entity->getTranslation($langcode);
        if ($translation instanceof FieldableEntityInterface) {
          $this->syncTranslation($translation);
        }
      }
    }

    if ($langcodes === []) {
      return;
    }

    // Delete any records for translations that no longer exist.
    $records = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->condition('langcode', $langcodes, 'NOT IN')
      ->execute()
      ->fetchAll();

    foreach ($records as $record) {
      $this->deleteRecord($record);
    }
  }

  /**
   * Synchronizes one translation of a saved entity.
   */
  private function syncTranslation(FieldableEntityInterface $entity): void {
    $langcode = $entity->language()->getId();

    $existing = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchAllAssoc('id');

    $retained_ids = [];
    $trusted_hosts = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get('trusted_hosts') ?: [];

    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      // Only process text fields that use this formatter.
      if (
        !in_array(
          $definition->getType(),
          ['text', 'text_long', 'text_with_summary'],
          TRUE
        )
        || !$this->fieldUsesFormatter($entity, $field_name)
      ) {
        continue;
      }

      foreach ($entity->get($field_name) as $delta => $item) {
        $parsed = $this->iframeParser->parse((string) $item->value);

        if (
          !($parsed instanceof ParsedIframe)
          || $this->iframeParser->isTrustedHost($parsed->host, $trusted_hosts)
        ) {
          continue;
        }

        $this->ensureThumbnail($entity, $field_name, (int) $delta, $parsed);

        foreach ($existing as $id => $record) {
          if (
            $record->field_name === $field_name
            && (int) $record->delta === (int) $delta
          ) {
            $retained_ids[(int) $id] = TRUE;
          }
        }
      }
    }

    foreach ($existing as $id => $record) {
      if (!isset($retained_ids[(int) $id])) {
        $this->deleteRecord($record);
      }
    }
  }

  /**
   * Deletes every thumbnail belonging to an entity.
   */
  public function deleteForEntity(EntityInterface $entity): void {
    if ($entity->uuid() === NULL) {
      return;
    }

    $records = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->execute()
      ->fetchAll();

    foreach ($records as $record) {
      $this->deleteRecord($record);
    }
  }

  /**
   * Processes one queued oEmbed thumbnail download.
   *
   * @param int $recordId
   *   The ID of the thumbnail record to process.
   *
   * @throws \RuntimeException
   *   If the download fails permanently.
   * @throws \Throwable
   *   If an unexpected error occurs.
   */
  public function processThumbnail(int $recordId): void {
    $record = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('id', $recordId)
      ->execute()
      ->fetchObject();

    if ($record === FALSE || $record->status !== 'pending') {
      return;
    }

    $this->database
      ->update(self::TABLE)
      ->fields(['changed' => time()])
      ->condition('id', $recordId)
      ->execute();

    try {
      $max_download_bytes = $this->positiveIntegerSetting(
        'remote_thumbnail_max_size',
        self::DEFAULT_MAX_DOWNLOAD_BYTES,
      );
      $resource_url = $this->urlResolver->getResourceUrl($record->iframe_url);
      $resource = $this->resourceFetcher->fetchResource($resource_url);
      $thumbnail_url = $resource->getThumbnailUrl();

      if (empty($thumbnail_url)) {
        $this->markFailed($recordId, $record->source_hash);
        return;
      }

      $thumbnail_url_string = $thumbnail_url->toString();
      $response = $this->requestRemoteImage($thumbnail_url_string);

      $content_length = (int) $response->getHeaderLine('Content-Length');

      if ($content_length > $max_download_bytes) {
        throw new \RuntimeException(
          'The oEmbed thumbnail exceeds the size limit.'
        );
      }

      $mime_type = strtolower(trim(explode(
        ';',
        $response->getHeaderLine('Content-Type'))[0])
      );

      $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
      ];

      if (!isset($extensions[$mime_type])) {
        throw new \RuntimeException(
          'The oEmbed thumbnail has an unsupported media type.'
        );
      }

      $data = $response->getBody()->read($max_download_bytes + 1);
      if ($data === '' || strlen($data) > $max_download_bytes) {
        throw new \RuntimeException(
          'The oEmbed thumbnail is empty or exceeds the size limit.'
        );
      }

      $directory = 'public://rouen_iframe_consent/thumbnails';

      if (
        !$this->fileSystem->prepareDirectory(
          $directory,
          FileSystemInterface::CREATE_DIRECTORY
            | FileSystemInterface::MODIFY_PERMISSIONS
        )
      ) {
        throw new \RuntimeException(
          'The thumbnail directory could not be prepared.'
        );
      }

      $image_info = @getimagesizefromstring($data);
      if (
        $image_info === FALSE
        || ($image_info['mime'] ?? NULL) !== $mime_type
      ) {
        throw new \RuntimeException(
          'The downloaded data is not a valid image.'
        );
      }

      $destination =
        $directory
        . '/' . $record->source_hash
        . '-' . $recordId
        . '.' . $extensions[$mime_type];

      $uri = $this->fileSystem->saveData(
        $data,
        $destination,
        FileExists::Replace
      );

      if ($uri === FALSE) {
        throw new \RuntimeException('The thumbnail file could not be saved.');
      }

      $updated = $this->database
        ->update(self::TABLE)
        ->fields([
          'thumbnail_uri' => $uri,
          'status' => 'ready',
          'changed' => time(),
        ])
        ->condition('id', $recordId)
        ->condition('source_hash', $record->source_hash)
        ->condition('status', 'pending')
        ->execute();

      if ($updated === 0) {
        $this->deleteFile($uri);
        return;
      }

      $this->invalidateRecord($record);
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Thumbnail retrieval failed for @url: @message',
        [
          '@url' => $record->iframe_url,
          '@message' => $exception->getMessage(),
        ]
      );

      $this->markFailed($recordId, $record->source_hash);
    }
  }

  /**
   * Checks whether any view mode uses this formatter for a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to check.
   * @param string $fieldName
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field uses this formatter in any view mode, FALSE otherwise.
   */
  private function fieldUsesFormatter(
    FieldableEntityInterface $entity,
    string $fieldName,
  ): bool {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $cache_key = $entityTypeId . ':' . $bundle;

    if (isset($this->formatterFields[$cache_key])) {
      return isset($this->formatterFields[$cache_key][$fieldName]);
    }

    $this->formatterFields[$cache_key] = [];
    $viewModes = $this->entityDisplayRepository
      ->getViewModeOptionsByBundle($entityTypeId, $bundle);

    foreach (array_keys($viewModes) as $viewMode) {
      $display = $this->entityDisplayRepository
        ->getViewDisplay($entityTypeId, $bundle, $viewMode);
      foreach ($display->getComponents() as $name => $component) {
        if (($component['type'] ?? NULL) === 'rouen_iframe_consent') {
          $this->formatterFields[$cache_key][$name] = TRUE;
        }
      }
    }

    return isset($this->formatterFields[$cache_key][$fieldName]);
  }

  /**
   * Builds the language-aware logical key for a thumbnail record.
   *
   * @return array<string, int|string>
   *   The database key fields.
   */
  private function recordKeys(
    EntityInterface $entity,
    string $fieldName,
    int $delta,
  ): array {
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_uuid' => (string) $entity->uuid(),
      'langcode' => $entity->language()->getId(),
      'field_name' => $fieldName,
      'delta' => $delta,
    ];
  }

  /**
   * Loads a thumbnail record by its logical key.
   */
  private function loadRecord(array $keys): object|false {
    $query = $this->database->select(self::TABLE, 't')->fields('t');
    foreach ($keys as $name => $value) {
      $query->condition($name, $value);
    }

    return $query->execute()->fetchObject();
  }

  /**
   * Retrieves an image while validating every redirect destination.
   */
  private function requestRemoteImage(string $url): ResponseInterface {
    $settings = $this->configFactory->get('rouen_iframe_consent.settings');
    $max_redirects = $settings->get('thumbnail_max_redirects');
    $max_redirects = is_numeric($max_redirects) && (int) $max_redirects >= 0
      ? (int) $max_redirects
      : self::DEFAULT_MAX_REDIRECTS;
    $connect_timeout = $this->positiveIntegerSetting(
      'thumbnail_connect_timeout',
      self::DEFAULT_CONNECT_TIMEOUT,
    );
    $timeout = $this->positiveIntegerSetting(
      'thumbnail_timeout',
      self::DEFAULT_TIMEOUT,
    );

    for ($redirects = 0; $redirects <= $max_redirects; $redirects++) {
      if (!$this->remoteUrlValidator->isSafe($url)) {
        throw new \RuntimeException(
          'The oEmbed thumbnail URL is not safe to retrieve.'
        );
      }

      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'connect_timeout' => $connect_timeout,
        'timeout' => $timeout,
        'headers' => ['Accept' => 'image/jpeg,image/png,image/gif,image/webp'],
      ]);
      $status = $response->getStatusCode();

      if ($status >= 200 && $status < 300) {
        return $response;
      }

      if ($status < 300 || $status >= 400) {
        throw new \RuntimeException(sprintf(
          'The oEmbed thumbnail returned HTTP status %d.',
          $status,
        ));
      }

      $location = $response->getHeaderLine('Location');
      if ($location === '') {
        throw new \RuntimeException(
          'The oEmbed thumbnail redirect has no destination.'
        );
      }

      $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
    }

    throw new \RuntimeException('The oEmbed thumbnail redirected too often.');
  }

  /**
   * Returns a positive integer setting or its fallback value.
   */
  private function positiveIntegerSetting(string $key, int $fallback): int {
    $value = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get($key);

    return is_numeric($value) && (int) $value > 0
      ? (int) $value
      : $fallback;
  }

  /**
   * Returns the URL for an existing thumbnail URI.
   *
   * @param string|null $uri
   *   The file URI of the thumbnail.
   *
   * @return string|null
   *   The public URL of the thumbnail, or NULL if the file does not exist.
   */
  private function thumbnailUrl(?string $uri): ?string {
    if ($uri === NULL || !file_exists($uri)) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Removes one database record and its file.
   *
   * @param object $record
   *   The database record to delete.
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   If a database constraint is violated during deletion.
   */
  private function deleteRecord(object $record): void {
    // Delete the thumbnail file if it exists.
    $this->deleteFile($record->thumbnail_uri ?? NULL);

    // Delete the database record.
    $this->database
      ->delete(self::TABLE)
      ->condition('id', $record->id)
      ->execute();
  }

  /**
   * Removes a thumbnail file if it exists.
   *
   * @param string|null $uri
   *   The file URI of the thumbnail.
   */
  private function deleteFile(?string $uri): void {
    if ($uri !== NULL && file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

  /**
   * Marks a record as permanently unavailable.
   *
   * @param int $recordId
   *   The ID of the thumbnail record.
   * @param string $sourceHash
   *   The source hash of the iframe content.
   */
  private function markFailed(int $recordId, string $sourceHash): void {
    $this->database
      ->update(self::TABLE)
      ->fields(['status' => 'failed', 'changed' => time()])
      ->condition('id', $recordId)
      ->condition('source_hash', $sourceHash)
      ->condition('status', 'pending')
      ->execute();
  }

  /**
   * Invalidates the rendered owning entity after a thumbnail changes.
   *
   * @param object $record
   *   The database record of the thumbnail.
   */
  private function invalidateRecord(object $record): void {
    $definition = $this
      ->entityTypeManager
      ->getDefinition($record->entity_type, FALSE);

    if ($definition !== NULL) {
      $this->cacheTagsInvalidator->invalidateTags(
        [$record->entity_type . ':' . $record->entity_id]
      );
    }
  }

}
