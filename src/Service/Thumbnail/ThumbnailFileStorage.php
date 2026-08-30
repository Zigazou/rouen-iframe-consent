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
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator service.
   */
  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Stores thumbnail data and returns its URI.
   *
   * @param string $data
   *   The thumbnail data.
   * @param string $extension
   *   The file extension (without the dot).
   * @param \Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord $record
   *   The thumbnail record associated with the file.
   *
   * @return string
   *   The URI of the stored thumbnail file.
   */
  public function save(
    string $data,
    string $extension,
    ThumbnailRecord $record,
  ): string {
    // FileSystemInterface::prepareDirectory() accepts its directory argument
    // by reference because it may normalize the stream-wrapper URI.
    $directory = self::DIRECTORY;
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY
        | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException(
        'The thumbnail directory could not be prepared.'
      );
    }

    $destination = sprintf(
      '%s/%s-%d.%s',
      $directory,
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
   *
   * @param string|null $uri
   *   The URI of the thumbnail file, or NULL if no file exists.
   *
   * @return string|null
   *   The public URL of the thumbnail file, or NULL if no file exists.
   */
  public function url(?string $uri): ?string {
    if ($uri === NULL || !file_exists($uri)) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Removes a thumbnail file if it exists.
   *
   * @param string|null $uri
   *   The URI of the thumbnail file to delete, or NULL if no file should be.
   */
  public function delete(?string $uri): void {
    if ($uri !== NULL && file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

}
