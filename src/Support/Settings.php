<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Support;

final class Settings
{
    public const OPTION = 'oy_yf_settings';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'mode'                 => 'live',   // test|live
            'passkey_test'         => '',
            'passkey_live'         => '',
            'endpoint_basic'       => 'https://www.yachtfolio.com/api/api_basic.cgi',
            'endpoint_brochure'    => 'https://www.yachtfolio.com/api/api_brochure.cgi',
            'endpoint_media'       => 'https://www.yachtfolio.com/api/media/res',
            'request_timeout'      => 45,
            'budget_limit'         => 600,      // safety margin under the documented 800
            'budget_window'        => 300,      // seconds
            'schedule'             => 'off',    // off|hourly|six_hours|daily
            'batch_size'           => 10,
            'media_batch_size'     => 20,
            'import_brochure'      => true,
            'import_media'         => true,
            /**
             * Every image bucket, PDF excluded.
             *
             * Census of the brochure endpoint, 2026-08-30 (6 yachts):
             *   Acapella 56/34/16/4/0 = 110 images + 54 PDF
             *   4A       50/13/22/13/4 = 102 images + 44 PDF
             *   Navilux, Green Ray, Lotus, Libra = 87, 74, 66, 56 images
             * LIFESTYLE carries real per-yacht titles ("Beach club"), so it is
             * photography of this yacht, not stock. PDF is documents — brochures
             * and menus — which would pollute an image slider; the sample menu
             * has its own setting.
             */
            'media_galleries'      => ['FULL', 'EXTERIOR', 'INTERIOR', 'LIFESTYLE', 'LAYOUT'],
            // Largest set observed above is 110. 120 clears every yacht measured
            // while still bounding a pathological record.
            'media_cap_per_yacht'  => 120,
            // The site's WebP plugin converts nothing (see Media\WebpEncoder),
            // so the importer re-encodes JPEG/PNG itself, keeping the smaller of
            // the two exactly as that plugin's `only_smaller` would.
            'convert_to_webp'      => true,
            'webp_quality'         => 85,       // matches the site's WebP Converter setting
            'import_sample_menu'   => false,
            'import_crew_photos'   => true,
            'set_featured_image'   => true,     // only when the post has none
            'create_terms'         => true,
            // 2026-08-30 client mandate: hand-entered yachts are never overwritten.
            // fill_empty_only = a manual yacht only gets values written into EMPTY fields.
            // feed_authoritative = the pre-mandate behaviour; the feed rewrites everything.
            'write_mode'           => 'fill_empty_only',
            // Bricks single template used for feed-created yachts. 0 = off,
            // every yacht keeps the original template. See Frontend\TemplateRouter.
            'single_template_id'   => 0,
            'reference_ttl'        => 86400,
            'log_retention_days'   => 30,
            'brochure_scenario'    => 'auto',   // auto|manual|text (API treats them alike)
            'detail_scope'         => 'selected', // selected|all — selected = linked/selected/visible only
            'shoulder_source'      => 'next_season_min',
            'currency_locale'      => 'hr',
            'remove_data_on_uninstall' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }
        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
    }

    /** @param array<string,mixed> $patch */
    public function set(array $patch): void
    {
        $current = $this->all();
        foreach ($patch as $k => $v) {
            $current[$k] = $v;
        }
        $this->cache = $current;
        update_option(self::OPTION, $current, false);
    }

    public function mode(): string
    {
        return $this->get('mode') === 'test' ? 'test' : 'live';
    }

    /**
     * wp-config constants win over the database so a production key never has
     * to be stored in wp_options.
     */
    public function passkey(?string $mode = null): string
    {
        $mode = $mode ?: $this->mode();
        $constant = $mode === 'test' ? 'OY_YF_PASSKEY_TEST' : 'OY_YF_PASSKEY_LIVE';
        $key = defined($constant) ? (string) constant($constant) : '';
        if ($key === '') {
            $key = (string) $this->get($mode === 'test' ? 'passkey_test' : 'passkey_live', '');
        }
        $key = trim($key);
        Secrets::register($key);
        return $key;
    }

    public function passkey_source(?string $mode = null): string
    {
        $mode = $mode ?: $this->mode();
        $constant = $mode === 'test' ? 'OY_YF_PASSKEY_TEST' : 'OY_YF_PASSKEY_LIVE';
        if (defined($constant) && (string) constant($constant) !== '') {
            return 'constant';
        }
        return $this->get($mode === 'test' ? 'passkey_test' : 'passkey_live', '') !== '' ? 'option' : 'missing';
    }

    public function masked_passkey(?string $mode = null): string
    {
        return Secrets::mask($this->passkey($mode));
    }

    /** @return array<string,int> season kind => season offset rule */
    public function season_period_map(): array
    {
        $map = $this->get('season_period_map');
        if (is_array($map) && $map !== []) {
            return $map;
        }
        return [
            'high'     => 0,  // current season, max_rate
            'low'      => 0,  // current season, min_rate
            'shoulder' => 1,  // next season, min_rate
        ];
    }
}
