<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailFileStorage;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailQueue;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailRepository;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailSourceHasher;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Provides the public API for iframe preview thumbnails.
 */
final class ThumbnailManager {

  /**
   * Creates the thumbnail manager.
   */
  public function __construct(
    private readonly ThumbnailRepository $repository,
    private readonly ThumbnailQueue $queue,
    private readonly ThumbnailSourceHasher $sourceHasher,
    private readonly ThumbnailFileStorage $fileStorage,
  ) {}

  /**
   * Returns an existing thumbnail without changing persistent state.
   *
   * If the thumbnail is not ready, returns NULL.
   *
   * This method does not create or update any thumbnail records.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   *
   * @return string|null
   *   The URL of the thumbnail if ready, or NULL if not ready or not found
   */
  public function getThumbnail(
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

    // Look up the thumbnail record for the given entity, field, and delta.
    $record = $this->repository->find($entity, $fieldName, $delta);
    if (
      $record === NULL
      || !hash_equals($record->sourceHash, $this->sourceHasher->hash($iframe))
      || $record->status !== 'ready'
    ) {
      return NULL;
    }

    return $this->fileStorage->url($record->thumbnailUri);
  }

  /**
   * Creates or updates a thumbnail record and returns its URL if ready.
   *
   * If the thumbnail is not ready, returns NULL and enqueues a job to generate
   * it.
   *
   * This method ensures that the thumbnail record is up-to-date with the
   * current iframe data.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   *
   * @return string|null
   *   The URL of the thumbnail if ready, or NULL if not ready or not found or
   *   if the entity is not suitable for thumbnail generation.
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
      || ($entity->getEntityType()->isRevisionable()
        && !$entity->isDefaultRevision())
    ) {
      return NULL;
    }

    $sourceHash = $this->sourceHasher->hash($iframe);
    $record = $this->repository->find($entity, $fieldName, $delta);

    // If the record exists and the source hash matches, check its status.
    if ($record !== NULL && hash_equals($record->sourceHash, $sourceHash)) {
      if ($record->sourceUrl !== $iframe->sourceUrl) {
        $this->repository->updateSourceUrl($record->id, $iframe->sourceUrl);
      }

      $url = $this->fileStorage->url($record->thumbnailUri);
      if ($url !== NULL || $record->status === 'pending') {
        return $url;
      }

      if ($record->status === 'failed') {
        return NULL;
      }

      $this->repository->markPending($record->id);
      $this->queue->enqueue($record->id);

      return NULL;
    }

    // If the record does not exist or the source hash does not match, create
    // or reset the record and enqueue a job to generate the thumbnail.
    if ($record !== NULL) {
      $this->fileStorage->delete($record->thumbnailUri);
      $this->repository->resetPending(
        $record,
        $entity,
        $iframe,
        $sourceHash,
      );
      $recordId = $record->id;
    }
    else {
      try {
        // Create a new pending record for the thumbnail.
        $recordId = $this->repository->createPending(
          $entity,
          $fieldName,
          $delta,
          $iframe,
          $sourceHash,
        );
      }
      catch (IntegrityConstraintViolationException) {
        return $this->ensureThumbnail($entity, $fieldName, $delta, $iframe);
      }
    }

    $this->queue->enqueue($recordId);

    return NULL;
  }

}
