<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\GarbageCollector;
use IndianOpinion\ShareCards\Key;
use WpStub;

/**
 * T33 (orphaned temporaries) and T42: garbage collection.
 */
final class GarbageCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        mkdir($this->cacheDir() . '/.locks', 0755, true);
    }

    private function file(string $name, int $age = 0, int $bytes = 4): string
    {
        $path = $this->cacheDir() . '/' . $name;
        file_put_contents($path, str_repeat('x', $bytes));
        touch($path, (int) $this->clock->now() - $age);
        return $path;
    }

    public function testT33TemporaryFilesAgedOutAfterAnHour(): void
    {
        $old = $this->file('.post-10-0123456789.abcdef012345.tmp', 7200);
        $young = $this->file('.post-10-0123456789.0123456789ab.tmp', 600);
        $this->plugin->gc()->runFull();
        self::assertFileDoesNotExist($old);
        self::assertFileExists($young);
    }

    public function testT42RemovesWhatIsNotNeededAndNothingElse(): void
    {
        $this->addPost(10);
        $this->addPost(11, 'Draft', ['post_status' => 'draft']);
        $current = $this->file($this->keyForPost(10)->filename());
        $stale = $this->file('post-10-ffffffffff.jpg');
        $orphan = $this->file('post-999-0123456789.jpg');
        $unpublished = $this->file('post-11-0123456789.jpg');
        $expired = $this->file('post-10-ffffffffff.fail', 700);
        $fresh = $this->file('post-10-eeeeeeeeee.fail', 10);
        $lockOld = $this->cacheDir() . '/.locks/slot-0.lock';
        touch($lockOld, (int) $this->clock->now() - 90000);
        $lockNew = $this->cacheDir() . '/.locks/slot-1.lock';
        touch($lockNew, (int) $this->clock->now());
        $foreign = $this->file('holiday.jpg');
        $foreign2 = $this->file('post-10-0123456789.jpeg');
        $index = $this->file('index.html');

        $purged = [];
        add_action('share_cards_purged', static function (array $urls, string $reason) use (&$purged): void {
            $purged[] = $reason;
        }, 10, 2);

        $result = $this->plugin->gc()->runFull();
        self::assertFileExists($current);
        self::assertFileDoesNotExist($stale);
        self::assertFileDoesNotExist($orphan);
        self::assertFileDoesNotExist($unpublished);
        self::assertFileDoesNotExist($expired);
        self::assertFileExists($fresh);
        self::assertFileDoesNotExist($lockOld);
        self::assertFileExists($lockNew);
        self::assertFileExists($foreign);
        self::assertFileExists($foreign2);
        self::assertFileExists($index);
        self::assertSame(1, $result['removed']['stale']);
        self::assertSame(2, $result['removed']['gone']);
        self::assertSame(1, $result['removed']['marker']);
        self::assertSame(1, $result['removed']['lock']);
        self::assertSame(['gc'], $purged);
        self::assertNotNull($this->plugin->gc()->state()['last']);
    }

    public function testT42ResumesFromItsCursor(): void
    {
        add_filter('share_cards_gc_batch', static fn (): int => 2);
        $this->addPost(10);
        $names = ['post-990-0123456789.jpg', 'post-991-0123456789.jpg', 'post-992-0123456789.jpg', 'post-993-0123456789.jpg', 'post-994-0123456789.jpg'];
        foreach ($names as $n) {
            $this->file($n);
        }
        $gc = $this->plugin->gc();
        $state = $gc->runBatch();
        self::assertSame('post-991-0123456789.jpg', $state['cursor']);
        self::assertFileDoesNotExist($this->cacheDir() . '/post-990-0123456789.jpg');
        self::assertFileExists($this->cacheDir() . '/post-992-0123456789.jpg');
        $state = $gc->runBatch();
        self::assertSame('post-993-0123456789.jpg', $state['cursor']);
        $state = $gc->runBatch();
        self::assertSame('', $state['cursor']);
        self::assertSame(5, $state['last']['removed']['gone'] ?? null);
        foreach ($names as $n) {
            self::assertFileDoesNotExist($this->cacheDir() . '/' . $n);
        }
    }

    public function testT42TrimsToNinetyPercentOfTheCapOldestFirst(): void
    {
        $this->settings(['cache_max_mb' => 1]);
        $keys = [];
        // Ten current cards of 150 KB, oldest first: 1.5 MB against a 1 MB cap.
        for ($i = 1; $i <= 10; $i++) {
            $this->addPost(100 + $i, 'Post ' . $i);
            $key = $this->keyForPost(100 + $i);
            $keys[$i] = $this->file($key->filename(), 1000 - $i, 153600);
        }
        $result = $this->plugin->gc()->runFull();
        self::assertGreaterThan(0, $result['removed']['cap']);
        $left = 0;
        foreach (glob($this->cacheDir() . '/*.jpg') ?: [] as $f) {
            $left += filesize($f);
        }
        self::assertLessThan(0.9 * 1048576, $left);
        // The oldest went first.
        self::assertFileDoesNotExist($keys[1]);
        self::assertFileExists($keys[10]);
    }

    public function testDryRunDeletesNothing(): void
    {
        $stale = $this->file('post-999-0123456789.jpg');
        $result = $this->plugin->gc()->runFull(true);
        self::assertSame(1, $result['removed']['gone']);
        self::assertFileExists($stale);
        self::assertSame(GarbageCollector::emptyResult(), $this->plugin->gc()->state()['cycle']);
        self::assertNull($this->plugin->gc()->state()['last']);
    }

    public function testCronRunsTheCycle(): void
    {
        $this->file('post-999-0123456789.jpg');
        $this->plugin->onCron();
        self::assertSame(1, $this->plugin->gc()->state()['last']['removed']['gone'] ?? null);
        unset(WpStub::$options[GarbageCollector::STATE_OPTION]);
    }
}
