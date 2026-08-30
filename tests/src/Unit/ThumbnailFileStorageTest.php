<?php

declare(strict_types=1);

namespace Drupal\Tests\rouen_iframe_consent\Unit;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\rouen_iframe_consent\Service\Thumbnail\ThumbnailFileStorage;
use Drupal\rouen_iframe_consent\ValueObject\ThumbnailRecord;
use Drupal\Tests\UnitTestCase;

/**
 * Tests thumbnail file storage.
 *
 * @group rouen_iframe_consent
 */
final class ThumbnailFileStorageTest extends UnitTestCase {

  /**
   * Tests that the directory can be normalized by reference before saving.
   */
  public function testSaveUsesPreparedDirectory(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->expects(self::once())
      ->method('prepareDirectory')
      ->willReturnCallback(static function (string &$directory): bool {
        self::assertSame(
          'public://rouen_iframe_consent/thumbnails',
          $directory,
        );
        $directory = 'public://normalized/thumbnails';

        return TRUE;
      });
    $fileSystem->expects(self::once())
      ->method('saveData')
      ->with(
        'image data',
        'public://normalized/thumbnails/source-hash-12.jpg',
        FileExists::Replace,
      )
      ->willReturn('public://normalized/thumbnails/source-hash-12.jpg');

    $storage = new ThumbnailFileStorage(
      $fileSystem,
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    self::assertSame(
      'public://normalized/thumbnails/source-hash-12.jpg',
      $storage->save('image data', 'jpg', $this->record()),
    );
  }

  /**
   * Creates a thumbnail record for the storage test.
   */
  private function record(): ThumbnailRecord {
    return new ThumbnailRecord(
      id: 12,
      entityType: 'node',
      entityId: '42',
      entityUuid: 'entity-uuid',
      langcode: 'en',
      fieldName: 'field_embed',
      delta: 0,
      sourceHash: 'source-hash',
      iframeUrl: 'https://www.example.com/embed',
      sourceUrl: 'https://www.example.com/watch',
      thumbnailUri: NULL,
      status: 'pending',
      changed: 0,
    );
  }

}
