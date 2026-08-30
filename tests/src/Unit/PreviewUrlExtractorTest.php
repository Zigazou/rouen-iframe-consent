<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\rouen_iframe_consent\Service\PreviewUrlExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * Tests preview URL extraction from remote markup.
 *
 * @group rouen_iframe_consent
 */
final class PreviewUrlExtractorTest extends UnitTestCase {

  /**
   * Tests metadata-first image extraction and relative URL resolution.
   */
  public function testExtractImageUrls(): void {
    $extractor = new PreviewUrlExtractor();
    $html = <<<'HTML'
      <html><head>
        <meta property="og:image" content="/preview.jpg">
        <meta name="twitter:image" content="https://cdn.example/second.png">
      </head><body>
        <video poster="poster.webp"></video>
        <img src="javascript:alert(1)">
        <img data-src="/lazy.gif">
      </body></html>
      HTML;

    self::assertSame([
      'https://www.example.com/preview.jpg',
      'https://cdn.example/second.png',
      'https://www.example.com/embed/poster.webp',
      'https://www.example.com/lazy.gif',
    ], $extractor->extractImageUrls(
      $html,
      'https://www.example.com/embed/player.html',
    ));
  }

  /**
   * Tests iframe extraction, normalization, and duplicate removal.
   */
  public function testExtractIframeUrls(): void {
    $extractor = new PreviewUrlExtractor();
    $html = <<<'HTML'
      <iframe src="//www.facebook.com/plugins/video.php?id=1"></iframe>
      <iframe src="//www.facebook.com/plugins/video.php?id=1"></iframe>
      <iframe src="data:text/html,bad"></iframe>
      HTML;

    self::assertSame([
      'https://www.facebook.com/plugins/video.php?id=1',
    ], $extractor->extractIframeUrls(
      $html,
      'https://www.facebook.com/example/videos/1',
    ));
  }

  /**
   * Tests exclusion of images explicitly marked as icons or avatars.
   */
  public function testExtractImageUrlsExcludingIcons(): void {
    $extractor = new PreviewUrlExtractor();
    $html = <<<'HTML'
      <div class="profile-avatar"><img src="/profile.jpg"></div>
      <img src="/small.jpg" width="64" height="64">
      <img src="/content.jpg" width="640" height="360">
      HTML;

    self::assertSame([
      'https://www.example.com/content.jpg',
    ], $extractor->extractImageUrls(
      $html,
      'https://www.example.com/embed/player.html',
      TRUE,
    ));
  }

  /**
   * Tests Facebook resize dimensions used to distinguish icons from content.
   */
  public function testLikelyIconUrl(): void {
    $extractor = new PreviewUrlExtractor();

    self::assertTrue($extractor->isLikelyIconUrl(
      'https://scontent.example/v/t39.30808-1/profile.jpg'
      . '?stp=cp0_dst-jpg_s40x40_tt6',
    ));
    self::assertFalse($extractor->isLikelyIconUrl(
      'https://scontent.example/v/t39.30808-6/content.jpg'
      . '?stp=cp6_dst-jpg_s526x395_tt6',
    ));
  }

  /**
   * Tests extraction of the largest responsive Facebook image candidate.
   */
  public function testExtractResponsiveFacebookImageUrls(): void {
    $extractor = new PreviewUrlExtractor();
    $html = <<<'HTML'
      <img
        src="https://scontent.example/v/t39.30808-1/avatar.jpg?stp=s50x50"
        srcset="https://scontent.example/v/t39.30808-6/post-small.jpg 320w,
          https://scontent.example/v/t39.30808-6/post-large.jpg 960w"
        width="640"
        height="480"
      >
      HTML;

    self::assertSame([
      'https://scontent.example/v/t39.30808-6/post-large.jpg',
      'https://scontent.example/v/t39.30808-6/post-small.jpg',
    ], $extractor->extractImageUrls(
      $html,
      'https://www.facebook.com/plugins/post.php',
      TRUE,
    ));
  }

}
