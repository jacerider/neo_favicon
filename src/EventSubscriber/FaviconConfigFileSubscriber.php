<?php

declare(strict_types=1);

namespace Drupal\neo_favicon\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Drupal\neo_config_file\Event\ConfigFilePreDeleteEvent;
use Drupal\neo_config_file\Event\ConfigFilePreSaveEvent;
use Drupal\neo_config_file\Exception\ExtractionRefusedException;
use Drupal\neo_config_file\ZipExtractor;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Act on config file events.
 */
final class FaviconConfigFileSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a FaviconConfigFileSubscriber object.
   *
   * @param \Drupal\neo_config_file\ZipExtractor $zipExtractor
   *   The zip extractor, which unpacks the favicon package. Drupal 12 removes
   *   the whole plugin namespace this used to go through, with no
   *   replacement; the extractor is the one implementation the modules that
   *   unpack a config file's payload share now.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service, which the delete path below still needs.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    private readonly ZipExtractor $zipExtractor,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Kernel request event handler.
   */
  public function onConfigFilePreSave(ConfigFilePreSaveEvent $event): void {
    $configFile = $event->getConfigFile();
    if ($configFile->getParentFormId() !== 'neo_favicon_settings') {
      return;
    }
    $file = $configFile->getFile();
    if (!$file) {
      return;
    }
    $zip_uri = $file->getFileUri();
    // Nothing is emptied first: the extractor unpacks elsewhere and replaces
    // the directory only once the whole package is on disk, so a package that
    // will not open leaves the site's existing favicons where they are. And it
    // is logged rather than thrown, because this runs inside a settings save
    // and an uncaught exception there takes the form down. Only the refusal is
    // caught; anything else is a bug that should surface.
    try {
      $this->zipExtractor->extract($zip_uri, 'public://neo-favicon');
    }
    catch (ExtractionRefusedException $e) {
      $this->logger->warning('The favicon package %file could not be unpacked, so the favicons already installed are unchanged: @message', [
        '%file' => $zip_uri,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Kernel response event handler.
   */
  public function onConfigFilePreDelete(ConfigFilePreDeleteEvent $event): void {
    $configFile = $event->getConfigFile();
    if ($configFile->getParentFormId() !== 'neo_favicon_settings') {
      return;
    }
    $this->fileSystem->deleteRecursive('public://neo-favicon');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigFilePreSaveEvent::EVENT_NAME => ['onConfigFilePreSave'],
      ConfigFilePreDeleteEvent::EVENT_NAME => ['onConfigFilePreDelete'],
    ];
  }

}
