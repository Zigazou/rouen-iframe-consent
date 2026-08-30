<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\ValueObject;

/**
 * Contains ordered image and document preview candidates.
 */
final readonly class PreviewCandidates {

  /**
   * Creates a preview candidate collection.
   *
   * @param string[] $imageUrls
   *   Direct image candidates, in preference order.
   * @param string[] $documentUrls
   *   HTML documents that may expose additional image candidates.
   * @param bool $excludeIcons
   *   Whether icon-like image candidates should be ignored.
   */
  public function __construct(
    public array $imageUrls,
    public array $documentUrls = [],
    public bool $excludeIcons = FALSE,
  ) {}

}
