<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Admin;

use Otium\Yachtfolio\Activator;
use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Support\Secrets;
use Otium\Yachtfolio\Write\Writer;

final class Ajax
{
    private const ACTIONS = [
        'oy_yf_sync_one',
        'oy_yf_dry_run',
        'oy_yf_show_json',
        'oy_yf_fetch_media',
        'oy_yf_toggle_visible',
        'oy_yf_toggle_selected',
        'oy_yf_publish',
        'oy_yf_unpublish',
        'oy_yf_link',
        'oy_yf_unlink',
        'oy_yf_bulk',
        'oy_yf_check',
        'oy_yf_refresh_reference',
    ];

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        foreach (self::ACTIONS as $action) {
            add_action('wp_ajax_' . $action, [$this, 'dispatch']);
        }
    }

    public function dispatch(): void
    {
        check_ajax_referer(Menu::NONCE_AJAX, 'nonce');

        if (!current_user_can(Activator::CAPABILITY)) {
            wp_send_json_error(['message' => __('Not allowed.', 'otium-yachtfolio-sync')], 403);
        }

        $action = isset($_POST['action']) ? sanitize_key((string) wp_unslash($_POST['action'])) : '';
        $yfId = isset($_POST['yacht']) ? (int) $_POST['yacht'] : 0;

        try {
            match ($action) {
                'oy_yf_sync_one'          => $this->sync_one($yfId),
                'oy_yf_dry_run'           => $this->dry_run($yfId),
                'oy_yf_show_json'         => $this->show_json($yfId),
                'oy_yf_fetch_media'       => $this->fetch_media($yfId),
                'oy_yf_toggle_visible'    => $this->toggle_visible($yfId),
                'oy_yf_toggle_selected'   => $this->toggle_selected($yfId),
                'oy_yf_publish'           => $this->set_status($yfId, 'publish'),
                'oy_yf_unpublish'         => $this->set_status($yfId, 'draft'),
                'oy_yf_link'              => $this->link($yfId),
                'oy_yf_unlink'            => $this->unlink($yfId),
                'oy_yf_bulk'              => $this->bulk(),
                'oy_yf_check'             => $this->check(),
                'oy_yf_refresh_reference' => $this->refresh_reference(),
                default                   => wp_send_json_error(['message' => __('Unknown action.', 'otium-yachtfolio-sync')], 400),
            };
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => (string) Secrets::scrub($e->getMessage())], 500);
        }
    }

    /* ------------------------------------------------------------------ */

    private function sync_one(int $yfId): void
    {
        $this->require_yacht($yfId);
        $result = $this->plugin->orchestrator()->sync_yacht($yfId, ['trigger' => 'admin']);

        $payload = [
            'status'    => (string) $result['status'],
            'message'   => (string) $result['message'],
            'post_id'   => $result['post_id'],
            'attention' => $result['attention'],
        ];

        $result['status'] === 'error'
            ? wp_send_json_error($payload, 200)
            : wp_send_json_success($payload);
    }

    private function dry_run(int $yfId): void
    {
        $this->require_yacht($yfId);
        $result = $this->plugin->orchestrator()->sync_yacht($yfId, ['dry_run' => true, 'trigger' => 'admin']);

        wp_send_json_success([
            'status'  => (string) $result['status'],
            'message' => (string) $result['message'],
            'diff'    => $result['diff'],
        ]);
    }

    private function show_json(int $yfId): void
    {
        $row = $this->require_yacht($yfId);
        $postId = (int) ($row['post_id'] ?? 0);

        if ($postId <= 0) {
            wp_send_json_error(['message' => __('This yacht has no linked post yet, so no payload is stored.', 'otium-yachtfolio-sync')]);
        }

        // Writer::read_raw() already scrubs; the gallery URLs in the payload
        // carry the passkey and must never leave the server unmasked.
        $raw = Writer::read_raw($postId);
        if ($raw === '') {
            wp_send_json_error(['message' => __('No stored payload for this yacht.', 'otium-yachtfolio-sync')]);
        }

        $decoded = json_decode($raw, true);
        $pretty = is_array($decoded)
            ? (string) wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $raw;

        wp_send_json_success([
            'json'  => (string) Secrets::scrub($pretty),
            'title' => (string) $row['yacht_name'],
        ]);
    }

    /**
     * Fetches a yacht's photos on demand.
     *
     * The import brings text for every yacht but no images, because images are
     * the expensive part. Until now the only way to get them was to know that
     * the Visible switch also gates media — which the label does not say. This
     * is the explicit action: one click, states what it will do, and reports
     * what it did.
     *
     * Opening the gate is part of the operation, not a separate step the admin
     * has to discover.
     */
    private function fetch_media(int $yfId): void
    {
        $row = $this->require_yacht($yfId);
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            wp_send_json_error([
                'message' => __('Import this yacht first — there is no post to attach photos to.', 'otium-yachtfolio-sync'),
            ]);
        }

        update_post_meta($postId, 'yf_visible', 'true');

        $result = $this->plugin->orchestrator()->sync_media($yfId);

        if (empty($result['ok'])) {
            wp_send_json_error([
                'message' => (string) ($result['message'] ?? __('Could not fetch the photos.', 'otium-yachtfolio-sync')),
            ]);
        }

        $imported = (int) ($result['imported'] ?? 0);
        $failed   = (int) ($result['failed'] ?? 0);

        wp_send_json_success([
            'imported' => $imported,
            'message'  => $failed > 0
                ? sprintf(
                    /* translators: 1: imported count, 2: failed count */
                    __('%1$d photos imported, %2$d failed — see the log.', 'otium-yachtfolio-sync'),
                    $imported,
                    $failed
                )
                : sprintf(
                    /* translators: %d: number of photos */
                    _n('%d photo imported.', '%d photos imported.', $imported, 'otium-yachtfolio-sync'),
                    $imported
                ),
        ]);
    }

    private function toggle_visible(int $yfId): void
    {
        $row = $this->require_yacht($yfId);
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            wp_send_json_error(['message' => __('Link the yacht to a post first.', 'otium-yachtfolio-sync')]);
        }

        $on = (string) get_post_meta($postId, 'yf_visible', true) !== 'true';
        update_post_meta($postId, 'yf_visible', $on ? 'true' : 'false');

        wp_send_json_success([
            'visible' => $on,
            'message' => $on
                ? __('Marked visible.', 'otium-yachtfolio-sync')
                : __('Marked hidden.', 'otium-yachtfolio-sync'),
        ]);
    }

    private function toggle_selected(int $yfId): void
    {
        $row = $this->require_yacht($yfId);
        $on = empty($row['selected']);
        $this->plugin->map()->set_selected($yfId, $on);

        wp_send_json_success([
            'selected' => $on,
            'message'  => $on
                ? __('Added to the sync selection.', 'otium-yachtfolio-sync')
                : __('Removed from the sync selection.', 'otium-yachtfolio-sync'),
        ]);
    }

    /** The only place a post status ever changes, and always by explicit click. */
    private function set_status(int $yfId, string $status): void
    {
        $row = $this->require_yacht($yfId);
        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId <= 0) {
            wp_send_json_error(['message' => __('Link the yacht to a post first.', 'otium-yachtfolio-sync')]);
        }

        if ($status === 'publish' && (string) get_post_meta($postId, 'yf_visible', true) !== 'true') {
            wp_send_json_error(['message' => __('Mark the yacht visible before publishing it.', 'otium-yachtfolio-sync')]);
        }

        $updated = wp_update_post(['ID' => $postId, 'post_status' => $status], true);
        if (is_wp_error($updated)) {
            wp_send_json_error(['message' => $updated->get_error_message()]);
        }

        wp_send_json_success([
            'post_status' => $status,
            'message'     => $status === 'publish'
                ? __('Published.', 'otium-yachtfolio-sync')
                : __('Moved to draft.', 'otium-yachtfolio-sync'),
        ]);
    }

    private function link(int $yfId): void
    {
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($yfId <= 0 || $postId <= 0) {
            wp_send_json_error(['message' => __('Both a feed id and a post id are required.', 'otium-yachtfolio-sync')]);
        }

        $this->plugin->linker()->confirm($yfId, $postId);
        wp_send_json_success(['message' => __('Linked.', 'otium-yachtfolio-sync')]);
    }

    private function unlink(int $yfId): void
    {
        $this->require_yacht($yfId);
        $this->plugin->map()->unlink($yfId);
        wp_send_json_success(['message' => __('Unlinked; the post keeps its content.', 'otium-yachtfolio-sync')]);
    }

    private function bulk(): void
    {
        $operation = isset($_POST['operation']) ? sanitize_key((string) wp_unslash($_POST['operation'])) : '';
        $ids = array_map('intval', (array) ($_POST['yacht_ids'] ?? []));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

        if ($ids === []) {
            wp_send_json_error(['message' => __('No yachts selected.', 'otium-yachtfolio-sync')]);
        }

        $map = $this->plugin->map();
        $done = 0;
        $messages = [];
        // Rows a bulk action deliberately refused, reported back rather than
        // silently dropped: "12 processed" out of 20 selected is a question.
        $skipped = [];

        foreach ($ids as $yfId) {
            $row = $map->get($yfId);
            if ($row === null) {
                continue;
            }
            $postId = (int) ($row['post_id'] ?? 0);

            switch ($operation) {
                case 'select':
                case 'unselect':
                    $map->set_selected($yfId, $operation === 'select');
                    $done++;
                    break;

                case 'show':
                case 'hide':
                    if ($postId <= 0) {
                        $skipped[] = sprintf(
                            /* translators: %s: yacht name */
                            __('%s: not linked to a post', 'otium-yachtfolio-sync'),
                            (string) $row['yacht_name']
                        );
                        break;
                    }
                    update_post_meta($postId, 'yf_visible', $operation === 'show' ? 'true' : 'false');
                    $done++;
                    break;

                case 'show_sync':
                    if ($postId <= 0) {
                        $skipped[] = sprintf(
                            /* translators: %s: yacht name */
                            __('%s: not linked to a post', 'otium-yachtfolio-sync'),
                            (string) $row['yacht_name']
                        );
                        break;
                    }
                    // Order matters: the gate has to be open before the pass
                    // runs, or the media step skips the yacht it was opened for.
                    update_post_meta($postId, 'yf_visible', 'true');
                    $result = $this->plugin->orchestrator()->sync_yacht($yfId, [
                        'dry_run' => false,
                        'trigger' => 'admin',
                    ]);
                    $done++;
                    if ($result['status'] === 'error') {
                        $messages[] = sprintf('%s: %s', (string) $row['yacht_name'], (string) $result['message']);
                    }
                    break;

                case 'publish':
                case 'unpublish':
                    if ($postId <= 0) {
                        $skipped[] = sprintf(
                            /* translators: %s: yacht name */
                            __('%s: not linked to a post', 'otium-yachtfolio-sync'),
                            (string) $row['yacht_name']
                        );
                        break;
                    }
                    // Same rule the single-row action enforces: Visible is the
                    // gate in front of publishing, and a bulk action must not be
                    // a way around it.
                    if ($operation === 'publish'
                        && (string) get_post_meta($postId, 'yf_visible', true) !== 'true') {
                        $skipped[] = sprintf(
                            /* translators: %s: yacht name */
                            __('%s: not marked visible', 'otium-yachtfolio-sync'),
                            (string) $row['yacht_name']
                        );
                        break;
                    }
                    wp_update_post([
                        'ID'          => $postId,
                        'post_status' => $operation === 'publish' ? 'publish' : 'draft',
                    ]);
                    $done++;
                    break;

                case 'sync':
                case 'dry_run':
                    $result = $this->plugin->orchestrator()->sync_yacht($yfId, [
                        'dry_run' => $operation === 'dry_run',
                        'trigger' => 'admin',
                    ]);
                    $done++;
                    if ($result['status'] === 'error') {
                        $messages[] = sprintf('%s: %s', (string) $row['yacht_name'], (string) $result['message']);
                    }
                    break;

                default:
                    wp_send_json_error(['message' => __('Unknown bulk operation.', 'otium-yachtfolio-sync')]);
            }
        }

        $summary = sprintf(
            /* translators: %d: number of yachts */
            _n('%d yacht processed.', '%d yachts processed.', $done, 'otium-yachtfolio-sync'),
            $done
        );

        if ($skipped !== []) {
            $summary .= ' ' . sprintf(
                /* translators: 1: number skipped, 2: reasons */
                __('%1$d skipped — %2$s', 'otium-yachtfolio-sync'),
                count($skipped),
                implode('; ', array_slice($skipped, 0, 5))
            );
        }
        if ($messages !== []) {
            $summary .= ' ' . implode(' | ', array_slice($messages, 0, 5));
        }

        wp_send_json_success([
            'processed' => $done,
            'skipped'   => count($skipped),
            'message'   => $summary,
        ]);
    }

    private function check(): void
    {
        $rows = [];
        foreach ($this->plugin->client()->ping() as $probe) {
            $rows[] = [
                'probe'  => (string) $probe['probe'],
                'ok'     => (bool) $probe['ok'],
                'detail' => (string) $probe['detail'],
            ];
        }
        wp_send_json_success(['probes' => $rows]);
    }

    private function refresh_reference(): void
    {
        $counts = $this->plugin->reference()->refresh(true);
        wp_send_json_success(['counts' => $counts]);
    }

    /** @return array<string,mixed> */
    private function require_yacht(int $yfId): array
    {
        if ($yfId <= 0) {
            wp_send_json_error(['message' => __('Missing yacht id.', 'otium-yachtfolio-sync')], 400);
        }
        $row = $this->plugin->map()->get($yfId);
        if ($row === null) {
            wp_send_json_error(['message' => __('This yacht is not in the index; run an index pass first.', 'otium-yachtfolio-sync')], 404);
        }
        return $row;
    }
}
