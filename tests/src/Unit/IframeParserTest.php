<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\rouen_iframe_consent\Service\IframeParser;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\DailymotionThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\FacebookThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\InstagramThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\VimeoThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\XThumbnailLookupUrlHandler;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\YouTubeThumbnailLookupUrlHandler;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests secure iframe extraction.
 *
 * @group rouen_iframe_consent
 */
final class IframeParserTest extends UnitTestCase {

  /**
   * The iframe parser service.
   *
   * @var \Drupal\rouen_iframe_consent\Service\IframeParser
   */
  private IframeParser $parser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = new IframeParser([
      new YouTubeThumbnailLookupUrlHandler(),
      new DailymotionThumbnailLookupUrlHandler(),
      new VimeoThumbnailLookupUrlHandler(),
      new FacebookThumbnailLookupUrlHandler(),
      new InstagramThumbnailLookupUrlHandler(),
      new XThumbnailLookupUrlHandler(),
    ]);
  }

  /**
   * Tests extraction and removal of unsafe or irrelevant attributes.
   */
  public function testSafeExtraction(): void {
    $html = '
      <p>Discard me</p>
      <iframe src="https://www.youtube.com/embed/abc_123"
        width="640"
        height="360"
        title="A video"
        allow="autoplay; fullscreen"
        allowfullscreen
        onclick="alert(1)"
        srcdoc="bad"
      >
      </iframe>
      <script>alert(1)</script>';

    $iframe = $this->parser->parse($html);

    self::assertNotNull($iframe);
    self::assertSame(
      'https://www.youtube.com/embed/abc_123',
      $iframe->sourceUrl
    );
    self::assertSame(
      'https://www.youtube.com/watch?v=abc_123',
      $iframe->thumbnailLookupUrl
    );
    self::assertSame('YouTube', $iframe->providerName);
    self::assertSame(640, $iframe->width);
    self::assertSame(360, $iframe->height);
    self::assertTrue($iframe->attributes['allowfullscreen']);
    self::assertArrayNotHasKey('onclick', $iframe->attributes);
    self::assertArrayNotHasKey('srcdoc', $iframe->attributes);
  }

  /**
   * Tests rejection of executable and credential-bearing source URLs.
   */
  #[DataProvider('unsafeUrlProvider')]
  public function testUnsafeUrlsAreRejected(string $url): void {
    self::assertNull(
      $this->parser->parse('<iframe src="' . $url . '"></iframe>')
    );
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
    $html = '
      <iframe
        src="//player.vimeo.com/video/42"
        width="100%"
        height="0"
        sandbox="allow-scripts unknown-token"
      ></iframe>';
    $iframe = $this->parser->parse($html);

    self::assertNotNull($iframe);
    self::assertSame('https://player.vimeo.com/video/42', $iframe->sourceUrl);
    self::assertSame('https://vimeo.com/42', $iframe->thumbnailLookupUrl);
    self::assertSame('100%', $iframe->width);
    self::assertSame(315, $iframe->height);
    self::assertSame('100%', $iframe->attributes['width']);
    self::assertSame('allow-scripts', $iframe->attributes['sandbox']);
  }

  /**
   * Tests provider-specific thumbnail lookup URL handling.
   */
  #[DataProvider('thumbnailLookupUrlProvider')]
  public function testThumbnailLookupUrl(
    string $source,
    string $expected_lookup_url,
  ): void {
    $html = sprintf(
      '<iframe src="%s"></iframe>',
      htmlspecialchars($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    );

    $iframe = $this->parser->parse($html);

    self::assertNotNull($iframe);
    self::assertSame($expected_lookup_url, $iframe->thumbnailLookupUrl);
  }

  /**
   * Provides iframe source URLs and their thumbnail lookup URLs.
   *
   * @return array<string, array{string, string}>
   *   Provider URL cases.
   */
  public static function thumbnailLookupUrlProvider(): array {
    return [
      'YouTube' => [
        'https://www.youtube-nocookie.com/embed/abc_123',
        'https://www.youtube.com/watch?v=abc_123',
      ],
      'Dailymotion' => [
        'https://geo.dailymotion.com/embed/video/x123ab',
        'https://www.dailymotion.com/video/x123ab',
      ],
      'Vimeo' => [
        'https://player.vimeo.com/video/12345',
        'https://vimeo.com/12345',
      ],
      'Facebook' => [
        'https://www.facebook.com/plugins/video.php?href=' .
          'https%3A%2F%2Fwww.facebook.com%2Fexample%2Fvideos%2F123',
        'https://www.facebook.com/example/videos/123',
      ],
      'Instagram' => [
        'https://www.instagram.com/embed/?src=' .
          'https%3A%2F%2Fwww.instagram.com%2Fp%2FABC123%2F',
        'https://www.instagram.com/p/ABC123/',
      ],
      'X' => [
        'https://platform.twitter.com/widgets/tweet_button.html?url=' .
          'https%3A%2F%2Fx.com%2Fexample%2Fstatus%2F123',
        'https://x.com/example/status/123',
      ],
      'Unknown provider' => [
        'https://video.example.com/embed/123',
        'https://video.example.com/embed/123',
      ],
    ];
  }

  /**
   * Tests exact and parent-domain trust matching.
   */
  public function testTrustedHosts(): void {
    self::assertTrue(
      $this->parser->isTrustedHost('media.example.org', ['example.org'])
    );

    self::assertTrue(
      $this->parser->isTrustedHost('example.org', ['example.org'])
    );

    self::assertFalse(
      $this->parser->isTrustedHost('evil-example.org', ['example.org'])
    );
  }

}
