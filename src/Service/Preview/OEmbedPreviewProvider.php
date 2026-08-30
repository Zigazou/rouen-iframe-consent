<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Preview;

use Drupal\media\OEmbed\ResourceFetcherInterface;
use Drupal\media\OEmbed\UrlResolverInterface;
use Drupal\rouen_iframe_consent\ValueObject\PreviewCandidates;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Finds standard oEmbed thumbnail candidates.
 */
final class OEmbedPreviewProvider implements PreviewProviderInterface {

  /**
   * Creates the oEmbed preview provider.
   *
   * @param \Drupal\media\OEmbed\UrlResolverInterface $urlResolver
   *   The oEmbed URL resolver service.
   * @param \Drupal\media\OEmbed\ResourceFetcherInterface $resourceFetcher
   *   The oEmbed resource fetcher service.
   */
  public function __construct(
    private readonly UrlResolverInterface $urlResolver,
    private readonly ResourceFetcherInterface $resourceFetcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(ThumbnailRecord $record): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function findCandidates(ThumbnailRecord $record): PreviewCandidates {
    $resourceUrl = $this->urlResolver->getResourceUrl($record->iframeUrl);
    $resource = $this->resourceFetcher->fetchResource($resourceUrl);
    $thumbnailUrl = $resource->getThumbnailUrl();

    return new PreviewCandidates(
      $thumbnailUrl === NULL ? [] : [$thumbnailUrl->toString()],
    );
  }

}
