<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Media;

/**
 * Access layer for {prefix}oy_yf_media — one row per Yachtfolio file we have
 * already pulled into the media library.
 *
 * This is the dedupe memory of the media importer: `id_file` is the API's stable
 * identity for a binary, so a row here means "never download this again".
 * Without it a re-sync of the linked yachts would burn hundreds of media calls
 * and duplicate every image.
 *
 * The source URL is deliberately **not** a column: every `media/res` URL carries
 * `&api=<PASSKEY>`, so only `id_file` + `filename` are ever stored.
 */
final class MediaLedger
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oy_yf_media';
    }

    /**
     * @return array<string,mixed>|null the ledger row for a Yachtfolio file id
     */
    public function find(string $idFile): ?array
    {
        global $wpdb;

        $idFile = trim($idFile);
        if ($idFile === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id_file = %s', $idFile),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Idempotent upsert keyed on the UNIQUE `id_file`.
     *
     * Re-importing the same file (for instance after the attachment was deleted
     * by hand) rewrites the row in place instead of tripping the unique key.
     */
    public function record(
        string $idFile,
        int $attachmentId,
        int $yfId,
        string $gallery,
        string $filename,
        string $realExt,
        int $bytes
    ): void {
        global $wpdb;

        $idFile = substr(trim($idFile), 0, 64);
        if ($idFile === '' || $attachmentId <= 0) {
            return;
        }

        $data = [
            'id_file'       => $idFile,
            'attachment_id' => $attachmentId,
            'yf_id'         => $yfId,
            'gallery_type'  => substr(strtoupper($gallery), 0, 16),
            'filename'      => substr($filename, 0, 255),
            'real_ext'      => substr(strtolower(ltrim($realExt, '.')), 0, 8),
            'bytes'         => max(0, $bytes),
            'imported_at'   => current_time('mysql', true),
        ];
        $format = ['%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s'];

        if ($this->find($idFile) !== null) {
            unset($data['id_file']);
            array_shift($format);
            $wpdb->update(self::table(), $data, ['id_file' => $idFile], $format, ['%s']);
            return;
        }

        $wpdb->insert(self::table(), $data, $format);
    }

    public function forget(string $idFile): void
    {
        global $wpdb;

        $idFile = substr(trim($idFile), 0, 64);
        if ($idFile === '') {
            return;
        }

        $wpdb->delete(self::table(), ['id_file' => $idFile], ['%s']);
    }

    /** How many files the ledger holds for one yacht. */
    public function count_for(int $yfId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM ' . self::table() . ' WHERE yf_id = %d', $yfId)
        );
    }

    /**
     * Attachment IDs for one yacht in import order (ledger `id` ascending),
     * which is the order the `gallery` CSV must keep.
     *
     * @param array<int,string> $galleries empty = every gallery type
     * @return array<int,int>
     */
    public function attachments_for(int $yfId, array $galleries = []): array
    {
        global $wpdb;

        $sql = 'SELECT attachment_id FROM ' . self::table() . ' WHERE yf_id = %d';
        $args = [$yfId];

        $galleries = array_values(array_unique(array_filter(array_map(
            static fn($g): string => strtoupper(trim((string) $g)),
            $galleries
        ))));

        if ($galleries !== []) {
            $sql .= ' AND gallery_type IN (' . implode(', ', array_fill(0, count($galleries), '%s')) . ')';
            $args = array_merge($args, $galleries);
        }

        $sql .= ' ORDER BY id ASC';

        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args));

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    public function attachment_exists(int $attachmentId): bool
    {
        global $wpdb;

        if ($attachmentId <= 0) {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment' LIMIT 1",
            $attachmentId
        ));

        return (int) $found === $attachmentId;
    }

    /**
     * Drop ledger rows whose attachment has been deleted from the media library,
     * so the next run re-imports them instead of silently pointing at nothing.
     *
     * @return int rows removed
     */
    public function prune_missing(): int
    {
        global $wpdb;

        $table = self::table();
        $rows = $wpdb->get_results("SELECT id, attachment_id FROM {$table} ORDER BY id ASC", ARRAY_A);
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $wanted = array_values(array_unique(array_map(
            static fn(array $r): int => (int) $r['attachment_id'],
            $rows
        )));

        $alive = [];
        foreach (array_chunk($wanted, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $found = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ($placeholders)",
                ...$chunk
            ));
            foreach (is_array($found) ? $found : [] as $id) {
                $alive[(int) $id] = true;
            }
        }

        $dead = [];
        foreach ($rows as $row) {
            if (!isset($alive[(int) $row['attachment_id']])) {
                $dead[] = (int) $row['id'];
            }
        }
        if ($dead === []) {
            return 0;
        }

        $removed = 0;
        foreach (array_chunk($dead, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $removed += (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ($placeholders)",
                ...$chunk
            ));
        }

        return $removed;
    }
}
