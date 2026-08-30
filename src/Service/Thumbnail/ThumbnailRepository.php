<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Persists thumbnail records.
 */
final class ThumbnailRepository {

  /**
   * The database table for thumbnail records.
   *
   * @var string
   */
  private const TABLE = 'rouen_iframe_consent_thumbnail';

  /**
   * Creates the thumbnail repository.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Finds a record by its entity field location.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord|null
   *   The matching thumbnail record, or NULL if not found.
   */
  public function find(
    EntityInterface $entity,
    string $fieldName,
    int $delta,
  ): ?ThumbnailRecord {
    $query = $this->database->select(self::TABLE, 't')->fields('t');

    foreach ($this->recordKeys($entity, $fieldName, $delta) as $key => $value) {
      $query->condition($key, $value);
    }

    return $this->map($query->execute()->fetchObject());
  }

  /**
   * Finds a record by its primary key.
   *
   * @param int $id
   *   The ID of the thumbnail record.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord|null
   *   The matching thumbnail record, or NULL if not found.
   */
  public function findById(int $id): ?ThumbnailRecord {
    $record = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    return $this->map($record);
  }

  /**
   * Creates a pending record and returns its ID.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   * @param string $sourceHash
   *   The hash of the iframe source URL.
   *
   * @return int
   *   The ID of the newly created thumbnail record.
   */
  public function createPending(
    EntityInterface $entity,
    string $fieldName,
    int $delta,
    ParsedIframe $iframe,
    string $sourceHash,
  ): int {
    return (int) $this->database
      ->insert(self::TABLE)
      ->fields($this->recordKeys($entity, $fieldName, $delta) + [
        'entity_id' => (string) $entity->id(),
        'source_hash' => $sourceHash,
        'iframe_url' => $iframe->thumbnailLookupUrl,
        'source_url' => $iframe->sourceUrl,
        'status' => 'pending',
        'changed' => time(),
      ])
      ->execute();
  }

  /**
   * Resets an existing record for a new thumbnail source.
   *
   * This method updates the record to reflect the new source URL and hash, and
   * sets its status back to 'pending'.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The existing thumbnail record to reset.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data with the new source.
   * @param string $sourceHash
   *   The new hash of the iframe source URL.
   */
  public function resetPending(
    ThumbnailRecord $record,
    EntityInterface $entity,
    ParsedIframe $iframe,
    string $sourceHash,
  ): void {
    $this->database
      ->update(self::TABLE)
      ->fields([
        'entity_id' => (string) $entity->id(),
        'source_hash' => $sourceHash,
        'iframe_url' => $iframe->thumbnailLookupUrl,
        'source_url' => $iframe->sourceUrl,
        'thumbnail_uri' => NULL,
        'status' => 'pending',
        'changed' => time(),
      ])
      ->condition('id', $record->id)
      ->execute();
  }

  /**
   * Updates the source URL without changing the thumbnail state.
   *
   * This is useful for cases where the source URL changes but the thumbnail
   * remains valid.
   *
   * @param int $id
   *   The ID of the thumbnail record to update.
   * @param string $sourceUrl
   *   The new source URL to set.
   */
  public function updateSourceUrl(int $id, string $sourceUrl): void {
    $this->database
      ->update(self::TABLE)
      ->fields(['source_url' => $sourceUrl])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks an unknown state as pending again.
   *
   * This is useful for cases where the thumbnail generation failed or was
   * interrupted, and we want to retry.
   *
   * @param int $id
   *   The ID of the thumbnail record to mark as pending.
   */
  public function markPending(int $id): void {
    $this->database
      ->update(self::TABLE)
      ->fields(['status' => 'pending', 'changed' => time()])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Touches a pending record before processing begins.
   *
   * This updates the 'changed' timestamp to prevent other workers from
   * processing the same record simultaneously.
   *
   * @param int $id
   *   The ID of the thumbnail record to touch.
   */
  public function touch(int $id): void {
    $this->database
      ->update(self::TABLE)
      ->fields(['changed' => time()])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Marks a pending record as ready if its source has not changed.
   *
   * If the source hash does not match, the record is not updated and the method
   * returns FALSE. If the update is successful, the method returns TRUE.
   *
   * @param int $id
   *   The ID of the thumbnail record to mark as ready.
   * @param string $sourceHash
   *   The expected source hash of the thumbnail record.
   * @param string $uri
   *   The URI of the generated thumbnail file.
   *
   * @return bool
   *   TRUE if the record was successfully marked as ready, FALSE otherwise.
   */
  public function markReady(
    int $id,
    string $sourceHash,
    string $uri,
  ): bool {
    $updated = $this->database
      ->update(self::TABLE)
      ->fields([
        'thumbnail_uri' => $uri,
        'status' => 'ready',
        'changed' => time(),
      ])
      ->condition('id', $id)
      ->condition('source_hash', $sourceHash)
      ->condition('status', 'pending')
      ->execute();

    return $updated > 0;
  }

  /**
   * Marks a pending record as permanently failed.
   *
   * This is used when thumbnail generation fails and should not be retried.
   *
   * @param int $id
   *   The ID of the thumbnail record to mark as failed.
   * @param string $sourceHash
   *   The expected source hash of the thumbnail record.
   */
  public function markFailed(int $id, string $sourceHash): void {
    $this->database
      ->update(self::TABLE)
      ->fields(['status' => 'failed', 'changed' => time()])
      ->condition('id', $id)
      ->condition('source_hash', $sourceHash)
      ->condition('status', 'pending')
      ->execute();
  }

  /**
   * Finds records for one entity translation, keyed by record ID.
   *
   * @return array<int, \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord>
   *   The matching records.
   */
  public function findForTranslation(
    FieldableEntityInterface $entity,
  ): array {
    $records = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->condition('langcode', $entity->language()->getId())
      ->execute()
      ->fetchAllAssoc('id');

    return $this->mapAll($records);
  }

  /**
   * Finds records for translations outside the supplied language list.
   *
   * This is useful for cleaning up thumbnail records when translations are
   * removed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string[] $langcodes
   *   Existing entity language codes.
   *
   * @return array<int, \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord>
   *   The stale records.
   */
  public function findOutsideTranslations(
    EntityInterface $entity,
    array $langcodes,
  ): array {
    $records = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->condition('langcode', $langcodes, 'NOT IN')
      ->execute()
      ->fetchAllAssoc('id');

    return $this->mapAll($records);
  }

  /**
   * Finds every record belonging to an entity.
   *
   * @return array<int, \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord>
   *   The matching records.
   */
  public function findForEntity(EntityInterface $entity): array {
    $records = $this->database
      ->select(self::TABLE, 't')
      ->fields('t')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_uuid', $entity->uuid())
      ->execute()
      ->fetchAllAssoc('id');

    return $this->mapAll($records);
  }

  /**
   * Deletes a record.
   *
   * This is used when the associated entity or translation is deleted, or when
   * a thumbnail is no longer needed.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record to delete.
   */
  public function delete(ThumbnailRecord $record): void {
    $this->database
      ->delete(self::TABLE)
      ->condition('id', $record->id)
      ->execute();
  }

  /**
   * Builds the logical key for a thumbnail record.
   *
   * This key is used to uniquely identify a thumbnail record based on its
   * associated entity, field, and delta.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name containing the iframe.
   * @param int $delta
   *   The delta of the field item.
   *
   * @return array<string, mixed>
   *   An associative array representing the logical key of the thumbnail
   *   record. The keys are 'entity_type', 'entity_uuid', 'langcode',
   *   'field_name', and 'delta'.
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
   * Maps one database result to a typed record.
   *
   * @param object|false $record
   *   The raw database result, or FALSE if no record was found.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord|null
   *   The typed record, or NULL if no record was found.
   */
  private function map(object|false $record): ?ThumbnailRecord {
    return $record === FALSE
      ? NULL
      : ThumbnailRecord::fromDatabaseRecord($record);
  }

  /**
   * Maps database results to typed records.
   *
   * @param object[] $records
   *   The raw database results.
   *
   * @return array<int, \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord>
   *   The typed records, keyed by record ID.
   */
  private function mapAll(array $records): array {
    $mapped = [];
    foreach ($records as $record) {
      $thumbnail = ThumbnailRecord::fromDatabaseRecord($record);
      $mapped[$thumbnail->id] = $thumbnail;
    }

    return $mapped;
  }

}
