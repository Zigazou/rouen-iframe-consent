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

  private const DEFAULT_MAX_REDIRECTS = 5;

  private const DEFAULT_CONNECT_TIMEOUT = 5;

  private const DEFAULT_TIMEOUT = 15;

  /**
   * Creates the safe remote HTTP client.
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

    for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
      if (!$this->remoteUrlValidator->isSafe($url)) {
        throw new \RuntimeException(
          'The remote resource URL is not safe to retrieve.'
        );
      }

      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'connect_timeout' => $connectTimeout,
        'timeout' => $timeout,
        'headers' => ['Accept' => implode(',', $acceptedMimeTypes)],
      ]);
      $status = $response->getStatusCode();

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
   */
  private function mimeType(ResponseInterface $response): string {
    return strtolower(trim(explode(
      ';',
      $response->getHeaderLine('Content-Type'),
    )[0]));
  }

  /**
   * Returns a positive integer setting or its fallback.
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
