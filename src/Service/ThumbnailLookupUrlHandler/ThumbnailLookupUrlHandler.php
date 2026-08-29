<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler;

/**
 * Base class for provider-specific thumbnail lookup URL handlers.
 */
abstract class ThumbnailLookupUrlHandler {

  /**
   * Converts an iframe source URL when the handler recognizes it.
   *
   * @param string $source
   *   The original iframe source URL.
   * @param string $host
   *   The normalized host name of the iframe source.
   *
   * @return string|null
   *   The thumbnail lookup URL, or NULL when this handler does not apply.
   */
  abstract public function getThumbnailLookupUrl(
    string $source,
    string $host,
  ): ?string;

  /**
   * Determines whether a host is one of the supplied domains or a subdomain.
   *
   * @param string $host
   *   The host name to check.
   * @param string ...$domains
   *   The domains to check against.
   *
   * @return bool
   *   TRUE if the host matches one of the domains or is a subdomain, FALSE
   *   otherwise.
   */
  final protected function hostMatches(string $host, string ...$domains): bool {
    foreach ($domains as $domain) {
      if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Gets a decoded query parameter from a URL.
   *
   * @param string $source
   *   The URL to parse.
   * @param string $parameter
   *   The query parameter to retrieve.
   *
   * @return string|null
   *   The value of the query parameter, or NULL if not found.
   */
  final protected function getQueryParameter(
    string $source,
    string $parameter,
  ): ?string {
    $query = parse_url($source, PHP_URL_QUERY);
    if (!is_string($query)) {
      return NULL;
    }

    parse_str($query, $parameters);
    $value = $parameters[$parameter] ?? NULL;

    return is_string($value) && $value !== '' ? $value : NULL;
  }

}
