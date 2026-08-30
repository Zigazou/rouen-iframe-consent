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
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record.
   *
   * @return bool
   *   TRUE if this provider handles the record, FALSE otherwise.
   */
  public function supports(ThumbnailRecord $record): bool;

  /**
   * Finds ordered preview candidates.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\PreviewCandidates
   *   The preview candidates.
   */
  public function findCandidates(ThumbnailRecord $record): PreviewCandidates;

}
