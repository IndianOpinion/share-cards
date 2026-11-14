<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Current keys and URLs for subjects.
 */
final class Cards
{
    public const QUERY_VAR = 'share_card';
    public const PREVIOUS_QUERY_VAR = 'share_card_prev';

    public function __construct(
        private Settings $settings,
        private SubjectResolver $resolver,
        private Fingerprint $fingerprint
    ) {
    }

    /**
     * The current key of a subject. Computing it reads no image file.
     */
    public function keyFor(Subject $subject): Key
    {
        $file = $subject->backdropId > 0 ? (string) get_attached_file($subject->backdropId, true) : '';
        return Key::fromParts($subject->type, $subject->id, $this->fingerprint->hash($subject, $file));
    }

    public static function prettyPermalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    public function url(Key $key, ?string $prefix = null): string
    {
        $prefix ??= $this->settings->string('url_prefix');
        if (self::prettyPermalinks()) {
            return home_url('/' . $prefix . '/' . $key->filename());
        }
        return add_query_arg(self::QUERY_VAR, $key->toString(), home_url('/'));
    }

    public function urlForSubject(Subject $subject): string
    {
        return $this->url($this->keyFor($subject));
    }

    public function resolver(): SubjectResolver
    {
        return $this->resolver;
    }
}
