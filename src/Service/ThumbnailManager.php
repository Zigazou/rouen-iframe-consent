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
use Drupal\Core\Queue\RequeueException;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;
use GuzzleHttp\ClientInterface;

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
  private const MAX_DOWNLOAD_BYTES = 5_242_880;

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
  ) {}

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
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
    ) {
      return NULL;
    }

    $keys = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_uuid' => $entity->uuid(),
      'field_name' => $fieldName,
      'delta' => $delta,
    ];

    $record = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $keys['entity_type'])
      ->condition('entity_uuid', $keys['entity_uuid'])
      ->condition('field_name', $fieldName)
      ->condition('delta', $delta)
      ->execute()
      ->fetchObject();

    if (
      $record !== FALSE
      && hash_equals($record->source_hash, $iframe->getSourceHash())
    ) {
      $url = $this->thumbnailUrl($record->thumbnail_uri ?? NULL);

      if ($url !== NULL || $record->status !== 'ready') {
        return $url;
      }

      $this->database
        ->update(self::TABLE)
        ->fields(['status' => 'pending', 'attempts' => 0, 'changed' => time()])
        ->condition('id', $record->id)
        ->execute();

      $this->queueFactory
        ->get(self::QUEUE)
        ->createItem(['record_id' => (int) $record->id]);

      return NULL;
    }

    if ($record !== FALSE) {
      $this->deleteFile($record->thumbnail_uri ?? NULL);

      $this->database
        ->update(self::TABLE)
        ->fields([
          'entity_id' => (string) $entity->id(),
          'source_hash' => $iframe->getSourceHash(),
          'iframe_url' => $iframe->thumbnailLookupUrl,
          'thumbnail_uri' => NULL,
          'status' => 'pending',
          'attempts' => 0,
          'changed' => time(),
        ])
        ->condition('id', $record->id)
        ->execute();

      $record_id = (int) $record->id;
    }
    else {
      try {
        $record_id = (int) $this->database
          ->insert(self::TABLE)
          ->fields($keys + [
            'entity_id' => (string) $entity->id(),
            'source_hash' => $iframe->getSourceHash(),
            'iframe_url' => $iframe->thumbnailLookupUrl,
            'status' => 'pending',
            'attempts' => 0,
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
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
    ) {
      return;
    }

    $existing = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
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
          $parsed === NULL
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
   * @throws \Drupal\Core\Queue\RequeueException
   *   If the download should be retried later.
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

    $attempts = (int) $record->attempts + 1;

    $this->database
      ->update(self::TABLE)
      ->fields(['attempts' => $attempts, 'changed' => time()])
      ->condition('id', $recordId)
      ->execute();

    try {
      $resource_url = $this->urlResolver->getResourceUrl($record->iframe_url);
      $resource = $this->resourceFetcher->fetchResource($resource_url);
      $thumbnail_url = $resource->getThumbnailUrl();

      if (empty($thumbnail_url)) {
        $this->markFailed($recordId, $record->source_hash);
        return;
      }

      $thumbnail_url_string = $thumbnail_url->toString();

      if (!$this->isSafeRemoteImageUrl($thumbnail_url_string)) {
        throw new \RuntimeException(
          'The oEmbed thumbnail URL is not safe to retrieve.'
        );
      }

      $response = $this->httpClient->request('GET', $thumbnail_url_string, [
        'allow_redirects' => ['max' => 5],
        'connect_timeout' => 5,
        'timeout' => 15,
        'headers' => ['Accept' => 'image/jpeg,image/png,image/gif,image/webp'],
      ]);

      $content_length = (int) $response->getHeaderLine('Content-Length');

      if ($content_length > self::MAX_DOWNLOAD_BYTES) {
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

      $data = $response->getBody()->read(self::MAX_DOWNLOAD_BYTES + 1);
      if ($data === '' || strlen($data) > self::MAX_DOWNLOAD_BYTES) {
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

      if ($attempts < 3) {
        throw new RequeueException(
          'Thumbnail retrieval will be retried.',
          0,
          $exception
        );
      }

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
    $viewModes = $this->entityDisplayRepository
      ->getViewModeOptionsByBundle($entityTypeId, $bundle);

    foreach (array_keys($viewModes) as $viewMode) {
      $display = $this->entityDisplayRepository
        ->getViewDisplay($entityTypeId, $bundle, $viewMode);
      $component = $display->getComponent($fieldName);

      if (($component['type'] ?? NULL) === 'rouen_iframe_consent') {
        return TRUE;
      }
    }

    return FALSE;
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

  /**
   * Rejects malformed URLs and literal local or private network addresses.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if the URL is safe to retrieve, FALSE otherwise.
   */
  private function isSafeRemoteImageUrl(string $url): bool {
    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }

    $parts = parse_url($url);
    if (!is_array($parts)
      || !in_array(
        strtolower((string) ($parts['scheme'] ?? '')),
        ['http', 'https'], TRUE
      )
      || empty($parts['host'])
      || !empty($parts['user'])
      || !empty($parts['pass'])) {
      return FALSE;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));

    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
      return FALSE;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) {
      return filter_var(
        $host,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
      ) !== FALSE;
    }

    return TRUE;
  }

}
