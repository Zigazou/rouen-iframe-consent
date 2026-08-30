<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Security;

/**
 * Normalizes and validates URLs embedded in user-provided HTML.
 */
final class EmbedUrlValidator {

  /**
   * A regex pattern that matches control characters in a string.
   *
   * @var string
   */
  private const CONTROL_CHARACTERS_PATTERN = '/[\x00-\x1F\x7F]/';

  /**
   * Returns a normalized safe URL, or NULL when the URL is unsafe.
   *
   * @param string $url
   *   The URL to normalize and validate.
   *
   * @return string|null
   *   The normalized URL, or NULL if the URL is unsafe.
   */
  public function normalize(string $url): ?string {
    // Decode HTML entities and trim whitespace.
    $url = html_entity_decode(
      trim($url),
      ENT_QUOTES | ENT_HTML5,
      'UTF-8',
    );

    // Handle protocol-relative URLs by prepending "https:".
    if (str_starts_with($url, '//')) {
      $url = 'https:' . $url;
    }

    // Reject empty URLs or URLs containing control characters.
    if ($url === '' || preg_match(self::CONTROL_CHARACTERS_PATTERN, $url)) {
      return NULL;
    }

    // Validate the URL format and components.
    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return NULL;
    }

    // Reject URLs with disallowed schemes, missing hosts, or user info.
    $parts = parse_url($url);
    if (!is_array($parts)
      || !in_array(
        strtolower((string) ($parts['scheme'] ?? '')),
        ['http', 'https'],
        TRUE,
      )
      || empty($parts['host'])
      || !empty($parts['user'])
      || !empty($parts['pass'])
    ) {
      return NULL;
    }

    return $url;
  }

}
