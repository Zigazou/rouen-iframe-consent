<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Describes a downloaded and stored thumbnail.
 */
final readonly class DownloadedThumbnail {

  /**
   * Creates downloaded thumbnail metadata.
   */
  public function __construct(
    public string $uri,
    public string $mimeType,
    public int $width,
    public int $height,
  ) {}

}
