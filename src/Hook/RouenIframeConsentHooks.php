<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\rouen_iframe_consent\Service\ThumbnailManager;

/**
 * Hook implementations for Rouen Iframe Consent.
 */
final class RouenIframeConsentHooks {

  /**
   * Creates the hook implementation service.
   *
   * @param \Drupal\rouen_iframe_consent\Service\ThumbnailManager $thumbnailManager
   *   The thumbnail manager service.
   */
  public function __construct(
    private readonly ThumbnailManager $thumbnailManager,
  ) {}

  /**
   * Implements hook_theme().
   *
   * The rouen_iframe_consent_placeholder theme hook is used to render the
   * placeholder for the iframe consent.
   *
   * @return array
   *   An array of theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'rouen_iframe_consent_placeholder' => [
        'variables' => [
          'iframe_attributes' => '',
          'width' => 560,
          'height' => 315,
          'provider' => '',
          'thumbnail_url' => '',
          'message' => '',
          'button_label' => '',
        ],
        'template' => 'rouen-iframe-consent-placeholder',
      ],
    ];
  }

  /**
   * Implements hook_entity_insert().
   *
   * This hook is called after an entity has been inserted into the database.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was inserted.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->syncEntity($entity);
  }

  /**
   * Implements hook_entity_update().
   *
   * This hook is called after an entity has been updated in the database.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was updated.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->syncEntity($entity);
  }

  /**
   * Implements hook_entity_delete().
   *
   * This hook is called after an entity has been deleted from the database.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was deleted.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->thumbnailManager->deleteForEntity($entity);
  }

  /**
   * Synchronizes thumbnails for the entity's active revision.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to synchronize.
   */
  private function syncEntity(EntityInterface $entity): void {
    if (!$entity->getEntityType()->isRevisionable() ||
      $entity->isDefaultRevision()
    ) {
      $this->thumbnailManager->syncEntity($entity);
    }
  }

}
