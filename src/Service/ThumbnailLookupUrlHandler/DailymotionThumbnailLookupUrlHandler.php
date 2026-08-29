<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Converts Dailymotion embed URLs to video URLs.
 */
final class DailymotionThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $this->hostMatches($host, 'dailymotion.com')
      && preg_match('#/embed/video/([a-zA-Z0-9]+)#', $path, $matches)
    ) {
      return 'https://www.dailymotion.com/video/' . $matches[1];
    }

    return NULL;
  }

}
