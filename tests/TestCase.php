<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Key;
use IndianOpinion\ShareCards\Plugin;
use IndianOpinion\ShareCards\Settings;
use IndianOpinion\ShareCards\Subject;
use WP_Post;
use WP_Term;
use WpStub;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $tmp;
    protected string $uploads;
    protected FakeClock $clock;
    protected Plugin $plugin;

    /** @var list<string> */
    protected array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        WpStub::reset();
        $this->tmp = sys_get_temp_dir() . '/share-cards-' . bin2hex(random_bytes(6));
        $this->uploads = $this->tmp . '/uploads';
        mkdir($this->uploads, 0755, true);
        WpStub::$uploadsBase = $this->uploads;
        WpStub::$now = time();
        $this->clock = new FakeClock((float) WpStub::$now);
        $this->logs = [];
        $this->newPlugin();
    }

    protected function tearDown(): void
    {
        Plugin::setInstance(null);
        self::removeTree($this->tmp);
        parent::tearDown();
    }

    /**
     * A fresh plugin instance, as on a new request.
     */
    protected function newPlugin(): Plugin
    {
        $this->plugin = new Plugin($this->clock, function (string $line): void {
            $this->logs[] = $line;
        });
        Plugin::setInstance($this->plugin);
        return $this->plugin;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function settings(array $values): void
    {
        (new Settings(array_merge(Settings::defaults(), ['log_level' => 'debug'], $values)))->save();
        $this->newPlugin();
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                chmod($item->getPathname(), 0755);
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $props
     */
    protected function addPost(int $id, string $title = 'A headline', array $props = []): WP_Post
    {
        $post = new WP_Post(array_merge(['ID' => $id, 'post_title' => $title], $props));
        WpStub::$posts[$id] = $post;
        return $post;
    }

    protected function addTerm(int $id, string $name, string $taxonomy = 'category', int $count = 3): WP_Term
    {
        $term = new WP_Term(['term_id' => $id, 'name' => $name, 'slug' => strtolower($name), 'taxonomy' => $taxonomy, 'count' => $count]);
        WpStub::$terms[$id] = $term;
        return $term;
    }

    /**
     * Registers an image attachment. With `$file`, a real image is written.
     *
     * @param array<string, array{width: int, height: int, file: string}> $sizes
     */
    protected function addAttachment(int $id, int $width, int $height, bool $file = false, string $mime = 'image/jpeg', array $sizes = []): string
    {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][$mime] ?? 'jpg';
        $name = 'img-' . $id . '.' . $ext;
        WpStub::$posts[$id] = new WP_Post(['ID' => $id, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $mime, 'post_title' => 'Image ' . $id]);
        WpStub::$meta[$id]['_wp_attached_file'] = $name;
        WpStub::$meta[$id]['_wp_attachment_metadata'] = ['width' => $width, 'height' => $height, 'file' => $name, 'sizes' => $sizes];
        $path = $this->uploads . '/' . $name;
        if ($file) {
            Images::write($path, $width, $height, $mime);
        }
        return $path;
    }

    protected function setFeatured(int $postId, int $attachmentId): void
    {
        WpStub::$meta[$postId]['_thumbnail_id'] = $attachmentId;
    }

    protected function viewPost(WP_Post $post): void
    {
        WpStub::$query = ['singular' => true];
        WpStub::$queried = $post;
    }

    protected function viewTerm(WP_Term $term): void
    {
        $kind = $term->taxonomy === 'category' ? 'category' : ($term->taxonomy === 'post_tag' ? 'tag' : 'tax');
        WpStub::$query = [$kind => true];
        WpStub::$queried = $term;
    }

    protected function cacheDir(): string
    {
        return $this->uploads . '/share-cards';
    }

    protected function keyForPost(int $id): Key
    {
        $post = WpStub::$posts[$id];
        return $this->plugin->cards()->keyFor($this->plugin->resolver()->resolvePost($post));
    }

    protected function subject(string $headline, string $kicker = '', string $footnote = '', int $backdrop = 0): Subject
    {
        return new Subject('post', '1', $headline, $kicker, $footnote, $backdrop, '', true);
    }

    /**
     * @return list<string>
     */
    protected function logsMatching(string $needle): array
    {
        return array_values(array_filter($this->logs, static fn (string $l): bool => str_contains($l, $needle)));
    }
}
