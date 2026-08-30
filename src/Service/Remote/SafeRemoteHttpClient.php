<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Remote;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\rouen_iframe_consent\Service\RemoteUrlValidator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/**
 * Retrieves remote resources while validating every redirect destination.
 */
final class SafeRemoteHttpClient {

  /**
   * The default maximum number of redirects to follow.
   *
   * @var int
   */
  private const DEFAULT_MAX_REDIRECTS = 5;

  /**
   * The default connection timeout in seconds.
   *
   * @var int
   */
  private const DEFAULT_CONNECT_TIMEOUT = 5;

  /**
   * The default request timeout in seconds.
   *
   * @var int
   */
  private const DEFAULT_TIMEOUT = 15;

  /**
   * Creates the safe remote HTTP client.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The Guzzle HTTP client service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\rouen_iframe_consent\Service\RemoteUrlValidator $remoteUrlValidator
   *   The remote URL validator service.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RemoteUrlValidator $remoteUrlValidator,
  ) {}

  /**
   * Retrieves a URL and accepts only one of the supplied media types.
   *
   * @param string $url
   *   The initial resource URL.
   * @param string[] $acceptedMimeTypes
   *   Accepted response media types.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The validated response, with its final URL in a custom header.
   *
   * @throws \RuntimeException
   *   If the resource could not be retrieved or validated.
   */
  public function get(
    string $url,
    array $acceptedMimeTypes,
  ): ResponseInterface {
    $settings = $this->configFactory->get('rouen_iframe_consent.settings');
    $maxRedirects = $settings->get('thumbnail_max_redirects');

    $maxRedirects = is_numeric($maxRedirects) && (int) $maxRedirects >= 0
      ? (int) $maxRedirects
      : self::DEFAULT_MAX_REDIRECTS;

    $connectTimeout = $this->positiveIntegerSetting(
      'thumbnail_connect_timeout',
      self::DEFAULT_CONNECT_TIMEOUT,
    );

    $timeout = $this->positiveIntegerSetting(
      'thumbnail_timeout',
      self::DEFAULT_TIMEOUT,
    );

    // Follow redirects manually to validate each destination URL.
    for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
      if (!$this->remoteUrlValidator->isSafe($url)) {
        throw new \RuntimeException(
          'The remote resource URL is not safe to retrieve.'
        );
      }

      // Perform the HTTP GET request without following redirects.
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'connect_timeout' => $connectTimeout,
        'timeout' => $timeout,
        'headers' => ['Accept' => implode(',', $acceptedMimeTypes)],
      ]);

      $status = $response->getStatusCode();

      // Handle successful responses, errors, and redirects.
      if ($status >= 200 && $status < 300) {
        $mimeType = $this->mimeType($response);
        if (!in_array($mimeType, $acceptedMimeTypes, TRUE)) {
          throw new \RuntimeException(
            'The remote resource has an unsupported media type.'
          );
        }

        return $response->withHeader('X-Rouen-Iframe-Consent-Url', $url);
      }

      if ($status < 300 || $status >= 400) {
        throw new \RuntimeException(sprintf(
          'The remote resource returned HTTP status %d.',
          $status,
        ));
      }

      $location = $response->getHeaderLine('Location');
      if ($location === '') {
        throw new \RuntimeException(
          'The remote resource redirect has no destination.'
        );
      }

      $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
    }

    throw new \RuntimeException('The remote resource redirected too often.');
  }

  /**
   * Returns the normalized response media type.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The HTTP response.
   *
   * @return string
   *   The normalized media type, in lowercase and without parameters.
   */
  private function mimeType(ResponseInterface $response): string {
    return strtolower(trim(explode(
      ';',
      $response->getHeaderLine('Content-Type'),
    )[0]));
  }

  /**
   * Returns a positive integer setting or its fallback.
   *
   * @param string $key
   *   The configuration key for the setting.
   * @param int $fallback
   *   The fallback value if the configuration is missing or invalid.
   *
   * @return int
   *   A positive integer value.
   */
  private function positiveIntegerSetting(string $key, int $fallback): int {
    $value = $this->configFactory
      ->get('rouen_iframe_consent.settings')
      ->get($key);

    return is_numeric($value) && (int) $value > 0
      ? (int) $value
      : $fallback;
  }

}
