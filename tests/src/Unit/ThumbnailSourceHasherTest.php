<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailSourceHasher;
use Drupal\rouen_iframe_consent\ValueObject\ParsedIframe;
use Drupal\Tests\UnitTestCase;

/**
 * Tests provider-aware thumbnail source hashing.
 *
 * @group rouen_iframe_consent
 */
final class ThumbnailSourceHasherTest extends UnitTestCase {

  /**
   * Tests that standard providers retain the parsed iframe source hash.
   */
  public function testStandardProviderHash(): void {
    $iframe = $this->iframe('YouTube');

    self::assertSame(
      $iframe->getSourceHash(),
      (new ThumbnailSourceHasher())->hash($iframe),
    );
  }

  /**
   * Tests that Facebook hashes include the preview strategy version.
   */
  public function testFacebookHashIsVersioned(): void {
    $iframe = $this->iframe('Facebook');
    $hasher = new ThumbnailSourceHasher();

    self::assertNotSame($iframe->getSourceHash(), $hasher->hash($iframe));
    self::assertSame($hasher->hash($iframe), $hasher->hash($iframe));
  }

  /**
   * Creates parsed iframe data for a provider.
   */
  private function iframe(string $providerName): ParsedIframe {
    return new ParsedIframe(
      'https://www.example.com/embed/123',
      'https://www.example.com/watch/123',
      'www.example.com',
      $providerName,
      560,
      315,
      [],
    );
  }

}
