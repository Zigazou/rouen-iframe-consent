<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Preview;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor;
use Drupal\rouen_iframe_consent\ValueObject\PreviewCandidates;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Finds preview candidates for Facebook embeds.
 */
final class FacebookPreviewProvider implements PreviewProviderInterface {

  /**
   * Creates the Facebook preview provider.
   *
   * @param \Drupal\media\OEmbed\UrlResolverInterface $urlResolver
   *   The oEmbed URL resolver service.
   * @param \Drupal\media\OEmbed\ResourceFetcherInterface $resourceFetcher
   *   The oEmbed resource fetcher service.
   * @param \Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor $previewUrlExtractor
   *   The preview URL extractor service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel service.
   */
  public function __construct(
    private readonly UrlResolverInterface $urlResolver,
    private readonly ResourceFetcherInterface $resourceFetcher,
    private readonly PreviewUrlExtractor $previewUrlExtractor,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(ThumbnailRecord $record): bool {
    $host = strtolower((string) parse_url($record->iframeUrl, PHP_URL_HOST));

    return $host === 'facebook.com' || str_ends_with($host, '.facebook.com');
  }

  /**
   * {@inheritdoc}
   */
  public function findCandidates(ThumbnailRecord $record): PreviewCandidates {
    $imageUrls = [];
    $oembedHtml = '';

    try {
      $resourceUrl = $this->urlResolver->getResourceUrl($record->iframeUrl);
      $resource = $this->resourceFetcher->fetchResource($resourceUrl);
      $thumbnailUrl = $resource->getThumbnailUrl();

      if ($thumbnailUrl !== NULL) {
        $imageUrls[] = $thumbnailUrl->toString();
      }

      $oembedHtml = $resource->getHtml() ?? '';
    }
    catch (\Throwable $exception) {
      $this->logger->notice(
        'Facebook oEmbed preview lookup failed for @url: @message',
        [
          '@url' => $record->iframeUrl,
          '@message' => $exception->getMessage(),
        ],
      );
    }

    // Extract image URLs from the oEmbed HTML, filtering out likely icon URLs.
    $imageUrls = array_merge(
      $imageUrls,
      $this->previewUrlExtractor->extractImageUrls(
        $oembedHtml,
        $record->iframeUrl,
        TRUE,
      ),
    );

    // Filter out likely icon URLs from the extracted image URLs.
    $imageUrls = array_filter(
      $imageUrls,
      fn(string $url): bool =>
        !$this->previewUrlExtractor->isLikelyIconUrl($url),
    );

    // Extract document URLs from the oEmbed HTML, including the source URL.
    $documentUrls = $this->previewUrlExtractor->extractIframeUrls(
      $oembedHtml,
      $record->iframeUrl,
    );

    $documentUrls[] = $record->sourceUrl ?: $record->iframeUrl;

    return new PreviewCandidates(
      array_values(array_unique($imageUrls)),
      array_values(array_unique($documentUrls)),
      TRUE,
    );
  }

}
