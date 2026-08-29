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
   */
  public function __construct(
    private readonly ThumbnailManager $thumbnailManager,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'rouen_iframe_consent_placeholder' => [
        'variables' => [
          'attributes' => '',
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
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->syncEntity($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->syncEntity($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->thumbnailManager->deleteForEntity($entity);
  }

  /**
   * Synchronizes thumbnails for the entity's active revision.
   */
  private function syncEntity(EntityInterface $entity): void {
    if (!$entity->getEntityType()->isRevisionable() ||
      $entity->isDefaultRevision()
    ) {
      $this->thumbnailManager->syncEntity($entity);
    }
  }

}
