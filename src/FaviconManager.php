<?php

declare(strict_types=1);

namespace Drupal\neo_favicon;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * The favicon manager.
 */
final class FaviconManager {

  /**
   * The data.
   *
   * @var string
   */
  protected $data;

  /**
   * The available images.
   *
   * @var array
   */
  protected $images;

  /**
   * Constructs a FaviconManager object.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Get the HTML markup for the favicon.
   *
   * @return string
   *   The HTML markup.
   */
  public function getHtml(): string {
    if (!isset($this->data)) {
      $cid = 'neo_favicon';
      $cache = $this->cache->get($cid);
      if ($cache) {
        $this->data = $cache->data;
      }
      else {
        $data = [];
        $config = $this->configFactory->get('neo_favicon.settings');
        $file = $config->get('file');
        $tags = $config->get('tags');
        if ($file && $tags) {
          $data = [];
          $dom = new \DOMDocument();
          $dom->loadHTML($tags);

          // Find all icons.
          $tags = $dom->getElementsByTagName('link');
          foreach ($tags as $tag) {
            if ($tag instanceof \DOMElement) {
              $file_path = 'public://neo-favicon' . $tag->getAttribute('href');
              if (file_exists($file_path)) {
                $tag->setAttribute('href', $this->fileUrlGenerator->generateString($file_path));
                $data[] = $dom->saveXML($tag);
              }
            }
          }

          // Find any Windows 8 meta tags.
          $tags = $dom->getElementsByTagName('meta');
          foreach ($tags as $tag) {
            if ($tag instanceof \DOMElement) {
              $data[] = $dom->saveXML($tag);
            }
          }
          $data = implode(PHP_EOL, $data) . PHP_EOL;
          $this->cache->set($cid, $data, Cache::PERMANENT, ['config:neo_favicon.settings']);
        }
        $this->data = $data;
      }
    }
    return $this->data;
  }

  /**
   * Get the available images.
   *
   * @return array
   *   An associative array (keyed on the chosen key) of objects with 'uri',
   *   'filename', and 'name' properties corresponding to the matched files.
   */
  public function getImages(): array {
    if (!isset($this->images)) {
      $cid = 'neo_favicon:images';
      $cache = $this->cache->get($cid);
      if ($cache) {
        $this->images = $cache->data;
      }
      else {
        foreach ($this->fileSystem->scanDirectory('public://neo-favicon', '/.*.png$/i') as $file) {
          if (file_exists($file->uri)) {
            $size = getimagesize($file->uri);
            $this->images[$file->uri] = [
              'width' => $size[0],
              'height' => $size[1],
            ];
          }
        }
        $this->cache->set($cid, $this->images, Cache::PERMANENT, ['config:neo_favicon.settings']);
      }
    }

    return $this->images;
  }

}
