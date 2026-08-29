<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\ValueObject\ParsedBlockquote;
use Drupal\rouen_iframe_consent\ValueObject\ParsedEmbed;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;

/**
 * Extracts and sanitizes a single external embed from an HTML field value.
 *
 * This service parses an HTML fragment, locating either the first iframe or a
 * supported blockquote-and-script integration. It validates external URLs and
 * rebuilds the retained markup from explicit element and attribute allowlists.
 *
 * The result can be rendered without contacting its provider until consent.
 */
final class IframeParser {

  /**
   * Supported provider script endpoints and their display names.
   *
   * The path is matched exactly. Arbitrary third-party JavaScript must never
   * be accepted because embed scripts execute in the first-party page context.
   *
   * @var array<string, array<string, string>>
   */
  private const SUPPORTED_SCRIPT_ENDPOINTS = [
    'www.instagram.com' => ['/embed.js' => 'Instagram'],
    'www.tiktok.com' => ['/embed.js' => 'TikTok'],
    'platform.x.com' => ['/widgets.js' => 'X (Twitter)'],
    'platform.twitter.com' => ['/widgets.js' => 'X (Twitter)'],
    'embed.bsky.app' => ['/static/embed.js' => 'Bluesky'],
  ];

  /**
   * Provider classes required on the root blockquote.
   *
   * @var array<string, string>
   */
  private const PROVIDER_BLOCKQUOTE_CLASSES = [
    'Instagram' => 'instagram-media',
    'TikTok' => 'tiktok-embed',
    'X (Twitter)' => 'twitter-tweet',
    'Bluesky' => 'bluesky-embed',
  ];

  /**
   * Elements retained in blockquote previews.
   *
   * @var string[]
   */
  private const ALLOWED_PREVIEW_ELEMENTS = [
    'blockquote',
    'div',
    'p',
    'span',
    'section',
    'cite',
    'time',
    'br',
    'strong',
    'em',
    'b',
    'i',
    'small',
    'a',
  ];

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
   * @var \Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler[]
   */
  private readonly array $thumbnailLookupUrlHandlers;

  /**
   * Creates an iframe parser.
   *
   * @param iterable<ThumbnailLookupUrlHandler> $thumbnailLookupUrlHandlers
   *   Provider-specific thumbnail lookup URL handlers.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory, or NULL when the parser is used in isolation.
   */
  public function __construct(
    iterable $thumbnailLookupUrlHandlers,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {
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
   * Parses the first supported embed in an HTML fragment.
   *
   * @param string $html
   *   The HTML fragment to parse.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedEmbed|null
   *   A parsed embed object, or NULL if no valid embed was found.
   */
  public function parse(string $html): ?ParsedEmbed {
    // Skip parsing if the HTML is empty or contains no iframe or blockquote.
    if (trim($html) === '' || (
      stripos($html, '<iframe') === FALSE
      && stripos($html, '<blockquote') === FALSE
    )) {
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
    if ($iframe instanceof \DOMElement) {
      return $this->parseIframeElement($iframe);
    }

    return $this->parseBlockquoteEmbed($document);
  }

  /**
   * Parses a sanitized iframe element.
   *
   * @param \DOMElement $iframe
   *   The iframe element to parse.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedIframe|null
   *   A parsed iframe object, or NULL if the iframe is invalid or unsafe.
   */
  private function parseIframeElement(\DOMElement $iframe): ?ParsedIframe {
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
   * Parses a blockquote immediately followed by a supported provider script.
   *
   * The script must be a known provider endpoint and the blockquote must
   * contain the expected provider class. The blockquote is sanitized to
   * remove any potentially executable markup, leaving only inert, allowlisted
   * elements and attributes.
   *
   * @param \DOMDocument $document
   *   The DOM document containing the blockquote and script.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedBlockquote|null
   *   A parsed blockquote object, or NULL if no valid blockquote embed is
   *   found.
   */
  private function parseBlockquoteEmbed(
    \DOMDocument $document,
  ): ?ParsedBlockquote {
    foreach ($document->getElementsByTagName('blockquote') as $blockquote) {
      if (!$blockquote instanceof \DOMElement) {
        continue;
      }

      $sibling = $blockquote->nextSibling;
      while ($sibling !== NULL && !($sibling instanceof \DOMElement)) {
        $sibling = $sibling->nextSibling;
      }

      if (!$sibling instanceof \DOMElement
        || strtolower($sibling->tagName) !== 'script'
      ) {
        continue;
      }

      $source = html_entity_decode(
        trim($sibling->getAttribute('src')),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8',
      );
      if (str_starts_with($source, '//')) {
        $source = 'https:' . $source;
      }

      $provider = $this->getScriptProvider($source);
      if ($provider === NULL || !$this->hasProviderClass(
        $blockquote,
        self::PROVIDER_BLOCKQUOTE_CLASSES[$provider],
      )) {
        continue;
      }

      $preview = $this->sanitizePreview($blockquote);
      if ($preview === '') {
        continue;
      }

      $script_attributes = ['src' => $source];
      if ($sibling->hasAttribute('async')) {
        $script_attributes['async'] = TRUE;
      }
      if ($sibling->hasAttribute('defer')) {
        $script_attributes['defer'] = TRUE;
      }
      if (strtolower(trim($sibling->getAttribute('charset'))) === 'utf-8') {
        $script_attributes['charset'] = 'utf-8';
      }

      $host = strtolower((string) parse_url($source, PHP_URL_HOST));
      return new ParsedBlockquote(
        $source,
        $host,
        $provider,
        $this->defaultDimension('default_width', 560),
        $this->defaultDimension('default_height', 315),
        $preview,
        $script_attributes,
      );
    }

    return NULL;
  }

  /**
   * Returns the provider for a strictly supported script URL.
   *
   * @param string $source
   *   The script URL to check.
   *
   * @return string|null
   *   The provider name, or NULL if the script is not supported.
   */
  private function getScriptProvider(string $source): ?string {
    if (!$this->isSafeUrl($source)) {
      return NULL;
    }

    $parts = parse_url($source);
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || isset($parts['query'])
      || isset($parts['fragment'])
    ) {
      return NULL;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    return self::SUPPORTED_SCRIPT_ENDPOINTS[$host][$path] ?? NULL;
  }

  /**
   * Determines whether an element contains the expected provider class.
   *
   * @param \DOMElement $element
   *   The element to check.
   * @param string $requiredClass
   *   The required class name.
   *
   * @return bool
   *   TRUE if the element contains the required class, FALSE otherwise.
   */
  private function hasProviderClass(
    \DOMElement $element,
    string $requiredClass,
  ): bool {
    $classes = preg_split(
      self::SPACES_PATTERN,
      trim($element->getAttribute('class')),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];

    return in_array($requiredClass, $classes, TRUE);
  }

  /**
   * Builds a new preview tree containing only inert, allowlisted markup.
   */
  private function sanitizePreview(\DOMElement $source): string {
    $document = new \DOMDocument('1.0', 'UTF-8');
    $preview = $this->copyPreviewNode($source, $document);
    if (!$preview instanceof \DOMElement) {
      return '';
    }

    $document->appendChild($preview);
    return (string) $document->saveHTML($preview);
  }

  /**
   * Recursively copies safe preview markup into a clean document.
   *
   * @param \DOMNode $source
   *   The source node to copy.
   * @param \DOMDocument $document
   *   The target document.
   *
   * @return \DOMNode|null
   *   The copied node, or NULL if the node is not allowed.
   */
  private function copyPreviewNode(
    \DOMNode $source,
    \DOMDocument $document,
  ): ?\DOMNode {
    if ($source instanceof \DOMText) {
      return $document->createTextNode($source->data);
    }

    if (!$source instanceof \DOMElement) {
      return NULL;
    }

    $tag = strtolower($source->tagName);
    if (!in_array($tag, self::ALLOWED_PREVIEW_ELEMENTS, TRUE)) {
      return NULL;
    }

    $copy = $document->createElement($tag);
    $this->copyPreviewAttributes($source, $copy);

    foreach ($source->childNodes as $child) {
      $safe_child = $this->copyPreviewNode($child, $document);
      if ($safe_child !== NULL) {
        $copy->appendChild($safe_child);
      }
    }

    return $copy;
  }

  /**
   * Copies only attributes needed by the supported provider integrations.
   *
   * @param \DOMElement $source
   *   The source element to copy attributes from.
   * @param \DOMElement $copy
   *   The target element to copy attributes to.
   */
  private function copyPreviewAttributes(
    \DOMElement $source,
    \DOMElement $copy,
  ): void {
    $class_tokens = preg_split(
      self::SPACES_PATTERN,
      trim($source->getAttribute('class')),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];
    $class_tokens = array_filter(
      $class_tokens,
      static fn(string $token): bool => (bool) preg_match(
        '/^[a-z0-9_-]{1,80}$/i',
        $token,
      ),
    );
    if ($class_tokens !== []) {
      $copy->setAttribute(
        'data-rouen-embed-class',
        implode(' ', $class_tokens),
      );
    }

    $url_attributes = ['cite', 'data-instgrm-permalink'];
    foreach ($url_attributes as $attribute) {
      $value = trim($source->getAttribute($attribute));
      if ($value !== '' && $this->isSafeUrl($value)) {
        $copy->setAttribute(
          'data-rouen-embed-' . str_replace('data-', '', $attribute),
          $value,
        );
      }
    }

    if (strtolower($source->tagName) === 'a') {
      $href = trim($source->getAttribute('href'));
      if ($this->isSafeUrl($href)) {
        $copy->setAttribute('data-rouen-embed-href', $href);
      }
    }

    $token_attributes = [
      'data-instgrm-version' => '/^\d{1,3}$/',
      'data-video-id' => '/^[a-z0-9_-]{1,128}$/i',
      'data-unique-id' => '/^[a-z0-9._-]{1,128}$/i',
      'data-embed-type' => '/^[a-z0-9_-]{1,40}$/i',
      'data-embed-from' => '/^[a-z0-9_-]{1,40}$/i',
      'data-bluesky-uri' => '/^at:\/\/[a-z0-9._:%\/-]{1,500}$/i',
      'data-bluesky-cid' => '/^[a-z0-9]{1,128}$/i',
      'data-bluesky-embed-color-mode' => '/^(?:light|dark|system)$/',
    ];
    foreach ($token_attributes as $attribute => $pattern) {
      $value = trim($source->getAttribute($attribute));
      if ($value !== '' && preg_match($pattern, $value)) {
        $copy->setAttribute(
          'data-rouen-embed-' . str_replace('data-', '', $attribute),
          $value,
        );
      }
    }

    if ($source->hasAttribute('data-instgrm-captioned')) {
      $copy->setAttribute('data-rouen-embed-instgrm-captioned', '');
    }
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
   * Returns a configured default dimension within the parser's valid range.
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
