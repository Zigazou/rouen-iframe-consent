<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service;

/**
 * Validates that remote URLs resolve exclusively to public IP addresses.
 */
final class RemoteUrlValidator {

  /**
   * Non-public and special-purpose address ranges.
   *
   * @var string[]
   */
  private const BLOCKED_IP_RANGES = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/4',
    '240.0.0.0/4',
    '::/128',
    '::1/128',
    '::ffff:0:0/96',
    '64:ff9b::/96',
    '64:ff9b:1::/48',
    '100::/64',
    '2001::/23',
    '2001:db8::/32',
    '2002::/16',
    '3fff::/20',
    '5f00::/16',
    'fc00::/7',
    'fe80::/10',
    'ff00::/8',
  ];

  /**
   * Resolves a host to its IPv4 and IPv6 addresses.
   *
   * @var \Closure(string): string[]
   */
  private readonly \Closure $hostResolver;

  /**
   * Creates the remote URL validator.
   *
   * @param callable(string): string[]|null $hostResolver
   *   An optional host resolver, primarily for deterministic tests.
   */
  public function __construct(?callable $hostResolver = NULL) {
    $this->hostResolver = $hostResolver !== NULL
      ? \Closure::fromCallable($hostResolver)
      : $this->resolveHost(...);
  }

  /**
   * Checks that a URL is HTTP(S) and resolves only to public addresses.
   */
  public function isSafe(string $url): bool {
    // Validate the URL format and scheme.
    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }

    // Parse the URL and check for disallowed schemes, missing hosts, or
    // credentials.
    $parts = parse_url($url);
    if (!is_array($parts)
      || !in_array(
        strtolower((string) ($parts['scheme'] ?? '')),
        ['http', 'https'],
        TRUE,
      )
      || empty($parts['host'])
      || !empty($parts['user'])
      || !empty($parts['pass'])
    ) {
      return FALSE;
    }

    // Normalize the host and check for localhost.
    $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
      return FALSE;
    }

    // Resolve the host to its IP addresses and check that they are all public.
    $addresses = filter_var($host, FILTER_VALIDATE_IP) !== FALSE
      ? [$host]
      : ($this->hostResolver)($host);

    if ($addresses === []) {
      return FALSE;
    }

    // Check that all resolved addresses are public.
    foreach (array_unique($addresses) as $address) {
      if (!$this->isPublicAddress($address)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks an address against private, reserved, and special-use ranges.
   *
   * @param string $address
   *   The IP address to check.
   *
   * @return bool
   *   TRUE if the address is public, FALSE otherwise.
   */
  private function isPublicAddress(string $address): bool {
    // Validate the address format.
    if (filter_var($address, FILTER_VALIDATE_IP) === FALSE) {
      return FALSE;
    }

    // Check the address against blocked ranges.
    foreach (self::BLOCKED_IP_RANGES as $range) {
      if ($this->addressIsInRange($address, $range)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks whether an IP address belongs to a CIDR range.
   *
   * @param string $address
   *   The IP address to check.
   * @param string $range
   *   The CIDR range to check against.
   *
   * @return bool
   *   TRUE if the address is in the range, FALSE otherwise.
   */
  private function addressIsInRange(string $address, string $range): bool {
    [$network, $prefix] = explode('/', $range, 2);
    $address_bytes = @inet_pton($address);
    $network_bytes = @inet_pton($network);

    // Validate the address and network formats and ensure they are the same
    // length.
    if ($address_bytes === FALSE
      || $network_bytes === FALSE
      || strlen($address_bytes) !== strlen($network_bytes)
    ) {
      return FALSE;
    }

    $prefix_length = (int) $prefix;
    $whole_bytes = intdiv($prefix_length, 8);
    $remaining_bits = $prefix_length % 8;

    // Compare the whole bytes of the address and network.
    if (substr($address_bytes, 0, $whole_bytes)
      !== substr($network_bytes, 0, $whole_bytes)
    ) {
      return FALSE;
    }

    // Compare the remaining bits of the address and network.
    if ($remaining_bits === 0) {
      return TRUE;
    }

    $mask = (0xFF << (8 - $remaining_bits)) & 0xFF;

    // Compare the next byte of the address and network using the mask.
    return (ord($address_bytes[$whole_bytes]) & $mask)
      === (ord($network_bytes[$whole_bytes]) & $mask);
  }

  /**
   * Resolves all available A and AAAA records for a host.
   *
   * @param string $host
   *   The host name to resolve.
   *
   * @return string[]
   *   The resolved IP addresses.
   */
  private function resolveHost(string $host): array {
    // Validate the host format.
    if (filter_var(
      $host,
      FILTER_VALIDATE_DOMAIN,
      FILTER_FLAG_HOSTNAME,
    ) === FALSE) {
      return [];
    }

    // Attempt to resolve the host to its A and AAAA records.
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records)) {
      return [];
    }

    // Extract the IP addresses from the resolved records.
    $addresses = [];
    foreach ($records as $record) {
      // Get the IPv4 address if available.
      if (isset($record['ip']) && is_string($record['ip'])) {
        $addresses[] = $record['ip'];
      }

      // Get the IPv6 address if available.
      if (isset($record['ipv6']) && is_string($record['ipv6'])) {
        $addresses[] = $record['ipv6'];
      }
    }

    return $addresses;
  }

}
