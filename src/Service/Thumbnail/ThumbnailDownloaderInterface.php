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
   */
  public function downloadFirst(
    array $urls,
    int $maxDownloadBytes,
    ThumbnailRecord $record,
    bool $excludeIcons = FALSE,
  ): ?DownloadedThumbnail;

}
