<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Extracts the public post URL from Instagram embed URLs.
 */
final class InstagramThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $this->hostMatches($host, 'instagram.com', 'cdninstagram.com')
      && preg_match('#/embed/#', $path)
    ) {
      return $this->getQueryParameter($source, 'src');
    }

    return NULL;
  }

}
