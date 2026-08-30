<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor;
use Drupal\rouen_iframe_consent\Service\Remote\SafeRemoteHttpClient;
use Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Validates, downloads, and stores remote thumbnail images.
 */
final class ThumbnailDownloader implements ThumbnailDownloaderInterface {

  /**
   * Supported image media types and file extensions.
   *
   * @var array<string, string>
   */
  private const THUMBNAIL_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
  ];

  /**
   * Creates the thumbnail downloader.
   *
   * @param \Drupal\rouen_iframe_consent\Service\Remote\SafeRemoteHttpClient $httpClient
   *   A HTTP client that safely fetches remote resources.
   * @param \Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailFileStorage $fileStorage
   *   A service that handles storage of thumbnail files.
   * @param \Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor $previewUrlExtractor
   *   A service that extracts and analyzes preview URLs.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   A logger channel for logging thumbnail download events.
   */
  public function __construct(
    private readonly SafeRemoteHttpClient $httpClient,
    private readonly ThumbnailFileStorage $fileStorage,
    private readonly PreviewUrlExtractor $previewUrlExtractor,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Downloads and stores a single image.
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
  ): DownloadedThumbnail {
    $response = $this->httpClient->get(
      $url,
      array_keys(self::THUMBNAIL_TYPES),
    );

    // Check the Content-Length header to avoid downloading large files.
    $contentLength = (int) $response->getHeaderLine('Content-Length');

    if ($contentLength > $maxDownloadBytes) {
      throw new \RuntimeException('The preview image exceeds the size limit.');
    }

    $mimeType = strtolower(trim(explode(
      ';',
      $response->getHeaderLine('Content-Type'),
    )[0]));

    $data = $response->getBody()->read($maxDownloadBytes + 1);

    // Validate the downloaded data.
    if ($data === '' || strlen($data) > $maxDownloadBytes) {
      throw new \RuntimeException(
        'The preview image is empty or exceeds the size limit.'
      );
    }

    // If the MIME type of the downloaded data is not the same as the
    // Content-Type header, reject it.
    $imageInfo = @getimagesizefromstring($data);
    if ($imageInfo === FALSE || ($imageInfo['mime'] ?? NULL) !== $mimeType) {
      throw new \RuntimeException(
        'The downloaded data is not a valid image.'
      );
    }

    // If the image is likely to be an icon or avatar, and the caller requested
    // to exclude such images, reject it.
    if ($excludeIcons && $this->isLikelyIcon($url, $imageInfo)) {
      throw new \RuntimeException(
        'The preview image looks like an icon or avatar.'
      );
    }

    // Save the image data to the file storage and return a DownloadedThumbnail
    // object.
    $uri = $this->fileStorage->save(
      $data,
      self::THUMBNAIL_TYPES[$mimeType],
      $record,
    );

    return new DownloadedThumbnail(
      $uri,
      $mimeType,
      (int) ($imageInfo[0] ?? 0),
      (int) ($imageInfo[1] ?? 0),
    );
  }

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
  ): ?DownloadedThumbnail {
    // Iterate through the unique URLs and attempt to download each one until a
    // valid image is found.
    foreach (array_unique($urls) as $url) {
      try {
        return $this->download(
          $url,
          $maxDownloadBytes,
          $record,
          $excludeIcons,
        );
      }
      catch (\Throwable $exception) {
        $this->logger->notice(
          'Preview image candidate failed for @url: @message',
          ['@url' => $url, '@message' => $exception->getMessage()],
        );
      }
    }

    return NULL;
  }

  /**
   * Determines whether an image is likely to be an avatar or interface icon.
   *
   * @param string $url
   *   The URL of the image.
   * @param array<int|string, mixed> $imageInfo
   *   Image metadata returned by getimagesizefromstring().
   *
   * @return bool
   *   TRUE if the image is likely to be an icon or avatar, FALSE otherwise.
   */
  private function isLikelyIcon(string $url, array $imageInfo): bool {
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);

    // If the image dimensions are small, consider it an icon.
    if ($width > 0 && $height > 0 && $width <= 256 && $height <= 256) {
      return TRUE;
    }

    // Use the PreviewUrlExtractor service to analyze the URL for icon-like
    // patterns.
    return $this->previewUrlExtractor->isLikelyIconUrl($url);
  }

}
