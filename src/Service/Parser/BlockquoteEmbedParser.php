<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Parser;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rouen_iframe_consent\Service\Sanitizer\EmbedPreviewSanitizer;
use Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator;
use Drupal\rouen_iframe_consent\ValueObject\ParsedBlockquote;

/**
 * Extracts supported blockquote-and-script embeds from a DOM document.
 */
final class BlockquoteEmbedParser {

  /**
   * Supported provider script endpoints and their display names.
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
   * Creates a blockquote embed parser.
   *
   * @param \Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator $urlValidator
   *   The embed URL validator service.
   * @param \Drupal\rouen_iframe_consent\Service\Sanitizer\EmbedPreviewSanitizer $previewSanitizer
   *   The embed preview sanitizer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface|null $configFactory
   *   The config factory service, or NULL for default dimensions.
   */
  public function __construct(
    private readonly EmbedUrlValidator $urlValidator,
    private readonly EmbedPreviewSanitizer $previewSanitizer,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * Parses a blockquote followed by a supported provider script.
   *
   * @param \DOMDocument $document
   *   The DOM document containing the blockquote and script.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedBlockquote|null
   *   The parsed blockquote embed data, or NULL if no supported embed was
   *   found.
   */
  public function parse(\DOMDocument $document): ?ParsedBlockquote {
    // Iterate over all blockquote elements in the document.
    foreach ($document->getElementsByTagName('blockquote') as $blockquote) {
      if (!$blockquote instanceof \DOMElement) {
        continue;
      }

      // Find the next sibling element, which should be a <script> tag.
      $script = $this->nextElementSibling($blockquote);
      if ($script === NULL || strtolower($script->tagName) !== 'script') {
        continue;
      }

      // Validate the script source URL and check if it's a supported provider.
      $source = $this->urlValidator->normalize($script->getAttribute('src'));
      if ($source === NULL) {
        continue;
      }

      // Determine the provider name from the script source URL.
      $provider = $this->getScriptProvider($source);
      if ($provider === NULL || !$this->hasProviderClass(
        $blockquote,
        self::PROVIDER_BLOCKQUOTE_CLASSES[$provider],
      )) {
        continue;
      }

      // Sanitize the blockquote content for preview.
      $preview = $this->previewSanitizer->sanitize($blockquote);
      if ($preview === '') {
        continue;
      }

      // Prepare script attributes for the ParsedBlockquote object.
      $script_attributes = ['src' => $source];
      if ($script->hasAttribute('async')) {
        $script_attributes['async'] = TRUE;
      }
      if ($script->hasAttribute('defer')) {
        $script_attributes['defer'] = TRUE;
      }
      if (strtolower(trim($script->getAttribute('charset'))) === 'utf-8') {
        $script_attributes['charset'] = 'utf-8';
      }

      $host = strtolower((string) parse_url($source, PHP_URL_HOST));

      // Return the parsed blockquote embed data.
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
   * Returns the next element sibling, ignoring whitespace and comments.
   *
   * @param \DOMElement $element
   *   The element whose next sibling is sought.
   *
   * @return \DOMElement|null
   *   The next element sibling, or NULL if none exists.
   */
  private function nextElementSibling(\DOMElement $element): ?\DOMElement {
    // Skip over non-element siblings (e.g., text nodes, comments) to find the
    // next element.
    $sibling = $element->nextSibling;
    while ($sibling !== NULL && !($sibling instanceof \DOMElement)) {
      $sibling = $sibling->nextSibling;
    }

    return $sibling instanceof \DOMElement ? $sibling : NULL;
  }

  /**
   * Returns the provider for a strictly supported script URL.
   *
   * @param string $source
   *   The script source URL.
   *
   * @return string|null
   *   The provider name if supported, or NULL otherwise.
   */
  private function getScriptProvider(string $source): ?string {
    $parts = parse_url($source);
    if (!is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
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
   *   The required provider class.
   *
   * @return bool
   *   TRUE if the element contains the required class, FALSE otherwise.
   */
  private function hasProviderClass(
    \DOMElement $element,
    string $requiredClass,
  ): bool {
    // Split the class attribute into individual classes and check for the
    // required one.
    $classes = preg_split(
      '/\s+/',
      trim($element->getAttribute('class')),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];

    return in_array($requiredClass, $classes, TRUE);
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

}
