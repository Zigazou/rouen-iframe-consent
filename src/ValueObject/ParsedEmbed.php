<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Shared immutable metadata for a sanitized external embed.
 */
abstract readonly class ParsedEmbed {

  /**
   * Creates parsed embed data.
   *
   * @param string $sourceUrl
   *   The original source URL.
   * @param string $thumbnailLookupUrl
   *   The URL to look up a thumbnail for the embed.
   * @param string $host
   *   The host of the source URL.
   * @param string $providerName
   *   The provider name, e.g. "youtube".
   * @param int|string $width
   *   The width of the embed, or a string like "100%".
   * @param int $height
   *   The height of the embed.
   */
  public function __construct(
    public string $sourceUrl,
    public string $thumbnailLookupUrl,
    public string $host,
    public string $providerName,
    public int|string $width,
    public int $height,
  ) {}

  /**
   * Returns a stable hash of the external source.
   *
   * @return string
   *   A SHA-256 hash of the source URL.
   */
  public function getSourceHash(): string {
    return hash('sha256', $this->sourceUrl);
  }

}
