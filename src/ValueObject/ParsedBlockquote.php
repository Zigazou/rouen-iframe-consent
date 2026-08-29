<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Immutable, sanitized blockquote embed data.
 */
final readonly class ParsedBlockquote extends ParsedEmbed {

  /**
   * Creates parsed blockquote data.
   *
   * @param string $sourceUrl
   *   The original source URL.
   * @param string $host
   *   The host of the source URL.
   * @param string $providerName
   *   The provider name, e.g. "twitter".
   * @param int|string $width
   *   The width of the embed, or a string like "100%".
   * @param int $height
   *   The height of the embed.
   * @param string $previewHtml
   *   Sanitized HTML displayed before consent.
   * @param array<string, string|bool> $scriptAttributes
   *   Sanitized attributes for the provider script.
   */
  public function __construct(
    string $sourceUrl,
    string $host,
    string $providerName,
    int|string $width,
    int $height,
    public string $previewHtml,
    public array $scriptAttributes,
  ) {
    parent::__construct(
      $sourceUrl,
      $sourceUrl,
      $host,
      $providerName,
      $width,
      $height,
    );
  }

}
