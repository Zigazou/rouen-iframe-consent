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
use Drupal\rouen_iframe_consent\ValueObject\ParsedBlockquote;
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
   * Tests that percentage widths cannot exceed the containing element.
   */
  public function testPercentageWidthIsBounded(): void {
    $iframe = $this->parser->parse(
      '<iframe src="https://example.com/embed" width="101%"></iframe>'
    );

    self::assertNotNull($iframe);
    self::assertSame(560, $iframe->width);
    self::assertSame('560', $iframe->attributes['width']);
  }

  /**
   * Tests configurable fallback dimensions.
   */
  public function testConfiguredDefaultDimensions(): void {
    $config_factory = $this->getConfigFactoryStub([
      'rouen_iframe_consent.settings' => [
        'default_width' => 800,
        'default_height' => 450,
      ],
    ]);
    $parser = new IframeParser([], $config_factory);

    $iframe = $parser->parse(
      '<iframe src="https://example.com/embed"></iframe>'
    );

    self::assertNotNull($iframe);
    self::assertSame(800, $iframe->width);
    self::assertSame(450, $iframe->height);
    self::assertSame('800', $iframe->attributes['width']);
    self::assertSame('450', $iframe->attributes['height']);
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

  /**
   * Tests supported blockquote embed extraction.
   */
  #[DataProvider('blockquoteEmbedProvider')]
  public function testBlockquoteEmbedExtraction(
    string $html,
    string $provider,
    string $script_source,
  ): void {
    $embed = $this->parser->parse($html);

    self::assertInstanceOf(ParsedBlockquote::class, $embed);
    self::assertSame($provider, $embed->providerName);
    self::assertSame($script_source, $embed->sourceUrl);
    self::assertSame($script_source, $embed->scriptAttributes['src']);
    self::assertTrue($embed->scriptAttributes['async']);
    self::assertStringContainsString(
      'data-rouen-embed-class=',
      $embed->previewHtml,
    );
  }

  /**
   * Provides supported blockquote embed codes.
   *
   * @return array<string, array{string, string, string}>
   *   Provider embed cases.
   */
  public static function blockquoteEmbedProvider(): array {
    return [
      'Instagram' => [
        '<blockquote class="instagram-media" '
        . 'data-instgrm-permalink="https://www.instagram.com/p/ABC123/" '
        . 'data-instgrm-version="14"><p>Post</p></blockquote>'
        . '<script async src="//www.instagram.com/embed.js"></script>',
        'Instagram',
        'https://www.instagram.com/embed.js',
      ],
      'TikTok' => [
        '<blockquote class="tiktok-embed" '
        . 'cite="https://www.tiktok.com/@example/video/123" '
        . 'data-video-id="123"><section>Video</section></blockquote>'
        . '<script async src="https://www.tiktok.com/embed.js"></script>',
        'TikTok',
        'https://www.tiktok.com/embed.js',
      ],
      'X' => [
        '<blockquote class="twitter-tweet"><p>Post</p>'
        . '<a href="https://twitter.com/example/status/123">Date</a>'
        . '</blockquote><script async '
        . 'src="https://platform.x.com/widgets.js" '
        . 'charset="utf-8"></script>',
        'X (Twitter)',
        'https://platform.x.com/widgets.js',
      ],
      'Bluesky' => [
        '<blockquote class="bluesky-embed" '
        . 'data-bluesky-uri="at://did:plc:abc/app.bsky.feed.post/123" '
        . 'data-bluesky-cid="bafy123"><p>Post</p></blockquote>'
        . '<script async '
        . 'src="https://embed.bsky.app/static/embed.js"></script>',
        'Bluesky',
        'https://embed.bsky.app/static/embed.js',
      ],
    ];
  }

  /**
   * Tests that preview HTML is inert and strips unsafe markup.
   */
  public function testBlockquotePreviewSanitization(): void {
    $html = '<blockquote class="twitter-tweet extra" style="color:red" '
      . 'onclick="alert(1)"><p>Safe <strong>text</strong>'
      . '<script>alert(1)</script><img src="https://tracker.example/pixel">'
      . '<a href="https://x.com/example/status/123" target="_blank" '
      . 'onclick="alert(2)">Read it</a></p></blockquote>'
      . '<script async src="https://platform.twitter.com/widgets.js">'
      . '</script>';

    $embed = $this->parser->parse($html);

    self::assertInstanceOf(ParsedBlockquote::class, $embed);
    self::assertStringContainsString(
      '<strong>text</strong>',
      $embed->previewHtml,
    );
    self::assertStringContainsString(
      'data-rouen-embed-href="https://x.com/example/status/123"',
      $embed->previewHtml,
    );
    self::assertStringNotContainsString(' style=', $embed->previewHtml);
    self::assertStringNotContainsString(' onclick=', $embed->previewHtml);
    self::assertStringNotContainsString(' target=', $embed->previewHtml);
    self::assertStringNotContainsString(' href=', $embed->previewHtml);
    self::assertStringNotContainsString('<script', $embed->previewHtml);
    self::assertStringNotContainsString('<img', $embed->previewHtml);
  }

  /**
   * Tests rejection of unrecognized or mismatched provider scripts.
   */
  #[DataProvider('unsafeBlockquoteProvider')]
  public function testUnsafeBlockquoteEmbedsAreRejected(string $html): void {
    self::assertNull($this->parser->parse($html));
  }

  /**
   * Provides unsafe blockquote embed codes.
   *
   * @return array<string, array{string}>
   *   Unsafe embed cases.
   */
  public static function unsafeBlockquoteProvider(): array {
    return [
      'Arbitrary script' => [
        '<blockquote class="twitter-tweet">Post</blockquote>'
        . '<script src="https://evil.example/widgets.js"></script>',
      ],
      'Provider class mismatch' => [
        '<blockquote class="instagram-media">Post</blockquote>'
        . '<script src="https://platform.twitter.com/widgets.js"></script>',
      ],
      'Insecure script' => [
        '<blockquote class="twitter-tweet">Post</blockquote>'
        . '<script src="http://platform.twitter.com/widgets.js"></script>',
      ],
      'Script URL query' => [
        '<blockquote class="twitter-tweet">Post</blockquote>'
        . '<script src="https://platform.twitter.com/widgets.js?callback=x">'
        . '</script>',
      ],
      'Non-adjacent script' => [
        '<blockquote class="twitter-tweet">Post</blockquote><p>Gap</p>'
        . '<script src="https://platform.twitter.com/widgets.js"></script>',
      ],
    ];
  }

}
