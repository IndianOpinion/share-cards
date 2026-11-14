<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\HeadTags;
use IndianOpinion\ShareCards\Seo\RankMathAdapter;
use IndianOpinion\ShareCards\Seo\SeoAdapter;
use IndianOpinion\ShareCards\Seo\YoastAdapter;
use WpStub;

/**
 * T14, T15: tags printed by the plugin, or handed to an SEO plugin.
 */
final class HeadTagsTest extends TestCase
{
    private function head(): string
    {
        ob_start();
        $this->plugin->headTags()->print();
        return (string) ob_get_clean();
    }

    public function testT15EightTagsInOrderEscapedAndAbsolute(): void
    {
        $post = $this->addPost(10, 'Say "hello" &lt;3 & wave');
        $this->viewPost($post);
        $html = $this->head();
        preg_match_all('/<meta (property|name)="([^"]+)" content="([^"]*)" \/>/', $html, $m);
        self::assertSame(
            ['og:image', 'og:image:type', 'og:image:width', 'og:image:height', 'og:image:alt', 'twitter:card', 'twitter:image', 'twitter:image:alt'],
            $m[2]
        );
        self::assertMatchesRegularExpression('#^https://example\.test/share-card/post-10-[0-9a-f]{10}\.jpg$#', $m[3][0]);
        self::assertSame('image/jpeg', $m[3][1]);
        self::assertSame('1200', $m[3][2]);
        self::assertSame('630', $m[3][3]);
        self::assertSame('Say &quot;hello&quot; &lt;3 &amp; wave', $m[3][4]);
        self::assertSame('summary_large_image', $m[3][5]);
        self::assertSame($m[3][0], $m[3][6]);
        self::assertStringNotContainsString('"hello"', $html);
    }

    public function testT15EmitTagsOffPrintsNothingButFunctionStillWorks(): void
    {
        $this->settings(['emit_tags' => 'off']);
        $post = $this->addPost(10);
        $this->viewPost($post);
        self::assertSame('', $this->head());
        $image = share_cards_image_for_request();
        self::assertNotNull($image);
        self::assertSame('card', $image['source']);
    }

    public function testSchemeFollowsHome(): void
    {
        WpStub::$home = 'https://example.test';
        self::assertSame('https://cdn.example.test/a.jpg', HeadTags::absolute('http://cdn.example.test/a.jpg'));
        self::assertSame('https://example.test/a.jpg', HeadTags::absolute('/a.jpg'));
        self::assertSame('https://cdn.example.test/a.jpg', HeadTags::absolute('//cdn.example.test/a.jpg'));
        self::assertSame('', HeadTags::absolute('javascript:alert(1)'));
        WpStub::$home = 'http://example.test';
        self::assertSame('http://example.test/a.jpg', HeadTags::absolute('https://example.test/a.jpg'));
    }

    public function testT14StubAdapterReceivesTheImageAndNoTagsArePrinted(): void
    {
        $adapter = new class () implements SeoAdapter {
            /** @var callable|null */
            public $provider = null;

            public function isActive(): bool
            {
                return true;
            }

            public function register(callable $imageProvider): void
            {
                $this->provider = $imageProvider;
            }
        };
        add_filter('share_cards_seo_adapters', static fn (): array => [$adapter]);
        $post = $this->addPost(10);
        $this->viewPost($post);
        $this->plugin->onInit();

        self::assertSame('', $this->head());
        self::assertNotNull($adapter->provider);
        $image = ($adapter->provider)();
        self::assertSame(1200, $image['width']);
        self::assertSame(630, $image['height']);
        self::assertMatchesRegularExpression('#/share-card/post-10-#', $image['url']);
    }

    public function testEmitTagsOnPrintsEvenWithAnAdapter(): void
    {
        $this->settings(['emit_tags' => 'on']);
        $adapter = $this->createMock(SeoAdapter::class);
        $adapter->method('isActive')->willReturn(true);
        $adapter->expects(self::never())->method('register');
        add_filter('share_cards_seo_adapters', static fn (): array => [$adapter]);
        $post = $this->addPost(10);
        $this->viewPost($post);
        $this->plugin->onInit();
        self::assertStringContainsString('og:image', $this->head());
    }

    public function testYoastAdapterUsesDocumentedFilters(): void
    {
        $image = ['url' => 'https://example.test/c.jpg', 'width' => 1200, 'height' => 630, 'type' => 'image/jpeg', 'alt' => 'A'];
        (new YoastAdapter())->register(static fn (): array => $image);
        self::assertSame('https://example.test/c.jpg', apply_filters('wpseo_opengraph_image', 'https://example.test/old.jpg'));
        self::assertSame(1200, apply_filters('wpseo_opengraph_image_width', 800));
        self::assertSame(630, apply_filters('wpseo_opengraph_image_height', 600));
        self::assertSame('image/jpeg', apply_filters('wpseo_opengraph_image_type', 'image/png'));
        self::assertSame('https://example.test/c.jpg', apply_filters('wpseo_twitter_image', ''));

        $container = new class () {
            /** @var list<array<string, mixed>> */
            public array $images = [];

            public function has_images(): bool
            {
                return $this->images !== [];
            }

            /**
             * @param array<string, mixed> $image
             */
            public function add_image(array $image): void
            {
                $this->images[] = $image;
            }
        };
        do_action('wpseo_add_opengraph_images', $container);
        self::assertCount(1, $container->images);
        self::assertSame(630, $container->images[0]['height']);
        // A container that already has an image is left alone.
        do_action('wpseo_add_opengraph_images', $container);
        self::assertCount(1, $container->images);
    }

    public function testYoastAdapterLeavesYoastAloneWithoutAnImage(): void
    {
        (new YoastAdapter())->register(static fn (): ?array => null);
        self::assertSame('https://example.test/old.jpg', apply_filters('wpseo_opengraph_image', 'https://example.test/old.jpg'));
    }

    public function testRankMathAdapterUsesDocumentedFilters(): void
    {
        $image = ['url' => 'https://example.test/c.jpg', 'width' => 1200, 'height' => 630, 'type' => 'image/jpeg', 'alt' => 'A'];
        (new RankMathAdapter())->register(static fn (): array => $image);
        self::assertSame('https://example.test/c.jpg', apply_filters('rank_math/opengraph/facebook/image', 'old'));
        self::assertSame('https://example.test/c.jpg', apply_filters('rank_math/opengraph/twitter/image', 'old'));
        self::assertSame(1200, apply_filters('rank_math/opengraph/facebook/og_image_width', 1));
        self::assertSame(630, apply_filters('rank_math/opengraph/facebook/og_image_height', 1));
        self::assertSame('A', apply_filters('rank_math/opengraph/facebook/og_image_alt', 'x'));
    }

    public function testAdaptersDetectTheirPlugins(): void
    {
        self::assertFalse((new YoastAdapter())->isActive());
        self::assertFalse((new RankMathAdapter())->isActive());
    }
}
