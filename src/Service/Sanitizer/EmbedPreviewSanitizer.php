<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Sanitizer;

use Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator;

/**
 * Rebuilds social embed previews from inert, allowlisted markup.
 */
final class EmbedPreviewSanitizer {

  /**
   * Elements retained in blockquote previews.
   *
   * @var string[]
   */
  private const ALLOWED_ELEMENTS = [
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
   * Attributes that must match a specific pattern.
   *
   * @var string[]
   */
  private const TOKEN_ATTRIBUTES = [
    'data-instgrm-version' => '/^\d{1,3}$/',
    'data-video-id' => '/^[a-z0-9_-]{1,128}$/i',
    'data-unique-id' => '/^[a-z0-9._-]{1,128}$/i',
    'data-embed-type' => '/^[a-z0-9_-]{1,40}$/i',
    'data-embed-from' => '/^[a-z0-9_-]{1,40}$/i',
    'data-bluesky-uri' => '/^at:\/\/[a-z0-9._:%\/-]{1,500}$/i',
    'data-bluesky-cid' => '/^[a-z0-9]{1,128}$/i',
    'data-bluesky-embed-color-mode' => '/^(?:light|dark|system)$/',
  ];

  /**
   * A regex pattern to validate allowed characters in the class attribute.
   *
   * @var string
   */
  private const TOKEN_ATTRIBUTES_PATTERN = '/^[a-z0-9_-]{1,80}$/i';

  /**
   * Creates an embed preview sanitizer.
   *
   * @param \Drupal\rouen_iframe_consent\Service\Security\EmbedUrlValidator $urlValidator
   *   The embed URL validator service.
   */
  public function __construct(
    private readonly EmbedUrlValidator $urlValidator,
  ) {}

  /**
   * Returns a sanitized HTML preview.
   *
   * @param \DOMElement $source
   *   The source blockquote element.
   *
   * @return string
   *   Sanitized HTML suitable for display before consent.
   */
  public function sanitize(\DOMElement $source): string {
    $document = new \DOMDocument('1.0', 'UTF-8');
    $preview = $this->copyNode($source, $document);
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
   *   The target document for the copied node.
   *
   * @return \DOMNode|null
   *   The copied node, or NULL if the source node is not allowed.
   */
  private function copyNode(
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
    if (!in_array($tag, self::ALLOWED_ELEMENTS, TRUE)) {
      return NULL;
    }

    $copy = $document->createElement($tag);
    $this->copyAttributes($source, $copy);

    foreach ($source->childNodes as $child) {
      $safe_child = $this->copyNode($child, $document);
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
  private function copyAttributes(
    \DOMElement $source,
    \DOMElement $copy,
  ): void {
    // Get the class attribute tokens.
    $class_tokens = preg_split(
      '/\s+/',
      trim($source->getAttribute('class')),
      -1,
      PREG_SPLIT_NO_EMPTY,
    ) ?: [];

    // Filter class tokens to only include valid characters (alphanumeric,
    // underscore, hyphen).
    $class_tokens = array_filter(
      $class_tokens,
      static fn(string $token): bool => (bool) preg_match(
        self::TOKEN_ATTRIBUTES_PATTERN,
        $token,
      ),
    );

    // If there are valid class tokens, set them on the copy with a data
    // attribute.
    if ($class_tokens !== []) {
      $copy->setAttribute(
        'data-rouen-embed-class',
        implode(' ', $class_tokens),
      );
    }

    // Copy specific attributes that are relevant for the embed preview.
    foreach (['cite', 'data-instgrm-permalink'] as $attribute) {
      $value = $this->urlValidator->normalize(
        $source->getAttribute($attribute),
      );

      if ($value !== NULL) {
        $copy->setAttribute(
          'data-rouen-embed-' . str_replace('data-', '', $attribute),
          $value,
        );
      }
    }

    // If the source is an <a> tag, copy its href attribute as a data attribute.
    if (strtolower($source->tagName) === 'a') {
      $href = $this->urlValidator->normalize($source->getAttribute('href'));
      if ($href !== NULL) {
        $copy->setAttribute('data-rouen-embed-href', $href);
      }
    }

    // Copy token attributes that match the allowed patterns.
    foreach (self::TOKEN_ATTRIBUTES as $attribute => $pattern) {
      $value = trim($source->getAttribute($attribute));
      if ($value !== '' && preg_match($pattern, $value)) {
        $copy->setAttribute(
          'data-rouen-embed-' . str_replace('data-', '', $attribute),
          $value,
        );
      }
    }

    // Special handling for Instagram's captioned embeds.
    if ($source->hasAttribute('data-instgrm-captioned')) {
      $copy->setAttribute('data-rouen-embed-instgrm-captioned', '');
    }
  }

}
