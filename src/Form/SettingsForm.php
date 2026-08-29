<?php

declare(strict_types=1);

namespace Drupal\rouen_iframe_consent\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures iframe consent behavior.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Creates the settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\file\FileUsage\FileUsageInterface $fileUsage
   *   The file usage service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUsageInterface $fileUsage,
    TypedConfigManagerInterface $typedConfigManager,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('file.usage'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rouen_iframe_consent_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['rouen_iframe_consent.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $config = $this->config('rouen_iframe_consent.settings');
    $form['trusted_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Trusted sites'),
      '#description' => $this->t(
        'Enter one host name per line, without a path (for example, media.example.org). Iframes from these hosts and their subdomains are loaded without asking for consent.'
      ),
      '#default_value' => implode("\n", $config->get('trusted_hosts') ?: []),
    ];
    $fallback_fid = (int) $config->get('fallback_image_fid');
    $form['fallback_image_fid'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Generic preview image'),
      '#description' => $this->t(
        'Used when the external service does not expose an oEmbed thumbnail. Leave empty to use the image supplied with the module.'
      ),
      '#default_value' => $fallback_fid > 0 ? [$fallback_fid] : [],
      '#upload_location' => 'public://rouen_iframe_consent/fallback/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg gif webp'],
        'FileSizeLimit' => ['fileLimit' => 5_242_880],
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    parent::validateForm($form, $form_state);

    $hosts = preg_split(
      '/\R/',
      (string) $form_state->getValue('trusted_hosts'),
      -1,
      PREG_SPLIT_NO_EMPTY
    ) ?: [];

    // Validate host names using a regex pattern that matches valid host names.
    $hostname_pattern =
      '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*' .
      '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    $normalized = [];
    foreach ($hosts as $host) {
      $host = strtolower(rtrim(trim($host), '.'));
      if (!preg_match($hostname_pattern, $host)) {
        $form_state->setErrorByName(
          'trusted_hosts',
          $this->t('%host is not a valid host name.', ['%host' => $host])
        );
      }
      else {
        $normalized[$host] = $host;
      }
    }

    $form_state->setValue('trusted_hosts', array_values($normalized));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $config = $this->config('rouen_iframe_consent.settings');
    $old_fid = (int) $config->get('fallback_image_fid');
    $file_values = $form_state->getValue('fallback_image_fid');
    $new_fid = (int) ($file_values[0] ?? 0);

    if ($new_fid !== $old_fid) {
      if ($old_fid > 0) {
        $old_file = $this
          ->entityTypeManager
          ->getStorage('file')
          ->load($old_fid);

        if ($old_file) {
          $this->fileUsage->delete(
            $old_file,
            'rouen_iframe_consent',
            'fallback',
            'global'
          );

          if ($this->fileUsage->listUsage($old_file) === []) {
            $old_file->delete();
          }
        }
      }
      if ($new_fid > 0) {
        $new_file = $this->entityTypeManager
          ->getStorage('file')
          ->load($new_fid);

        if ($new_file) {
          $new_file->setPermanent();
          $new_file->save();
          $this->fileUsage->add(
            $new_file,
            'rouen_iframe_consent',
            'fallback',
            'global'
          );
        }
      }
    }

    $config
      ->set('trusted_hosts', $form_state->getValue('trusted_hosts'))
      ->set('fallback_image_fid', $new_fid)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
