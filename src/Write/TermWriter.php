<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Write;

use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Settings;

/**
 * Taxonomy assignment. Only taxonomies present in the incoming set are touched,
 * so a feed that cannot decide a yacht's type leaves the existing term alone.
 *
 * `$append` decides whether an assignment REPLACES the current terms (feed-owned
 * yachts) or is merged into them (hand-entered yachts, which are protected).
 */
final class TermWriter
{
    public function __construct(
        private Settings $settings,
        private Logger $log
    ) {
    }

    /**
     * @param array<string,array<int,string>> $terms taxonomy => term names
     * @return array{assigned:array<string,array<int,string>>,created:array<string,array<int,string>>,missing:array<string,array<int,string>>}
     */
    public function assign(int $postId, array $terms, ?bool $create = null, bool $append = false): array
    {
        $create ??= $this->settings->bool('create_terms', true);

        $report = ['assigned' => [], 'created' => [], 'missing' => []];

        foreach ($terms as $taxonomy => $names) {
            if (!taxonomy_exists($taxonomy) || !is_array($names) || $names === []) {
                continue;
            }

            $ids = [];
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }

                $term = get_term_by('name', $name, $taxonomy);
                if (!$term instanceof \WP_Term) {
                    $term = get_term_by('slug', sanitize_title($name), $taxonomy);
                }

                if ($term instanceof \WP_Term) {
                    $ids[] = (int) $term->term_id;
                    $report['assigned'][$taxonomy][] = $term->name;
                    continue;
                }

                if (!$create) {
                    $report['missing'][$taxonomy][] = $name;
                    $this->log->warn('write', 'term missing and term creation is off', [
                        'taxonomy' => $taxonomy,
                        'term'     => $name,
                    ]);
                    continue;
                }

                $created = wp_insert_term($name, $taxonomy);
                if (is_wp_error($created)) {
                    // A race can report "exists"; re-read before giving up.
                    $existing = get_term_by('name', $name, $taxonomy);
                    if ($existing instanceof \WP_Term) {
                        $ids[] = (int) $existing->term_id;
                        $report['assigned'][$taxonomy][] = $existing->name;
                        continue;
                    }
                    $report['missing'][$taxonomy][] = $name;
                    $this->log->warn('write', 'term creation failed', [
                        'taxonomy' => $taxonomy,
                        'term'     => $name,
                        'error'    => $created->get_error_message(),
                    ]);
                    continue;
                }

                $ids[] = (int) $created['term_id'];
                $report['assigned'][$taxonomy][] = $name;
                $report['created'][$taxonomy][] = $name;
            }

            if ($ids !== []) {
                // $append = true for hand-entered yachts: the feed may ADD a term but
                // never drop one an editor put there (2026-08-30 client mandate).
                wp_set_object_terms($postId, $ids, $taxonomy, $append);
            }
        }

        return $report;
    }

    /** @return array<int,string> current term names for a taxonomy */
    public static function current(int $postId, string $taxonomy): array
    {
        if ($postId <= 0) {
            return [];
        }
        $terms = get_the_terms($postId, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }
        return array_values(array_map(static fn(\WP_Term $t): string => $t->name, $terms));
    }
}
