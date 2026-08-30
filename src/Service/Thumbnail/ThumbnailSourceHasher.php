<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Builds thumbnail source hashes with provider strategy versions.
 */
final class ThumbnailSourceHasher {

  /**
   * Builds the source hash for parsed iframe data.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe $iframe
   *   The parsed iframe data.
   *
   * @return string
   *   The source hash.
   */
  public function hash(ParsedIframe $iframe): string {
    if ($iframe->providerName !== 'Facebook') {
      return $iframe->getSourceHash();
    }

    return hash('sha256', $iframe->getSourceHash());
  }

}
