<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\rouen_iframe_consent\Service\IframeParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests secure iframe extraction.
 *
 * @group rouen_iframe_consent
 */
final class IframeParserTest extends UnitTestCase {

  private IframeParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new IframeParser();
  }

  /**
   * Tests extraction and removal of unsafe or irrelevant attributes.
   */
  public function testSafeExtraction(): void {
    $html = '<p>Discard me</p><iframe src="https://www.youtube.com/embed/abc_123" width="640" height="360" title="A video" allow="autoplay; fullscreen" allowfullscreen onclick="alert(1)" srcdoc="bad"></iframe><script>alert(1)</script>';
    $iframe = $this->parser->parse($html);

    self::assertNotNull($iframe);
    self::assertSame('https://www.youtube.com/embed/abc_123', $iframe->sourceUrl);
    self::assertSame('https://www.youtube.com/watch?v=abc_123', $iframe->thumbnailLookupUrl);
    self::assertSame('YouTube', $iframe->providerName);
    self::assertSame(640, $iframe->width);
    self::assertSame(360, $iframe->height);
    self::assertTrue($iframe->attributes['allowfullscreen']);
    self::assertArrayNotHasKey('onclick', $iframe->attributes);
    self::assertArrayNotHasKey('srcdoc', $iframe->attributes);
  }

  /**
   * Tests rejection of executable and credential-bearing source URLs.
   *
   */
  #[DataProvider('unsafeUrlProvider')]
  public function testUnsafeUrlsAreRejected(string $url): void {
    self::assertNull($this->parser->parse('<iframe src="' . $url . '"></iframe>'));
  }

  /**
   * Provides unsafe iframe source URLs.
   *
   * @return array<string, array{string}>
   *   Unsafe URL cases.
   */
  public static function unsafeUrlProvider(): array {
    return [
      'JavaScript' => ['javascript:alert(1)'],
      'Data URI' => ['data:text/html,bad'],
      'Credentials' => ['https://user:password@example.com/embed'],
      'Missing host' => ['https:///embed'],
    ];
  }

  /**
   * Tests dimensions, sandbox tokens, and protocol-relative URLs.
   */
  public function testAttributeNormalization(): void {
    $iframe = $this->parser->parse('<iframe src="//player.vimeo.com/video/42" width="100%" height="0" sandbox="allow-scripts unknown-token"></iframe>');

    self::assertNotNull($iframe);
    self::assertSame('https://player.vimeo.com/video/42', $iframe->sourceUrl);
    self::assertSame('https://vimeo.com/42', $iframe->thumbnailLookupUrl);
    self::assertSame(560, $iframe->width);
    self::assertSame(315, $iframe->height);
    self::assertSame('allow-scripts', $iframe->attributes['sandbox']);
  }

  /**
   * Tests exact and parent-domain trust matching.
   */
  public function testTrustedHosts(): void {
    self::assertTrue($this->parser->isTrustedHost('media.example.org', ['example.org']));
    self::assertTrue($this->parser->isTrustedHost('example.org', ['example.org']));
    self::assertFalse($this->parser->isTrustedHost('evil-example.org', ['example.org']));
  }

}
