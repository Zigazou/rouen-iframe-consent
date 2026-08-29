<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Extracts and sanitizes a single iframe from an HTML field value.
 *
 * This service is responsible for parsing an HTML fragment, locating the first
 * iframe element, and extracting its attributes. It also performs validation
 * and sanitization of the iframe's source URL, dimensions, and other attributes
 * to ensure they are safe and conform to expected formats.
 *
 * The result is returned as a ParsedIframe value object, which can be used by
 * other services or controllers to render the iframe with consent management.
 */
final class IframeParser {

  /**
   * A regex pattern that matches allowed characters in the allow attribute.
   *
   * @var string
   */
  private const ALLOWED_ALLOW_CHARACTERS_PATTERN =
    "/^[a-z0-9_\\-*.:;,\\s\\/'()]+$/i";

  /**
   * A regex pattern that matches control characters in a string.
   *
   * @var string
   */
  private const CONTROL_CHARACTERS_PATTERN = '/[\x00-\x1F\x7F]/';

  /**
   * A regex pattern that matches a dimension in pixels.
   *
   * For example: "560px" or "560".
   *
   * @var string
   */
  private const DIMENSION_IN_PIXELS_PATTERN = '/^\s*(\d{1,5})(?:px)?\s*$/i';

  /**
   * A regex pattern that matches a percentage width.
   *
   * @var string
   */
  private const WIDTH_IN_PERCENT_PATTERN =
    '/^\s*(\d{1,5}(?:\.\d{1,4})?%)\s*$/';

  /**
   * A regex pattern that matches one or more whitespace characters.
   *
   * @var string
   */
  private const SPACES_PATTERN = '/\s+/';

  /**
   * Allowed values for the sandbox attribute.
   *
   * @var string[]
   */
  private const ALLOWED_SANDBOX_VALUES = [
    'allow-downloads',
    'allow-forms',
    'allow-modals',
    'allow-orientation-lock',
    'allow-pointer-lock',
    'allow-popups',
    'allow-popups-to-escape-sandbox',
    'allow-presentation',
    'allow-same-origin',
    'allow-scripts',
    'allow-storage-access-by-user-activation',
    'allow-top-navigation',
    'allow-top-navigation-by-user-activation',
    'allow-top-navigation-to-custom-protocols',
  ];

  /**
   * Allowed values for the referrerpolicy attribute.
   *
   * @var string[]
   */
  private const ALLOWED_REFERRER_POLICIES = [
    'no-referrer',
    'no-referrer-when-downgrade',
    'origin',
    'origin-when-cross-origin',
    'same-origin',
    'strict-origin',
    'strict-origin-when-cross-origin',
    'unsafe-url',
  ];

  /**
   * Known provider hosts mapped to their display names.
   *
   * @var string[]
   */
  private const KNOWN_PROVIDER_HOSTS = [
    'youtube.com' => 'YouTube',
    'youtube-nocookie.com' => 'YouTube',
    'youtu.be' => 'YouTube',
    'dailymotion.com' => 'Dailymotion',
    'dai.ly' => 'Dailymotion',
    'vimeo.com' => 'Vimeo',
    'facebook.com' => 'Facebook',
    'instagram.com' => 'Instagram',
    'twitter.com' => 'X (Twitter)',
    'x.com' => 'X (Twitter)',
  ];

  /**
   * Provider-specific thumbnail lookup URL handlers.
   *
   * @var \App\Service\ThumbnailLookupUrlHandler[]
   */
  private readonly array $thumbnailLookupUrlHandlers;

  /**
   * Creates an iframe parser.
   *
   * @param iterable<\App\Service\ThumbnailLookupUrlHandler> $thumbnailLookupUrlHandlers
   *   Provider-specific thumbnail lookup URL handlers.
   */
  public function __construct(iterable $thumbnailLookupUrlHandlers) {
    $handlers = [];
    foreach ($thumbnailLookupUrlHandlers as $handler) {
      if (!$handler instanceof ThumbnailLookupUrlHandler) {
        throw new \LogicException(sprintf(
          'Thumbnail lookup URL handlers must extend %s.',
          ThumbnailLookupUrlHandler::class,
        ));
      }

      $handlers[] = $handler;
    }
    $this->thumbnailLookupUrlHandlers = $handlers;
  }

  /**
   * Parses the first iframe in an HTML fragment.
   *
   * @param string $html
   *   The HTML fragment to parse.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe|null
   *   A parsed iframe object, or NULL if no valid iframe was found.
   */
  public function parse(string $html): ?ParsedIframe {
    if (trim($html) === '' || stripos($html, '<iframe') === FALSE) {
      return NULL;
    }

    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
      );
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    if (!$loaded) {
      return NULL;
    }

    $iframe = $document->getElementsByTagName('iframe')->item(0);
    if (!$iframe instanceof \DOMElement) {
      return NULL;
    }

    $source = html_entity_decode(
      trim($iframe->getAttribute('src')),
      ENT_QUOTES | ENT_HTML5,
      'UTF-8'
    );

    if (str_starts_with($source, '//')) {
      $source = 'https:' . $source;
    }

    if (!$this->isSafeUrl($source)) {
      return NULL;
    }

    $host = strtolower((string) parse_url($source, PHP_URL_HOST));
    $width = $this->width($iframe->getAttribute('width'), 560);
    $height = $this->dimension($iframe->getAttribute('height'), 315);
    $attributes = [
      'src' => $source,
      'width' => (string) $width,
      'height' => (string) $height,
      'loading' => 'lazy',
    ];

    $title = trim(strip_tags($iframe->getAttribute('title')));
    if ($title !== '') {
      $attributes['title'] = mb_substr($title, 0, 255);
    }

    $allow = trim($iframe->getAttribute('allow'));
    if (
      $allow !== ''
      && preg_match(self::ALLOWED_ALLOW_CHARACTERS_PATTERN, $allow)
    ) {
      $attributes['allow'] = $allow;
    }

    if ($iframe->hasAttribute('allowfullscreen')) {
      $attributes['allowfullscreen'] = TRUE;
    }

    $referrer_policy = strtolower(
      trim($iframe->getAttribute('referrerpolicy'))
    );

    if (in_array($referrer_policy, self::ALLOWED_REFERRER_POLICIES, TRUE)) {
      $attributes['referrerpolicy'] = $referrer_policy;
    }

    $sandbox = $this->sanitizeSandbox($iframe->getAttribute('sandbox'));
    if ($iframe->hasAttribute('sandbox')) {
      $attributes['sandbox'] = $sandbox;
    }

    return new ParsedIframe(
      $source,
      $this->getThumbnailLookupUrl($source, $host),
      $host,
      $this->getProviderName($host),
      $width,
      $height,
      $attributes,
    );
  }

  /**
   * Determines whether a host matches the administrator's allowlist.
   *
   * Subdomains are trusted when their parent domain is explicitly listed.
   *
   * @param string $host
   *   The host name to check.
   * @param string[] $trustedHosts
   *   Normalized host names.
   *
   * @return bool
   *   TRUE if the host is trusted, FALSE otherwise.
   */
  public function isTrustedHost(string $host, array $trustedHosts): bool {
    $host = strtolower(rtrim($host, '.'));
    foreach ($trustedHosts as $trusted_host) {
      $trusted_host = strtolower(rtrim(trim($trusted_host), '.'));
      if ($trusted_host !== '' &&
        ($host === $trusted_host || str_ends_with($host, '.' . $trusted_host))
      ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Validates an iframe source URL.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if the URL is valid and safe, FALSE otherwise.
   */
  private function isSafeUrl(string $url): bool {
    if ($url === '' || preg_match(self::CONTROL_CHARACTERS_PATTERN, $url)) {
      return FALSE;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }

    $parts = parse_url($url);
    return is_array($parts)
      && in_array(
        strtolower((string) ($parts['scheme'] ?? '')),
        ['http', 'https'],
        TRUE
      )
      && !empty($parts['host'])
      && empty($parts['user'])
      && empty($parts['pass']);
  }

  /**
   * Returns a bounded positive pixel dimension.
   *
   * @param string $value
   *   The dimension value to parse.
   * @param int $default
   *   The default value to return if parsing fails.
   *
   * @return int
   *   The parsed dimension, or the default if invalid.
   */
  private function dimension(string $value, int $default): int {
    if (!preg_match(self::DIMENSION_IN_PIXELS_PATTERN, $value, $matches)) {
      return $default;
    }

    $dimension = (int) $matches[1];

    return $dimension >= 1 && $dimension <= 10000 ? $dimension : $default;
  }

  /**
   * Returns a bounded pixel width or a valid percentage width.
   *
   * @param string $value
   *   The width value to parse.
   * @param int $default
   *   The default value to return if parsing fails.
   *
   * @return int|string
   *   The parsed width, or the default if invalid.
   */
  private function width(string $value, int $default): int|string {
    if (preg_match(self::WIDTH_IN_PERCENT_PATTERN, $value, $matches)) {
      $percentage = (float) rtrim($matches[1], '%');

      // Return the percentage string if it's within the valid range.
      if ($percentage >= 1 && $percentage <= 100) {
        return $matches[1];
      }
    }

    return $this->dimension($value, $default);
  }

  /**
   * Keeps only defined iframe sandbox tokens.
   *
   * @param string $sandbox
   *   The original sandbox attribute value.
   *
   * @return string
   *   The sanitized sandbox attribute value.
   */
  private function sanitizeSandbox(string $sandbox): string {
    $tokens = preg_split(
      self::SPACES_PATTERN,
      strtolower(trim($sandbox)),
      -1,
      PREG_SPLIT_NO_EMPTY
    ) ?: [];

    return implode(
      ' ',
      array_values(array_intersect($tokens, self::ALLOWED_SANDBOX_VALUES))
    );
  }

  /**
   * Returns a human-readable provider name.
   *
   * @param string $host
   *   The host name of the iframe source.
   *
   * @return string
   *   The provider name, or the host name if unknown.
   */
  private function getProviderName(string $host): string {
    foreach (self::KNOWN_PROVIDER_HOSTS as $domain => $name) {
      if ($host === $domain || str_ends_with($host, '.' . $domain)) {
        return $name;
      }
    }

    return $host;
  }

  /**
   * Converts common embed URLs to URLs recognized by oEmbed providers.
   *
   * @param string $source
   *   The original iframe source URL.
   * @param string $host
   *   The host name of the iframe source.
   *
   * @return string
   *   The URL to use for thumbnail lookup, or the original source if no special
   *   handling is needed.
   */
  private function getThumbnailLookupUrl(string $source, string $host): string {
    foreach ($this->thumbnailLookupUrlHandlers as $handler) {
      $lookup_url = $handler->getThumbnailLookupUrl($source, $host);

      if ($lookup_url !== NULL) {
        return $lookup_url;
      }
    }

    return $source;
  }

}
