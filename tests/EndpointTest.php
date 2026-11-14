<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Cards;
use IndianOpinion\ShareCards\FileLockProvider;
use IndianOpinion\ShareCards\Key;
use IndianOpinion\ShareCards\RenderBudget;
use IndianOpinion\ShareCards\Renderer;
use WpStub;

/**
 * T17 (query form), T20 to T33, T43, T66 and T67: the card endpoint.
 */
final class EndpointTest extends TestCase
{
    private CountingRenderer $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addPost(10, 'Budget passes after a long night');
        $this->addAttachment(20, 1600, 900, true);
        $this->setFeatured(10, 20);
        $this->useCounter(new CountingRenderer());
    }

    private function useCounter(CountingRenderer $renderer): void
    {
        $this->counter = $renderer;
        remove_all_filters('share_cards_renderer');
        add_filter('share_cards_renderer', fn (?Renderer $r): Renderer => $this->counter);
        $this->newPlugin();
    }

    private function key(): Key
    {
        return $this->keyForPost(10);
    }

    public function testT21ColdMissRendersAndServes(): void
    {
        $key = $this->key();
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(200, $r->status);
        self::assertSame('render', $r->header('X-Share-Card'));
        self::assertFileExists($this->cacheDir() . '/' . $key->filename());
        self::assertSame(1, $this->counter->calls);
        self::assertSame(file_get_contents($this->cacheDir() . '/' . $key->filename()), $r->body());
    }

    public function testT20WarmHitHeadersHeadAndConditional(): void
    {
        $key = $this->key();
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        $path = $this->cacheDir() . '/' . $key->filename();

        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(200, $r->status);
        self::assertSame('image/jpeg', $r->header('Content-Type'));
        self::assertSame((string) filesize($path), $r->header('Content-Length'));
        self::assertSame('public, max-age=31536000, immutable', $r->header('Cache-Control'));
        self::assertSame('"' . $key->hash() . '-' . filesize($path) . '"', $r->header('ETag'));
        self::assertSame(gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT', $r->header('Last-Modified'));
        self::assertSame('nosniff', $r->header('X-Content-Type-Options'));
        self::assertSame('hit', $r->header('X-Share-Card'));
        self::assertNull($r->header('Set-Cookie'));
        self::assertSame(file_get_contents($path), $r->body());
        self::assertSame(1, $this->counter->calls);

        $head = $this->plugin->endpoint()->handle($key->toString(), 'HEAD');
        self::assertSame(200, $head->status);
        self::assertSame('', $head->body());
        self::assertSame($r->header('Content-Length'), $head->header('Content-Length'));

        $notModified = $this->plugin->endpoint()->handle($key->toString(), 'GET', (string) $r->header('ETag'));
        self::assertSame(304, $notModified->status);
        self::assertSame('', $notModified->body());
        $weak = $this->plugin->endpoint()->handle($key->toString(), 'GET', 'W/' . $r->header('ETag'));
        self::assertSame(304, $weak->status);
    }

    public function testOtherMethodsGet405(): void
    {
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'POST');
        self::assertSame(405, $r->status);
        self::assertSame('GET, HEAD', $r->header('Allow'));
        self::assertSame(0, $this->counter->calls);
    }

    public function testT22StaleHashRedirectsWithoutWriting(): void
    {
        $current = $this->key();
        $stale = Key::fromParts('post', '10', $current->hash() === '0000000000' ? '1111111111' : '0000000000');
        $r = $this->plugin->endpoint()->handle($stale->toString(), 'GET');
        self::assertSame(302, $r->status);
        self::assertSame('https://example.test/share-card/' . $current->filename(), $r->header('Location'));
        self::assertSame('public, max-age=300', $r->header('Cache-Control'));
        self::assertSame('stale', $r->header('X-Share-Card'));
        self::assertFileDoesNotExist($this->cacheDir() . '/' . $stale->filename());
        self::assertSame(0, $this->counter->calls);
    }

    public function testT43StaleAfterSettingsChange(): void
    {
        $old = $this->key();
        $this->plugin->endpoint()->handle($old->toString(), 'GET');
        $this->settings(['colour_background' => '#000000']);
        $this->useCounter($this->counter);
        $new = $this->key();
        self::assertNotSame($old->hash(), $new->hash());
        $r = $this->plugin->endpoint()->handle($old->toString(), 'GET');
        self::assertSame(302, $r->status);
        self::assertStringEndsWith($new->filename(), (string) $r->header('Location'));
    }

    public function testT23MalformedKeysThroughTheQueryForm(): void
    {
        WpStub::$options['permalink_structure'] = '';
        $this->newPlugin();
        foreach (['Post-1-0123456789', 'post-01-0123456789', 'post-1-ABCDEF0123', 'post-1-abc.jpg', 'post-1-0123456789.png', '../x', 'post-1-0123456789/x', 'post-' . str_repeat('1', 21) . '-0123456789'] as $raw) {
            $r = $this->plugin->responseFor([Cards::QUERY_VAR => $raw]);
            self::assertNotNull($r);
            self::assertSame(404, $r->status, $raw);
            self::assertSame('not-found', $r->header('X-Share-Card'));
        }
        $r = $this->plugin->responseFor([Cards::QUERY_VAR => ['array']]);
        self::assertSame(404, $r?->status);
        self::assertSame(0, $this->counter->calls);
    }

    public function testT17PlainPermalinksUseTheQueryForm(): void
    {
        WpStub::$options['permalink_structure'] = '';
        $this->newPlugin();
        $key = $this->key();
        $url = $this->plugin->cards()->url($key);
        self::assertSame('https://example.test/?share_card=' . $key->toString(), $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $vars);
        $r = $this->plugin->responseFor($vars);
        self::assertSame(200, $r?->status);
        self::assertNull($this->plugin->responseFor(['p' => '1']));
    }

    public function testT24NotFoundResponsesAreIdentical(): void
    {
        $this->addPost(11, 'Draft', ['post_status' => 'draft']);
        $this->addTerm(9, 'Private', 'topic');
        $draftKey = $this->plugin->cards()->keyFor($this->plugin->resolver()->resolvePost(WpStub::$posts[11]));
        $responses = [
            $this->plugin->endpoint()->handle($draftKey->toString(), 'GET'),
            $this->plugin->endpoint()->handle('post-999-0123456789', 'GET'),
            $this->plugin->endpoint()->handle('nosuch-1-0123456789', 'GET'),
            $this->plugin->endpoint()->handle('term-9-0123456789', 'GET'),
            $this->plugin->endpoint()->handle('post-10-xyz', 'GET'),
        ];
        foreach ($responses as $r) {
            self::assertSame(404, $r->status);
            self::assertSame('public, max-age=60', $r->header('Cache-Control'));
            self::assertSame($responses[0]->headers, $r->headers);
            self::assertSame('', $r->body());
        }
        self::assertSame(0, $this->counter->calls);
    }

    public function testT25QueryStringIsIgnored(): void
    {
        $key = $this->key();
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        $plain = $this->plugin->responseFor([Cards::QUERY_VAR => $key->toString()]);
        $withQuery = $this->plugin->responseFor([Cards::QUERY_VAR => $key->toString(), 'w' => '9999', 'x' => '1']);
        self::assertEquals($plain, $withQuery);
        self::assertSame('hit', $withQuery?->header('X-Share-Card'));
    }

    public function testT26RenderFailureMarksAndFallsBack(): void
    {
        $this->useCounter(new CountingRenderer(null, true));
        $key = $this->key();
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(302, $r->status);
        self::assertSame('fallback-error', $r->header('X-Share-Card'));
        self::assertSame('public, max-age=60', $r->header('Cache-Control'));
        self::assertSame('https://example.test/wp-content/uploads/img-20.jpg', $r->header('Location'));
        self::assertFileExists($this->cacheDir() . '/' . $key->toString() . '.fail');
        self::assertCount(1, $this->logsMatching('render failed key=' . $key->toString()));
        self::assertSame([], glob($this->cacheDir() . '/*.tmp') ?: []);
    }

    public function testT27FailureMarkerBlocksUntilItsTtl(): void
    {
        $this->useCounter(new CountingRenderer(null, true));
        $key = $this->key();
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(1, $this->counter->calls);
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame('fallback-error', $r->header('X-Share-Card'));
        self::assertSame(1, $this->counter->calls, 'no render while the marker is fresh');

        $this->clock->time += 601;
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(2, $this->counter->calls);
    }

    public function testFallbackWithoutAnyImageIs404NeverHtml(): void
    {
        unset(WpStub::$meta[10]['_thumbnail_id']);
        $this->useCounter(new CountingRenderer(null, true));
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        self::assertSame(404, $r->status);
        self::assertSame('fallback-error', $r->header('X-Share-Card'));
        self::assertSame('public, max-age=60', $r->header('Cache-Control'));
        // A filter returning an HTML page is refused too.
        add_filter('share_cards_fallback_url', static fn (): string => 'not a url');
        $this->clock->time += 601;
        self::assertSame(404, $this->plugin->endpoint()->handle($this->key()->toString(), 'GET')->status);
    }

    public function testT28WaiterFindsTheFileOthersRendered(): void
    {
        $key = $this->key();
        $locks = new FileLockProvider($this->cacheDir() . '/.locks', $this->clock);
        $held = $locks->acquire($key->toString(), 0);
        self::assertNotNull($held);
        // While we wait, "the other process" finishes the card and releases.
        $this->clock->onSleep = function (int $n) use ($held, $key): void {
            if ($n === 2) {
                file_put_contents($this->cacheDir() . '/' . $key->filename(), (string) file_get_contents($this->uploads . '/img-20.jpg'));
                $held->release();
            }
        };
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(200, $r->status);
        self::assertSame('hit', $r->header('X-Share-Card'));
        self::assertSame(0, $this->counter->calls);
    }

    public function testT28TwoProcessesOneRender(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is not available.');
        }
        $counterFile = $this->tmp . '/renders.txt';
        $slow = new CountingRenderer(null, false, $counterFile);
        $slow->during = static fn () => usleep(300000);
        remove_all_filters('share_cards_renderer');
        add_filter('share_cards_renderer', static fn (): Renderer => $slow);
        $this->plugin = new \IndianOpinion\ShareCards\Plugin(null, function (string $l): void {
            $this->logs[] = $l;
        });
        \IndianOpinion\ShareCards\Plugin::setInstance($this->plugin);
        $key = $this->key();

        $pid = pcntl_fork();
        if ($pid === 0) {
            $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
            file_put_contents($this->tmp . '/child.txt', $r->status . ' ' . $r->header('X-Share-Card'));
            // Leave without running PHPUnit's shutdown in the child.
            posix_kill(getmypid(), SIGKILL);
        }
        usleep(50000);
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        pcntl_waitpid($pid, $status);
        $child = (string) file_get_contents($this->tmp . '/child.txt');
        self::assertSame(200, $r->status);
        self::assertStringStartsWith('200 ', $child);
        self::assertSame(1, substr_count((string) file_get_contents($counterFile), "\n"), 'exactly one render');
        self::assertContains('hit', [$r->header('X-Share-Card'), substr($child, 4)]);
    }

    public function testT28StampedeOfEightProcessesRendersOnce(): void
    {
        $counterFile = $this->tmp . '/renders.txt';
        $slow = new CountingRenderer(null, false, $counterFile);
        $slow->during = static fn () => usleep(300000);
        remove_all_filters('share_cards_renderer');
        add_filter('share_cards_renderer', static fn (): Renderer => $slow);
        $this->plugin = new \IndianOpinion\ShareCards\Plugin(null, function (string $l): void {
            $this->logs[] = $l;
        });
        \IndianOpinion\ShareCards\Plugin::setInstance($this->plugin);
        $key = $this->key();

        $out = $this->inProcesses(8, function () use ($key): string {
            $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
            return $r->status . ' ' . $r->header('X-Share-Card');
        });
        sort($out);
        self::assertSame(['200 hit', '200 hit', '200 hit', '200 hit', '200 hit', '200 hit', '200 hit', '200 render'], $out);
        self::assertSame(1, substr_count((string) file_get_contents($counterFile), "\n"), 'exactly one render');
        self::assertSame([], glob($this->cacheDir() . '/.*.tmp') ?: [], 'no temporary file left');
    }

    public function testT31BudgetHoldsAcrossProcesses(): void
    {
        // Stay inside one wall-clock minute for the whole test.
        $second = (int) date('s');
        if ($second > 50) {
            sleep(61 - $second);
        }
        $path = $this->tmp . '/.budget';
        $out = $this->inProcesses(8, static function () use ($path): string {
            $budget = new RenderBudget($path, 10);
            $started = 0;
            for ($i = 0; $i < 5; $i++) {
                $started += $budget->tryStart() ? 1 : 0;
            }
            return (string) $started;
        });
        self::assertSame(10, array_sum(array_map('intval', $out)), 'exactly the budget, across 40 attempts in 8 processes');
        self::assertSame(10, (new RenderBudget($path, 10))->current());
    }

    /**
     * Runs `$work` in `$n` forked processes that start together, and returns
     * what each returned.
     *
     * @param callable(): string $work
     * @return list<string>
     */
    private function inProcesses(int $n, callable $work): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are needed.');
        }
        $go = $this->tmp . '/go';
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('fork failed');
            }
            if ($pid === 0) {
                while (!is_file($go)) {
                    usleep(1000);
                    clearstatcache(true, $go);
                }
                file_put_contents($this->tmp . '/out-' . $i . '.txt', $work());
                // Leave without running PHPUnit's shutdown in the child.
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        touch($go);
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = (string) file_get_contents($this->tmp . '/out-' . $i . '.txt');
        }
        return $out;
    }

    public function testT29WaiterTimesOut(): void
    {
        $key = $this->key();
        $held = (new FileLockProvider($this->cacheDir() . '/.locks', $this->clock))->acquire($key->toString(), 0);
        self::assertNotNull($held);
        $start = $this->clock->now();
        $r = $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame(302, $r->status);
        self::assertSame('fallback-busy', $r->header('X-Share-Card'));
        self::assertLessThanOrEqual(2.1, $this->clock->now() - $start);
        self::assertGreaterThanOrEqual(2.0, $this->clock->now() - $start);
        self::assertSame(0, $this->counter->calls);
        $held->release();
    }

    public function testT30AllSlotsHeld(): void
    {
        $locks = new FileLockProvider($this->cacheDir() . '/.locks', $this->clock);
        $a = $locks->acquire('slot-0', 0);
        $b = $locks->acquire('slot-1', 0);
        self::assertNotNull($a);
        self::assertNotNull($b);
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        self::assertSame('fallback-slots', $r->header('X-Share-Card'));
        self::assertSame(0, $this->counter->calls);
        $a->release();
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        self::assertSame('render', $r->header('X-Share-Card'));
        $b->release();
    }

    public function testT31BudgetSpent(): void
    {
        mkdir($this->cacheDir(), 0755, true);
        file_put_contents($this->cacheDir() . '/' . RenderBudget::FILENAME, intdiv((int) $this->clock->now(), 60) . ' 30');
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        self::assertSame('fallback-budget', $r->header('X-Share-Card'));
        self::assertSame(0, $this->counter->calls);

        // A new minute resets the counter.
        $this->clock->time += 60;
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        self::assertSame('render', $r->header('X-Share-Card'));
    }

    public function testBudgetCountsRenders(): void
    {
        $budget = new RenderBudget($this->tmp . '/.budget', 2, $this->clock);
        self::assertTrue($budget->tryStart());
        self::assertTrue($budget->tryStart());
        self::assertFalse($budget->tryStart());
        self::assertSame(2, $budget->current());
    }

    public function testT32RenderFinishesWhenTheClientHangsUp(): void
    {
        $this->counter->during = static function (): void {
            // By now the endpoint has asked PHP to keep going after an abort.
            self::assertSame(1, ignore_user_abort());
        };
        $previous = ignore_user_abort(false);
        $r = $this->plugin->endpoint()->handle($this->key()->toString(), 'GET');
        ignore_user_abort((bool) $previous);
        self::assertSame(200, $r->status);
        self::assertFileExists($this->cacheDir() . '/' . $this->key()->filename());
    }

    public function testT33NoFinalFileBeforeRename(): void
    {
        $key = $this->key();
        $final = $this->cacheDir() . '/' . $key->filename();
        $this->counter->during = function () use ($final): void {
            self::assertFileDoesNotExist($final);
            self::assertCount(0, glob($this->cacheDir() . '/*.jpg') ?: []);
        };
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertFileExists($final);
        self::assertSame([], glob($this->cacheDir() . '/.*.tmp') ?: []);
    }

    public function testT66NoRendererFallsBackUnavailable(): void
    {
        remove_all_filters('share_cards_renderer');
        add_filter('share_cards_capabilities', static fn (array $c): array => array_merge($c, ['gd' => false, 'imagick' => false]));
        $this->newPlugin();
        $r = $this->plugin->endpoint()->handle('post-10-0123456789', 'GET');
        self::assertSame(302, $r->status);
        self::assertSame('fallback-unavailable', $r->header('X-Share-Card'));
    }

    public function testT67UnwritableCacheFallsBack(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // Root ignores permissions; simulate through the capability flag.
            add_filter('share_cards_capabilities', static fn (array $c): array => array_merge($c, ['cache_writable' => false]));
        } else {
            mkdir($this->cacheDir(), 0555);
        }
        $this->newPlugin();
        $r = $this->plugin->endpoint()->handle('post-10-0123456789', 'GET');
        self::assertSame('fallback-unwritable', $r->header('X-Share-Card'));
        $this->viewPost(WpStub::$posts[10]);
        $this->addAttachment(20, 300, 200, true);
        self::assertSame('featured', $this->plugin->chooser()->forRequest()['source'] ?? null);
    }

    public function testRenderedActionAndLogLine(): void
    {
        $seen = [];
        add_action('share_cards_rendered', static function (string $key, string $path, int $ms, string $name) use (&$seen): void {
            $seen[] = [$key, basename($path), $name];
        }, 10, 4);
        $this->settings(['log_level' => 'info']);
        $this->useCounter($this->counter);
        $key = $this->key();
        $this->plugin->endpoint()->handle($key->toString(), 'GET');
        self::assertSame([[$key->toString(), $key->filename(), 'gd']], $seen);
        self::assertCount(1, $this->logsMatching('render key=' . $key->toString()));
    }

    public function testPreviousPrefixRedirectsForThirtyDays(): void
    {
        $key = $this->key();
        update_option('share_cards_previous_prefix', ['prefix' => 'og', 'until' => (int) $this->clock->now() + 86400]);
        $this->newPlugin();
        $r = $this->plugin->responseFor([Cards::PREVIOUS_QUERY_VAR => $key->toString()]);
        self::assertSame(302, $r?->status);
        self::assertSame('https://example.test/share-card/' . $key->filename(), $r?->header('Location'));
        $this->clock->time += 86401;
        $r = $this->plugin->responseFor([Cards::PREVIOUS_QUERY_VAR => $key->toString()]);
        self::assertSame(404, $r?->status);
    }
}
