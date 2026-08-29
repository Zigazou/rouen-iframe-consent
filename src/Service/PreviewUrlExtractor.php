<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * Extracts image and iframe candidates from remote preview markup.
 */
final class PreviewUrlExtractor {

  /**
   * The uppercase letters for case-insensitive XPath queries.
   *
   * @var string
   */
  private const UPPERCASE_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

  /**
   * The lowercase letters for case-insensitive XPath queries.
   *
   * @var string
   */
  private const LOWERCASE_LETTERS = 'abcdefghijklmnopqrstuvwxyz';

  /**
   * Extracts image URLs in preferred metadata-first order.
   *
   * @param string $html
   *   The HTML markup to parse.
   * @param string $baseUrl
   *   The base URL to resolve relative URLs against.
   * @param bool $excludeIcons
   *   Whether to exclude elements that are explicitly icon-like.
   *
   * @return string[]
   *   Absolute image URLs without duplicates.
   */
  public function extractImageUrls(
    string $html,
    string $baseUrl,
    bool $excludeIcons = FALSE,
  ): array {
    // Load the HTML document without network access.
    $document = $this->loadDocument($html);
    if ($document === NULL) {
      return [];
    }

    // Use XPath to query for image URLs in metadata and elements.
    $xpath = new \DOMXPath($document);
    $urls = [];
    $queries = [
      sprintf(
        '//meta[translate(@property, "%s", "%s")="og:image"]/@content',
        self::UPPERCASE_LETTERS,
        self::LOWERCASE_LETTERS,
      ),
      sprintf(
        '//meta[translate(@property, "%s", "%s")="og:image:url"]/@content',
        self::UPPERCASE_LETTERS,
        self::LOWERCASE_LETTERS,
      ),
      sprintf(
        '//meta[translate(@name, "%s", "%s")="twitter:image"]/@content',
        self::UPPERCASE_LETTERS,
        self::LOWERCASE_LETTERS,
      ),
      sprintf(
        '//meta[translate(@itemprop, "%s", "%s")="image"]/@content',
        self::UPPERCASE_LETTERS,
        self::LOWERCASE_LETTERS,
      ),
      sprintf(
        '//link[translate(@rel, "%s", "%s")="image_src"]/@href',
        self::UPPERCASE_LETTERS,
        self::LOWERCASE_LETTERS,
      ),
      '//video/@poster',
      '//img/@src',
      '//img/@data-src',
    ];

    // Iterate over the XPath queries and extract URLs, skipping icon-like
    // elements if requested.
    foreach ($queries as $query) {
      $nodes = $xpath->query($query);
      if ($nodes === FALSE) {
        continue;
      }

      // Iterate over the nodes and extract absolute URLs.
      foreach ($nodes as $node) {
        // Skip icon-like elements if requested.
        if ($excludeIcons && $this->belongsToIconElement($node)) {
          continue;
        }

        // Resolve the URL against the base URL and add it to the list.
        $url = $this->absoluteUrl(trim($node->nodeValue), $baseUrl);
        if ($url !== NULL) {
          $urls[$url] = TRUE;
        }
      }
    }

    // Return the unique absolute URLs as an array.
    return array_keys($urls);
  }

  /**
   * Determines whether an extracted URL belongs to an icon-like element.
   *
   * @param \DOMNode $node
   *   The DOM node to inspect.
   *
   * @return bool
   *   TRUE if the node belongs to an icon-like element, FALSE otherwise.
   */
  private function belongsToIconElement(\DOMNode $node): bool {
    $element = $node instanceof \DOMAttr
      ? $node->ownerElement
      : $node->parentNode;

    // Traverse up to three levels of parent elements to check for icon-like
    // hints.
    for ($depth = 0; $depth < 3 && $element instanceof \DOMElement; $depth++) {
      // Check for class, id, role, alt, and aria-label attributes for icon-like
      // hints.
      $hints = strtolower(implode(' ', [
        $element->getAttribute('class'),
        $element->getAttribute('id'),
        $element->getAttribute('role'),
        $element->getAttribute('alt'),
        $element->getAttribute('aria-label'),
      ]));

      // If any of the hints contain keywords like "avatar", "emoji", "icon",
      // "profile", or "userpic", consider it icon-like.
      if (preg_match('/\b(?:avatar|emoji|icon|profile|userpic)\b/', $hints)) {
        return TRUE;
      }

      // If the element is an <img> tag, check its width and height attributes.
      if ($element->tagName === 'img') {
        $width = $this->positiveDimension($element->getAttribute('width'));
        $height = $this->positiveDimension($element->getAttribute('height'));

        // If both width and height are present and less than or equal to 256
        // pixels, consider it icon-like.
        if ($width !== NULL && $height !== NULL
          && $width <= 256 && $height <= 256
        ) {
          return TRUE;
        }
      }

      $element = $element->parentNode;
    }

    return FALSE;
  }

  /**
   * Parses a positive integer dimension.
   *
   * @param string $value
   *   The dimension value to parse.
   *
   * @return int|null
   *   The positive integer dimension, or NULL if invalid.
   */
  private function positiveDimension(string $value): ?int {
    return preg_match('/^\s*(\d+)\s*(?:px)?\s*$/i', $value, $matches)
      && (int) $matches[1] > 0
      ? (int) $matches[1]
      : NULL;
  }

  /**
   * Determines whether a URL identifies an avatar or interface icon.
   *
   * @param string $url
   *   The image URL to inspect.
   *
   * @return bool
   *   TRUE when the URL is icon-like, FALSE otherwise.
   */
  public function isLikelyIconUrl(string $url): bool {
    // Decode the URL and check for known icon-like patterns.
    $decoded_url = strtolower(rawurldecode($url));
    if (preg_match(
      '#(?:avatar|emoji|profile|userpic|/rsrc\.php/|/v/t\d+\.\d+-1/)#',
      $decoded_url,
    )) {
      return TRUE;
    }

    // Check for query parameters that indicate small dimensions.
    if (preg_match(
      '/[?&_](?:s|p)(\d{1,4})x(\d{1,4})(?:[?&_.-]|$)/',
      $decoded_url,
      $matches,
    )) {
      return (int) $matches[1] <= 256 && (int) $matches[2] <= 256;
    }

    return FALSE;
  }

  /**
   * Extracts iframe source URLs from markup.
   *
   * @param string $html
   *   The HTML markup to parse.
   * @param string $baseUrl
   *   The base URL to resolve relative URLs against.
   *
   * @return string[]
   *   Absolute iframe URLs without duplicates.
   */
  public function extractIframeUrls(string $html, string $baseUrl): array {
    // Load the HTML document without network access.
    $document = $this->loadDocument($html);
    if ($document === NULL) {
      return [];
    }

    // Use DOMDocument to find all <iframe> elements and extract their src
    // attributes.
    $urls = [];
    foreach ($document->getElementsByTagName('iframe') as $iframe) {
      $url = $this->absoluteUrl(trim($iframe->getAttribute('src')), $baseUrl);

      if ($url !== NULL) {
        $urls[$url] = TRUE;
      }
    }

    return array_keys($urls);
  }

  /**
   * Loads untrusted markup without network access.
   *
   * @param string $html
   *   The HTML markup to load.
   *
   * @return \DOMDocument|null
   *   The loaded document, or NULL if loading failed.
   */
  private function loadDocument(string $html): ?\DOMDocument {
    if (trim($html) === '') {
      return NULL;
    }

    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);

    try {
      $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
      );
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    return $loaded ? $document : NULL;
  }

  /**
   * Resolves an HTTP(S) URL against the document URL.
   *
   * @param string $url
   *   The URL to resolve.
   * @param string $baseUrl
   *   The base URL to resolve against.
   *
   * @return string|null
   *   The resolved absolute URL, or NULL if the URL is invalid or not HTTP(S).
   */
  private function absoluteUrl(string $url, string $baseUrl): ?string {
    if ($url === '') {
      return NULL;
    }

    try {
      $resolved = (string) UriResolver::resolve(
        new Uri($baseUrl),
        new Uri(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
      );
    }
    catch (\Throwable) {
      return NULL;
    }

    $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], TRUE) ? $resolved : NULL;
  }

}
