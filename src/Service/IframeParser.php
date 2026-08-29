<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Extracts and sanitizes a single iframe from an HTML field value.
 */
final class IframeParser {

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
    $width = $this->dimension($iframe->getAttribute('width'), 560);
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

    // Allow only a limited set of characters in the allow attribute to prevent
    // XSS.
    $allower_characters_pattern = "/^[a-z0-9_\\-*.:;,\\s\\/'()]+$/i";

    $allow = trim($iframe->getAttribute('allow'));
    if ($allow !== '' && preg_match($allower_characters_pattern, $allow)) {
      $attributes['allow'] = $allow;
    }

    if ($iframe->hasAttribute('allowfullscreen')) {
      $attributes['allowfullscreen'] = TRUE;
    }

    $referrer_policy = strtolower(trim($iframe->getAttribute('referrerpolicy')));
    $allowed_referrer_policies = [
      'no-referrer',
      'no-referrer-when-downgrade',
      'origin',
      'origin-when-cross-origin',
      'same-origin',
      'strict-origin',
      'strict-origin-when-cross-origin',
      'unsafe-url',
    ];

    if (in_array($referrer_policy, $allowed_referrer_policies, TRUE)) {
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
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
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
    if (!preg_match('/^\s*(\d{1,5})(?:px)?\s*$/i', $value, $matches)) {
      return $default;
    }

    $dimension = (int) $matches[1];

    return $dimension >= 1 && $dimension <= 10000 ? $dimension : $default;
  }

  /**
   * Keeps only defined iframe sandbox tokens.
   */
  private function sanitizeSandbox(string $sandbox): string {
    $allowed = [
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

    $tokens = preg_split(
      '/\s+/',
      strtolower(trim($sandbox)),
      -1,
      PREG_SPLIT_NO_EMPTY
    ) ?: [];

    return implode(' ', array_values(array_intersect($tokens, $allowed)));
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
    $providers = [
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
    foreach ($providers as $domain => $name) {
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
    $path = (string) parse_url($source, PHP_URL_PATH);

    // Handle YouTube URL.
    if (
      ($host === 'youtube.com'
        || str_ends_with($host, '.youtube.com')
        || str_ends_with($host, '.youtube-nocookie.com')
      )
      && preg_match('#/embed/([a-zA-Z0-9_-]+)#', $path, $matches)) {

      return 'https://www.youtube.com/watch?v=' . $matches[1];
    }

    // Handle Dailymotion URL.
    if (
      ($host === 'dailymotion.com'
        || str_ends_with($host, '.dailymotion.com')
      )
      && preg_match('#/embed/video/([a-zA-Z0-9]+)#', $path, $matches)
    ) {
      return 'https://www.dailymotion.com/video/' . $matches[1];
    }

    // Handle Vimeo URL.
    if (
      ($host === 'player.vimeo.com')
      && preg_match('#/video/(\d+)#', $path, $matches)
    ) {
      return 'https://vimeo.com/' . $matches[1];
    }

    // Handle Facebook URL.
    if (
      ($host === 'facebook.com'
        || str_ends_with($host, '.facebook.com')
        || str_ends_with($host, '.fbcdn.net')
      )
      && preg_match('#/plugins/video\.php#', $path)
      && ($href = $this->getQueryParameter($source, 'href')) !== NULL
    ) {
      return $href;
    }

    // Handle Instagram URL.
    if (
      ($host === 'instagram.com'
        || str_ends_with($host, '.instagram.com')
        || str_ends_with($host, '.cdninstagram.com')
      )
      && preg_match('#/embed/#', $path)
      && ($src = $this->getQueryParameter($source, 'src')) !== NULL
    ) {
      return $src;
    }

    // Handle X (Twitter) URL.
    if (
      ($host === 'x.com'
        || str_ends_with($host, '.x.com')
        || str_ends_with($host, '.twitter.com')
      )
      && preg_match('#/widgets/tweet_button\.html#', $path)
      && ($url = $this->getQueryParameter($source, 'url')) !== NULL
    ) {
      return $url;
    }

    // Return the original source URL if no special handling is needed.
    return $source;
  }

}
