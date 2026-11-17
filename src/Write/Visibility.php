<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

/**
 * "Visible" and "published" are one decision.
 *
 * Up to 0.14.x Visible was only a gate in front of a separate Publish action.
 * Editors switched Visible on, read the switch as "on the website", and sent
 * visitors to yachts that were still drafts — 26 such yachts on 2026-10-07,
 * every one a 404. The client's rule since then: a yacht marked visible is
 * public, and a hidden one is not.
 *
 * Both directions are enforced on the write itself, so no current or future
 * path — the editor metabox, the row switch, a bulk action, WordPress's own
 * Publish button, WP-CLI — can leave the two disagreeing:
 *  - yf_visible set to 'true'  → a draft or pending yacht is published;
 *  - yf_visible set to 'false' → a published yacht goes back to draft;
 *  - a yacht published by any other means is marked visible, and one taken
 *    off the site (draft, pending, private, trash) is marked hidden.
 *
 * The importer never writes yf_visible except to initialise a new draft as
 * hidden (Ownership::ADDON_ONLY), so a sync can never publish anything.
 */
final class Visibility
{
    public const META = 'yf_visible';

    /** Statuses a visible yacht is promoted from. Scheduled and private are left alone. */
    private const PROMOTABLE = ['draft', 'pending'];

    /** Set while this class is writing, so its own write does not echo back. */
    private bool $busy = false;

    public function register(): void
    {
        add_action('added_post_meta', [$this, 'on_meta'], 20, 4);
        add_action('updated_post_meta', [$this, 'on_meta'], 20, 4);
        add_action('transition_post_status', [$this, 'on_status'], 10, 3);
    }

    /**
     * @param int|string $metaId
     * @param int|string $postId
     */
    public function on_meta(mixed $metaId, mixed $postId, string $metaKey, mixed $value): void
    {
        if ($metaKey !== self::META || $this->busy) {
            return;
        }
        $post = get_post((int) $postId);
        if (!$post || $post->post_type !== 'yacht') {
            return;
        }

        $visible = (string) $value === 'true';
        if ($visible && in_array($post->post_status, self::PROMOTABLE, true)) {
            $this->set_status($post->ID, 'publish');
        } elseif (!$visible && $post->post_status === 'publish') {
            $this->set_status($post->ID, 'draft');
        }
    }

    public function on_status(string $new, string $old, \WP_Post $post): void
    {
        if ($this->busy || $post->post_type !== 'yacht' || $new === $old) {
            return;
        }
        if ($new === 'publish') {
            $flag = 'true';
        } elseif ($old === 'publish') {
            $flag = 'false';
        } else {
            return;
        }

        $this->busy = true;
        try {
            update_post_meta($post->ID, self::META, $flag);
        } finally {
            $this->busy = false;
        }
    }

    private function set_status(int $postId, string $status): void
    {
        $this->busy = true;
        try {
            wp_update_post(['ID' => $postId, 'post_status' => $status]);
        } finally {
            $this->busy = false;
        }
    }
}
