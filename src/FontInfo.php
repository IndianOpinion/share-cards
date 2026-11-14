<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Reads the family name from a TrueType or OpenType font's `name` table.
 *
 * Pango selects fonts by family name through fontconfig, not by path, so the
 * shaping layer needs the family of each mapped font file. Nothing else in
 * the font is parsed (character maps are not read; see ARCHITECTURE.md).
 */
final class FontInfo
{
    /**
     * @return string The family name, or '' when the file has none we can read.
     */
    public static function familyName(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            return self::read($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private static function read($handle): string
    {
        $header = fread($handle, 12);
        if (!is_string($header) || strlen($header) < 12) {
            return '';
        }
        $tag = substr($header, 0, 4);
        if (!in_array($tag, ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
            return '';
        }
        $numTables = self::u16($header, 4);
        if ($numTables < 1 || $numTables > 512) {
            return '';
        }
        $records = fread($handle, 16 * $numTables);
        if (!is_string($records) || strlen($records) < 16 * $numTables) {
            return '';
        }
        for ($i = 0; $i < $numTables; $i++) {
            if (substr($records, $i * 16, 4) !== 'name') {
                continue;
            }
            $offset = self::u32($records, $i * 16 + 8);
            $length = self::u32($records, $i * 16 + 12);
            if ($length < 6 || $length > 1048576 || fseek($handle, $offset) !== 0) {
                return '';
            }
            $table = fread($handle, $length);
            return is_string($table) && strlen($table) === $length ? self::family($table) : '';
        }
        return '';
    }

    private static function family(string $table): string
    {
        $count = self::u16($table, 2);
        $storage = self::u16($table, 4);
        $best = [];
        for ($i = 0; $i < $count; $i++) {
            $base = 6 + $i * 12;
            if ($base + 12 > strlen($table)) {
                break;
            }
            $platform = self::u16($table, $base);
            $language = self::u16($table, $base + 4);
            $nameId = self::u16($table, $base + 6);
            $length = self::u16($table, $base + 8);
            $offset = self::u16($table, $base + 10);
            if ($nameId !== 1 && $nameId !== 16) {
                continue;
            }
            $raw = substr($table, $storage + $offset, $length);
            if ($platform === 3 || $platform === 0) {
                $value = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            } elseif ($platform === 1) {
                $value = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            } else {
                continue;
            }
            // Prefer the typographic family (16) over the legacy one (1), and
            // English (Windows 0x409) over other languages.
            $score = ($nameId === 16 ? 4 : 0) + ($platform === 3 && $language === 0x409 ? 2 : 0) + ($platform === 3 ? 1 : 0);
            $value = trim($value);
            if ($value !== '' && (!isset($best[0]) || $score > $best[0])) {
                $best = [$score, $value];
            }
        }
        return $best[1] ?? '';
    }

    private static function u16(string $data, int $at): int
    {
        $v = unpack('n', $data, $at);
        return is_array($v) ? (int) $v[1] : 0;
    }

    private static function u32(string $data, int $at): int
    {
        $v = unpack('N', $data, $at);
        return is_array($v) ? (int) $v[1] : 0;
    }
}
