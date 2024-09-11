<?php

declare(strict_types=1);

namespace Drupal\neo_favicon\EventSubscriber;

use Drupal\Core\Archiver\ArchiverManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\neo_config_file\Event\ConfigFilePreDeleteEvent;
use Drupal\neo_config_file\Event\ConfigFilePreSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Act on config file events.
 */
final class FaviconConfigFileSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a FaviconConfigFileSubscriber object.
   */
  public function __construct(
    private readonly ArchiverManager $archiveManager,
    private readonly FileSystemInterface $fileSystem,
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
    $archiver = $this->archiveManager->getInstance(['filepath' => $zip_uri]);
    if (!$archiver) {
      return;
    }
    $directory = 'public://neo-favicon';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $this->fileSystem->deleteRecursive($directory);
    $archiver->extract($directory);
  }

  /**
   * Kernel response event handler.
   */
  public function onConfigFilePreDelete(ConfigFilePreDeleteEvent $event): void {
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
