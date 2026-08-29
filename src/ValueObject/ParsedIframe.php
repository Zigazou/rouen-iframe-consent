<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Immutable, sanitized iframe data.
 */
final readonly class ParsedIframe {

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
    public string $sourceUrl,
    public string $thumbnailLookupUrl,
    public string $host,
    public string $providerName,
    public int|string $width,
    public int $height,
    public array $attributes,
  ) {}

  /**
   * Returns a stable hash of the iframe source.
   *
   * @return string
   *   A SHA-256 hash of the iframe source URL.
   */
  public function getSourceHash(): string {
    return hash('sha256', $this->sourceUrl);
  }

}
