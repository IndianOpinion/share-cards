<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The name of one card file: `{type}-{id}-{hash}`.
 *
 * A key is only ever built from parts that passed the strict pattern, so a
 * file name made from it can never escape the cache directory.
 */
final class Key
{
    /** Pattern for the type part. */
    public const TYPE_PATTERN = '[a-z][a-z0-9]{0,15}';

    /** Pattern for the id part: decimal, 1 to 20 digits, no leading zero. */
    public const ID_PATTERN = '[1-9][0-9]{0,19}';

    /** Pattern for the hash part: 10 lower-case hex characters. */
    public const HASH_PATTERN = '[0-9a-f]{10}';

    /** The whole key, anchored, with capture groups for type, id and hash. */
    public const PATTERN = '/^(' . self::TYPE_PATTERN . ')-(' . self::ID_PATTERN . ')-(' . self::HASH_PATTERN . ')$/D';

    private function __construct(
        private string $type,
        private string $id,
        private string $hash
    ) {
    }

    /**
     * Parses a key string.
     *
     * @throws InvalidKeyException When the string does not match the pattern.
     */
    public static function parse(string $key): self
    {
        if (strlen($key) > 64 || preg_match(self::PATTERN, $key, $m) !== 1) {
            throw new InvalidKeyException('Malformed card key.');
        }
        return new self($m[1], $m[2], $m[3]);
    }

    /**
     * Parses a key string, returning null instead of throwing.
     */
    public static function tryParse(string $key): ?self
    {
        try {
            return self::parse($key);
        } catch (InvalidKeyException) {
            return null;
        }
    }

    /**
     * Builds a key from parts, validating each one.
     *
     * @throws InvalidKeyException When a part is malformed.
     */
    public static function fromParts(string $type, string $id, string $hash): self
    {
        return self::parse($type . '-' . $id . '-' . $hash);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * The key without its hash, `{type}-{id}`, shared by every variant.
     */
    public function stem(): string
    {
        return $this->type . '-' . $this->id;
    }

    public function toString(): string
    {
        return $this->type . '-' . $this->id . '-' . $this->hash;
    }

    public function filename(): string
    {
        return $this->toString() . '.jpg';
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
