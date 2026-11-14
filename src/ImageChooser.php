<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Decides the image the current request advertises (cases C1 to C16).
 * Runs once per request and is memoised. Reads no files.
 *
 * Every image is an array: url, width, height, type, alt, source
 * (`card`, `featured`, `default`).
 */
final class ImageChooser
{
    /** @var array{0: bool, 1: array<string, mixed>|null}|null */
    private ?array $memo = null;

    public function __construct(private Plugin $plugin)
    {
    }

    public function reset(): void
    {
        $this->memo = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forRequest(): ?array
    {
        if ($this->memo === null) {
            $image = apply_filters('share_cards_image', $this->choose());
            $this->memo = [true, is_array($image) ? $image : null];
        }
        return $this->memo[1];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function choose(): ?array
    {
        $resolver = $this->plugin->resolver();

        // C13: a custom type that claims the request.
        foreach ($resolver->customTypes() as $type => $def) {
            if ($def['matchRequest'] === null) {
                continue;
            }
            $id = ($def['matchRequest'])();
            if (!is_string($id) && !is_int($id)) {
                continue;
            }
            $subject = $resolver->resolve($type, (string) $id);
            if ($subject !== null && $subject->isPublic) {
                return $this->cardOr($subject, null);
            }
        }

        if (is_front_page() && $resolver->frontPageCard()) {
            $subject = $resolver->resolve('site', '1');
            if ($subject !== null) {
                return $this->cardOr($subject, null);
            }
        }

        if (is_singular() && !is_attachment()) {
            $post = get_queried_object();
            if ($post instanceof \WP_Post && in_array($post->post_type, $resolver->postTypes(), true)) {
                return $this->forPost($post);
            }
            return $this->defaultImage();
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (!$term instanceof \WP_Term) {
                return null;
            }
            if (!in_array($term->taxonomy, $resolver->taxonomies(), true)) {
                // C9: leave the head alone.
                return null;
            }
            return $this->forTerm($term);
        }

        // C10: everything else.
        return $this->defaultImage();
    }

    /**
     * The image a single view of this post advertises.
     *
     * @return array<string, mixed>|null
     */
    public function forPost(\WP_Post $post): ?array
    {
        $resolver = $this->plugin->resolver();
        if (!in_array($post->post_type, $resolver->postTypes(), true)) {
            return $this->defaultImage();
        }
        if (!$resolver->postIsPublic($post)) {
            // C12: never a card URL for something not public.
            return $this->defaultImage();
        }
        $featured = (int) get_post_thumbnail_id($post);
        $generic = $featured > 0 && in_array($featured, $resolver->genericImages(), true);
        $mode = apply_filters('share_cards_mode', $this->plugin->settings()->string('mode'), $post);
        $mode = in_array($mode, ['auto', 'always', 'never'], true) ? $mode : 'auto';

        $featuredImage = $featured > 0 ? $this->attachmentImage($featured, $post) : null;

        if ($mode === 'never') {
            return $featuredImage ?? $this->defaultImage();
        }
        if ($mode === 'auto' && !$generic && $featuredImage !== null && $this->eligible($featuredImage)) {
            return $featuredImage;
        }
        $card = $this->card($resolver->resolvePost($post));
        if ($card !== null) {
            return $card;
        }
        // C16: no renderer. Featured image of any size, else default.
        return $featuredImage ?? $this->defaultImage();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forTerm(\WP_Term $term): ?array
    {
        $resolver = $this->plugin->resolver();
        if (!in_array($term->taxonomy, $resolver->taxonomies(), true)) {
            return null;
        }
        if ($resolver->termIsPublic($term)) {
            $card = $this->card($resolver->resolveTerm($term));
            if ($card !== null) {
                return $card;
            }
        }
        return $this->defaultImage();
    }

    /**
     * The card image for a subject, or null when cards are unavailable.
     *
     * @return array<string, mixed>|null
     */
    public function card(Subject $subject): ?array
    {
        if (!$subject->isPublic || $this->plugin->unavailableReason() !== null) {
            return null;
        }
        $alt = $subject->alt !== '' ? $subject->alt : ($subject->headline !== '' ? $subject->headline : TextLayout::normalise((string) get_bloginfo('name'), TextLayout::MAX_HEADLINE));
        return [
            'url' => $this->plugin->cards()->urlForSubject($subject),
            'width' => Template::WIDTH,
            'height' => Template::HEIGHT,
            'type' => 'image/jpeg',
            'alt' => $alt,
            'source' => 'card',
        ];
    }

    /**
     * @param array<string, mixed>|null $otherwise
     * @return array<string, mixed>|null
     */
    private function cardOr(Subject $subject, ?array $otherwise): ?array
    {
        return $this->card($subject) ?? $otherwise ?? $this->defaultImage();
    }

    /**
     * @param array<string, mixed> $image
     */
    private function eligible(array $image): bool
    {
        $s = $this->plugin->settings();
        $w = (int) $image['width'];
        $h = (int) $image['height'];
        if ($w < $s->int('min_width') || $h <= 0) {
            return false;
        }
        $ratio = $w / $h;
        return $ratio >= $s->float('aspect_min') && $ratio <= $s->float('aspect_max');
    }

    /**
     * The default image, if one is configured.
     *
     * @return array<string, mixed>|null
     */
    public function defaultImage(): ?array
    {
        $id = $this->plugin->settings()->int('default_image_id');
        return $id > 0 ? $this->attachmentImage($id, null, 'default') : null;
    }

    /**
     * An attachment at its largest size, with its real dimensions.
     *
     * @return array<string, mixed>|null
     */
    public function attachmentImage(int $id, ?\WP_Post $post, string $source = 'featured'): ?array
    {
        if ($id <= 0 || !wp_attachment_is_image($id)) {
            return null;
        }
        $src = wp_get_attachment_image_src($id, 'full');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '' || (int) $src[1] <= 0 || (int) $src[2] <= 0) {
            return null;
        }
        $alt = trim(wp_strip_all_tags((string) get_post_meta($id, '_wp_attachment_image_alt', true)));
        if ($alt === '' && $post !== null) {
            $alt = TextLayout::normalise(get_the_title($post), TextLayout::MAX_HEADLINE);
        }
        if ($alt === '') {
            $alt = TextLayout::normalise((string) get_bloginfo('name'), TextLayout::MAX_HEADLINE);
        }
        $mime = (string) get_post_mime_type($id);
        return [
            'url' => $src[0],
            'width' => (int) $src[1],
            'height' => (int) $src[2],
            'type' => $mime !== '' ? $mime : 'image/jpeg',
            'alt' => $alt,
            'source' => $source,
        ];
    }

    /**
     * Where a card URL redirects when it cannot be served now: the post's
     * featured image, else the default image, else ''.
     */
    public function fallbackUrl(Subject $subject): string
    {
        if ($subject->type === 'post') {
            $featured = (int) get_post_thumbnail_id((int) $subject->id);
            if ($featured > 0) {
                $image = $this->attachmentImage($featured, null);
                if ($image !== null) {
                    return HeadTags::absolute((string) $image['url']);
                }
            }
        }
        $default = $this->defaultImage();
        return $default !== null ? HeadTags::absolute((string) $default['url']) : '';
    }
}
