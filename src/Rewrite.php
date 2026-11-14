<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * The rewrite rule for card URLs, and the previous prefix kept routing for
 * a while after a prefix change.
 */
final class Rewrite
{
    public const PREVIOUS_OPTION = 'share_cards_previous_prefix';
    public const FLUSH_GUARD = 'share_cards_flush_guard';
    public const PREVIOUS_DAYS = 30;

    public function __construct(private Settings $settings, private Clock $clock)
    {
    }

    public static function regex(string $prefix): string
    {
        // The prefix is validated as [a-z0-9-], so it needs no quoting.
        return '^' . $prefix . '/(' . Key::TYPE_PATTERN . ')-(' . Key::ID_PATTERN . ')-(' . Key::HASH_PATTERN . ')\.jpg$';
    }

    public function register(): void
    {
        add_rewrite_rule(
            self::regex($this->settings->string('url_prefix')),
            'index.php?' . Cards::QUERY_VAR . '=$matches[1]-$matches[2]-$matches[3]',
            'top'
        );
        $previous = $this->previous();
        if ($previous !== null) {
            add_rewrite_rule(
                self::regex($previous['prefix']),
                'index.php?' . Cards::PREVIOUS_QUERY_VAR . '=$matches[1]-$matches[2]-$matches[3]',
                'top'
            );
        }
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function queryVars(array $vars): array
    {
        $vars[] = Cards::QUERY_VAR;
        $vars[] = Cards::PREVIOUS_QUERY_VAR;
        return $vars;
    }

    /**
     * Whether the stored rules hold our pattern. Always true with plain
     * permalinks, which need no rule.
     */
    public function rulePresent(): bool
    {
        if (!Cards::prettyPermalinks()) {
            return true;
        }
        $rules = get_option('rewrite_rules');
        return is_array($rules) && array_key_exists(self::regex($this->settings->string('url_prefix')), $rules);
    }

    /**
     * On an admin page load: when another plugin flushed before our rule was
     * registered, flush once. A one-hour guard stops a flush loop.
     */
    public function repairIfMissing(): bool
    {
        if ($this->rulePresent() || get_transient(self::FLUSH_GUARD) !== false) {
            return false;
        }
        set_transient(self::FLUSH_GUARD, 1, 3600);
        $this->register();
        flush_rewrite_rules(false);
        return true;
    }

    /**
     * The previous prefix while it still routes.
     *
     * @return array{prefix: string, until: int}|null
     */
    public function previous(): ?array
    {
        $stored = get_option(self::PREVIOUS_OPTION, null);
        if (!is_array($stored) || !isset($stored['prefix'], $stored['until']) || !Settings::isPrefix($stored['prefix'])) {
            return null;
        }
        if ((int) $stored['until'] <= (int) $this->clock->now()) {
            return null;
        }
        return ['prefix' => (string) $stored['prefix'], 'until' => (int) $stored['until']];
    }

    /**
     * Records the old prefix and flushes the rules exactly once.
     */
    public function prefixChanged(string $old, Settings $new): void
    {
        $days = (int) apply_filters('share_cards_previous_prefix_days', self::PREVIOUS_DAYS);
        if ($days > 0 && Settings::isPrefix($old) && $old !== $new->string('url_prefix')) {
            update_option(self::PREVIOUS_OPTION, ['prefix' => $old, 'until' => (int) $this->clock->now() + $days * 86400], false);
        } else {
            delete_option(self::PREVIOUS_OPTION);
        }
        $this->settings = $new;
        $this->register();
        flush_rewrite_rules(false);
    }
}
