<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Describes a downloaded and stored thumbnail.
 */
final readonly class DownloadedThumbnail {

  /**
   * Creates downloaded thumbnail metadata.
   *
   * @param string $uri
   *   The URI of the stored thumbnail file.
   * @param string $mimeType
   *   The MIME type of the thumbnail.
   * @param int $width
   *   The width of the thumbnail in pixels.
   * @param int $height
   *   The height of the thumbnail in pixels.
   */
  public function __construct(
    public string $uri,
    public string $mimeType,
    public int $width,
    public int $height,
  ) {}

}
