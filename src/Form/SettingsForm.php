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
   * Valid hostname pattern for trusted hosts.
   *
   * @var string
   */
  private const VALID_HOSTNAME_PATTERN =
      '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*' .
      '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

  /**
   * A regex pattern that matches any newline character sequence.
   *
   * @var string
   */
  private const NEWLINE_PATTERN = '/\R/';

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
        'Enter one host name per line, without a path (for example, media.example.org). Embeds from these hosts and their subdomains are loaded without asking for consent.'
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

    $form['consent_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Consent message'),
      '#description' => $this->t(
        'Use @provider where the external service name should appear.'
      ),
      '#default_value' => $config->get('consent_message')
      ?? 'This content is hosted by @provider. Loading it may allow this ' .
      'service to store cookies on your device.',
      '#required' => TRUE,
      '#rows' => 3,
    ];

    $form['consent_button_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Consent button label'),
      '#default_value' => $config->get('consent_button_label') ?? 'I accept',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['default_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Default iframe width'),
      '#description' => $this->t(
        'Width in pixels used when an iframe has no valid width.'
      ),
      '#default_value' => $config->get('default_width') ?? 560,
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 10000,
      '#step' => 1,
    ];

    $form['default_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Default iframe height'),
      '#description' => $this->t(
        'Height in pixels used when an iframe has no valid height.'
      ),
      '#default_value' => $config->get('default_height') ?? 315,
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 10000,
      '#step' => 1,
    ];

    $form['remote_thumbnail_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Remote thumbnail settings'),
      '#open' => FALSE,
    ];

    $form['remote_thumbnail_settings']['remote_thumbnail_max_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum remote thumbnail size'),
      '#description' => $this->t('Maximum downloaded file size in bytes.'),
      '#default_value' => $config->get('remote_thumbnail_max_size')
      ?? 5_242_880,
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['remote_thumbnail_settings']['thumbnail_connect_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail connection timeout'),
      '#description' => $this->t(
        'Maximum time in seconds allowed to establish the connection.'
      ),
      '#default_value' => $config->get('thumbnail_connect_timeout') ?? 5,
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['remote_thumbnail_settings']['thumbnail_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Thumbnail request timeout'),
      '#description' => $this->t(
        'Maximum total time in seconds allowed for the request.'
      ),
      '#default_value' => $config->get('thumbnail_timeout') ?? 15,
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['remote_thumbnail_settings']['thumbnail_max_redirects'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum thumbnail redirects'),
      '#description' => $this->t(
        'Maximum number of HTTP redirects followed for one thumbnail.'
      ),
      '#default_value' => $config->get('thumbnail_max_redirects') ?? 5,
      '#required' => TRUE,
      '#min' => 0,
      '#step' => 1,
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
      self::NEWLINE_PATTERN,
      (string) $form_state->getValue('trusted_hosts'),
      -1,
      PREG_SPLIT_NO_EMPTY
    ) ?: [];

    // Validate host names.
    $normalized = [];
    foreach ($hosts as $host) {
      $host = strtolower(rtrim(trim($host), '.'));

      if (!preg_match(self::VALID_HOSTNAME_PATTERN, $host)) {
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

    foreach (['consent_message', 'consent_button_label'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      $form_state->setValue($key, $value);

      if ($value === '') {
        $form_state->setErrorByName(
          $key,
          $this->t('This field is required.')
        );
      }
    }
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
        $old_file = $this->entityTypeManager
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
      ->set(
        'consent_message',
        $form_state->getValue('consent_message')
      )
      ->set(
        'consent_button_label',
        $form_state->getValue('consent_button_label')
      )
      ->set('default_width', (int) $form_state->getValue('default_width'))
      ->set('default_height', (int) $form_state->getValue('default_height'))
      ->set(
        'remote_thumbnail_max_size',
        (int) $form_state->getValue('remote_thumbnail_max_size')
      )
      ->set(
        'thumbnail_connect_timeout',
        (int) $form_state->getValue('thumbnail_connect_timeout')
      )
      ->set(
        'thumbnail_timeout',
        (int) $form_state->getValue('thumbnail_timeout')
      )
      ->set(
        'thumbnail_max_redirects',
        (int) $form_state->getValue('thumbnail_max_redirects')
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

}
