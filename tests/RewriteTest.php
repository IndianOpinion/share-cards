<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Rewrite;
use IndianOpinion\ShareCards\SettingsPage;
use IndianOpinion\ShareCards\SiteHealth;
use WpStub;

/**
 * T18, T19: rewrite rules, prefix changes and repair.
 */
final class RewriteTest extends TestCase
{
    public function testRuleRegisteredOnInitAtTop(): void
    {
        $this->plugin->onInit();
        self::assertSame([Rewrite::regex('share-card'), 'index.php?share_card=$matches[1]-$matches[2]-$matches[3]', 'top'], WpStub::$rewriteRules[0]);
        self::assertSame(0, WpStub::$flushes);
        self::assertContains('share_card', Rewrite::queryVars([]));
    }

    public function testT19NoFlushOnOrdinaryRequestsAndRepairWhenMissing(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->newPlugin()->onInit();
        }
        self::assertSame(0, WpStub::$flushes);

        // Another plugin flushed before ours registered: rule missing.
        WpStub::$options['rewrite_rules'] = ['^other$' => 'index.php'];
        self::assertSame('recommended', (new SiteHealth($this->plugin))->result('rewrite')['status']);
        $this->plugin->onAdminInit();
        self::assertSame(1, WpStub::$flushes);
        self::assertTrue($this->plugin->rewrite()->rulePresent());
        self::assertSame('good', (new SiteHealth($this->plugin))->result('rewrite')['status']);
        // A second admin load does not flush again.
        $this->plugin->onAdminInit();
        self::assertSame(1, WpStub::$flushes);
    }

    public function testPlainPermalinksNeedNoRule(): void
    {
        WpStub::$options['permalink_structure'] = '';
        self::assertTrue($this->plugin->rewrite()->rulePresent());
    }

    public function testT18PrefixChangeFlushesOnceAndNewUrlsUseIt(): void
    {
        $this->addPost(10);
        $this->viewPost(WpStub::$posts[10]);
        $page = new SettingsPage($this->plugin);
        $result = $page->processSave(['_wpnonce' => wp_create_nonce(SettingsPage::SAVE_ACTION), 'share_cards' => ['url_prefix' => 'og']]);
        self::assertTrue($result['ok']);
        self::assertSame(1, WpStub::$flushes);
        $this->newPlugin();
        self::assertMatchesRegularExpression('#^https://example\.test/og/post-10-[0-9a-f]{10}\.jpg$#', (string) ($this->plugin->chooser()->forRequest()['url'] ?? ''));

        // The previous prefix keeps routing for 30 days.
        $previous = $this->plugin->rewrite()->previous();
        self::assertSame('share-card', $previous['prefix'] ?? null);
        self::assertSame((int) $this->clock->now() + 30 * 86400, $previous['until'] ?? null);
        $this->plugin->rewrite()->register();
        $regexes = array_column(WpStub::$rewriteRules, 0);
        self::assertContains(Rewrite::regex('og'), $regexes);
        self::assertContains(Rewrite::regex('share-card'), $regexes);

        // Saving again without a prefix change does not flush.
        $page = new SettingsPage($this->plugin);
        $page->processSave(['_wpnonce' => wp_create_nonce(SettingsPage::SAVE_ACTION), 'share_cards' => ['url_prefix' => 'og']]);
        self::assertSame(1, WpStub::$flushes);
    }

    public function testInvalidPrefixIsRejected(): void
    {
        $page = new SettingsPage($this->plugin);
        $result = $page->processSave(['_wpnonce' => wp_create_nonce(SettingsPage::SAVE_ACTION), 'share_cards' => ['url_prefix' => '-Bad/Prefix']]);
        self::assertNotEmpty($result['errors']);
        self::assertSame(0, WpStub::$flushes);
        self::assertSame('share-card', $this->plugin->settings()->string('url_prefix'));
    }
}
