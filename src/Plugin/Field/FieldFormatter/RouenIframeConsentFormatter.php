<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Plugin\Field\FieldFormatter;

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
    \Drupal\Core\Field\FieldDefinitionInterface $field_definition,
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
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
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
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $entity = $items->getEntity();
    $settings = $this->configFactory->get('rouen_iframe_consent.settings');
    $trusted_hosts = $settings->get('trusted_hosts') ?: [];

    foreach ($items as $delta => $item) {
      $parsed = $this->iframeParser->parse((string) $item->value);
      if ($parsed === NULL) {
        continue;
      }

      $attributes = $parsed->attributes;
      if (!isset($attributes['title'])) {
        $attributes['title'] = (string) $this->t('External content from @provider', ['@provider' => $parsed->providerName]);
      }

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

      $thumbnail_url = $this->thumbnailManager->ensureThumbnail(
        $entity,
        $items->getName(),
        (int) $delta,
        $parsed,
      );
      if ($thumbnail_url === NULL) {
        $thumbnail_url = $this->getFallbackImageUrl((int) $settings->get('fallback_image_fid'));
      }

      $attributes['width'] = '100%';
      $attributes['height'] = '100%';

      $elements[$delta] = [
        '#theme' => 'rouen_iframe_consent_placeholder',
        '#attributes' => base64_encode((string) json_encode($attributes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)),
        '#width' => $parsed->width,
        '#height' => $parsed->height,
        '#provider' => $parsed->providerName,
        '#thumbnail_url' => $thumbnail_url,
        '#message' => $this->t('This content is hosted by @provider. Loading it may allow this service to store cookies on your device.', ['@provider' => $parsed->providerName]),
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
   */
  private function getFallbackImageUrl(int $fileId): string {
    if ($fileId > 0) {
      $file = $this->entityTypeManager->getStorage('file')->load($fileId);
      if ($file !== NULL) {
        return $this->fileUrlGenerator->generateString($file->getFileUri());
      }
    }
    $path = $this->moduleExtensionList->getPath('rouen_iframe_consent') . '/images/fallback.svg';
    return $this->fileUrlGenerator->generateString($path);
  }

}
