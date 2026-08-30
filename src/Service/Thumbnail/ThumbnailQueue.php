<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\Core\Queue\QueueFactory;

/**
 * Queues thumbnail processing tasks.
 */
final class ThumbnailQueue {

  /**
   * The queue name for thumbnail download tasks.
   */
  private const QUEUE = 'rouen_iframe_consent_thumbnail';

  /**
   * Creates the thumbnail queue.
   */
  public function __construct(
    private readonly QueueFactory $queueFactory,
  ) {}

  /**
   * Queues a record for processing.
   */
  public function enqueue(int $recordId): void {
    $this->queueFactory
      ->get(self::QUEUE)
      ->createItem(['record_id' => $recordId]);
  }

}
