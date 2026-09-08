<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_favicon\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_favicon\Plugin\ToolbarItem\Favicon;
use PHPUnit\Framework\Attributes\Group;

/**
 * Drives the favicon toolbar item form's image preview path.
 *
 * The preview loop in Favicon::itemForm() had no test at all, which is why a
 * LogicException on every configured site went unnoticed. Renderer::render()
 * needs an open render context, and the toolbar item's edit and add forms are
 * built during the controller step — before the page has one. The fix is
 * renderInIsolation(); this class is the seam that would have caught the
 * throw, and that goes red again if the previews are handed back to render()
 * plus Markup::create().
 *
 * It is a kernel test rather than a unit test because the loop really renders:
 * NeoImageStyle builds a theme hook, Tooltip applies attributes from the
 * settings repository, and FaviconManager::getImages() calls getimagesize()
 * on real PNG bytes. Doubling any of that would leave the preview untested,
 * and a test that never built a preview would not have caught this bug.
 *
 * Kernel tests resolve no info.yml dependencies, so the boot is enumerated by
 * hand and verified in set-up before anything else runs. The plugin extends
 * ToolbarItemPluginBase and uses Tooltip and NeoImageStyle, so neo_toolbar,
 * neo_tooltip and neo_image have to be named even though neo_favicon.info.yml
 * does not depend on neo_toolbar. neo_modal sits under neo_toolbar; token,
 * breakpoint, file and image are what those packages actually construct
 * from; neo and neo_settings are the floor neo_tooltip and neo_image stand
 * on; neo_config_file is this module's own declared dependency.
 *
 * neo.services.yml declares neo.linkit_resolver, which needs
 * path_alias.manager and plugin.manager.linkit.substitution, so the
 * container fails to compile without path_alias and linkit. They are not in
 * this module's info.yml either; they are named for the same reason neo's
 * own kernel tests name them.
 *
 * The two PNGs are copied from core's fixtures at run time. A committed
 * binary would put the thing under test into a file nobody can read in a
 * diff, and core already ships a 360×240 image (over the preview threshold)
 * and a 40×20 image (under it). Both are real PNGs, which getimagesize()
 * requires. The under-threshold file is load-bearing: without asserting it
 * is absent from the options, the test would pass vacuously if the loop
 * were ever gutted.
 *
 * The call goes straight at itemForm(), which is public on the class. That
 * keeps the test on the seam that breaks and away from element processing
 * and route access.
 */
#[Group('neo_favicon')]
final class FaviconItemFormPreviewTest extends KernelTestBase {

  /**
   * The directory a favicon package is unpacked into.
   */
  private const DIRECTORY = 'public://neo-favicon';

  /**
   * The URI of the 360×240 fixture, which is over the preview threshold.
   */
  private const OVER_URI = 'public://neo-favicon/image-1.png';

  /**
   * The URI of the 40×20 fixture, which is under the preview threshold.
   */
  private const UNDER_URI = 'public://neo-favicon/image-test.png';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'breakpoint',
    'token',
    'path_alias',
    'linkit',
    'neo',
    'neo_settings',
    'neo_image',
    'neo_tooltip',
    'neo_modal',
    'neo_toolbar',
    'neo_config_file',
    'neo_favicon',
  ];

  /**
   * {@inheritdoc}
   *
   * The inherited set-up maps the public stream onto a virtual filesystem, on
   * which `realpath()` answers nothing, so a PNG at `public://` never reaches
   * `getimagesize()`. A real site directory is what core's own file tests use
   * for the same reason, and what this module's other kernel test already
   * does for ZipArchive.
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
    // Tooltip::applyTo() reads neo_tooltip.settings, and the preview's
    // #theme => neo_image_style preprocess asks the image toolkit for
    // dimensions, so those defaults have to exist. neo_toolbar's own
    // config/install is deliberately not installed: one of its shipped items
    // is this plugin, and installing it would couple the boot to a toolbar
    // entity this method never uses.
    $this->installConfig(['system', 'image', 'neo_tooltip']);
    // The boot, asserted before any behaviour is: the modules the preview
    // path constructs from are present, and the plugin manager discovers the
    // favicon item rather than the test building it by hand.
    $moduleHandler = $this->container->get('module_handler');
    $this->assertTrue($moduleHandler->moduleExists('neo_favicon'));
    $this->assertTrue($moduleHandler->moduleExists('neo_toolbar'));
    $this->assertTrue($moduleHandler->moduleExists('neo_image'));
    $this->assertTrue($moduleHandler->moduleExists('neo_tooltip'));
    $this->assertTrue($this->container->get('plugin.manager.neo_toolbar_item')->hasDefinition('favicon'));
  }

  /**
   * Only images over 100px wide become rendered preview options.
   *
   * The call is the proof: itemForm() used to throw a render-context
   * LogicException the moment it had an image to preview. Returning is the
   * first assertion, and the options are the rest — Default plus the 360px
   * URI, not the 40px one, a MarkupInterface whose string contains an img
   * tag, and the tooltip library on the radios so isolation does not drop it.
   *
   * Covers: it builds image previews for favicon files wider than 100px
   * without throwing, and skips files at or under that threshold.
   */
  public function testItemFormBuildsImagePreviewsWithoutRenderContext(): void {
    $this->seed();
    $plugin = $this->container->get('plugin.manager.neo_toolbar_item')->createInstance('favicon');
    $this->assertInstanceOf(Favicon::class, $plugin);
    $form_state = new FormState();
    $complete_form = [];

    $form = $plugin->itemForm([], $form_state, $complete_form);

    $this->assertSame(['', self::OVER_URI], array_keys($form['image']['#options']));
    $this->assertArrayNotHasKey(self::UNDER_URI, $form['image']['#options']);
    $preview = $form['image']['#options'][self::OVER_URI];
    $this->assertInstanceOf(MarkupInterface::class, $preview);
    $this->assertStringContainsString('<img', (string) $preview);
    $this->assertContains('neo_tooltip/tooltip', $form['image']['#attached']['library']);
  }

  /**
   * Copies core's PNG fixtures into the favicon directory.
   *
   * Core's image-1.png is 360×240 and image-test.png is 40×20. Both are real
   * PNGs, so getimagesize() can read them, and the width split is the one
   * itemForm() uses to decide whether to build a preview.
   */
  private function seed(): void {
    if (!is_dir(self::DIRECTORY)) {
      mkdir(self::DIRECTORY, 0777, TRUE);
    }
    copy($this->root . '/core/tests/fixtures/files/image-1.png', self::OVER_URI);
    copy($this->root . '/core/tests/fixtures/files/image-test.png', self::UNDER_URI);
  }

}
