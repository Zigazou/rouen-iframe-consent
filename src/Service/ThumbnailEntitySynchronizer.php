<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailFileStorage;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailRepository;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Synchronizes thumbnail tracking with Drupal entity lifecycle changes.
 */
final class ThumbnailEntitySynchronizer {

  /**
   * Formatter usage cached by entity type and bundle for this request.
   *
   * @var array<string, array<string, bool>>
   */
  private array $formatterFields = [];

  /**
   * Creates the entity synchronizer.
   */
  public function __construct(
    private readonly ThumbnailManager $thumbnailManager,
    private readonly ThumbnailRepository $repository,
    private readonly ThumbnailFileStorage $fileStorage,
    private readonly IframeParser $iframeParser,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Synchronizes tracked thumbnails when a fieldable entity is saved.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was saved.
   */
  public function sync(EntityInterface $entity): void {
    // Only synchronize fieldable entities that are not new and have a UUID.
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->isNew()
      || $entity->uuid() === NULL
      || ($entity->getEntityType()->isRevisionable()
        && !$entity->isDefaultRevision())
    ) {
      return;
    }

    // Synchronize each translation of the entity.
    $langcodes = array_keys($entity->getTranslationLanguages());
    foreach ($langcodes as $langcode) {
      if (!$entity->hasTranslation($langcode)) {
        continue;
      }

      $translation = $entity->getTranslation($langcode);
      if ($translation instanceof FieldableEntityInterface) {
        $this->syncTranslation($translation);
      }
    }

    if ($langcodes === []) {
      return;
    }

    // Delete any thumbnail records that belong to translations that no longer
    // exist.
    foreach ($this->repository->findOutsideTranslations(
      $entity,
      $langcodes,
    ) as $record) {
      $this->deleteRecord($record);
    }
  }

  /**
   * Deletes every thumbnail belonging to an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was deleted.
   */
  public function delete(EntityInterface $entity): void {
    if ($entity->uuid() === NULL) {
      return;
    }

    // Delete every thumbnail record for the entity, regardless of translation.
    foreach ($this->repository->findForEntity($entity) as $record) {
      $this->deleteRecord($record);
    }
  }

  /**
   * Synchronizes one translation of a saved entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The translation of the entity that was saved.
   */
  private function syncTranslation(FieldableEntityInterface $entity): void {
    $existing = $this->repository->findForTranslation($entity);
    $retainedIds = [];
    $trustedHosts = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get('trusted_hosts') ?: [];

    // Iterate over every field and delta of the entity to find iframes.
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      // Skip fields that are not text fields or do not use the consent
      // formatter.
      if (
        !in_array(
          $definition->getType(),
          ['text', 'text_long', 'text_with_summary'],
          TRUE,
        )
        || !$this->fieldUsesFormatter($entity, $fieldName)
      ) {
        continue;
      }

      // Iterate over every field item to find iframes.
      foreach ($entity->get($fieldName) as $delta => $item) {
        $parsed = $this->iframeParser->parse((string) $item->value);
        if (
          !$parsed instanceof ParsedIframe
          || $this->iframeParser->isTrustedHost($parsed->host, $trustedHosts)
        ) {
          continue;
        }

        // Ensure that a thumbnail record exists for this iframe and get its URL
        // if ready.
        $this->thumbnailManager->ensureThumbnail(
          $entity,
          $fieldName,
          (int) $delta,
          $parsed,
        );

        // Retain any existing record that matches this field and delta.
        foreach ($existing as $id => $record) {
          if ($record->fieldName === $fieldName && $record->delta === $delta) {
            $retainedIds[$id] = TRUE;
          }
        }
      }
    }

    // Delete any existing records that were not retained.
    foreach ($existing as $id => $record) {
      if (!isset($retainedIds[$id])) {
        $this->deleteRecord($record);
      }
    }
  }

  /**
   * Checks whether any view mode uses the consent formatter for a field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity containing the field.
   * @param string $fieldName
   *   The field name to check.
   *
   * @return bool
   *   TRUE if any view mode uses the consent formatter for the field, FALSE
   *   otherwise.
   */
  private function fieldUsesFormatter(
    FieldableEntityInterface $entity,
    string $fieldName,
  ): bool {
    $entityTypeId = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $cacheKey = $entityTypeId . ':' . $bundle;

    // If the formatter usage for this entity type and bundle has already been
    // computed, return the cached result.
    if (isset($this->formatterFields[$cacheKey])) {
      return isset($this->formatterFields[$cacheKey][$fieldName]);
    }

    // Compute the formatter usage for this entity type and bundle and cache it
    // for future calls.
    $this->formatterFields[$cacheKey] = [];
    $viewModes = $this->entityDisplayRepository
      ->getViewModeOptionsByBundle($entityTypeId, $bundle);

    // Iterate over every view mode and check if the consent formatter is used
    // for any field.
    foreach (array_keys($viewModes) as $viewMode) {
      $display = $this->entityDisplayRepository
        ->getViewDisplay($entityTypeId, $bundle, $viewMode);

      // Iterate over every field in the view mode and check if the consent
      // formatter is used.
      foreach ($display->getComponents() as $name => $component) {
        if (($component['type'] ?? NULL) === 'rouen_iframe_consent') {
          $this->formatterFields[$cacheKey][$name] = TRUE;
        }
      }
    }

    return isset($this->formatterFields[$cacheKey][$fieldName]);
  }

  /**
   * Removes one record and its thumbnail file.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record to delete.
   */
  private function deleteRecord(ThumbnailRecord $record): void {
    // Delete the thumbnail file and the record from the repository.
    $this->fileStorage->delete($record->thumbnailUri);
    $this->repository->delete($record);
  }

}
