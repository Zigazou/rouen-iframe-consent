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
   * @param array<string, string|bool> $attributes
   *   Safe iframe attributes.
   */
  public function __construct(
    public string $sourceUrl,
    public string $thumbnailLookupUrl,
    public string $host,
    public string $providerName,
    public int $width,
    public int $height,
    public array $attributes,
  ) {}

  /**
   * Returns a stable hash of the iframe source.
   */
  public function getSourceHash(): string {
    return hash('sha256', $this->sourceUrl);
  }

}

