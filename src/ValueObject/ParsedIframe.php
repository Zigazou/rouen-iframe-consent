<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Immutable, sanitized iframe data.
 */
final readonly class ParsedIframe extends ParsedEmbed {

  /**
   * Creates parsed iframe data.
   *
   * @param string $sourceUrl
   *   The iframe source URL.
   * @param string $thumbnailLookupUrl
   *   The URL to retrieve a thumbnail for the iframe.
   * @param string $host
   *   The iframe source host name.
   * @param string $providerName
   *   The iframe provider name.
   * @param int|string $width
   *   The iframe width in pixels or as a percentage.
   * @param int $height
   *   The iframe height in pixels.
   * @param array<string, string> $attributes
   *   The iframe attributes.
   */
  public function __construct(
    string $sourceUrl,
    string $thumbnailLookupUrl,
    string $host,
    string $providerName,
    int|string $width,
    int $height,
    public array $attributes,
  ) {
    parent::__construct(
      $sourceUrl,
      $thumbnailLookupUrl,
      $host,
      $providerName,
      $width,
      $height,
    );
  }

}
