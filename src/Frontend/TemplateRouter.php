<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Frontend;

use Otium\Yachtfolio\Support\Settings;
use Otium\Yachtfolio\Write\Ownership;

/**
 * Sends feed-created yachts to their own Bricks single template.
 *
 * WHY A HOOK AND NOT A SECOND POST TYPE
 * -------------------------------------
 * Bricks template conditions can key off post type, specific IDs, and taxonomy
 * terms — but not a custom field. The obvious way to get "a different single
 * template" is therefore a second CPT, and that is exactly the expensive way:
 * on this site 25+ Bricks query loops target `post_type: ["yacht"]`, five
 * separate filter element sets are bound to those loops, the filter index would
 * need rebuilding, both header templates carry postType conditions, and the 73
 * JetEngine meta fields are attached to `yacht`.
 *
 * Keeping ONE post type and swapping the template here costs ~20 lines and
 * touches none of that. Every query loop and every filter keeps working
 * untouched, because the filters are all taxonomy-based and taxonomies do not
 * care where a post came from.
 *
 * The hook is `bricks/active_templates`, fired in
 * `bricks/includes/database.php` (Bricks 2.3.8) as:
 *     apply_filters( 'bricks/active_templates', $active_templates, $post_id, $content_type )
 *
 * TRADE-OFF, STATED PLAINLY: the routing is invisible in the Bricks UI. Nobody
 * looking at the template's own "Conditions" panel will see why it is being
 * used. That is why the Yachtfolio metabox on every yacht prints which template
 * will render it, and why the Settings screen explains the rule.
 */
final class TemplateRouter
{
    public const OPTION_KEY = 'single_template_id';

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        add_filter('bricks/active_templates', [$this, 'route'], 10, 3);
    }

    /**
     * @param mixed  $active       Bricks' active template map (header/content/footer/...)
     * @param mixed  $postId       the post being rendered
     * @param mixed  $contentType  'content' | 'archive' | 'search' | ...
     * @return mixed
     */
    public function route($active, $postId, $contentType)
    {
        if (!is_array($active) || $contentType !== 'content') {
            return $active;
        }

        $postId = (int) $postId;
        $target = self::resolve_for($postId, $this->settings);

        if ($target === null) {
            return $active;
        }

        // Nothing to do if Bricks already chose it.
        if ((int) ($active['content'] ?? 0) === $target) {
            return $active;
        }

        $active['content'] = $target;

        return $active;
    }

    /**
     * The single source of truth for "which template renders this yacht".
     * Shared with the admin so the metabox can never disagree with reality.
     *
     * @return int|null template id, or null to leave Bricks' own choice alone
     */
    public static function resolve_for(int $postId, ?Settings $settings = null): ?int
    {
        if ($postId <= 0) {
            return null;
        }

        // Only real yacht posts. When a Bricks template is being viewed or
        // edited, $post_id is the template itself, so this also keeps the
        // builder predictable.
        if (get_post_type($postId) !== 'yacht') {
            return null;
        }

        if (Ownership::origin_of($postId) !== Ownership::ORIGIN_FEED) {
            return null; // hand-entered yachts keep the original template
        }

        $settings ??= new Settings();
        $target = (int) $settings->get(self::OPTION_KEY, 0);

        /**
         * Allow code to override the feed template per yacht.
         *
         * @param int $target  configured template id (0 = feature disabled)
         * @param int $postId  the yacht being rendered
         */
        $target = (int) apply_filters('oy_yf_single_template_id', $target, $postId);

        if ($target <= 0 || $target === $postId) {
            return null;
        }

        // A trashed or deleted template must never take the page down.
        if (get_post_status($target) !== 'publish') {
            return null;
        }
        if (get_post_type($target) !== 'bricks_template') {
            return null;
        }

        return $target;
    }

    /**
     * Content templates available to choose from, for the Settings dropdown.
     *
     * @return array<int,string> id => label
     */
    public static function content_templates(): array
    {
        $posts = get_posts([
            'post_type'   => 'bricks_template',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'meta_query'  => [
                [
                    'key'   => '_bricks_template_type',
                    'value' => 'content',
                ],
            ],
        ]);

        $out = [];
        foreach ($posts as $p) {
            $out[(int) $p->ID] = $p->post_title . ' (#' . $p->ID . ')';
        }

        return $out;
    }
}
