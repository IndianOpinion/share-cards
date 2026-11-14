<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Deletes card files that must not be seen any more, at once, and fires
 * `share_cards_purged` with their URLs so a CDN purge can follow.
 */
final class Invalidator
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onSavePost'], 20, 2);
        add_action('added_post_meta', [$this, 'onPostMeta'], 20, 3);
        add_action('updated_post_meta', [$this, 'onPostMeta'], 20, 3);
        add_action('deleted_post_meta', [$this, 'onPostMeta'], 20, 3);
        add_action('before_delete_post', [$this, 'onDeletePost'], 20, 1);
        add_action('edited_term', [$this, 'onEditedTerm'], 20, 3);
        add_action('delete_term', [$this, 'onDeleteTerm'], 20, 3);
    }

    public function onSavePost(int $postId, ?\WP_Post $post = null): void
    {
        $post ??= get_post($postId);
        if (!$post instanceof \WP_Post || wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }
        $this->refreshPost($post);
    }

    /**
     * The featured image is stored as post meta, sometimes after `save_post`.
     *
     * @param int|array<int, int> $metaId
     */
    public function onPostMeta($metaId, int $objectId, string $metaKey): void
    {
        if ($metaKey !== '_thumbnail_id') {
            return;
        }
        $post = get_post($objectId);
        if ($post instanceof \WP_Post) {
            $this->refreshPost($post);
        }
    }

    public function onDeletePost(int $postId): void
    {
        $this->purge($this->plugin->cache()->deleteVariants('post', (string) $postId), 'delete');
    }

    public function onEditedTerm(int $termId, int $ttId = 0, string $taxonomy = ''): void
    {
        $term = get_term($termId);
        if (!$term instanceof \WP_Term) {
            return;
        }
        $resolver = $this->plugin->resolver();
        if (!$resolver->termIsPublic($term)) {
            $this->purge($this->plugin->cache()->deleteVariants('term', (string) $termId), 'unpublish');
            return;
        }
        $current = $this->plugin->cards()->keyFor($resolver->resolveTerm($term));
        $this->purge($this->plugin->cache()->deleteVariants('term', (string) $termId, $current), 'edit');
    }

    public function onDeleteTerm(int $termId, int $ttId = 0, string $taxonomy = ''): void
    {
        $this->purge($this->plugin->cache()->deleteVariants('term', (string) $termId), 'delete');
    }

    public function refreshPost(\WP_Post $post): void
    {
        $resolver = $this->plugin->resolver();
        $cache = $this->plugin->cache();
        $id = (string) $post->ID;
        if (!$resolver->postIsPublic($post)) {
            $this->purge($cache->deleteVariants('post', $id), 'unpublish');
            return;
        }
        $current = $this->plugin->cards()->keyFor($resolver->resolvePost($post));
        $this->purge($cache->deleteVariants('post', $id, $current), 'edit');
    }

    /**
     * @param list<Key> $keys
     */
    public function purge(array $keys, string $reason): void
    {
        if ($keys === []) {
            return;
        }
        $urls = [];
        foreach ($keys as $key) {
            $urls[] = $this->plugin->cards()->url($key);
        }
        $this->plugin->logger()->info(sprintf('purge count=%d reason=%s', count($urls), $reason));
        do_action('share_cards_purged', $urls, $reason);
    }
}
