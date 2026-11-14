<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * An endpoint response, built without sending anything so it can be tested.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param string|null $file Path of a file to stream as the body.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $file = null,
        public readonly bool $withBody = true
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /**
     * The body as a string (tests, and HEAD returns '').
     */
    public function body(): string
    {
        if (!$this->withBody || $this->file === null) {
            return '';
        }
        $data = file_get_contents($this->file);
        return $data === false ? '' : $data;
    }

    /**
     * Sends the response. The caller exits afterwards.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header_remove('X-Pingback');
            header_remove('Link');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->withBody && $this->file !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->file);
        }
    }
}
