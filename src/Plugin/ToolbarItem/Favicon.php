<?php

declare(strict_types=1);

namespace Drupal\neo_favicon\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_favicon\FaviconManager;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemColorSchemeTrait;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'favicon',
  label: new TranslatableMarkup('Favicon'),
  description: new TranslatableMarkup('A favicon image.'),
)]
final class Favicon extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use ToolbarItemColorSchemeTrait;

  /**
   * The favicon manager.
   *
   * @var \Drupal\neo_favicon\FaviconManager
   */
  protected $faviconManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    FaviconManager $favicon_manager,
    RendererInterface $renderer
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->faviconManager = $favicon_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transliteration'),
      $container->get('neo_favicon.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'url' => '',
      'target' => '',
      'image' => '',
      'scheme' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    $form['url'] = $this->urlForm([], $form_state, $this->configuration['url']);

    $form['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open link in new window'),
      '#return_value' => '_blank',
      '#default_value' => $this->configuration['target'],
    ];

    $options = [];
    foreach ($this->faviconManager->getImages() as $uri => $data) {
      if ($data['width'] > 100) {
        $neoImageStyle = new NeoImageStyle();
        $neoImageStyle->cropSides();
        $neoImageStyle->scale(36, 36);
        $neoImageStyle->cropSides();
        $build = $neoImageStyle->toRenderableFromUri($uri);
        $build['#prefix'] = '<div class="flex items-center justify-center bg-primary-500 w-12 h-12 p-1 rounded">';
        $build['#suffix'] = '</div>';
        $build['#attributes']['class'][] = 'w-auto';
        $build = $this->renderer->render($build);
        $options[$uri] = Markup::create($build);
      }
    }

    $form['image'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image'),
      '#options' => $options,
      '#required' => TRUE,
      '#description' => $this->t('The favicon image.'),
      '#default_value' => $this->configuration['image'],
    ];

    $form['scheme'] = $this->getSchemeElement($this->configuration['scheme']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string {
    return 'shield-virus';
  }

  /**
   * {@inheritdoc}
   */
  protected function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $element->setImage($this->configuration['image']);
    $this->processSchemeElement($element);
    $this->linkProcessElement($element);
    return $element;
  }

}
