<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Preview;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor;
use Drupal\rouen_iframe_consent\Service\Remote\SafeRemoteHttpClient;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailDownloaderInterface;
use Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Resolves and downloads the best preview for a thumbnail record.
 */
final class PreviewResolver {

  /**
   * Creates the preview resolver.
   *
   * @param iterable<\Drupal\rouen_iframe_consent\Service\Preview\PreviewProviderInterface> $providers
   *   The preview providers.
   * @param \Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailDownloaderInterface $thumbnailDownloader
   *   The thumbnail downloader service.
   * @param \Drupal\rouen_iframe_consent\Service\Remote\SafeRemoteHttpClient $httpClient
   *   The safe remote HTTP client service.
   * @param \Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor $previewUrlExtractor
   *   The preview URL extractor service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel service.
   */
  public function __construct(
    private readonly iterable $providers,
    private readonly ThumbnailDownloaderInterface $thumbnailDownloader,
    private readonly SafeRemoteHttpClient $httpClient,
    private readonly PreviewUrlExtractor $previewUrlExtractor,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Resolves and downloads a thumbnail preview.
   *
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record.
   * @param int $maxDownloadBytes
   *   The maximum size of downloaded thumbnails in bytes.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail|null
   *   The downloaded thumbnail, or NULL if no preview could be resolved.
   */
  public function resolve(
    ThumbnailRecord $record,
    int $maxDownloadBytes,
  ): ?DownloadedThumbnail {
    // Iterate over the registered preview providers to find one that supports
    // the record.
    foreach ($this->providers as $provider) {
      // Skip providers that do not support the record.
      if (!$provider->supports($record)) {
        continue;
      }

      $candidates = $provider->findCandidates($record);
      $thumbnail = $this->thumbnailDownloader->downloadFirst(
        $candidates->imageUrls,
        $maxDownloadBytes,
        $record,
        $candidates->excludeIcons,
      );

      // If an image candidate was successfully downloaded, return it.
      if ($thumbnail !== NULL) {
        return $thumbnail;
      }

      // If no image candidates were successfully downloaded, attempt to resolve
      // document candidates.
      foreach ($candidates->documentUrls as $documentUrl) {
        $thumbnail = $this->resolveDocument(
          $documentUrl,
          $record,
          $maxDownloadBytes,
          $candidates->excludeIcons,
        );

        if ($thumbnail !== NULL) {
          return $thumbnail;
        }
      }

      return NULL;
    }

    return NULL;
  }

  /**
   * Downloads image candidates exposed by an HTML document.
   *
   * @param string $url
   *   The URL of the HTML document.
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record.
   * @param int $maxDownloadBytes
   *   The maximum size of downloaded thumbnails in bytes.
   * @param bool $excludeIcons
   *   Whether to exclude likely icon URLs from the candidates.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\DownloadedThumbnail|null
   *   The downloaded thumbnail, or NULL if no candidates could be downloaded.
   */
  private function resolveDocument(
    string $url,
    ThumbnailRecord $record,
    int $maxDownloadBytes,
    bool $excludeIcons,
  ): ?DownloadedThumbnail {
    try {
      $response = $this->httpClient->get(
        $url,
        ['text/html', 'application/xhtml+xml'],
      );

      $contentLength = (int) $response->getHeaderLine('Content-Length');

      // If the content length exceeds the maximum download size, skip
      // processing.
      if ($contentLength > $maxDownloadBytes) {
        return NULL;
      }

      // Read the response body up to the maximum download size plus one byte to
      // detect if the content exceeds the limit.
      $html = $response->getBody()->read($maxDownloadBytes + 1);
      if ($html === '' || strlen($html) > $maxDownloadBytes) {
        return NULL;
      }

      // Determine the base URL for resolving relative URLs, using the
      // X-Rouen-Iframe-Consent-Url header if present, otherwise falling back
      // to the requested URL.
      $documentUrl = $response->getHeaderLine(
        'X-Rouen-Iframe-Consent-Url'
      ) ?: $url;

      // Extract image URLs from the HTML document, filtering out likely icon
      // URLs.
      $imageUrls = $this->previewUrlExtractor->extractImageUrls(
        $html,
        $documentUrl,
        TRUE,
      );

      return $this->thumbnailDownloader->downloadFirst(
        $imageUrls,
        $maxDownloadBytes,
        $record,
        $excludeIcons,
      );
    }
    catch (\Throwable $exception) {
      $this->logger->notice(
        'Iframe preview lookup failed for @url: @message',
        ['@url' => $url, '@message' => $exception->getMessage()],
      );

      return NULL;
    }
  }

}
