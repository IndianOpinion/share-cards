<?php

// Minimal WordPress classes for the test stub layer.

declare(strict_types=1);

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_password = '';
    public string $post_title = '';
    public string $post_mime_type = '';
    public string $post_date = '2026-01-01 00:00:00';

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
    public string $slug = '';
    public string $taxonomy = 'category';
    public int $count = 0;

    /**
     * @param array<string, mixed> $props
     */
    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
}

class WP_Taxonomy
{
    public string $name;
    public object $labels;

    public function __construct(string $name, string $singular)
    {
        $this->name = $name;
        $this->labels = (object) ['singular_name' => $singular];
    }
}

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

class WP
{
    /** @var array<string, mixed> */
    public array $query_vars = [];
}

final class WpCliExit extends RuntimeException
{
}

class WP_CLI
{
    /** @var list<string> */
    public static array $out = [];

    /** @var array<string, object> */
    public static array $commands = [];

    public static function add_command(string $name, object $handler): void
    {
        self::$commands[$name] = $handler;
    }

    public static function log(string $message): void
    {
        self::$out[] = $message;
    }

    public static function line(string $message = ''): void
    {
        self::$out[] = $message;
    }

    public static function success(string $message): void
    {
        self::$out[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        self::$out[] = 'Warning: ' . $message;
    }

    public static function error(string $message): void
    {
        self::$out[] = 'Error: ' . $message;
        throw new WpCliExit($message);
    }
}
