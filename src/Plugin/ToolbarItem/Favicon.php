<?php

declare(strict_types=1);

namespace Drupal\neo_favicon\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_favicon\FaviconManager;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemColorSchemeTrait;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_tooltip\Tooltip;
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
    RendererInterface $renderer,
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
      'size' => '',
      'scheme' => '',
      'filter' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);
    $id = Html::getId('toolbar-item-' . $this->pluginId);

    $form['url'] = $this->urlForm([], $form_state, $this->configuration['url']);

    $form['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open link in new window'),
      '#return_value' => '_blank',
      '#default_value' => $this->configuration['target'],
    ];

    $options = [
      '' => $this->t('Default'),
    ];
    foreach ($this->faviconManager->getImages() as $uri => $data) {
      if ($data['width'] > 100) {
        $neoImageStyle = new NeoImageStyle();
        $neoImageStyle->cropSides();
        $neoImageStyle->exact(36, 36);
        $build = $neoImageStyle->toRenderableFromUri($uri);
        $build['#prefix'] = '<div class="flex items-center justify-center bg-primary-500 w-12 h-12 p-1 rounded">';
        $build['#suffix'] = '</div>';
        $build['#attributes']['class'][] = 'w-auto';
        if ($filter = $this->configuration['filter']) {
          $build['#attributes']['style'] = $filter;
        }
        $tooltip = new Tooltip($uri);
        $tooltip->applyTo($build);
        $build = $this->renderer->render($build);
        $options[$uri] = Markup::create($build);
      }
    }

    $form['image'] = [
      '#type' => 'radios',
      '#title' => $this->t('Image'),
      '#options' => $options,
      '#description' => $this->t('The favicon image.'),
      '#default_value' => $this->configuration['image'],
      '#prefix' => '<div id="' . $id . '-image">',
      '#suffix' => '</div>',
    ];

    $form['size'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Size'),
      '#options' => $this->getSizeOptions(),
      '#default_value' => $this->configuration['size'],
      '#description' => $this->t('The image size.'),
      '#ajax' => [
        'callback' => [self::class, 'ajaxPreview'],
        'wrapper' => $id . '-image',
      ],
    ];

    $form['filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Image Filter'),
      '#options' => $this->getFilterOptions(),
      '#default_value' => $this->configuration['filter'],
      '#description' => $this->t('Apply a CSS filter to the image.'),
      '#ajax' => [
        'callback' => [self::class, 'ajaxPreview'],
        'wrapper' => $id . '-image',
      ],
    ];

    $form['scheme'] = $this->getSchemeElement($this->configuration['scheme']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function ajaxPreview(array $form, FormStateInterface $form_state): array {
    $parents = array_splice($form_state->getTriggeringElement()['#array_parents'], 0, -1);
    return NestedArray::getValue($form, $parents)['image'];
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
    if (file_exists($this->configuration['image'])) {
      $element->setImage($this->configuration['image']);
      $element->setImageSize(36, 36);
    }
    else {
      // Use the default favicon image.
      $path = \Drupal::service('extension.list.module')->getPath('neo_favicon');
      $element->setImage('/' . $path . '/images/favicon.png');
    }
    if ($size = $this->configuration['size']) {
      if ($size === 'full') {
        $element->addClass('relative');
        $element->addImageClass('absolute top-0 left-0 w-full h-full object-cover');
        $element->setImageSize(100, 100);
      }
    }
    if ($filter = $this->configuration['filter']) {
      $element->setImageAttribute('style', $filter);
    }
    $this->processSchemeElement($element);
    $this->linkProcessElement($element);
    return $element;
  }

  /**
   * Get size options.
   *
   * @return array
   *   The size options.
   */
  protected function getSizeOptions(): array {
    return [
      '' => $this->t('Default'),
      'full' => $this->t('Full'),
    ];
  }

  /**
   * Get filter options.
   *
   * @return array
   *   The filter options.
   */
  protected function getFilterOptions(): array {
    return [
      '' => $this->t('None'),
      'full' => $this->t('Full'),
      'filter: brightness(0) invert(1);' => $this->t('Style 1'),
      'mix-blend-mode: screen; filter: invert(1) brightness(2);' => $this->t('Style 2'),
    ];
  }

}
