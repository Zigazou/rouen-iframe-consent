<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Converts YouTube embed URLs to watch URLs.
 */
final class YouTubeThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $this->hostMatches($host, 'youtube.com', 'youtube-nocookie.com')
      && preg_match('#/embed/([a-zA-Z0-9_-]+)#', $path, $matches)
    ) {
      return 'https://www.youtube.com/watch?v=' . $matches[1];
    }

    return NULL;
  }

}
