<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests\Unit;

use IndianOpinion\ShareCards\InvalidKeyException;
use IndianOpinion\ShareCards\Key;
use IndianOpinion\ShareCards\Rewrite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T17, T23: the key pattern.
 */
final class KeyTest extends TestCase
{
    public function testParsesValidKeys(): void
    {
        $key = Key::parse('post-12-0123456789');
        self::assertSame('post', $key->type());
        self::assertSame('12', $key->id());
        self::assertSame('0123456789', $key->hash());
        self::assertSame('post-12-0123456789.jpg', $key->filename());
        self::assertSame('post-12', $key->stem());

        $term = Key::parse('term-7-abcdef0123');
        self::assertSame('term', $term->type());
        self::assertSame('7', $term->id());
    }

    public function testRewriteRuleMatchesValidKeys(): void
    {
        $regex = '#' . Rewrite::regex('share-card') . '#';
        self::assertSame(1, preg_match($regex, 'share-card/post-12-0123456789.jpg', $m));
        self::assertSame(['post', '12', '0123456789'], [$m[1], $m[2], $m[3]]);
        self::assertSame(1, preg_match($regex, 'share-card/term-7-abcdef0123.jpg'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformed(): array
    {
        return [
            'upper-case type' => ['Post-1-0123456789'],
            'leading zero' => ['post-01-0123456789'],
            'upper-case hash' => ['post-1-ABCDEF0123'],
            'short hash' => ['post-1-abc'],
            'traversal' => ['../x'],
            'extra segment' => ['post-1-0123456789/x'],
            '21-digit id' => ['post-' . str_repeat('1', 21) . '-0123456789'],
            'missing hash' => ['post-1'],
            'dots' => ['post.1-0123456789'],
            'encoded slash' => ['post-1-01234%2F789'],
            'trailing newline' => ["post-1-0123456789\n"],
            'empty' => [''],
        ];
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedKeys(string $raw): void
    {
        self::assertNull(Key::tryParse($raw));
        $this->expectException(InvalidKeyException::class);
        Key::parse($raw);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPaths(): array
    {
        return [
            'upper-case type' => ['share-card/Post-1-0123456789.jpg'],
            'leading zero' => ['share-card/post-01-0123456789.jpg'],
            'upper-case hash' => ['share-card/post-1-ABCDEF0123.jpg'],
            'short hash' => ['share-card/post-1-abc.jpg'],
            'png' => ['share-card/post-1-0123456789.png'],
            'traversal' => ['share-card/../x'],
            'extra segment' => ['share-card/post-1-0123456789/x'],
            'long id' => ['share-card/post-' . str_repeat('1', 21) . '-0123456789.jpg'],
        ];
    }

    #[DataProvider('malformedPaths')]
    public function testRewriteRuleRejectsMalformedPaths(string $path): void
    {
        self::assertSame(0, preg_match('#' . Rewrite::regex('share-card') . '#', $path));
    }
}
