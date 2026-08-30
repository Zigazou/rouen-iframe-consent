<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Preview;

use Drupal\rouen_iframe_consent\ValueObject\PreviewCandidates;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Resolves preview candidates for one kind of remote provider.
 */
interface PreviewProviderInterface {

  /**
   * Determines whether this provider handles the record.
   */
  public function supports(ThumbnailRecord $record): bool;

  /**
   * Finds ordered preview candidates.
   */
  public function findCandidates(ThumbnailRecord $record): PreviewCandidates;

}
