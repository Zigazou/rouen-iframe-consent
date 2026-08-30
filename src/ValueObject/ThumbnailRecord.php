<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Represents one persisted iframe thumbnail.
 */
final readonly class ThumbnailRecord {

  /**
   * Creates a thumbnail record from a database result.
   *
   * @param object $record
   *   A database record object with the following properties:
   *   - id: The unique ID of the thumbnail record.
   *   - entity_type: The entity type of the associated entity.
   *   - entity_id: The ID of the associated entity.
   *   - entity_uuid: The UUID of the associated entity.
   *   - langcode: The language code of the associated entity.
   *   - field_name: The name of the field containing the thumbnail.
   *   - delta: The delta of the field item containing the thumbnail.
   *   - source_hash: A hash of the source URL for the thumbnail.
   *   - iframe_url: The URL of the iframe associated with the thumbnail.
   *   - source_url: The original source URL of the thumbnail.
   *   - thumbnail_uri: The URI of the stored thumbnail file, or NULL if not
   *     yet downloaded.
   *   - status: The status of the thumbnail record (e.g., 'pending',
   *     'downloaded', 'failed').
   *   - changed: The timestamp of the last change to the thumbnail record.
   */
  public static function fromDatabaseRecord(object $record): self {
    return new self(
      id: (int) $record->id,
      entityType: (string) $record->entity_type,
      entityId: (string) $record->entity_id,
      entityUuid: (string) $record->entity_uuid,
      langcode: (string) $record->langcode,
      fieldName: (string) $record->field_name,
      delta: (int) $record->delta,
      sourceHash: (string) $record->source_hash,
      iframeUrl: (string) $record->iframe_url,
      sourceUrl: (string) $record->source_url,
      thumbnailUri: isset($record->thumbnail_uri)
        ? (string) $record->thumbnail_uri
        : NULL,
      status: (string) $record->status,
      changed: (int) $record->changed,
    );
  }

  /**
   * Creates a thumbnail record.
   * 
   * @param int $id
   *   The unique ID of the thumbnail record.
   * @param string $entityType
   *   The entity type of the associated entity.
   * @param string $entityId
   *   The ID of the associated entity.
   * @param string $entityUuid
   *   The UUID of the associated entity.
   * @param string $langcode
   *   The language code of the associated entity.
   * @param string $fieldName
   *   The name of the field containing the thumbnail.
   * @param int $delta
   *   The delta of the field item containing the thumbnail.
   * @param string $sourceHash
   *   A hash of the source URL for the thumbnail.
   * @param string $iframeUrl
   *   The URL of the iframe associated with the thumbnail.
   * @param string $sourceUrl
   *   The original source URL of the thumbnail.
   * @param string|null $thumbnailUri
   *   The URI of the stored thumbnail file, or NULL if not yet downloaded.
   * @param string $status
   *   The status of the thumbnail record (e.g., 'pending', 'downloaded',
   *   'failed').
   * @param int $changed
   *   The timestamp of the last change to the thumbnail record.
   */
  public function __construct(
    public int $id,
    public string $entityType,
    public string $entityId,
    public string $entityUuid,
    public string $langcode,
    public string $fieldName,
    public int $delta,
    public string $sourceHash,
    public string $iframeUrl,
    public string $sourceUrl,
    public ?string $thumbnailUri,
    public string $status,
    public int $changed,
  ) {}

}
