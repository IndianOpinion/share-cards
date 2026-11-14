<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Maps a key's type and id to a Subject, with every text field normalised.
 */
final class SubjectResolver
{
    public const BUILT_IN = ['post', 'term', 'site'];

    /** @var array<string, array{pattern: string, resolve: callable, matchRequest: callable|null}>|null */
    private ?array $types = null;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        $types = apply_filters('share_cards_post_types', ['post']);
        return is_array($types) ? array_values(array_filter($types, 'is_string')) : ['post'];
    }

    /**
     * @return list<string>
     */
    public function taxonomies(): array
    {
        $taxes = apply_filters('share_cards_taxonomies', ['category', 'post_tag']);
        return is_array($taxes) ? array_values(array_filter($taxes, 'is_string')) : ['category', 'post_tag'];
    }

    /**
     * @return list<int>
     */
    public function genericImages(): array
    {
        $ids = apply_filters('share_cards_generic_images', $this->settings->intList('generic_image_ids'));
        return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];
    }

    public function frontPageCard(): bool
    {
        return (bool) apply_filters('share_cards_front_page_card', false);
    }

    /**
     * Registered custom types, validated.
     *
     * @return array<string, array{pattern: string, resolve: callable, matchRequest: callable|null}>
     */
    public function customTypes(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }
        $raw = apply_filters('share_cards_types', []);
        $types = [];
        if (is_array($raw)) {
            foreach ($raw as $name => $def) {
                if (!is_string($name) || in_array($name, self::BUILT_IN, true) || preg_match('/^' . Key::TYPE_PATTERN . '$/D', $name) !== 1) {
                    continue;
                }
                if (!is_array($def) || !isset($def['resolve']) || !is_callable($def['resolve'])) {
                    continue;
                }
                $pattern = isset($def['pattern']) && is_string($def['pattern']) ? $def['pattern'] : '/^' . Key::ID_PATTERN . '$/D';
                $match = isset($def['matchRequest']) && is_callable($def['matchRequest']) ? $def['matchRequest'] : null;
                $types[$name] = ['pattern' => $pattern, 'resolve' => $def['resolve'], 'matchRequest' => $match];
            }
        }
        return $this->types = $types;
    }

    /**
     * Resolves a subject, or null when the type or subject is unknown.
     * The subject's `isPublic` says whether it may be shown.
     */
    public function resolve(string $type, string $id): ?Subject
    {
        if (preg_match('/^' . Key::ID_PATTERN . '$/D', $id) !== 1) {
            return null;
        }
        $subject = match ($type) {
            'post' => $this->post((int) $id),
            'term' => $this->term((int) $id),
            'site' => $id === '1' ? $this->site() : null,
            default => $this->custom($type, $id),
        };
        return $subject === null ? null : $this->finish($subject, $type, $id);
    }

    public function resolvePost(\WP_Post $post): Subject
    {
        return $this->finish($this->postSubject($post), 'post', (string) $post->ID);
    }

    public function resolveTerm(\WP_Term $term): Subject
    {
        return $this->finish($this->termSubject($term), 'term', (string) $term->term_id);
    }

    /**
     * Whether a post may have a public card.
     */
    public function postIsPublic(\WP_Post $post): bool
    {
        return in_array($post->post_type, $this->postTypes(), true)
            && $post->post_status === 'publish'
            && (string) $post->post_password === ''
            && is_post_type_viewable($post->post_type);
    }

    public function termIsPublic(\WP_Term $term): bool
    {
        return $this->settings->bool('term_cards')
            && in_array($term->taxonomy, $this->taxonomies(), true)
            && is_taxonomy_viewable($term->taxonomy);
    }

    /**
     * The featured image, unless generic or vetoed, else 0.
     */
    public function backdropFor(\WP_Post $post): int
    {
        $featured = (int) get_post_thumbnail_id($post);
        $id = $featured;
        if ($id > 0 && in_array($id, $this->genericImages(), true)) {
            $id = 0;
        }
        if (!$this->settings->bool('backdrop')) {
            $id = 0;
        }
        $filtered = apply_filters('share_cards_backdrop_attachment', $id, $post);
        $id = is_numeric($filtered) ? (int) $filtered : 0;
        // A generic image is never a backdrop, even when a filter offers it.
        return $id > 0 && !in_array($id, $this->genericImages(), true) ? $id : 0;
    }

    private function post(int $id): ?Subject
    {
        $post = get_post($id);
        return $post instanceof \WP_Post ? $this->postSubject($post) : null;
    }

    private function postSubject(\WP_Post $post): Subject
    {
        $kicker = '';
        if (is_object_in_taxonomy($post->post_type, 'category')) {
            $terms = get_the_terms($post, 'category');
            if (is_array($terms) && $terms !== [] && $terms[0] instanceof \WP_Term) {
                $kicker = $terms[0]->name;
            }
        }
        return new Subject(
            'post',
            (string) $post->ID,
            get_the_title($post),
            $kicker,
            '',
            $this->backdropFor($post),
            '',
            $this->postIsPublic($post)
        );
    }

    private function term(int $id): ?Subject
    {
        $term = get_term($id);
        return $term instanceof \WP_Term ? $this->termSubject($term) : null;
    }

    private function termSubject(\WP_Term $term): Subject
    {
        $labels = $this->settings->stringMap('taxonomy_labels');
        $kicker = $labels[$term->taxonomy] ?? '';
        if ($kicker === '') {
            $tax = get_taxonomy($term->taxonomy);
            if ($tax instanceof \WP_Taxonomy && isset($tax->labels->singular_name)) {
                $kicker = (string) $tax->labels->singular_name;
            }
        }
        $footnote = $this->settings->string('term_footnote');
        if ($footnote !== '') {
            $footnote = str_replace('{count}', (string) (int) $term->count, $footnote);
        }
        return new Subject('term', (string) $term->term_id, $term->name, $kicker, $footnote, 0, '', $this->termIsPublic($term));
    }

    private function site(): ?Subject
    {
        if (!$this->frontPageCard()) {
            return null;
        }
        $name = (string) get_bloginfo('name');
        $tagline = (string) get_bloginfo('description');
        return new Subject('site', '1', $tagline !== '' ? $tagline : $name, '', '', 0, $name, true);
    }

    private function custom(string $type, string $id): ?Subject
    {
        $types = $this->customTypes();
        if (!isset($types[$type])) {
            return null;
        }
        $pattern = $types[$type]['pattern'];
        set_error_handler(static fn (): bool => true);
        try {
            $ok = preg_match($pattern, $id) === 1;
        } finally {
            restore_error_handler();
        }
        if (!$ok) {
            return null;
        }
        $subject = ($types[$type]['resolve'])($id);
        if (!$subject instanceof Subject) {
            return null;
        }
        // The key decides type and id, never the resolver.
        return new Subject($type, $id, $subject->headline, $subject->kicker, $subject->footnote, $subject->backdropId, $subject->alt, $subject->isPublic);
    }

    private function finish(Subject $subject, string $type, string $id): Subject
    {
        $filtered = apply_filters('share_cards_subject', $subject, $type, $id);
        if ($filtered instanceof Subject) {
            $subject = $filtered;
        }
        $backdrop = $subject->backdropId;
        if ($backdrop > 0 && in_array($backdrop, $this->genericImages(), true)) {
            $backdrop = 0;
        }
        return new Subject(
            $type,
            $id,
            TextLayout::normalise($subject->headline, TextLayout::MAX_HEADLINE),
            TextLayout::normalise($subject->kicker, TextLayout::MAX_KICKER),
            TextLayout::normalise($subject->footnote, TextLayout::MAX_FOOTNOTE),
            $backdrop,
            TextLayout::normalise($subject->alt, TextLayout::MAX_HEADLINE),
            $subject->isPublic
        );
    }
}
