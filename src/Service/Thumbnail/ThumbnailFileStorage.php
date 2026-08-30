<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Service\Thumbnail;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;

/**
 * Stores and removes downloaded thumbnail files.
 */
final class ThumbnailFileStorage {

  /**
   * The public thumbnail directory.
   */
  private const DIRECTORY = 'public://rouen_iframe_consent/thumbnails';

  /**
   * Creates the thumbnail file storage.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Stores thumbnail data and returns its URI.
   */
  public function save(
    string $data,
    string $extension,
    ThumbnailRecord $record,
  ): string {
    if (!$this->fileSystem->prepareDirectory(
      self::DIRECTORY,
      FileSystemInterface::CREATE_DIRECTORY
        | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException(
        'The thumbnail directory could not be prepared.'
      );
    }

    $destination = sprintf(
      '%s/%s-%d.%s',
      self::DIRECTORY,
      $record->sourceHash,
      $record->id,
      $extension,
    );

    $uri = $this->fileSystem->saveData(
      $data,
      $destination,
      FileExists::Replace,
    );

    if ($uri === FALSE) {
      throw new \RuntimeException('The thumbnail file could not be saved.');
    }

    return $uri;
  }

  /**
   * Returns the public URL of an existing thumbnail.
   */
  public function url(?string $uri): ?string {
    if ($uri === NULL || !file_exists($uri)) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Removes a thumbnail file if it exists.
   */
  public function delete(?string $uri): void {
    if ($uri !== NULL && file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

}
