<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Sync;

/**
 * Suggests links between existing yacht posts and feed rows.
 *
 * Measured on the real catalogue: 9 of 19 posts match a feed name exactly
 * (Acapella, Green Ray, Libra, Lotus, Navilux, Maia, Omnia, Oriy, Son De Mar),
 * the other 10 have no counterpart at all. Nothing is ever linked
 * automatically without a human confirming it.
 */
final class Linker
{
    public function __construct(private YachtMapStore $map)
    {
    }

    public static function normalize(string $name): string
    {
        $name = strtolower(remove_accents($name));
        $name = (string) preg_replace('/^\s*(m\s*\/?\s*y|s\s*\/?\s*y|motor\s+yacht|sailing\s+yacht)\b/', '', $name);
        return (string) preg_replace('/[^a-z0-9]/', '', $name);
    }

    /**
     * @return array<int,array{yf_id:int,yf_name:string,post_id:int,post_title:string,score:int,kind:string}>
     */
    public function suggest(int $limit = 0): array
    {
        $posts = $this->yacht_posts();
        $feed = $this->feed_rows();

        $byName = [];
        foreach ($feed as $row) {
            $byName[self::normalize((string) $row['yacht_name'])][] = $row;
        }

        $suggestions = [];
        foreach ($posts as $post) {
            if ($this->map->by_post((int) $post->ID) !== null) {
                continue; // already linked
            }

            $needle = self::normalize($post->post_title);
            if ($needle === '') {
                continue;
            }

            if (isset($byName[$needle])) {
                $row = $byName[$needle][0];
                $suggestions[] = [
                    'yf_id'      => (int) $row['yf_id'],
                    'yf_name'    => (string) $row['yacht_name'],
                    'post_id'    => (int) $post->ID,
                    'post_title' => (string) $post->post_title,
                    'score'      => 100,
                    'kind'       => 'exact',
                ];
                continue;
            }

            $best = null;
            $bestScore = 0.0;
            foreach ($byName as $candidate => $rows) {
                if ($candidate === '') {
                    continue;
                }
                similar_text($needle, $candidate, $percent);
                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $rows[0];
                }
            }

            if ($best !== null && $bestScore >= 88.0) {
                $suggestions[] = [
                    'yf_id'      => (int) $best['yf_id'],
                    'yf_name'    => (string) $best['yacht_name'],
                    'post_id'    => (int) $post->ID,
                    'post_title' => (string) $post->post_title,
                    'score'      => (int) round($bestScore),
                    'kind'       => 'fuzzy',
                ];
            }
        }

        usort($suggestions, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $limit > 0 ? array_slice($suggestions, 0, $limit) : $suggestions;
    }

    /** @throws \RuntimeException */
    public function confirm(int $yfId, int $postId): void
    {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'yacht') {
            throw new \RuntimeException("post $postId is not a yacht");
        }

        $row = $this->map->get($yfId);
        if ($row === null) {
            throw new \RuntimeException("yacht $yfId is not in the index; run the index pass first");
        }
        if (!empty($row['post_id']) && (int) $row['post_id'] !== $postId) {
            throw new \RuntimeException("yacht $yfId is already linked to post " . (int) $row['post_id']);
        }

        $existing = $this->map->by_post($postId);
        if ($existing !== null && (int) $existing['yf_id'] !== $yfId) {
            throw new \RuntimeException("post $postId is already linked to yacht " . (int) $existing['yf_id']);
        }

        $this->map->link($yfId, $postId);
    }

    /** @return array<int,array<string,mixed>> posts with no feed counterpart */
    public function unmatched_posts(): array
    {
        $suggested = [];
        foreach ($this->suggest() as $suggestion) {
            $suggested[$suggestion['post_id']] = true;
        }

        $out = [];
        foreach ($this->yacht_posts() as $post) {
            if (isset($suggested[$post->ID]) || $this->map->by_post((int) $post->ID) !== null) {
                continue;
            }
            $out[] = ['post_id' => (int) $post->ID, 'post_title' => (string) $post->post_title];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> feed rows with no linked post */
    public function unmatched_feed(): array
    {
        return $this->map->all(['linked' => false, 'limit' => 1000]);
    }

    /** @return array<int,\WP_Post> */
    private function yacht_posts(): array
    {
        $query = new \WP_Query([
            'post_type'      => 'yacht',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        /** @var array<int,\WP_Post> */
        return $query->posts;
    }

    /** @return array<int,array<string,mixed>> */
    private function feed_rows(): array
    {
        return $this->map->all(['limit' => 1000]);
    }
}
