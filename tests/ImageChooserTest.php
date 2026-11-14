<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use WpStub;

/**
 * T1 to T13 and T16: which image a request advertises.
 */
final class ImageChooserTest extends TestCase
{
    private function chosen(): ?array
    {
        $this->plugin->chooser()->reset();
        return $this->plugin->chooser()->forRequest();
    }

    private function head(): string
    {
        ob_start();
        $this->plugin->headTags()->print();
        return (string) ob_get_clean();
    }

    public function testT1GoodFeaturedImageIsUsedAsItIs(): void
    {
        $post = $this->addPost(10);
        $this->addAttachment(20, 1600, 900);
        $this->setFeatured(10, 20);
        $this->viewPost($post);

        $image = $this->chosen();
        self::assertNotNull($image);
        self::assertSame('featured', $image['source']);
        self::assertSame('https://example.test/wp-content/uploads/img-20.jpg', $image['url']);
        self::assertSame(1600, $image['width']);
        self::assertSame(900, $image['height']);
        self::assertStringNotContainsString('share-card', $this->head());
    }

    public function testT2NarrowFeaturedImageGetsACard(): void
    {
        $post = $this->addPost(10);
        $this->addAttachment(20, 800, 450);
        $this->setFeatured(10, 20);
        $this->viewPost($post);

        $image = $this->chosen();
        self::assertNotNull($image);
        self::assertSame('card', $image['source']);
        self::assertMatchesRegularExpression('#^https://example\.test/share-card/post-10-[0-9a-f]{10}\.jpg$#', (string) $image['url']);
        self::assertSame(1200, $image['width']);
        self::assertSame(630, $image['height']);
        self::assertSame('image/jpeg', $image['type']);
        // The narrow image is still the backdrop.
        self::assertSame(20, $this->plugin->resolver()->resolvePost($post)->backdropId);
    }

    public function testT3WrongShapesGetACard(): void
    {
        $post = $this->addPost(10);
        $this->viewPost($post);
        foreach ([[1200, 152], [1200, 1200], [900, 1600]] as $i => [$w, $h]) {
            $this->addAttachment(30 + $i, $w, $h);
            $this->setFeatured(10, 30 + $i);
            $image = $this->chosen();
            self::assertNotNull($image);
            self::assertSame('card', $image['source'], "{$w}x{$h}");
        }
    }

    public function testT4NoFeaturedImageGetsACardWithoutBackdrop(): void
    {
        $post = $this->addPost(10);
        $this->viewPost($post);
        $image = $this->chosen();
        self::assertSame('card', $image['source'] ?? null);
        self::assertSame(0, $this->plugin->resolver()->resolvePost($post)->backdropId);
    }

    public function testT5GenericImageIsNeverUsedNorABackdrop(): void
    {
        $this->settings(['generic_image_ids' => [20]]);
        $post = $this->addPost(10);
        $this->addAttachment(20, 1200, 675);
        $this->setFeatured(10, 20);
        $this->viewPost($post);
        $image = $this->chosen();
        self::assertSame('card', $image['source'] ?? null);
        self::assertSame(0, $this->plugin->resolver()->resolvePost($post)->backdropId);

        // Even if a filter offers it.
        add_filter('share_cards_backdrop_attachment', static fn (): int => 20);
        self::assertSame(0, $this->plugin->resolver()->resolvePost($post)->backdropId);
    }

    public function testT6ModeAlwaysAndNever(): void
    {
        $post = $this->addPost(10);
        $this->addAttachment(20, 1600, 900);
        $this->setFeatured(10, 20);
        $this->viewPost($post);

        $this->settings(['mode' => 'always']);
        $image = $this->chosen();
        self::assertSame('card', $image['source'] ?? null);
        self::assertSame(20, $this->plugin->resolver()->resolvePost($post)->backdropId);

        $this->settings(['mode' => 'never']);
        self::assertSame('featured', $this->chosen()['source'] ?? null);

        unset(WpStub::$meta[10]['_thumbnail_id']);
        $this->addAttachment(99, 1200, 630);
        $this->settings(['mode' => 'never', 'default_image_id' => 99]);
        $image = $this->chosen();
        self::assertSame('default', $image['source'] ?? null);
        self::assertSame(1200, $image['width']);

        $this->settings(['mode' => 'never']);
        self::assertNull($this->chosen());
        self::assertSame('', $this->head());
    }

    public function testModeIsFilterablePerPost(): void
    {
        $post = $this->addPost(10);
        $this->addAttachment(20, 1600, 900);
        $this->setFeatured(10, 20);
        $this->viewPost($post);
        add_filter('share_cards_mode', static fn (string $mode, \WP_Post $p): string => $p->ID === 10 ? 'always' : $mode, 10, 2);
        self::assertSame('card', $this->chosen()['source'] ?? null);
    }

    public function testT7T8CategoryAndTagArchives(): void
    {
        $cat = $this->addTerm(7, 'Politics', 'category');
        $this->viewTerm($cat);
        $image = $this->chosen();
        self::assertSame('card', $image['source'] ?? null);
        self::assertMatchesRegularExpression('#/share-card/term-7-[0-9a-f]{10}\.jpg$#', (string) $image['url']);

        $tag = $this->addTerm(8, 'Budget', 'post_tag');
        $this->viewTerm($tag);
        $image = $this->chosen();
        self::assertMatchesRegularExpression('#/share-card/term-8-[0-9a-f]{10}\.jpg$#', (string) ($image['url'] ?? ''));
    }

    public function testTermCardsSwitchOff(): void
    {
        $this->settings(['term_cards' => false]);
        $cat = $this->addTerm(7, 'Politics', 'category');
        $this->viewTerm($cat);
        self::assertNull($this->chosen());
    }

    public function testT9DisallowedTaxonomyLeavesTheHeadAlone(): void
    {
        $this->addAttachment(99, 1200, 630);
        $this->settings(['default_image_id' => 99]);
        $term = $this->addTerm(9, 'Something', 'topic');
        $this->viewTerm($term);
        self::assertNull($this->chosen());
        self::assertSame('', $this->head());
    }

    public function testT10OtherViews(): void
    {
        foreach (['front', 'search', '404', 'home'] as $view) {
            WpStub::$query = [$view => true];
            self::assertNull($this->chosen(), $view);
        }
        $this->addAttachment(99, 1000, 500);
        $this->settings(['default_image_id' => 99]);
        foreach (['front', 'search', '404', 'home'] as $view) {
            WpStub::$query = [$view => true];
            $image = $this->chosen();
            self::assertSame('default', $image['source'] ?? null, $view);
            self::assertSame(1000, $image['width']);
        }
    }

    public function testT11PostTypeNotAllowed(): void
    {
        $page = $this->addPost(11, 'About', ['post_type' => 'page']);
        $this->viewPost($page);
        self::assertNull($this->chosen());
        $this->addAttachment(99, 1000, 500);
        $this->settings(['default_image_id' => 99]);
        self::assertSame('default', $this->chosen()['source'] ?? null);
    }

    public function testT12PasswordProtectedOrDraftNeverAdvertisesACard(): void
    {
        $post = $this->addPost(10, 'Secret', ['post_password' => 'pw']);
        $this->viewPost($post);
        self::assertNull($this->chosen());
        self::assertStringNotContainsString('share-card', $this->head());

        $draft = $this->addPost(12, 'Draft', ['post_status' => 'draft']);
        $this->viewPost($draft);
        self::assertNull($this->chosen());
    }

    public function testT13CustomTypeSharedByTwoUrls(): void
    {
        $day = '20260301';
        add_filter('share_cards_types', static function (array $types) use (&$day): array {
            $types['day'] = [
                'pattern' => '/^\d{8}$/',
                'resolve' => static fn (string $id) => new \IndianOpinion\ShareCards\Subject('day', $id, 'The day in brief ' . $id, 'Daily', ''),
                'matchRequest' => static function () use (&$day): ?string {
                    return WpStub::$query['day'] ?? null ? $day : null;
                },
            ];
            return $types;
        });
        // The stable "today" page.
        WpStub::$query = ['day' => true, 'front' => true];
        $today = $this->chosen();
        // The dated archive page for the same day.
        WpStub::$query = ['day' => true, 'singular' => true];
        WpStub::$queried = $this->addPost(50, 'Brief 1 March', ['post_type' => 'brief']);
        $dated = $this->chosen();
        self::assertNotNull($today);
        self::assertSame($today['url'], $dated['url'] ?? null);
        self::assertMatchesRegularExpression('#/share-card/day-20260301-[0-9a-f]{10}\.jpg$#', (string) $today['url']);

        // Rendering one, then requesting the other, is a hit.
        $key = basename((string) parse_url((string) $today['url'], PHP_URL_PATH), '.jpg');
        $first = $this->plugin->endpoint()->handle($key, 'GET');
        self::assertSame('render', $first->header('X-Share-Card'));
        $second = $this->plugin->endpoint()->handle(basename((string) parse_url((string) $dated['url'], PHP_URL_PATH), '.jpg'), 'GET');
        self::assertSame('hit', $second->header('X-Share-Card'));
    }

    public function testT16NoRendererFallsBack(): void
    {
        add_filter('share_cards_capabilities', static fn (array $c): array => array_merge($c, ['gd' => false, 'imagick' => false]));
        $this->newPlugin();
        $post = $this->addPost(10);
        $this->addAttachment(20, 300, 200);
        $this->setFeatured(10, 20);
        $this->viewPost($post);
        $image = $this->chosen();
        self::assertSame('featured', $image['source'] ?? null);
        self::assertSame(300, $image['width']);
        self::assertStringNotContainsString('share-card', $this->head());

        unset(WpStub::$meta[10]['_thumbnail_id']);
        self::assertNull($this->chosen());
        $this->addAttachment(99, 1000, 500);
        $this->settings(['default_image_id' => 99]);
        self::assertSame('default', $this->chosen()['source'] ?? null);

        $cat = $this->addTerm(7, 'Politics');
        $this->viewTerm($cat);
        self::assertSame('default', $this->chosen()['source'] ?? null);
    }

    public function testFrontPageCardIsOffByDefaultAndFilterable(): void
    {
        WpStub::$query = ['front' => true];
        self::assertNull($this->chosen());
        add_filter('share_cards_front_page_card', '__return_true');
        $image = $this->chosen();
        self::assertSame('card', $image['source'] ?? null);
        self::assertMatchesRegularExpression('#/share-card/site-1-[0-9a-f]{10}\.jpg$#', (string) $image['url']);
    }

    public function testFinalImageFilter(): void
    {
        WpStub::$query = ['search' => true];
        add_filter('share_cards_image', static fn () => ['url' => 'https://example.test/x.jpg', 'width' => 10, 'height' => 10, 'type' => 'image/jpeg', 'alt' => 'x', 'source' => 'default']);
        self::assertSame('https://example.test/x.jpg', $this->chosen()['url'] ?? null);
    }
}
