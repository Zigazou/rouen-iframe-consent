<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

use Drupal\rouen_iframe_consent\Service\Parser\BlockquoteEmbedParser;
use Drupal\rouen_iframe_consent\Service\Parser\IframeElementParser;
use Drupal\rouen_iframe_consent\ValueObject\ParsedEmbed;

/**
 * Extracts a single external embed from an HTML field value.
 */
final class IframeParser {

  /**
   * Creates an iframe parser.
   *
   * @param \Drupal\rouen_iframe_consent\Service\Parser\IframeElementParser $iframeElementParser
   *   The iframe element parser.
   * @param \Drupal\rouen_iframe_consent\Service\Parser\BlockquoteEmbedParser $blockquoteEmbedParser
   *   The blockquote embed parser.
   */
  public function __construct(
    private readonly IframeElementParser $iframeElementParser,
    private readonly BlockquoteEmbedParser $blockquoteEmbedParser,
  ) {}

  /**
   * Parses the first supported embed in an HTML fragment.
   *
   * @param string $html
   *   The HTML fragment to parse.
   *
   * @return \Drupal\rouen_iframe_consent\ValueObject\ParsedEmbed|null
   *   The parsed embed data, or NULL if no supported embed was found.
   */
  public function parse(string $html): ?ParsedEmbed {
    // Quick check for empty or unsupported content.
    if (trim($html) === '' || (
      stripos($html, '<iframe') === FALSE
      && stripos($html, '<blockquote') === FALSE
    )) {
      return NULL;
    }

    // Load the HTML into a DOMDocument for parsing.
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

    // If loading failed, return NULL.
    if (!$loaded) {
      return NULL;
    }

    // Check for an <iframe> element first, then a <blockquote> embed.
    $iframe = $document->getElementsByTagName('iframe')->item(0);
    if ($iframe instanceof \DOMElement) {
      return $this->iframeElementParser->parse($iframe);
    }

    return $this->blockquoteEmbedParser->parse($document);
  }

  /**
   * Determines whether a host matches the administrator's allowlist.
   *
   * Subdomains are trusted when their parent domain is explicitly listed.
   *
   * @param string $host
   *   The normalized host name.
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

      // Detect trusted hosts and their subdomains.
      if ($trusted_host !== '' && (
        $host === $trusted_host
        || str_ends_with($host, '.' . $trusted_host)
      )) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
