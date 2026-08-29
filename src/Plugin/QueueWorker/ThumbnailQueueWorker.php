<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rouen_iframe_consent\Service\ThumbnailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Downloads consent placeholder thumbnails during cron runs.
 */
#[QueueWorker(
  id: 'rouen_iframe_consent_thumbnail',
  title: new TranslatableMarkup('Rouen Iframe Consent thumbnail retrieval'),
  cron: ['time' => 60],
)]
final class ThumbnailQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Creates the queue worker.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ThumbnailManager $thumbnailManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('rouen_iframe_consent.thumbnail_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (is_array($data) && isset($data['record_id'])) {
      $this->thumbnailManager->processThumbnail((int) $data['record_id']);
    }
  }

}
