<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Builds thumbnail source hashes with provider strategy versions.
 */
final class ThumbnailSourceHasher {

  /**
   * Version of the Facebook preview selection strategy.
   */
  private const FACEBOOK_PREVIEW_VERSION = 3;

  /**
   * Builds the source hash for parsed iframe data.
   */
  public function hash(ParsedIframe $iframe): string {
    if ($iframe->providerName !== 'Facebook') {
      return $iframe->getSourceHash();
    }

    return hash(
      'sha256',
      $iframe->getSourceHash() . ':' . self::FACEBOOK_PREVIEW_VERSION,
    );
  }

}
