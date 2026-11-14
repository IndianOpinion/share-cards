<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Cache;
use IndianOpinion\ShareCards\Capabilities;
use IndianOpinion\ShareCards\GarbageCollector;
use IndianOpinion\ShareCards\Plugin;
use IndianOpinion\ShareCards\Settings;
use IndianOpinion\ShareCards\SiteHealth;
use WpStub;

/**
 * T66, T68, T70, T78: activation, environments, files, uninstall.
 */
final class LifecycleTest extends TestCase
{
    public function testT66ActivationWithoutGdOrImagick(): void
    {
        add_filter('share_cards_capabilities', static fn (array $c): array => array_merge($c, ['gd' => false, 'gd_jpeg' => false, 'imagick' => false, 'imagick_jpeg' => false]));
        $this->plugin->activate();
        self::assertArrayHasKey(Settings::OPTION, WpStub::$options);

        $health = (new SiteHealth($this->plugin))->result('renderer');
        self::assertSame('critical', $health['status']);
        self::assertStringContainsString('ext-gd', $health['description']);

        ob_start();
        (new \IndianOpinion\ShareCards\SettingsPage($this->plugin))->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('ext-gd', $html);
    }

    public function testMissingPiecesAreNamed(): void
    {
        self::assertSame(['JPEG support in GD', 'FreeType support in GD', 'ext-imagick (alternative)'], Capabilities::missing(['gd' => true, 'gd_jpeg' => false, 'gd_freetype' => false, 'imagick' => false]));
        self::assertSame([], Capabilities::missing(['gd' => true, 'gd_jpeg' => true, 'gd_freetype' => true]));
    }

    public function testActivationSchedulesCronAndFlushes(): void
    {
        $this->plugin->activate();
        self::assertArrayHasKey(Plugin::CRON_HOOK, WpStub::$cron);
        self::assertSame(1, WpStub::$flushes);
        self::assertFileExists($this->cacheDir() . '/index.html');
    }

    public function testT68StreamWrapperUploadsMoveTheCache(): void
    {
        WpStub::$uploadsBase = 's3://bucket/uploads';
        self::assertSame(WP_CONTENT_DIR . '/cache/share-cards', Cache::defaultDirectory());
        add_filter('share_cards_cache_dir', static fn (): string => '/srv/cards/');
        self::assertSame('/srv/cards', Cache::defaultDirectory());
    }

    public function testT70DirectoryAndFileModes(): void
    {
        $cache = new Cache($this->cacheDir());
        self::assertTrue($cache->ensure());
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->cacheDir())), -4));
        self::assertSame('', file_get_contents($this->cacheDir() . '/index.html'));
        $key = \IndianOpinion\ShareCards\Key::parse('post-1-0123456789');
        $old = umask(0077);
        try {
            $path = $cache->writeAtomic($key, static function (string $tmp): void {
                file_put_contents($tmp, 'jpeg');
            });
        } finally {
            umask($old);
        }
        self::assertSame('0644', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testT78DeactivateKeepsFilesAndUnschedules(): void
    {
        $this->plugin->activate();
        file_put_contents($this->cacheDir() . '/post-1-0123456789.jpg', 'x');
        $flushes = WpStub::$flushes;
        $this->plugin->deactivate();
        self::assertArrayNotHasKey(Plugin::CRON_HOOK, WpStub::$cron);
        self::assertFileExists($this->cacheDir() . '/post-1-0123456789.jpg');
        self::assertSame($flushes + 1, WpStub::$flushes);
    }

    public function testT78UninstallRemovesEverythingItMadeAndNothingElse(): void
    {
        $this->plugin->activate();
        $dir = $this->cacheDir();
        mkdir($dir . '/.locks', 0755, true);
        foreach (['post-1-0123456789.jpg', 'post-1-0123456789.fail', '.post-1-0123456789.abcdef012345.tmp', '.preview.jpg', '.budget'] as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        touch($dir . '/.locks/slot-0.lock');
        set_transient(Capabilities::TRANSIENT, ['gd' => true], 300);
        update_option(GarbageCollector::STATE_OPTION, ['cursor' => '']);
        $flushes = WpStub::$flushes;

        // First with a foreign file present: it stays, and so does the directory.
        file_put_contents($dir . '/holiday.jpg', 'mine');
        (new Plugin($this->clock))->uninstallSite();
        self::assertSame(['holiday.jpg'], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        self::assertArrayNotHasKey(Settings::OPTION, WpStub::$options);
        self::assertArrayNotHasKey(GarbageCollector::STATE_OPTION, WpStub::$options);
        self::assertFalse(get_transient(Capabilities::TRANSIENT));
        self::assertArrayNotHasKey(Plugin::CRON_HOOK, WpStub::$cron);
        self::assertSame($flushes + 1, WpStub::$flushes);

        // Without it, the empty directory goes too.
        unlink($dir . '/holiday.jpg');
        (new Plugin($this->clock))->uninstallSite();
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testT78UninstallOnMultisiteCleansEverySite(): void
    {
        // Site 1 is current; site 2 has its own uploads, options and cron.
        $this->plugin->activate();
        file_put_contents($this->cacheDir() . '/post-1-0123456789.jpg', 'x');
        WpStub::$options['rewrite_rules'] = ['site-one' => 'index.php'];
        $second = $this->tmp . '/uploads-2';
        mkdir($second . '/share-cards/.locks', 0755, true);
        file_put_contents($second . '/share-cards/term-7-abcdef0123.jpg', 'x');
        file_put_contents($second . '/share-cards/index.html', '');
        touch($second . '/share-cards/.locks/slot-1.lock');
        WpStub::saveSite();
        WpStub::$sites[2] = [
            'options' => [Settings::OPTION => ['url_prefix' => 'cards'], 'permalink_structure' => '/%year%/%postname%/', 'rewrite_rules' => ['site-two' => 'index.php']],
            'transients' => [Capabilities::TRANSIENT => [['gd' => true], 0]],
            'cron' => [Plugin::CRON_HOOK => 1],
            'uploadsBase' => $second,
        ];
        $flushes = WpStub::$flushes;

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'share-cards/share-cards.php');
        }
        require dirname(__DIR__) . '/uninstall.php';

        self::assertSame(1, WpStub::$blogId, 'the original site is current again');
        self::assertDirectoryDoesNotExist($this->cacheDir());
        self::assertDirectoryDoesNotExist($second . '/share-cards');
        WpStub::saveSite();
        foreach ([1, 2] as $id) {
            $site = WpStub::$sites[$id];
            self::assertArrayNotHasKey(Settings::OPTION, $site['options'], "site $id");
            self::assertArrayNotHasKey(Capabilities::TRANSIENT, $site['transients'], "site $id");
            self::assertArrayNotHasKey(Plugin::CRON_HOOK, $site['cron'], "site $id");
            // Never another site's rules: the stored rules are dropped and
            // rebuilt by WordPress on that site's next request.
            self::assertArrayNotHasKey('rewrite_rules', $site['options'], "site $id");
        }
        self::assertSame($flushes, WpStub::$flushes, 'no flush while switched');
    }

    public function testBootRegistersHooks(): void
    {
        WpStub::$isAdmin = true;
        $this->plugin->boot();
        foreach (['init', 'parse_request', 'wp_head', 'save_post', 'edited_term', 'delete_term', Plugin::CRON_HOOK, 'admin_menu', 'site_status_tests', 'admin_init'] as $hook) {
            self::assertArrayHasKey($hook, WpStub::$hooks, $hook);
        }
        self::assertArrayHasKey(5, WpStub::$hooks['wp_head']);
    }

    public function testSiteHealthReportsEveryTest(): void
    {
        $tests = (new SiteHealth($this->plugin))->addTests([]);
        self::assertCount(5, $tests['direct']);
        foreach ((new SiteHealth($this->plugin))->results() as $name => $result) {
            self::assertContains($result['status'], ['good', 'recommended', 'critical'], $name);
        }
    }
}
