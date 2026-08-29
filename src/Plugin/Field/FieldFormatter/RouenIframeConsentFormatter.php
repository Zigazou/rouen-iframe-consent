<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\rouen_iframe_consent\Service\IframeParser;
use Drupal\rouen_iframe_consent\Service\ThumbnailManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a consent placeholder before loading a third-party iframe.
 */
#[FieldFormatter(
  id: 'rouen_iframe_consent',
  label: new TranslatableMarkup('Iframe with individual consent'),
  field_types: ['text', 'text_long', 'text_with_summary'],
)]
final class RouenIframeConsentFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * Creates the formatter.
   */
  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    private readonly IframeParser $iframeParser,
    private readonly ThumbnailManager $thumbnailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('rouen_iframe_consent.iframe_parser'),
      $container->get('rouen_iframe_consent.thumbnail_manager'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
      $container->get('entity_type.manager'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(
    FieldItemListInterface $items,
    $langcode,
  ): array {
    $elements = [];
    $entity = $items->getEntity();
    $settings = $this->configFactory->get('rouen_iframe_consent.settings');
    $trusted_hosts = $settings->get('trusted_hosts') ?: [];

    // Iterate over each field item and generate the appropriate render array.
    foreach ($items as $delta => $item) {
      $parsed = $this->iframeParser->parse((string) $item->value);

      // If the iframe could not be parsed, skip this item.
      if ($parsed === NULL) {
        continue;
      }

      // Prepare the iframe attributes, ensuring a title is set for
      // accessibility.
      $attributes = $parsed->attributes;
      if (!isset($attributes['title'])) {
        $attributes['title'] = (string) $this->t(
          'External content from @provider',
          ['@provider' => $parsed->providerName]
        );
      }

      // If the host is trusted, render the iframe directly with a special
      // class.
      if ($this->iframeParser->isTrustedHost($parsed->host, $trusted_hosts)) {
        $attributes['class'] = ['rouen-iframe-consent__trusted'];

        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'iframe',
          '#attributes' => $attributes,
          '#value' => '',
          '#attached' => [
            'library' => ['rouen_iframe_consent/consent'],
          ],
          '#cache' => [
            'tags' => ['config:rouen_iframe_consent.settings'],
          ],
        ];

        continue;
      }

      // Generate the thumbnail URL, falling back to the configured image if
      // necessary.
      $thumbnail_url = $this->thumbnailManager->getThumbnail(
        $entity,
        $items->getName(),
        (int) $delta,
        $parsed,
      );

      // Repair missing or stale records for published content only. The
      // manager independently enforces the same revision guard.
      if ($thumbnail_url === NULL
        && (!$entity->getEntityType()->isRevisionable()
          || $entity->isDefaultRevision())
      ) {
        $thumbnail_url = $this->thumbnailManager->ensureThumbnail(
          $entity,
          $items->getName(),
          (int) $delta,
          $parsed,
        );
      }

      // If no thumbnail could be generated, use the fallback image.
      if ($thumbnail_url === NULL) {
        $thumbnail_url = $this->getFallbackImageUrl(
          (int) $settings->get('fallback_image_fid')
        );
      }

      $attributes['height'] = '100%';

      // Render the consent placeholder with the necessary data attributes and
      // localized strings.
      $elements[$delta] = [
        '#theme' => 'rouen_iframe_consent_placeholder',
        '#iframe_attributes' => base64_encode(
          (string) json_encode(
            $attributes,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
          )
        ),
        '#width' => is_int($parsed->width)
          ? $parsed->width . 'px'
          : $parsed->width,
        '#height' => $parsed->height,
        '#provider' => $parsed->providerName,
        '#thumbnail_url' => $thumbnail_url,
        '#message' => $this->t(
          'This content is hosted by @provider. Loading it may allow this service to store cookies on your device.',
          ['@provider' => $parsed->providerName]
        ),
        '#button_label' => $this->t('I accept'),
        '#attached' => [
          'library' => ['rouen_iframe_consent/consent'],
        ],
        '#cache' => [
          'tags' => ['config:rouen_iframe_consent.settings'],
        ],
      ];
    }

    return $elements;
  }

  /**
   * Returns the configured fallback image or the module default.
   *
   * @param int $fileId
   *   The file ID of the configured fallback image.
   *
   * @return string
   *   The URL of the fallback image.
   */
  private function getFallbackImageUrl(int $fileId): string {
    // If a valid file ID is provided, attempt to load the file and generate its
    // URL.
    if ($fileId > 0) {
      $file = $this->entityTypeManager->getStorage('file')->load($fileId);

      if ($file !== NULL) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }

    // If no valid file is found, use the module's default fallback image.
    $path = $this
      ->moduleExtensionList
      ->getPath('rouen_iframe_consent') . '/images/fallback.svg';

    return $this->fileUrlGenerator->generateString($path);
  }

}
