<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Parser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Extracts and sanitizes an iframe element.
 */
final class IframeElementParser {

  /**
   * A regex pattern that matches allowed characters in the allow attribute.
   *
   * @var string
   */
  private const ALLOWED_ALLOW_CHARACTERS_PATTERN =
    "/^[a-z0-9_\\-*.:;,\\s\\/'()]+$/i";

  /**
   * A regex pattern that matches valid percentage widths (1% to 100%).
   *
   * @var string
   */
  private const PERCENTAGE_WIDTH_PATTERN = '/^\s*(\d{1,5}(?:\.\d{1,4})?%)\s*$/';

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
   * @var \Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler[]
   */
  private readonly array $thumbnailLookupUrlHandlers;

  /**
   * Creates an iframe element parser.
   *
   * @param iterable<\Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler> $thumbnailLookupUrlHandlers
   *   Provider-specific thumbnail lookup URL handlers.
   * @param \Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator $urlValidator
   *   The embed URL validator service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory service, or NULL for default dimensions.
   */
  public function __construct(
    iterable $thumbnailLookupUrlHandlers,
    private readonly EmbedUrlValidator $urlValidator,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {
    // Validate and store the thumbnail lookup URL handlers.
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
   * Parses a sanitized iframe element.
   *
   * @param \DOMElement $iframe
   *   The iframe element to parse.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe|null
   *   The parsed iframe data, or NULL if the iframe is invalid or unsupported.
   */
  public function parse(\DOMElement $iframe): ?ParsedIframe {
    // Validate the iframe source URL.
    $source = $this->urlValidator->normalize($iframe->getAttribute('src'));
    if ($source === NULL) {
      return NULL;
    }

    // Extract the host and dimensions, applying defaults and bounds.
    $host = strtolower((string) parse_url($source, PHP_URL_HOST));

    $width = $this->width(
      $iframe->getAttribute('width'),
      $this->defaultDimension('default_width', 560),
    );

    $height = $this->dimension(
      $iframe->getAttribute('height'),
      $this->defaultDimension('default_height', 315),
    );

    $attributes = [
      'src' => $source,
      'width' => (string) $width,
      'height' => (string) $height,
      'loading' => 'lazy',
    ];

    // Sanitize title.
    $title = trim(strip_tags($iframe->getAttribute('title')));
    if ($title !== '') {
      $attributes['title'] = mb_substr($title, 0, 255);
    }

    // Sanitize allow attribute.
    $allow = trim($iframe->getAttribute('allow'));
    if ($allow !== ''
      && preg_match(self::ALLOWED_ALLOW_CHARACTERS_PATTERN, $allow)
    ) {
      $attributes['allow'] = $allow;
    }

    // Sanitize allowfullscreen attribute.
    if ($iframe->hasAttribute('allowfullscreen')) {
      $attributes['allowfullscreen'] = TRUE;
    }

    // Sanitize referrerpolicy attribute.
    $referrer_policy = strtolower(
      trim($iframe->getAttribute('referrerpolicy')),
    );
    if (in_array($referrer_policy, self::ALLOWED_REFERRER_POLICIES, TRUE)) {
      $attributes['referrerpolicy'] = $referrer_policy;
    }

    // Sanitize sandbox attribute.
    if ($iframe->hasAttribute('sandbox')) {
      $attributes['sandbox'] = $this->sanitizeSandbox(
        $iframe->getAttribute('sandbox'),
      );
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
   * Returns a bounded positive pixel dimension.
   *
   * @param string $value
   *   The dimension value to parse.
   * @param int $default
   *   The default dimension to use if parsing fails.
   *
   * @return int
   *   A positive pixel dimension between 1 and 10000, or the default.
   */
  private function dimension(string $value, int $default): int {
    if (!preg_match('/^\s*(\d{1,5})(?:px)?\s*$/i', $value, $matches)) {
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
   *   The default width to use if parsing fails.
   *
   * @return int|string
   *   A positive pixel width between 1 and 10000, a percentage width between 1%
   *   and 100%, or the default.
   */
  private function width(string $value, int $default): int|string {
    if (preg_match(self::PERCENTAGE_WIDTH_PATTERN, $value, $matches)) {
      $percentage = (float) rtrim($matches[1], '%');
      if ($percentage >= 1 && $percentage <= 100) {
        return $matches[1];
      }
    }

    return $this->dimension($value, $default);
  }

  /**
   * Returns a configured default dimension within the valid range.
   *
   * @param string $key
   *   The configuration key for the dimension.
   * @param int $fallback
   *   The fallback dimension if the configuration is missing or invalid.
   *
   * @return int
   *   A positive integer dimension between 1 and 10000.
   */
  private function defaultDimension(string $key, int $fallback): int {
    $value = (int) ($this->configFactory
      ?->get('rouen_iframe_consent.settings')
        ->get($key) ?? $fallback
    );

    return $value >= 1 && $value <= 10000 ? $value : $fallback;
  }

  /**
   * Keeps only defined iframe sandbox tokens.
   *
   * @param string $sandbox
   *   The sandbox attribute value to sanitize.
   *
   * @return string
   *   A sanitized sandbox attribute value containing only allowed tokens.
   */
  private function sanitizeSandbox(string $sandbox): string {
    // Split the sandbox attribute into tokens, normalize them, and filter out
    // any that are not in the allowed list.
    $tokens = preg_split(
      '/\s+/',
      strtolower(trim($sandbox)),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];

    return implode(
      ' ',
      array_values(array_intersect($tokens, self::ALLOWED_SANDBOX_VALUES)),
    );
  }

  /**
   * Returns a human-readable provider name.
   *
   * @param string $host
   *   The normalized host name.
   *
   * @return string
   *   The provider name, or the host if unknown.
   */
  private function getProviderName(string $host): string {
    // Check against known provider hosts and return the corresponding name.
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
   *   The original embed source URL.
   * @param string $host
   *   The normalized host name.
   *
   * @return string
   *   The URL to look up a thumbnail for the embed, or the original source if
   *   no provider-specific handler is available.
   */
  private function getThumbnailLookupUrl(string $source, string $host): string {
    // Iterate through the registered thumbnail lookup URL handlers.
    foreach ($this->thumbnailLookupUrlHandlers as $handler) {
      $lookup_url = $handler->getThumbnailLookupUrl($source, $host);
      if ($lookup_url !== NULL) {
        return $lookup_url;
      }
    }

    return $source;
  }

}
