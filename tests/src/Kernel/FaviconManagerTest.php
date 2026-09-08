<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_favicon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_favicon\FaviconManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises FaviconManager::getHtml() and FaviconManager::getImages().
 *
 * FaviconManager had no assertions on it anywhere. Two of the behaviours
 * under test are post-release one-line fixes for a site that has installed
 * the module but never uploaded a favicon package, and both would go
 * silently green again if someone tidied the code:
 *
 * - getHtml() builds $data as an array and, when no package is configured,
 *   that array is never replaced by the joined string. The trailing
 *   `$this->data = $data ?: '';` is what keeps a string-typed method from
 *   returning an array, i.e. what keeps neo_favicon_page_attachments_alter()
 *   from throwing a TypeError on every page.
 * - getImages() early-returns on !file_exists($directory) ||
 *   !is_dir($directory). Without it, FileSystem::scanDirectory() throws
 *   NotRegularDirectoryException, and neo_favicon_neo_token_logo_alter()
 *   calls getImages() while rendering a logo token.
 *
 * It is a kernel test rather than a unit test because getHtml() calls
 * file_exists() on public:// URIs and getImages() calls getimagesize() on
 * real PNG bytes. The inherited vfs makes realpath() answer nothing, so the
 * public stream is pointed at a real site directory, as this module's other
 * kernel tests already do.
 *
 * Kernel tests resolve no info.yml dependencies, so the boot is enumerated
 * by hand and verified in set-up before anything else runs. neo_config_file
 * is not optional even though nothing here touches it: neo_favicon.services.yml
 * wires the subscriber to @neo_config_file.zip_extractor and the container
 * will not compile without it.
 *
 * The manager is built by hand, not from neo_favicon.manager.
 * FaviconManager memoises into $this->data and $this->images, so one
 * instance answers at most one question per method. A fresh instance over
 * the same cache.default is what makes every cache assertion possible.
 * Kernel tests bind cache bins to cache.backend.memory, which honours
 * tags, so Cache::PERMANENT and config:neo_favicon.settings invalidation
 * both behave.
 *
 * The module ships no config/install, so neo_favicon.settings is set
 * directly through the config factory. Schema for file and tags exists, so
 * strict schema checking is fine. public://neo-favicon is seeded by writing
 * files into it directly — unpacking is the subscriber's business and
 * already has its own test. For getImages() the files must be real PNGs
 * because getimagesize() reads them; the copies are core's image-1.png
 * (360×240) and image-test.png (40×20), which is what the preview test
 * already does. For getHtml() only file_exists() is consulted, so any
 * bytes will do.
 *
 * DOMDocument::loadHTML() on this fragment does not inject a
 * <meta http-equiv="Content-Type"> on PHP 8.3 / libxml 2.9, which is the
 * runtime these tests run against. The meta loop therefore emits only the
 * msapplication-TileColor tag from the configured markup. That is recorded
 * rather than assumed: the package-configured assertion is the exact
 * fragment getHtml() returns, so an implicit Content-Type meta would make
 * it fail.
 *
 * The cache tag is config:neo_favicon.settings but getImages() data comes
 * from the filesystem — a coupling that only holds because the extractor
 * runs on config-file save, and worth pinning.
 */
#[Group('neo_favicon')]
final class FaviconManagerTest extends KernelTestBase {

  /**
   * The directory a favicon package is unpacked into.
   */
  private const DIRECTORY = 'public://neo-favicon';

  /**
   * The URI of the 360×240 fixture.
   */
  private const LARGE_URI = 'public://neo-favicon/image-1.png';

  /**
   * The URI of the 40×20 fixture.
   */
  private const SMALL_URI = 'public://neo-favicon/image-test.png';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'file', 'neo_config_file', 'neo_favicon'];

  /**
   * {@inheritdoc}
   *
   * The inherited set-up maps the public stream onto a virtual filesystem, on
   * which `realpath()` answers nothing, so a file at `public://` never
   * reaches `file_exists()` or `getimagesize()`. A real site directory is
   * what core's own file tests use for the same reason, and what this
   * module's other kernel tests already do.
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
    // The boot, asserted before any behaviour is: the modules the services
    // file names are present, and the manager service still constructs.
    $moduleHandler = $this->container->get('module_handler');
    $this->assertTrue($moduleHandler->moduleExists('system'));
    $this->assertTrue($moduleHandler->moduleExists('file'));
    $this->assertTrue($moduleHandler->moduleExists('neo_config_file'));
    $this->assertTrue($moduleHandler->moduleExists('neo_favicon'));
    $this->assertInstanceOf(FaviconManager::class, $this->container->get('neo_favicon.manager'));
  }

  /**
   * No package, and tags without a file, both return an empty string.
   *
   * The value has to survive a string return type: asserting "no exception"
   * would still pass if getHtml() handed an array back and the type hint
   * were ever dropped. The `?: ''` on the last assignment is what turns
   * the unused $data array into this string.
   *
   * Covers: getHtml() returns '' when neo_favicon.settings is empty, and
   * again when tags are set but file is unset.
   */
  public function testGetHtmlReturnsEmptyStringWhenNothingIsConfigured(): void {
    $this->assertSame('', $this->manager()->getHtml());

    $this->config('neo_favicon.settings')
      ->set('tags', $this->tags())
      ->save();

    $this->assertSame('', $this->manager()->getHtml());
  }

  /**
   * An empty package does not write the neo_favicon cache entry.
   *
   * The cache->set() sits inside `if ($file && $tags)`. A helpful move of
   * that line outside the branch would cache an empty string (or the unused
   * array) and this would go red.
   *
   * Covers: getHtml() writes no cache entry when nothing is configured.
   */
  public function testGetHtmlDoesNotCacheWhenNothingIsConfigured(): void {
    $this->assertSame('', $this->manager()->getHtml());
    $this->assertFalse($this->container->get('cache.default')->get('neo_favicon'));

    $this->config('neo_favicon.settings')
      ->set('tags', $this->tags())
      ->save();

    $this->assertSame('', $this->manager()->getHtml());
    $this->assertFalse($this->container->get('cache.default')->get('neo_favicon'));
  }

  /**
   * A configured package rewrites existing files and drops the rest.
   *
   * The path checked is `'public://neo-favicon' . $href`, so only an href
   * starting with `/` ever resolves. A relative href="favicon.png" is
   * dropped even when that file is on disk — that documents the real
   * contract, and flags it if someone later makes the concatenation
   * separator-safe. A link whose target is missing is absent entirely.
   * The <meta> comes back regardless, since that loop does no existence
   * check.
   *
   * Covers: getHtml() rewrites hrefs through FileUrlGenerator for files
   * that exist, drops missing and relative hrefs, and keeps meta tags.
   */
  public function testGetHtmlRewritesExistingLinksDropsMissingAndKeepsMeta(): void {
    $this->installConfiguredPackage();

    $html = $this->manager()->getHtml();

    $this->assertStringNotContainsString('favicon-16x16.png', $html);
    $this->assertStringNotContainsString('href="favicon.png"', $html);
    $this->assertStringNotContainsString($this->fileUrl('favicon.png'), $html);
    $this->assertSame($this->expectedHtml(['favicon-32x32.png', 'apple-touch-icon.png']), $html);
  }

  /**
   * HTML output is cached, and the config tag is what releases it.
   *
   * A second fresh manager still returns the identical string after a
   * seeded file is deleted — the config tag does not cover the filesystem.
   * Invalidating config:neo_favicon.settings, rather than re-saving
   * config, is what makes the third manager drop the deleted file's link
   * without involving ConfigFactory.
   *
   * Covers: getHtml() caches under neo_favicon, and invalidating
   * config:neo_favicon.settings rebuilds from disk.
   */
  public function testGetHtmlCacheSurvivesFileDeletionUntilTagInvalidation(): void {
    $this->installConfiguredPackage();
    $hrefApple = $this->fileUrl('apple-touch-icon.png');

    $first = $this->manager()->getHtml();
    unlink(self::DIRECTORY . '/apple-touch-icon.png');
    $this->assertSame($first, $this->manager()->getHtml());

    $this->container->get('cache_tags.invalidator')
      ->invalidateTags(['config:neo_favicon.settings']);

    $after = $this->manager()->getHtml();
    $this->assertStringNotContainsString($hrefApple, $after);
    $this->assertSame($this->expectedHtml(['favicon-32x32.png']), $after);
  }

  /**
   * A missing favicon directory is an empty list, not an exception.
   *
   * Covers: getImages() returns [] when public://neo-favicon does not
   * exist. The early return skips cache->set(); an empty list must not
   * outlive the next upload, so this path is not asserted to populate
   * the cache.
   */
  public function testGetImagesReturnsEmptyArrayWhenDirectoryIsAbsent(): void {
    $this->assertSame([], $this->manager()->getImages());
  }

  /**
   * PNGs are listed with their real dimensions; other files are not.
   *
   * The dimensions matter: neo_favicon_neo_token_logo_alter() picks the
   * largest by width * height, and the toolbar item previews on a width
   * threshold.
   *
   * Covers: getImages() is keyed by public:// URI, holds only the PNGs,
   * and carries the real width and height.
   */
  public function testGetImagesListsPngsWithDimensions(): void {
    $this->seedPngs();
    $this->seed(['readme.txt' => 'not a png']);

    $images = $this->manager()->getImages();

    $this->assertCount(2, $images);
    $this->assertSame(['width' => 360, 'height' => 240], $images[self::LARGE_URI]);
    $this->assertSame(['width' => 40, 'height' => 20], $images[self::SMALL_URI]);
    $this->assertArrayNotHasKey(self::DIRECTORY . '/readme.txt', $images);
  }

  /**
   * Image listing is cached, and the config tag is what releases it.
   *
   * The cache tag is config:neo_favicon.settings but the data comes from
   * the filesystem — a coupling that only holds because the extractor
   * runs on config-file save.
   *
   * Covers: getImages() caches under neo_favicon:images, and invalidating
   * config:neo_favicon.settings rebuilds from disk.
   */
  public function testGetImagesCacheSurvivesNewFileUntilTagInvalidation(): void {
    $this->seedPngs();
    $this->seed(['readme.txt' => 'not a png']);

    $this->assertCount(2, $this->manager()->getImages());
    copy($this->root . '/core/tests/fixtures/files/image-1.png', self::DIRECTORY . '/extra.png');
    $this->assertCount(2, $this->manager()->getImages());

    $this->container->get('cache_tags.invalidator')
      ->invalidateTags(['config:neo_favicon.settings']);

    $images = $this->manager()->getImages();
    $this->assertCount(3, $images);
    $this->assertArrayHasKey(self::DIRECTORY . '/extra.png', $images);
    $this->assertSame(['width' => 360, 'height' => 240], $images[self::DIRECTORY . '/extra.png']);
  }

  /**
   * Builds a fresh manager over the container's own services.
   *
   * FaviconManager memoises into $this->data and $this->images, so every
   * cache assertion needs a new instance over the same cache.default.
   *
   * @return \Drupal\neo_favicon\FaviconManager
   *   A manager that has not answered getHtml() or getImages() yet.
   */
  private function manager(): FaviconManager {
    return new FaviconManager(
      $this->container->get('config.factory'),
      $this->container->get('file_system'),
      $this->container->get('file_url_generator'),
      $this->container->get('cache.default'),
    );
  }

  /**
   * Sets a configured package and seeds the files some of its links point at.
   *
   * The favicon-32x32.png and apple-touch-icon.png files exist so those
   * links are rewritten. The favicon.png file exists so a separator-safe
   * concatenation would start resolving the relative href. The
   * favicon-16x16.png file is deliberately absent.
   */
  private function installConfiguredPackage(): void {
    $this->config('neo_favicon.settings')
      ->set('file', 'public://favicons.zip')
      ->set('tags', $this->tags())
      ->save();
    $this->seed([
      'favicon-32x32.png' => 'png-bytes',
      'apple-touch-icon.png' => 'png-bytes',
      'favicon.png' => 'png-bytes',
    ]);
  }

  /**
   * Real Favicon Generator-shaped markup with mixed hrefs.
   *
   * @return string
   *   Several link tags plus a Windows tile-color meta.
   */
  private function tags(): string {
    return <<<'HTML'
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" href="favicon.png">
<meta name="msapplication-TileColor" content="#da532c">
HTML;
  }

  /**
   * The fragment getHtml() emits for the given files, in link then meta order.
   *
   * @param array<int, string> $filenames
   *   Filenames under the favicon directory whose links should appear, in
   *   document order.
   *
   * @return string
   *   The markup, including the trailing newline implode() adds.
   */
  private function expectedHtml(array $filenames): string {
    $lines = [];
    foreach ($filenames as $filename) {
      $href = $this->fileUrl($filename);
      $lines[] = match ($filename) {
        'favicon-32x32.png' => '<link rel="icon" type="image/png" sizes="32x32" href="' . $href . '"/>',
        'apple-touch-icon.png' => '<link rel="apple-touch-icon" sizes="180x180" href="' . $href . '"/>',
        default => throw new \InvalidArgumentException($filename),
      };
    }
    $lines[] = '<meta name="msapplication-TileColor" content="#da532c"/>';
    return implode(PHP_EOL, $lines) . PHP_EOL;
  }

  /**
   * The public URL FileUrlGenerator would write into an href.
   *
   * @param string $filename
   *   A filename under the favicon directory.
   *
   * @return string
   *   The generated string, which is what getHtml() puts in href.
   */
  private function fileUrl(string $filename): string {
    return $this->container->get('file_url_generator')
      ->generateString(self::DIRECTORY . '/' . $filename);
  }

  /**
   * Copies core's PNG fixtures into the favicon directory.
   *
   * Core's image-1.png is 360×240 and image-test.png is 40×20. Both are
   * real PNGs, so getimagesize() can read them.
   */
  private function seedPngs(): void {
    if (!is_dir(self::DIRECTORY)) {
      mkdir(self::DIRECTORY, 0777, TRUE);
    }
    copy($this->root . '/core/tests/fixtures/files/image-1.png', self::LARGE_URI);
    copy($this->root . '/core/tests/fixtures/files/image-test.png', self::SMALL_URI);
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

}
