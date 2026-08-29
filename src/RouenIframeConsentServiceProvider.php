<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\rouen_iframe_consent\Service\ThumbnailLookupUrlHandler\ThumbnailLookupUrlHandler;

/**
 * Registers services discovered by the Rouen Iframe Consent module.
 */
final class RouenIframeConsentServiceProvider extends ServiceProviderBase {

  /**
   * The tag used to collect thumbnail lookup URL handlers.
   *
   * @var string
   */
  private const THUMBNAIL_LOOKUP_URL_HANDLER_TAG =
    'rouen_iframe_consent.thumbnail_lookup_url_handler';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $directory = __DIR__ . '/Service/ThumbnailLookupUrlHandler';
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(
        $directory,
        \FilesystemIterator::SKIP_DOTS,
      ),
    );

    $paths = [];
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
      }

      $paths[] = $file->getPathname();
    }
    sort($paths);

    foreach ($paths as $path) {
      $class = $this->getClassName($directory, $path);
      if (!is_subclass_of($class, ThumbnailLookupUrlHandler::class)) {
        continue;
      }

      $reflection = new \ReflectionClass($class);
      if (!$reflection->isInstantiable()) {
        continue;
      }

      $container
        ->register($class, $class)
        ->addTag(self::THUMBNAIL_LOOKUP_URL_HANDLER_TAG);
    }
  }

  /**
   * Builds a PSR-4 class name from a handler file path.
   *
   * @param string $directory
   *   The root handler directory.
   * @param string $path
   *   The handler file path.
   *
   * @return string
   *   The fully qualified handler class name.
   */
  private function getClassName(string $directory, string $path): string {
    $relative_path = substr($path, strlen($directory) + 1, -4);

    return 'Drupal\\rouen_iframe_consent\\Service\\ThumbnailLookupUrlHandler\\'
      . str_replace(DIRECTORY_SEPARATOR, '\\', $relative_path);
  }

}
