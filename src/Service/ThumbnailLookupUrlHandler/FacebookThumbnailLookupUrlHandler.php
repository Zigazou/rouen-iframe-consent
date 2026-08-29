<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Extracts the public video URL from Facebook video plugin URLs.
 */
final class FacebookThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $this->hostMatches($host, 'facebook.com', 'fbcdn.net')
      && preg_match('#/plugins/video\.php#', $path)
    ) {
      return $this->getQueryParameter($source, 'href');
    }

    return NULL;
  }

}
