<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Extracts the public post URL from X and Twitter widget URLs.
 */
final class XThumbnailLookupUrlHandler extends ThumbnailLookupUrlHandler {

  /**
   * {@inheritdoc}
   */
  public function getThumbnailLookupUrl(string $source, string $host): ?string {
    $path = (string) parse_url($source, PHP_URL_PATH);
    if (
      $this->hostMatches($host, 'x.com', 'twitter.com')
      && preg_match('#/widgets/tweet_button\.html#', $path)
    ) {
      return $this->getQueryParameter($source, 'url');
    }

    return NULL;
  }

}
