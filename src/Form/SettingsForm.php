<?php

declare(strict_types=1);

namespace Drupal\neo_favicon\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Neo | Favicon settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_favicon_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['neo_favicon.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('neo_favicon.settings');

    $form['file'] = [
      '#type' => 'neo_config_file',
      '#title' => $this->t('Real Favicon Package'),
      '#description' => t('Paste the .zip package provided by <a href="@url" target="_blank">@url</a>.', ['@url' => 'http://realfavicongenerator.net/']),
      '#default_value' => $config->get('file'),
      '#extensions' => ['zip'],
      '#dependencies' => [
        'config' => [
          'neo_favicon.settings',
        ],
      ],
    ];

    $form['tags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tags'),
      '#default_value' => $config->get('tags'),
      '#description' => t('Paste the code provided by <a href="@url" target="_blank">@url</a>. Make sure each link is on a separate line.', ['@url' => 'http://realfavicongenerator.net/']),
      '#rows' => 7,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // @todo Validate the form here.
    // Example:
    // @code
    //   if ($form_state->getValue('example') === 'wrong') {
    //     $form_state->setErrorByName(
    //       'message',
    //       $this->t('The value is not correct.'),
    //     );
    //   }
    // @endcode
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('neo_favicon.settings')
      ->set('file', $form_state->getValue('file'))
      ->set('tags', trim($form_state->getValue('tags')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
