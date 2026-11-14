<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Builds the fingerprint input for a subject and hashes it.
 *
 * Everything drawn on a card is in the input, so the hash, and with it the
 * URL, changes whenever the card would change. Only database values are
 * used: computing a hash never reads an image file.
 */
final class Fingerprint
{
    /** Bump whenever drawing code changes output. */
    public const TEMPLATE_VERSION = '1';

    public function __construct(
        private string $settingsFingerprint,
        private string $rendererKind,
        private string $siteName,
        private string $runtimeFingerprint = ''
    ) {
    }

    /**
     * The canonical input: keys sorted, UTF-8, slashes unescaped.
     *
     * @return array<string, mixed>
     */
    public function input(Subject $subject, string $backdropPath): array
    {
        $extra = apply_filters('share_cards_fingerprint_parts', [], $subject);
        return [
            'template' => self::TEMPLATE_VERSION,
            'settings' => $this->settingsFingerprint,
            'runtime' => $this->runtimeFingerprint,
            'renderer' => $this->rendererKind,
            'site' => $this->siteName,
            'type' => $subject->type,
            'id' => $subject->id,
            'headline' => $subject->headline,
            'kicker' => $subject->kicker,
            'footnote' => $subject->footnote,
            'backdrop' => $subject->backdropId > 0 ? $subject->backdropId : 0,
            'backdrop_file' => $subject->backdropId > 0 ? $backdropPath : '',
            'extra' => is_array($extra) ? $extra : [],
        ];
    }

    /**
     * First 10 hex characters of the SHA-256 of the canonical input.
     */
    public function hash(Subject $subject, string $backdropPath): string
    {
        return substr(hash('sha256', self::canonicalJson($this->input($subject, $backdropPath))), 0, 10);
    }

    public static function canonicalJson(mixed $value): string
    {
        $json = json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        return $json === false ? '' : $json;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_object($value) ? get_class($value) : $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::sortKeys($v);
        }
        return $value;
    }
}
