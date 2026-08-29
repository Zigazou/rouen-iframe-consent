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
   * Version of the Facebook preview selection strategy.
   *
   * Incrementing this value causes existing Facebook previews to regenerate.
   *
   * @var int
   */
  private const FACEBOOK_PREVIEW_VERSION = 3;

  /**
   * Supported thumbnail media types and their corresponding file extensions.
   *
   * @var array<string, string>
   */
  private const THUMBNAIL_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
  ];

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
    private readonly PreviewUrlExtractor $previewUrlExtractor,
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

    // If the record does not exist, the source hash does not match, or the
    // thumbnail is not ready, return NULL. This indicates that the thumbnail is
    // pending or failed.
    if ($record === FALSE
      || !hash_equals(
        $record->source_hash,
        $this->getThumbnailSourceHash($iframe),
      )
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

    // Load the existing thumbnail record for this entity, field, and delta. If
    // the record exists and the source hash matches, we can return the existing
    // thumbnail URL if it is ready, or queue a download if it is pending.
    $keys = $this->recordKeys($entity, $fieldName, $delta);
    $record = $this->loadRecord($keys);

    if (
      $record !== FALSE
      && hash_equals(
        $record->source_hash,
        $this->getThumbnailSourceHash($iframe),
      )
    ) {
      // If the source URL has changed, update it in the database. This ensures
      // that we always have the latest source URL for the thumbnail record,
      // even if the source hash remains the same.
      if (($record->source_url ?? NULL) !== $iframe->sourceUrl) {
        $this->database
          ->update(self::TABLE)
          ->fields(['source_url' => $iframe->sourceUrl])
          ->condition('id', $record->id)
          ->execute();
      }

      // If the thumbnail is ready, return its public URL. If it is pending,
      // queue a download and return NULL. If it has failed, return NULL without
      // queuing a download.
      $url = $this->thumbnailUrl($record->thumbnail_uri ?? NULL);

      // If the thumbnail is ready or pending, return the URL or NULL. If it has
      // failed, return NULL without queuing a download.
      if ($url !== NULL || $record->status === 'pending') {
        return $url;
      }

      // If the thumbnail has failed, we do not queue a download again. This
      // prevents repeated failed attempts for the same source. The user may
      // need to manually update the iframe or use a different source to trigger
      // a new thumbnail generation.
      if ($record->status === 'failed') {
        return NULL;
      }

      // If the thumbnail is pending, we queue a download and return NULL. This
      // ensures that the thumbnail will be generated in the background and the
      // user will see it once it is ready.
      $this->database
        ->update(self::TABLE)
        ->fields([
          'status' => 'pending',
          'changed' => time(),
        ])
        ->condition('id', $record->id)
        ->execute();

      // Queue a download for the pending thumbnail. This will trigger the
      // processThumbnail() method to download and store the thumbnail in the
      // background.
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
          'source_hash' => $this->getThumbnailSourceHash($iframe),
          'iframe_url' => $iframe->thumbnailLookupUrl,
          'source_url' => $iframe->sourceUrl,
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
            'source_hash' => $this->getThumbnailSourceHash($iframe),
            'iframe_url' => $iframe->thumbnailLookupUrl,
            'source_url' => $iframe->sourceUrl,
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
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The translation of the entity to synchronize.
   */
  private function syncTranslation(FieldableEntityInterface $entity): void {
    $langcode = $entity->language()->getId();

    // Load all existing thumbnail records for this entity translation.
    $existing = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchAllAssoc('id');

    $retained_ids = [];

    // Load the trusted hosts from configuration, defaulting to an empty array
    // if not set.
    $trusted_hosts = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get('trusted_hosts') ?: [];

    // Iterate over every field definition of the entity to find text fields
    // that use this formatter.
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

      // Iterate over every field item in the text field to find iframes that
      // need thumbnail tracking.
      foreach ($entity->get($field_name) as $delta => $item) {
        $parsed = $this->iframeParser->parse((string) $item->value);

        // Skip if the field item does not contain a valid iframe or if the
        // iframe is from a trusted host. We only track thumbnails for untrusted
        // iframes to avoid unnecessary downloads and storage of thumbnails for
        // content that is already trusted.
        if (
          !($parsed instanceof ParsedIframe)
          || $this->iframeParser->isTrustedHost($parsed->host, $trusted_hosts)
        ) {
          continue;
        }

        // Ensure that a thumbnail record exists for this iframe, creating or
        // updating it as necessary. This will also queue a download if the
        // thumbnail is not yet ready.
        $this->ensureThumbnail($entity, $field_name, (int) $delta, $parsed);

        // Mark this record as retained so that we don't delete it later.
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

    // Delete any existing thumbnail records that were not retained during the
    // synchronization process. This ensures that we don't keep orphaned records
    // for iframes that are no longer present in the entity's fields.
    foreach ($existing as $id => $record) {
      if (!isset($retained_ids[(int) $id])) {
        $this->deleteRecord($record);
      }
    }
  }

  /**
   * Deletes every thumbnail belonging to an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose thumbnails should be deleted.
   */
  public function deleteForEntity(EntityInterface $entity): void {
    // Only process fieldable entities that are saved and have a UUID.
    if ($entity->uuid() === NULL) {
      return;
    }

    // Delete every thumbnail record for this entity, regardless of language.
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
    // Load the thumbnail record from the database. We do this at the start of
    // the process to ensure that we have the most up-to-date information and
    // to avoid processing a record that has already been handled by another
    // process.
    $record = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('id', $recordId)
      ->execute()
      ->fetchObject();

    // If the record does not exist or is not pending, skip processing. This can
    // happen if another process has already handled it.
    if ($record === FALSE || $record->status !== 'pending') {
      return;
    }

    // Mark the record as in-progress to prevent other processes from attempting
    // to process it simultaneously.
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

      $is_facebook = $this->isFacebookUrl($record->iframe_url);
      $thumbnail_urls = [];
      $oembed_html = '';

      try {
        // Resolve the oEmbed resource URL.
        $resource_url = $this->urlResolver
          ->getResourceUrl($record->iframe_url);

        // Fetch the oEmbed resource and extract the thumbnail URL.
        $resource = $this->resourceFetcher->fetchResource($resource_url);
        $thumbnail_url = $resource->getThumbnailUrl();

        // If the oEmbed resource provides a thumbnail URL, add it to the list
        // of candidates to download.
        if (!empty($thumbnail_url)) {
          $thumbnail_urls[] = $thumbnail_url->toString();
        }

        // For Facebook, also extract the HTML from the oEmbed response to look
        // for additional image candidates in the iframe document.
        if ($is_facebook) {
          $oembed_html = $resource->getHtml() ?? '';
        }
      }
      catch (\Throwable $exception) {
        if (!$is_facebook) {
          throw $exception;
        }

        $this->logger->notice(
          'Facebook oEmbed preview lookup failed for @url: @message',
          [
            '@url' => $record->iframe_url,
            '@message' => $exception->getMessage(),
          ],
        );
      }

      if ($is_facebook) {
        // Facebook oEmbed responses frequently omit thumbnail_url. Prefer
        // inert image elements and metadata already present in the response.
        $thumbnail_urls = array_merge(
          $thumbnail_urls,
          $this->previewUrlExtractor->extractImageUrls(
            $oembed_html,
            $record->iframe_url,
            TRUE,
          ),
        );

        // Download the first valid image from the oEmbed thumbnail URL or the
        // iframe document.
        $uri = $this->downloadFirstValidImage(
          $thumbnail_urls,
          $max_download_bytes,
          $record,
          TRUE,
        );

        // If oEmbed has no usable image, inspect the iframe document for
        // Open Graph metadata, a video poster, or a regular image.
        if ($uri === NULL) {
          $iframe_urls = $this->previewUrlExtractor->extractIframeUrls(
            $oembed_html,
            $record->iframe_url,
          );
          $iframe_urls[] = $record->source_url ?? $record->iframe_url;
          $uri = $this->downloadFacebookIframePreview(
            array_values(array_unique($iframe_urls)),
            $max_download_bytes,
            $record,
          );
        }
      }
      else {
        // For non-Facebook providers, download the first valid image from the
        // oEmbed thumbnail URL.
        $uri = $this->downloadFirstValidImage(
          $thumbnail_urls,
          $max_download_bytes,
          $record,
        );
      }

      // If no usable image was found, throw an exception to mark the record as
      // failed.
      if ($uri === NULL) {
        throw new \RuntimeException('No usable preview image was found.');
      }

      // Update the record with the new thumbnail URI and mark it as ready.
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

      // If the record was not updated, it may have been deleted or changed by
      // another process.
      if ($updated === 0) {
        $this->deleteFile($uri);
        return;
      }

      // Invalidate any cached render arrays that reference this thumbnail.
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

      // Mark the record as failed to prevent repeated retries.
      $this->markFailed($recordId, $record->source_hash);
    }
  }

  /**
   * Downloads and stores the first valid image in a list of candidates.
   *
   * @param string[] $urls
   *   Candidate image URLs in preference order.
   * @param int $maxDownloadBytes
   *   Maximum response body size.
   * @param object $record
   *   The thumbnail database record.
   * @param bool $excludeIcons
   *   Whether to skip downloaded images that look like icons.
   *
   * @return string|null
   *   The stored file URI, or NULL when every candidate failed.
   */
  private function downloadFirstValidImage(
    array $urls,
    int $maxDownloadBytes,
    object $record,
    bool $excludeIcons = FALSE,
  ): ?string {
    foreach (array_unique($urls) as $url) {
      try {
        $response = $this->requestRemoteImage($url);
        $content_length = (int) $response->getHeaderLine('Content-Length');

        // Skip images that exceed the maximum size limit, even if the server
        // does not send a Content-Length header.
        if ($content_length > $maxDownloadBytes) {
          throw new \RuntimeException(
            'The preview image exceeds the size limit.'
          );
        }

        // Determine the MIME type of the response, ignoring any charset or
        // other parameters.
        $mime_type = strtolower(trim(explode(
          ';',
          $response->getHeaderLine('Content-Type'))[0])
        );

        // Skip images that are not of a supported media type.
        if (!isset(self::THUMBNAIL_TYPES[$mime_type])) {
          throw new \RuntimeException(
            'The preview image has an unsupported media type.'
          );
        }

        // Read the image data up to the maximum size limit, plus one byte to
        // detect overflow.
        $data = $response->getBody()->read($maxDownloadBytes + 1);
        if ($data === '' || strlen($data) > $maxDownloadBytes) {
          throw new \RuntimeException(
            'The preview image is empty or exceeds the size limit.'
          );
        }

        // Validate that the downloaded data is a valid image of the expected
        // type.
        $image_info = @getimagesizefromstring($data);
        if (
          $image_info === FALSE
          || ($image_info['mime'] ?? NULL) !== $mime_type
        ) {
          throw new \RuntimeException(
            'The downloaded data is not a valid image.'
          );
        }

        // Skip images that are likely to be avatars or interface icons, which
        // are not suitable for our thumbnail display.
        if ($excludeIcons && $this->isLikelyIcon($url, $image_info)) {
          $this->logger->notice(
            'Skipped icon-like Facebook preview candidate @url.',
            ['@url' => $url],
          );
          continue;
        }

        // Prepare the public thumbnail directory, creating it if necessary.
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

        // Build a unique destination filename using the source hash and record
        // ID.
        $destination = sprintf(
          '%s/%s-%d.%s',
          $directory,
          $record->source_hash,
          $record->id,
          self::THUMBNAIL_TYPES[$mime_type],
        );

        // Save the image data to the destination file, replacing any existing
        // file.
        $uri = $this->fileSystem->saveData(
          $data,
          $destination,
          FileExists::Replace,
        );

        // If the file could not be saved, throw an exception to trigger a
        // retry.
        if ($uri === FALSE) {
          throw new \RuntimeException(
            'The thumbnail file could not be saved.'
          );
        }

        return $uri;
      }
      catch (\Throwable $exception) {
        $this->logger->notice(
          'Preview image candidate failed for @url: @message',
          ['@url' => $url, '@message' => $exception->getMessage()],
        );
      }
    }

    return NULL;
  }

  /**
   * Determines whether an image is likely to be an avatar or interface icon.
   *
   * @param string $url
   *   The URL of the image.
   * @param array<int|string, mixed> $imageInfo
   *   Image metadata returned by getimagesizefromstring().
   */
  private function isLikelyIcon(string $url, array $imageInfo): bool {
    // Retrieve the image dimensions, defaulting to 0 if unavailable.
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);

    // Facebook often returns small avatar images for oEmbed previews, which are
    // not suitable for our thumbnail display. We consider images that are
    // 256x256 pixels or smaller to be likely icons or avatars.
    if ($width > 0 && $height > 0 && $width <= 256 && $height <= 256) {
      return TRUE;
    }

    // Check if the URL itself suggests that the image is an icon or avatar.
    return $this->previewUrlExtractor->isLikelyIconUrl($url);
  }

  /**
   * Finds and downloads an image exposed by a Facebook iframe document.
   *
   * @param string[] $iframeUrls
   *   Candidate iframe document URLs.
   * @param int $maxDownloadBytes
   *   Maximum document and image response body size.
   * @param object $record
   *   The thumbnail database record.
   *
   * @return string|null
   *   The stored file URI, or NULL when no usable preview was found.
   */
  private function downloadFacebookIframePreview(
    array $iframeUrls,
    int $maxDownloadBytes,
    object $record,
  ): ?string {
    foreach ($iframeUrls as $iframe_url) {
      try {
        $response = $this->requestRemoteDocument($iframe_url);
        $content_length = (int) $response->getHeaderLine('Content-Length');

        // Skip documents that exceed the maximum size limit.
        if ($content_length > $maxDownloadBytes) {
          continue;
        }

        // Read the document body up to the maximum size limit.
        $html = $response->getBody()->read($maxDownloadBytes + 1);
        if ($html === '' || strlen($html) > $maxDownloadBytes) {
          continue;
        }

        // Use the X-Rouen-Iframe-Consent-Url header if present, otherwise fall
        // back to the iframe URL.
        $document_url = $response->getHeaderLine(
          'X-Rouen-Iframe-Consent-Url'
        ) ?: $iframe_url;

        // Extract image URLs from the iframe document, including Open Graph
        // metadata and video posters.
        $image_urls = $this->previewUrlExtractor->extractImageUrls(
          $html,
          $document_url,
          TRUE,
        );

        // Download the first valid image from the iframe document.
        $uri = $this->downloadFirstValidImage(
          $image_urls,
          $maxDownloadBytes,
          $record,
          TRUE,
        );

        if ($uri !== NULL) {
          return $uri;
        }
      }
      catch (\Throwable $exception) {
        $this->logger->notice(
          'Facebook iframe preview lookup failed for @url: @message',
          ['@url' => $iframe_url, '@message' => $exception->getMessage()],
        );
      }
    }

    return NULL;
  }

  /**
   * Determines whether a URL belongs to Facebook.
   *
   * @param string $url
   *   The URL to check.
   *
   * @return bool
   *   TRUE if the URL is a Facebook URL, FALSE otherwise.
   */
  private function isFacebookUrl(string $url): bool {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return $host === 'facebook.com' || str_ends_with($host, '.facebook.com');
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
   * Builds the source hash used to invalidate provider preview strategies.
   *
   * For example, Facebook's oEmbed API may change which image it returns for
   * the same iframe URL, so we need to force a new thumbnail download when
   * the provider's preview strategy changes.
   *
   * @param \Rouen\IframeConsent\ParsedIframe $iframe
   *   The parsed iframe object.
   *
   * @return string
   *   The source hash for the thumbnail.
   */
  private function getThumbnailSourceHash(ParsedIframe $iframe): string {
    if ($iframe->providerName !== 'Facebook') {
      return $iframe->getSourceHash();
    }

    return hash(
      'sha256',
      $iframe->getSourceHash() . ':' . self::FACEBOOK_PREVIEW_VERSION,
    );
  }

  /**
   * Builds the language-aware logical key for a thumbnail record.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the iframe field.
   * @param string $fieldName
   *   The name of the field containing the iframe.
   * @param int $delta
   *   The delta of the field item.
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
   *
   * @param array<string, int|string> $keys
   *   The database key fields.
   *
   * @return object|false
   *   The thumbnail record object, or FALSE if not found.
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
   *
   * @param string $url
   *   The URL of the remote image.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response containing the image.
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

    // Follow redirects manually to validate each destination.
    for ($redirects = 0; $redirects <= $max_redirects; $redirects++) {
      if (!$this->remoteUrlValidator->isSafe($url)) {
        throw new \RuntimeException(
          'The oEmbed thumbnail URL is not safe to retrieve.'
        );
      }

      // Make the HTTP request without following redirects automatically.
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'connect_timeout' => $connect_timeout,
        'timeout' => $timeout,
        'headers' => ['Accept' => 'image/jpeg,image/png,image/gif,image/webp'],
      ]);

      $status = $response->getStatusCode();

      // If the response is successful, return it.
      if ($status >= 200 && $status < 300) {
        return $response;
      }

      // If the response is not a redirect, throw an exception.
      if ($status < 300 || $status >= 400) {
        throw new \RuntimeException(sprintf(
          'The oEmbed thumbnail returned HTTP status %d.',
          $status,
        ));
      }

      // Handle the redirect by resolving the new location.
      $location = $response->getHeaderLine('Location');
      if ($location === '') {
        throw new \RuntimeException(
          'The oEmbed thumbnail redirect has no destination.'
        );
      }

      // Resolve the new URL relative to the previous one.
      $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
    }

    // If we reach here, it means we exceeded the maximum number of redirects.
    throw new \RuntimeException('The oEmbed thumbnail redirected too often.');
  }

  /**
   * Retrieves an HTML document while validating every redirect destination.
   *
   * @param string $url
   *   The URL of the remote HTML document.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The HTTP response containing the HTML document.
   */
  private function requestRemoteDocument(string $url): ResponseInterface {
    // Load settings for thumbnail retrieval.
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

    // Follow redirects manually to validate each destination.
    for ($redirects = 0; $redirects <= $max_redirects; $redirects++) {
      // Validate the URL before making the request.
      if (!$this->remoteUrlValidator->isSafe($url)) {
        throw new \RuntimeException(
          'The iframe document URL is not safe to retrieve.'
        );
      }

      // Make the HTTP request without following redirects automatically.
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'connect_timeout' => $connect_timeout,
        'timeout' => $timeout,
        'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
      ]);

      $status = $response->getStatusCode();

      // If the response is successful, check the content type.
      if ($status >= 200 && $status < 300) {
        $mime_type = strtolower(trim(explode(
          ';',
          $response->getHeaderLine('Content-Type'))[0])
        );

        if (!in_array(
          $mime_type, ['text/html', 'application/xhtml+xml'], TRUE
        )) {
          throw new \RuntimeException(
            'The iframe document has an unsupported media type.'
          );
        }

        return $response->withHeader('X-Rouen-Iframe-Consent-Url', $url);
      }

      if ($status < 300 || $status >= 400) {
        // Not a redirect, throw an exception.
        throw new \RuntimeException(sprintf(
          'The iframe document returned HTTP status %d.',
          $status,
        ));
      }

      // Handle the redirect by resolving the new location.
      $location = $response->getHeaderLine('Location');
      if ($location === '') {
        throw new \RuntimeException(
          'The iframe document redirect has no destination.'
        );
      }

      $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
    }

    // If we reach here, it means we exceeded the maximum number of redirects.
    throw new \RuntimeException('The iframe document redirected too often.');
  }

  /**
   * Returns a positive integer setting or its fallback value.
   *
   * @param string $key
   *   The configuration key to retrieve.
   * @param int $fallback
   *   The fallback value to return if the setting is not a positive integer.
   *
   * @return int
   *   The positive integer setting or the fallback value.
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
      // Invalidate the cache tags for the entity type and ID to ensure that any
      // cached render arrays that reference this thumbnail are rebuilt.
      $this->cacheTagsInvalidator->invalidateTags(
        [$record->entity_type . ':' . $record->entity_id]
      );
    }
  }

}
