<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Represents one persisted iframe thumbnail.
 */
final readonly class ThumbnailRecord {

  /**
   * Creates a thumbnail record from a database result.
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
