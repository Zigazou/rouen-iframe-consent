<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Converts Vimeo player URLs to public video URLs.
 */
final class VimeoThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $host === 'player.vimeo.com'
      && preg_match('#/video/(\d+)#', $path, $matches)
    ) {
      return 'https://vimeo.com/' . $matches[1];
    }

    return NULL;
  }

}
