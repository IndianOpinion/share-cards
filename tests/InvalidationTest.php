<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\Invalidator;
use IndianOpinion\ShareCards\Key;
use WpStub;

/**
 * T34 to T41: fingerprints and deleting what must not be seen.
 */
final class InvalidationTest extends TestCase
{
    /** @var list<array{0: list<string>, 1: string}> */
    private array $purged = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_action('share_cards_purged', function (array $urls, string $reason): void {
            $this->purged[] = [$urls, $reason];
        }, 10, 2);
        mkdir($this->cacheDir(), 0755, true);
    }

    private function place(Key $key): string
    {
        $path = $this->cacheDir() . '/' . $key->filename();
        file_put_contents($path, 'jpeg');
        return $path;
    }

    private function invalidator(): Invalidator
    {
        return new Invalidator($this->plugin);
    }

    public function testT34TitleEditRemovesOldVariants(): void
    {
        $post = $this->addPost(10, 'Original title');
        $old = $this->keyForPost(10);
        $oldPath = $this->place($old);
        $other = $this->place(Key::fromParts('post', '10', 'ffffffffff'));
        $neighbour = $this->place(Key::fromParts('post', '100', 'ffffffffff'));

        $post->post_title = 'Corrected title';
        $new = $this->keyForPost(10);
        self::assertNotSame($old->hash(), $new->hash());
        $current = $this->place($new);

        $this->invalidator()->onSavePost(10, $post);
        self::assertFileDoesNotExist($oldPath);
        self::assertFileDoesNotExist($other);
        self::assertFileExists($current);
        self::assertFileExists($neighbour);
        self::assertCount(1, $this->purged);
        self::assertSame('edit', $this->purged[0][1]);
        self::assertEqualsCanonicalizing([
            'https://example.test/share-card/post-10-' . $old->hash() . '.jpg',
            'https://example.test/share-card/post-10-ffffffffff.jpg',
        ], $this->purged[0][0]);
    }

    public function testT35FeaturedImageSwappedAndRemoved(): void
    {
        $this->addPost(10);
        $this->addAttachment(20, 800, 450);
        $this->addAttachment(21, 800, 450);
        $this->setFeatured(10, 20);
        $first = $this->keyForPost(10);
        $firstPath = $this->place($first);

        $this->setFeatured(10, 21);
        $second = $this->keyForPost(10);
        self::assertNotSame($first->hash(), $second->hash());
        $this->invalidator()->onPostMeta(1, 10, '_thumbnail_id');
        self::assertFileDoesNotExist($firstPath);
        $secondPath = $this->place($second);

        unset(WpStub::$meta[10]['_thumbnail_id']);
        $third = $this->keyForPost(10);
        self::assertNotSame($second->hash(), $third->hash());
        $this->invalidator()->onPostMeta(1, 10, '_thumbnail_id');
        self::assertFileDoesNotExist($secondPath);
        self::assertCount(2, $this->purged);
        // Other meta keys are ignored.
        $this->invalidator()->onPostMeta(1, 10, '_edit_lock');
        self::assertCount(2, $this->purged);
    }

    public function testT36TermRenamed(): void
    {
        $term = $this->addTerm(7, 'Politics');
        $post = $this->addPost(10);
        WpStub::$postTerms[10]['category'] = [$term];
        $termOld = $this->plugin->cards()->keyFor($this->plugin->resolver()->resolveTerm($term));
        $termPath = $this->place($termOld);
        $postOld = $this->keyForPost(10);
        $postPath = $this->place($postOld);

        $term->name = 'Government';
        $this->invalidator()->onEditedTerm(7, 7, 'category');
        self::assertFileDoesNotExist($termPath);
        self::assertSame('edit', $this->purged[0][1]);
        // The post that uses the term as kicker re-keys, its file waits for gc.
        self::assertNotSame($postOld->hash(), $this->keyForPost(10)->hash());
        self::assertFileExists($postPath);
        self::assertSame('Government', $this->plugin->resolver()->resolvePost($post)->kicker);
    }

    public function testT37UnpublishTrashDeletePrivatePassword(): void
    {
        $cases = [
            'unpublish' => ['post_status', 'draft', 'unpublish'],
            'trash' => ['post_status', 'trash', 'unpublish'],
            'private' => ['post_status', 'private', 'unpublish'],
            'password' => ['post_password', 'secret', 'unpublish'],
        ];
        foreach ($cases as $name => [$field, $value, $reason]) {
            $this->purged = [];
            $post = $this->addPost(10, 'T ' . $name);
            $a = $this->place($this->keyForPost(10));
            $b = $this->place(Key::fromParts('post', '10', 'aaaaaaaaaa'));
            $post->$field = $value;
            $this->invalidator()->onSavePost(10, $post);
            self::assertFileDoesNotExist($a, $name);
            self::assertFileDoesNotExist($b, $name);
            self::assertSame($reason, $this->purged[0][1] ?? null, $name);
            self::assertCount(2, $this->purged[0][0]);
        }

        $this->purged = [];
        $this->addPost(10);
        $c = $this->place($this->keyForPost(10));
        $this->invalidator()->onDeletePost(10);
        self::assertFileDoesNotExist($c);
        self::assertSame('delete', $this->purged[0][1]);
    }

    public function testT38TermDeleted(): void
    {
        $term = $this->addTerm(7, 'Politics');
        $path = $this->place($this->plugin->cards()->keyFor($this->plugin->resolver()->resolveTerm($term)));
        $this->invalidator()->onDeleteTerm(7, 7, 'category');
        self::assertFileDoesNotExist($path);
        self::assertSame('delete', $this->purged[0][1]);
    }

    public function testT39SettingsChangeRekeysAndDeletesNothing(): void
    {
        $this->addPost(10);
        $this->addTerm(7, 'Politics');
        $postKey = $this->keyForPost(10);
        $termKey = $this->plugin->cards()->keyFor($this->plugin->resolver()->resolveTerm(WpStub::$terms[7]));
        $a = $this->place($postKey);
        $b = $this->place($termKey);

        $page = new \IndianOpinion\ShareCards\SettingsPage($this->plugin);
        $result = $page->processSave(['_wpnonce' => wp_create_nonce('share_cards_save'), 'share_cards' => ['colour_text' => '#ff0000']]);
        self::assertTrue($result['ok']);
        $this->newPlugin();
        self::assertNotSame($postKey->hash(), $this->keyForPost(10)->hash());
        self::assertNotSame($termKey->hash(), $this->plugin->cards()->keyFor($this->plugin->resolver()->resolveTerm(WpStub::$terms[7]))->hash());
        self::assertFileExists($a);
        self::assertFileExists($b);
        self::assertSame([], $this->purged);
    }

    public function testT40SiteNameInFingerprintOnlyWhenShown(): void
    {
        $this->addPost(10);
        $before = $this->keyForPost(10);
        WpStub::$bloginfo['name'] = 'Renamed News';
        $this->newPlugin();
        self::assertNotSame($before->hash(), $this->keyForPost(10)->hash());

        $this->settings(['footer' => 'none']);
        $none = $this->keyForPost(10);
        WpStub::$bloginfo['name'] = 'Another Name';
        $this->newPlugin();
        self::assertSame($none->hash(), $this->keyForPost(10)->hash());
    }

    public function testT41AttachedFileChangeRekeys(): void
    {
        $this->addPost(10);
        $this->addAttachment(20, 800, 450);
        $this->setFeatured(10, 20);
        $before = $this->keyForPost(10);
        WpStub::$meta[20]['_wp_attached_file'] = 'img-20-e1234567890.jpg';
        self::assertNotSame($before->hash(), $this->keyForPost(10)->hash());
    }

    public function testFingerprintPartsFilter(): void
    {
        $this->addPost(10);
        $before = $this->keyForPost(10);
        add_filter('share_cards_fingerprint_parts', static fn (array $parts): array => ['count' => 5]);
        self::assertNotSame($before->hash(), $this->keyForPost(10)->hash());
    }
}
