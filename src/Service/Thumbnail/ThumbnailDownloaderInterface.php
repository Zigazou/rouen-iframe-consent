<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Downloads and stores remote thumbnail images.
 */
interface ThumbnailDownloaderInterface {

  /**
   * Downloads and stores one image.
   *
   * @param string $url
   *   The URL of the image to download.
   * @param int $maxDownloadBytes
   *   The maximum number of bytes to download.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   Metadata about the thumbnail being downloaded.
   * @param bool $excludeIcons
   *   Whether to exclude likely icon or avatar images.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail
   *   Information about the downloaded thumbnail.
   */
  public function download(
    string $url,
    int $maxDownloadBytes,
    ThumbnailRecord $record,
    bool $excludeIcons = FALSE,
  ): DownloadedThumbnail;

  /**
   * Downloads the first usable image candidate.
   *
   * @param string[] $urls
   *   Candidate image URLs in preference order.
   * @param int $maxDownloadBytes
   *   Maximum number of bytes to download.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   Metadata about the thumbnail being downloaded.
   * @param bool $excludeIcons
   *   Whether to exclude likely icon or avatar images.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail|null
   *   Information about the downloaded thumbnail, or NULL if no valid image was
   *   found.
   */
  public function downloadFirst(
    array $urls,
    int $maxDownloadBytes,
    ThumbnailRecord $record,
    bool $excludeIcons = FALSE,
  ): ?DownloadedThumbnail;

}
