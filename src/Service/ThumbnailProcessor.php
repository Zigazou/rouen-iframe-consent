<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\rouen_iframe_consent\Service\Preview\PreviewResolver;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailFileStorage;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailRepository;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Processes queued thumbnail downloads.
 */
final class ThumbnailProcessor {

  /**
   * Maximum size of downloaded thumbnails in bytes (5 MB).
   *
   * @var int
   */
  private const DEFAULT_MAX_DOWNLOAD_BYTES = 5_242_880;

  /**
   * Creates the thumbnail processor.
   */
  public function __construct(
    private readonly ThumbnailRepository $repository,
    private readonly PreviewResolver $previewResolver,
    private readonly ThumbnailFileStorage $fileStorage,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerChannelInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Processes one queued thumbnail download.
   *
   * If the thumbnail is successfully downloaded, the record is marked as ready
   * and the owning entity is invalidated. If the download fails, the record is
   * marked as failed and the error is logged.
   *
   * @param int $recordId
   *   The ID of the thumbnail record to process.
   *
   * @throws \Drupal\Core\Database\IntegrityConstraintViolationException
   *   If the record is not found or has already been processed.
   * @throws \RuntimeException
   *   If the thumbnail could not be downloaded or processed.
   */
  public function process(int $recordId): void {
    // Look up the thumbnail record by ID.
    $record = $this->repository->findById($recordId);
    if ($record === NULL || $record->status !== 'pending') {
      return;
    }

    // Touch the record to prevent other workers from processing it
    // simultaneously.
    $this->repository->touch($recordId);

    try {
      // Attempt to download and process the thumbnail.
      $thumbnail = $this->previewResolver->resolve(
        $record,
        $this->maxDownloadBytes(),
      );

      if ($thumbnail === NULL) {
        throw new \RuntimeException('No usable preview image was found.');
      }

      // Mark the record as ready and invalidate the owning entity.
      if (!$this->repository->markReady(
        $recordId,
        $record->sourceHash,
        $thumbnail->uri,
      )) {
        $this->fileStorage->delete($thumbnail->uri);
        return;
      }

      // Invalidate the owning entity so that the new thumbnail is displayed.
      $this->invalidateRecord($record);
    }
    catch (\Throwable $exception) {
      $this->logger->warning(
        'Thumbnail retrieval failed for @url: @message',
        [
          '@url' => $record->iframeUrl,
          '@message' => $exception->getMessage(),
        ],
      );

      $this->repository->markFailed($recordId, $record->sourceHash);
    }
  }

  /**
   * Returns the configured maximum response body size.
   *
   * @return int
   *   The maximum number of bytes to download for a thumbnail.
   */
  private function maxDownloadBytes(): int {
    $value = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get('remote_thumbnail_max_size');

    return is_numeric($value) && (int) $value > 0
      ? (int) $value
      : self::DEFAULT_MAX_DOWNLOAD_BYTES;
  }

  /**
   * Invalidates the rendered owning entity after a thumbnail changes.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record that was updated.
   */
  private function invalidateRecord(ThumbnailRecord $record): void {
    $definition = $this->entityTypeManager->getDefinition(
      $record->entityType,
      FALSE,
    );

    // If the entity type is defined, invalidate the entity's cache tags.
    if ($definition !== NULL) {
      $this->cacheTagsInvalidator->invalidateTags(
        [$record->entityType . ':' . $record->entityId],
      );
    }
  }

}
