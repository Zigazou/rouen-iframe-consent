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
   */
  public function __construct(
    private readonly SafeRemoteHttpClient $httpClient,
    private readonly ThumbnailFileStorage $fileStorage,
    private readonly PreviewUrlExtractor $previewUrlExtractor,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Downloads and stores a single image.
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
    $contentLength = (int) $response->getHeaderLine('Content-Length');

    if ($contentLength > $maxDownloadBytes) {
      throw new \RuntimeException('The preview image exceeds the size limit.');
    }

    $mimeType = strtolower(trim(explode(
      ';',
      $response->getHeaderLine('Content-Type'),
    )[0]));
    $data = $response->getBody()->read($maxDownloadBytes + 1);

    if ($data === '' || strlen($data) > $maxDownloadBytes) {
      throw new \RuntimeException(
        'The preview image is empty or exceeds the size limit.'
      );
    }

    $imageInfo = @getimagesizefromstring($data);
    if ($imageInfo === FALSE || ($imageInfo['mime'] ?? NULL) !== $mimeType) {
      throw new \RuntimeException(
        'The downloaded data is not a valid image.'
      );
    }

    if ($excludeIcons && $this->isLikelyIcon($url, $imageInfo)) {
      throw new \RuntimeException(
        'The preview image looks like an icon or avatar.'
      );
    }

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
   */
  public function downloadFirst(
    array $urls,
    int $maxDownloadBytes,
    ThumbnailRecord $record,
    bool $excludeIcons = FALSE,
  ): ?DownloadedThumbnail {
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
   * @param array<int|string, mixed> $imageInfo
   *   Image metadata returned by getimagesizefromstring().
   */
  private function isLikelyIcon(string $url, array $imageInfo): bool {
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);

    if ($width > 0 && $height > 0 && $width <= 256 && $height <= 256) {
      return TRUE;
    }

    return $this->previewUrlExtractor->isLikelyIconUrl($url);
  }

}
