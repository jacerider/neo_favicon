<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_favicon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\FileInterface;
use Drupal\neo_config_file\ConfigFileInterface;
use Drupal\neo_config_file\Event\ConfigFilePreDeleteEvent;
use Drupal\neo_config_file\Event\ConfigFilePreSaveEvent;
use Drupal\neo_favicon\EventSubscriber\FaviconConfigFileSubscriber;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Drives the favicon config file subscriber against real favicon packages.
 *
 * This is the first test this module has ever had, and the seam it covers is
 * the one Drupal 12's removal of core's archive plugins left uncovered: a
 * favicon package used to be unpacked through a plugin manager that is going
 * away, guarded by nothing an upload named `.zip` could ever fail, and nothing
 * anywhere asserted what a site was left with when the package would not
 * open.
 *
 * It is a kernel test rather than a unit test because the zip extractor it now
 * delegates to is final with no interface — the shape the stack settled on for
 * a service of that kind — so PHPUnit cannot double it, and doubling the thing
 * that does the extraction would leave the extraction untested anyway. The
 * real extractor runs here against packages the test writes for itself, and
 * only the logger is a hand-rolled double.
 *
 * Kernel tests resolve no info.yml dependencies, so the boot is enumerated by
 * hand and verified in set-up before anything else runs. The base package is
 * deliberately not in it: nothing on the path under test reaches for it, and
 * the four modules named below are what the subscriber, the extractor and the
 * public stream wrapper actually need.
 *
 * Every package is written at run time with `ZipArchive` in write mode. A
 * committed binary fixture would put the thing under test into a file nobody
 * can read in a diff, and the zip extension is already a hard requirement of
 * the module that does the extracting, so writing one costs nothing.
 */
#[Group('neo_favicon')]
final class FaviconConfigFileSubscriberTest extends KernelTestBase {

  /**
   * The directory a favicon package is unpacked into.
   */
  private const DIRECTORY = 'public://neo-favicon';

  /**
   * The form whose config file carries a favicon package.
   */
  private const FORM_ID = 'neo_favicon_settings';

  /**
   * The entries a well-formed favicon package holds, in `ksort()` order.
   */
  private const PACKAGE = [
    'favicon.ico' => 'ico-bytes',
    'icons/apple-touch-icon.png' => 'png-bytes',
    'site.webmanifest' => '{"name":"site"}',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'file', 'neo_config_file', 'neo_favicon'];

  /**
   * Every record the hand-rolled logger was handed, oldest first.
   *
   * @var array<int, array{level: mixed, message: string, context: array<string, mixed>}>
   */
  private array $logged = [];

  /**
   * {@inheritdoc}
   *
   * The inherited set-up maps the public stream onto a virtual filesystem, on
   * which `realpath()` answers nothing, so a package at `public://` resolves
   * to FALSE and never reaches `ZipArchive`. A real site directory is what
   * core's own file tests use for the same reason.
   */
  protected function setUpFilesystem(): void {
    $files = $this->siteDirectory . '/files';
    mkdir($files, 0775, TRUE);
    mkdir($this->siteDirectory . '/config/sync', 0775, TRUE);
    $this->setSetting('file_public_path', $files);
    $this->setSetting('config_sync_directory', $this->siteDirectory . '/config/sync');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The boot, asserted before any behaviour is: this module answers config
    // file events without the base package, and its subscriber service still
    // constructs from the arguments its services file names.
    $moduleHandler = $this->container->get('module_handler');
    $this->assertTrue($moduleHandler->moduleExists('neo_favicon'));
    $this->assertFalse($moduleHandler->moduleExists('neo'));
    $this->assertInstanceOf(FaviconConfigFileSubscriber::class, $this->container->get('neo_favicon.event_subscriber'));
  }

  /**
   * A saved package is unpacked, and replaces whatever was installed.
   *
   * The pair that used to run before the extraction — prepare the directory,
   * then empty it — is gone: replacing the directory is the extractor's job
   * now, and it does it only once the whole package is on disk. What that buys
   * is asserted by the refusal below; what it must not cost is asserted here,
   * where a retired favicon is gone and the package's own entries, nested ones
   * included, are all that is left.
   *
   * Covers: it hands the favicon package and the favicon directory to the zip
   * extractor on save, without deleting the directory first.
   */
  public function testUnpacksTheFaviconPackageOnSave(): void {
    $this->seed(['retired.ico' => 'retired']);
    $configFile = $this->configFile(self::FORM_ID, $this->package(self::PACKAGE));

    $this->subscriber()->onConfigFilePreSave(new ConfigFilePreSaveEvent($configFile));

    $this->assertSame(self::PACKAGE, $this->contentsOf(self::DIRECTORY));
    $this->assertSame([], $this->logged);
  }

  /**
   * Every other form's config files go past untouched.
   *
   * Covers: it ignores a config file belonging to another form.
   */
  public function testIgnoresConfigFileBelongingToAnotherForm(): void {
    $this->seed(['installed.ico' => 'installed']);
    $configFile = $this->configFile('neo_site_settings', $this->package(self::PACKAGE));

    $this->subscriber()->onConfigFilePreSave(new ConfigFilePreSaveEvent($configFile));

    $this->assertSame(['installed.ico' => 'installed'], $this->contentsOf(self::DIRECTORY));
    $this->assertSame([], $this->logged);
  }

  /**
   * A config file with nothing to unpack is nothing to do.
   *
   * Covers: it ignores a config file that carries no file.
   */
  public function testIgnoresConfigFileThatCarriesNoFile(): void {
    $this->seed(['installed.ico' => 'installed']);
    $configFile = $this->configFile(self::FORM_ID, NULL);

    $this->subscriber()->onConfigFilePreSave(new ConfigFilePreSaveEvent($configFile));

    $this->assertSame(['installed.ico' => 'installed'], $this->contentsOf(self::DIRECTORY));
    $this->assertSame([], $this->logged);
  }

  /**
   * A package that will not open costs the site a log line and nothing else.
   *
   * Two things changed here at once, on purpose. An unopenable package used to
   * throw out of an event subscriber during a settings save, which is an
   * uncaught exception on a config form; it is a caught refusal and a logged
   * warning now, and the save completes. And because the extractor replaces
   * the directory rather than emptying it first, the favicons that were
   * installed are still byte-for-byte where they were.
   *
   * Covers: it logs a warning through the module's own channel and does not
   * throw when the package cannot be opened.
   */
  public function testWarnsRatherThanThrowingWhenThePackageCannotBeOpened(): void {
    $installed = [
      'favicon.ico' => 'installed-ico',
      'icons/apple-touch-icon.png' => 'installed-png',
    ];
    $this->seed($installed);
    $configFile = $this->configFile(self::FORM_ID, $this->corruptPackage());

    $this->subscriber()->onConfigFilePreSave(new ConfigFilePreSaveEvent($configFile));

    $this->assertSame($installed, $this->contentsOf(self::DIRECTORY));
    $this->assertCount(1, $this->logged);
    $this->assertSame(LogLevel::WARNING, $this->logged[0]['level']);
    $this->assertSame('public://favicons.zip', $this->logged[0]['context']['%file']);
  }

  /**
   * Deleting the config file still takes the unpacked favicons with it.
   *
   * Covers: it still deletes the favicon directory on a config file delete.
   */
  public function testDeletesTheFaviconDirectoryOnConfigFileDelete(): void {
    $this->seed(self::PACKAGE);
    $configFile = $this->configFile(self::FORM_ID, NULL);

    $this->subscriber()->onConfigFilePreDelete(new ConfigFilePreDeleteEvent($configFile));

    $this->assertDirectoryDoesNotExist(self::DIRECTORY);
  }

  /**
   * Builds the subscriber over the real extractor and a recording logger.
   *
   * The extractor and the file system are the container's own, because the
   * extractor is final and the point of this test is the extraction really
   * happening. The logger is the one dependency that is doubled, by hand:
   * what a warning means is asserted from what it was handed, not from an
   * expectation set on a mock.
   *
   * @return \Drupal\neo_favicon\EventSubscriber\FaviconConfigFileSubscriber
   *   The subscriber under test.
   */
  private function subscriber(): FaviconConfigFileSubscriber {
    return new FaviconConfigFileSubscriber(
      $this->container->get('neo_config_file.zip_extractor'),
      $this->container->get('file_system'),
      $this->logger(),
    );
  }

  /**
   * A logger that hands every record back to the test.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logger to inject.
   */
  private function logger(): LoggerInterface {
    return new class ($this->record(...)) extends AbstractLogger {

      /**
       * Constructs the recording logger.
       *
       * @param \Closure $record
       *   Called with the level, message and context of every record.
       */
      public function __construct(
        private readonly \Closure $record,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        ($this->record)($level, (string) $message, $context);
      }

    };
  }

  /**
   * Keeps one record the logger was handed.
   *
   * @param mixed $level
   *   The log level.
   * @param string $message
   *   The message, with its placeholders still in it.
   * @param array<string, mixed> $context
   *   The message's context.
   */
  private function record(mixed $level, string $message, array $context): void {
    $this->logged[] = [
      'level' => $level,
      'message' => $message,
      'context' => $context,
    ];
  }

  /**
   * Stubs the config file a config file event carries.
   *
   * @param string $formId
   *   The form the config file belongs to.
   * @param string|null $uri
   *   The uri of the file the config file carries, or NULL for a config file
   *   carrying no file at all.
   *
   * @return \Drupal\neo_config_file\ConfigFileInterface
   *   The config file.
   */
  private function configFile(string $formId, ?string $uri): ConfigFileInterface {
    $configFile = $this->createMock(ConfigFileInterface::class);
    $configFile->method('getParentFormId')->willReturn($formId);
    if ($uri === NULL) {
      $configFile->method('getFile')->willReturn(FALSE);
      return $configFile;
    }
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($uri);
    $configFile->method('getFile')->willReturn($file);
    return $configFile;
  }

  /**
   * Writes a favicon package the test can hand to the subscriber.
   *
   * @param array<string, string> $entries
   *   Entry names, which may contain directory separators, keyed to contents.
   *
   * @return string
   *   The package's stream uri, which is what a config file's file carries.
   */
  private function package(array $entries): string {
    $zip = new \ZipArchive();
    $zip->open($this->siteDirectory . '/files/favicons.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $contents) {
      $zip->addFromString($entry, $contents);
    }
    $zip->close();
    return 'public://favicons.zip';
  }

  /**
   * Writes a file named like a package whose bytes are not a zip.
   *
   * @return string
   *   The package's stream uri.
   */
  private function corruptPackage(): string {
    file_put_contents($this->siteDirectory . '/files/favicons.zip', 'these bytes are not a zip');
    return 'public://favicons.zip';
  }

  /**
   * Puts files in the favicon directory, as an earlier package would have.
   *
   * @param array<string, string> $files
   *   Paths relative to the favicon directory, keyed to contents.
   */
  private function seed(array $files): void {
    foreach ($files as $name => $contents) {
      $path = self::DIRECTORY . '/' . $name;
      $directory = dirname($path);
      if (!is_dir($directory)) {
        mkdir($directory, 0777, TRUE);
      }
      file_put_contents($path, $contents);
    }
  }

  /**
   * Every file beneath a directory, keyed by its path relative to it.
   *
   * @param string $directory
   *   The directory to read, which may be a stream uri and need not exist.
   *
   * @return array<string, string>
   *   Relative paths keyed to contents, sorted by path.
   */
  private function contentsOf(string $directory): array {
    $path = $this->container->get('file_system')->realpath($directory);
    if ($path === FALSE || !is_dir($path)) {
      return [];
    }
    $found = [];
    $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
    foreach ($tree as $file) {
      if ($file->isFile()) {
        $found[substr($file->getPathname(), strlen($path) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    ksort($found);
    return $found;
  }

}
