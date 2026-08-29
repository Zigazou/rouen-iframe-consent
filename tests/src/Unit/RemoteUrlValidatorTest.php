<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\rouen_iframe_consent\Service\RemoteUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests validation of remote thumbnail URLs.
 *
 * @group rouen_iframe_consent
 */
final class RemoteUrlValidatorTest extends TestCase {

  /**
   * Tests rejection of unsafe literal and resolved addresses.
   *
   * @dataProvider unsafeUrlProvider
   */
  #[DataProvider('unsafeUrlProvider')]
  public function testUnsafeUrls(string $url): void {
    $validator = new RemoteUrlValidator(
      static fn(string $host): array => match ($host) {
        'private.example' => ['10.0.0.1'],
        'mixed.example' => ['93.184.216.34', '::1'],
        default => [],
      },
    );

    self::assertFalse($validator->isSafe($url));
  }

  /**
   * Provides unsafe remote URL cases.
   *
   * @return array<string, array{string}>
   *   Unsafe URL cases.
   */
  public static function unsafeUrlProvider(): array {
    return [
      'IPv4 loopback' => ['http://127.0.0.1/image.jpg'],
      'IPv6 loopback' => ['http://[::1]/image.jpg'],
      'Private IPv4' => ['http://10.0.0.1/image.jpg'],
      'Carrier-grade NAT' => ['http://100.64.0.1/image.jpg'],
      'Link-local metadata' => ['http://169.254.169.254/image.jpg'],
      'Documentation IPv4' => ['http://192.0.2.1/image.jpg'],
      'Multicast IPv4' => ['http://224.0.0.1/image.jpg'],
      'Documentation IPv6' => ['http://[2001:db8::1]/image.jpg'],
      'Resolved private address' => ['https://private.example/image.jpg'],
      'One unsafe DNS answer' => ['https://mixed.example/image.jpg'],
      'Credentials' => ['https://user:pass@public.example/image.jpg'],
      'Unsupported scheme' => ['ftp://public.example/image.jpg'],
      'Unresolved host' => ['https://missing.example/image.jpg'],
    ];
  }

  /**
   * Tests acceptance when every resolved address is public.
   */
  public function testPublicAddressesAreAccepted(): void {
    $validator = new RemoteUrlValidator(
      static fn(string $host): array => $host === 'public.example'
        ? ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']
        : [],
    );

    self::assertTrue($validator->isSafe(
      'https://public.example/image.jpg',
    ));
  }

}
