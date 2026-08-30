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
   * @param iterable<PreviewProviderInterface> $providers
   *   Preview providers in priority order.
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
   */
  public function resolve(
    ThumbnailRecord $record,
    int $maxDownloadBytes,
  ): ?DownloadedThumbnail {
    foreach ($this->providers as $provider) {
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

      if ($thumbnail !== NULL) {
        return $thumbnail;
      }

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

      if ($contentLength > $maxDownloadBytes) {
        return NULL;
      }

      $html = $response->getBody()->read($maxDownloadBytes + 1);
      if ($html === '' || strlen($html) > $maxDownloadBytes) {
        return NULL;
      }

      $documentUrl = $response->getHeaderLine(
        'X-Rouen-Iframe-Consent-Url'
      ) ?: $url;
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
