<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * One line per event to `error_log`, prefixed `[share-cards]`.
 * Lines carry keys and attachment IDs, never visitor data.
 */
final class Logger
{
    private const LEVELS = ['error' => 0, 'info' => 1, 'debug' => 2];

    /** @var callable(string): void */
    private $sink;

    private int $level;

    /**
     * @param callable(string): void|null $sink Receives the full line.
     */
    public function __construct(string $level = 'error', ?callable $sink = null)
    {
        $this->level = self::LEVELS[$level] ?? 0;
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function error(string $message): void
    {
        $this->write(0, $message);
    }

    public function info(string $message): void
    {
        $this->write(1, $message);
    }

    public function debug(string $message): void
    {
        $this->write(2, $message);
    }

    private function write(int $level, string $message): void
    {
        if ($level > $this->level) {
            return;
        }
        $message = (string) preg_replace('/[\r\n]+/', ' ', $message);
        ($this->sink)('[share-cards] ' . $message);
    }
}
