<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Cli;
use WP_CLI;
use WpCliExit;

/**
 * T71 to T76: WP-CLI commands.
 */
final class CliTest extends TestCase
{
    private Cli $cli;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cli = new Cli($this->plugin);
        // Posts 1 to 5 need cards, post 6 has a good featured image.
        for ($i = 1; $i <= 6; $i++) {
            $this->addPost($i, 'Story ' . $i);
        }
        $this->addAttachment(60, 1600, 900);
        $this->setFeatured(6, 60);
        $this->addTerm(7, 'Politics', 'category', 4);
        $this->addTerm(8, 'Empty', 'category', 0);
    }

    private function cliOutput(): string
    {
        return implode("\n", WP_CLI::$out);
    }

    /**
     * @return list<string>
     */
    private function cards(): array
    {
        return array_map('basename', glob($this->cacheDir() . '/*.jpg') ?: []);
    }

    public function testT71WarmDryRunWritesNothing(): void
    {
        $this->cli->warm([], ['posts' => '3', 'terms' => true, 'dry-run' => true]);
        self::assertSame([], $this->cards());
        self::assertStringContainsString('Would render: 4, skipped: 0, failed: 0.', $this->cliOutput());
        self::assertStringContainsString('post-5-', $this->cliOutput());
        self::assertStringContainsString('term-7-', $this->cliOutput());
        self::assertStringNotContainsString('term-8-', $this->cliOutput());
    }

    public function testT71WarmRendersAndCounts(): void
    {
        $this->cli->warm([], ['posts' => '3', 'terms' => true]);
        self::assertCount(4, $this->cards());
        self::assertStringContainsString('Rendered: 4, skipped: 0, failed: 0.', $this->cliOutput());
        WP_CLI::$out = [];
        $this->cli->warm([], ['posts' => '6']);
        // Post 6 never needs a card; 1 to 5 exist or get rendered.
        self::assertStringContainsString('Rendered: 2, skipped: 3, failed: 0.', $this->cliOutput());
    }

    public function testT72StatusPrintsEveryField(): void
    {
        $this->cli->status([], []);
        $out = $this->cliOutput();
        foreach (['Renderer:', 'GD:', 'JPEG', 'PNG', 'WebP', 'FreeType', 'Imagick:', 'Pango:', 'Font regular:', 'Font bold:', 'readable', 'Cache directory:', 'Cards: 0 files', 'Rewrite rule:', 'Last garbage collection: never'] as $needle) {
            self::assertStringContainsString($needle, $out);
        }
    }

    public function testT73RenderToAFileLeavesTheCacheAlone(): void
    {
        $out = $this->tmp . '/x.jpg';
        $this->cli->render(['post:5'], ['out' => $out]);
        self::assertFileExists($out);
        self::assertSame([], $this->cards());
        self::assertMatchesRegularExpression('/in \d+ ms, peak memory/', $this->cliOutput());

        $this->cli->render(['post:5'], []);
        self::assertCount(1, $this->cards());
        $this->cli->render(['post:5'], []);
        self::assertStringContainsString('Card exists', $this->cliOutput());
        $this->cli->render(['post:5'], ['force' => true]);
        self::assertCount(1, $this->cards());
    }

    public function testRenderRejectsBadTargets(): void
    {
        $this->addPost(9, 'Draft', ['post_status' => 'draft']);
        foreach (['post', 'post:abc', 'post:9', 'post:404'] as $target) {
            try {
                $this->cli->render([$target], []);
                self::fail('Expected an error for ' . $target);
            } catch (WpCliExit) {
                self::assertStringContainsString('Error:', end(WP_CLI::$out) ?: '');
            }
        }
    }

    public function testT74GcDryRunDeletesNothing(): void
    {
        mkdir($this->cacheDir(), 0755, true);
        file_put_contents($this->cacheDir() . '/post-99-0123456789.jpg', 'x');
        $this->cli->gc([], ['dry-run' => true]);
        self::assertFileExists($this->cacheDir() . '/post-99-0123456789.jpg');
        self::assertStringContainsString('Dry run:', $this->cliOutput());
        self::assertStringContainsString('gone 1', $this->cliOutput());
        $this->cli->gc([], []);
        self::assertFileDoesNotExist($this->cacheDir() . '/post-99-0123456789.jpg');
    }

    public function testT75FlushNeedsYesAndRemovesOnlyPluginFiles(): void
    {
        $this->cli->render(['post:5'], []);
        file_put_contents($this->cacheDir() . '/holiday.jpg', 'mine');
        file_put_contents($this->cacheDir() . '/post-5-0123456789.fail', '');
        try {
            $this->cli->flush([], []);
            self::fail('Expected a refusal.');
        } catch (WpCliExit) {
            self::assertCount(2, $this->cards());
        }
        $purged = [];
        add_action('share_cards_purged', static function (array $urls, string $reason) use (&$purged): void {
            $purged = [$urls, $reason];
        }, 10, 2);
        $this->cli->flush([], ['yes' => true]);
        self::assertSame(['holiday.jpg'], $this->cards());
        self::assertFileDoesNotExist($this->cacheDir() . '/post-5-0123456789.fail');
        self::assertSame('flush', $purged[1] ?? null);
    }

    public function testT76UrlPrintsCardUrlAndAdvertisedImage(): void
    {
        $this->cli->url(['post:5'], []);
        self::assertMatchesRegularExpression('#Card URL: https://example\.test/share-card/post-5-[0-9a-f]{10}\.jpg#', $this->cliOutput());
        self::assertMatchesRegularExpression('#Advertised image: https://example\.test/share-card/post-5-[0-9a-f]{10}\.jpg \(1200x630, card\)#', $this->cliOutput());
        WP_CLI::$out = [];
        $this->cli->url(['post:6'], []);
        self::assertStringContainsString('Advertised image: https://example.test/wp-content/uploads/img-60.jpg (1600x900, featured)', $this->cliOutput());
        WP_CLI::$out = [];
        $this->cli->url(['term:7'], []);
        self::assertStringContainsString('term-7-', $this->cliOutput());
    }

    public function testCommandIsRegisteredUnderWpCli(): void
    {
        define('WP_CLI', true);
        $this->plugin->boot();
        self::assertArrayHasKey('share-cards', WP_CLI::$commands);
    }
}
