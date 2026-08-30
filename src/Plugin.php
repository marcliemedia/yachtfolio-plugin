<?php

declare(strict_types=1);

namespace Otium\Yachtfolio;

use Otium\Yachtfolio\Admin\Menu;
use Otium\Yachtfolio\Api\Client;
use Otium\Yachtfolio\Api\RateBudget;
use Otium\Yachtfolio\Domain\Normalizer;
use Otium\Yachtfolio\Frontend\DynamicTags;
use Otium\Yachtfolio\Frontend\TemplateRouter;
use Otium\Yachtfolio\Mapping\Mapper;
use Otium\Yachtfolio\Media\MediaImporter;
use Otium\Yachtfolio\Media\MediaLedger;
use Otium\Yachtfolio\Reference\AreaResolver;
use Otium\Yachtfolio\Reference\ReferenceCache;
use Otium\Yachtfolio\Reference\SeasonResolver;
use Otium\Yachtfolio\Support\Lock;
use Otium\Yachtfolio\Support\Logger;
use Otium\Yachtfolio\Support\Settings;
use Otium\Yachtfolio\Sync\Jobs;
use Otium\Yachtfolio\Sync\Linker;
use Otium\Yachtfolio\Sync\Orchestrator;
use Otium\Yachtfolio\Sync\RunStore;
use Otium\Yachtfolio\Sync\YachtMapStore;
use Otium\Yachtfolio\Write\Writer;

/**
 * Hand-rolled service container. Everything is lazy so a front-end request
 * pays for nothing but the meta registration.
 */
final class Plugin
{
    private static ?self $instance = null;

    /** @var array<string,object> */
    private array $services = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 20);
        add_action('init', [$this, 'register_meta']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('otium-yf', Cli\Commands::class);
        }
    }

    public function on_plugins_loaded(): void
    {
        Activator::maybe_upgrade();
        $this->jobs()->register();

        // Routes feed-created yachts to their own Bricks single template.
        // Registered on plugins_loaded so it is in place before Bricks
        // resolves templates, on the front end and in the builder alike.
        $this->templateRouter()->register();

        // Bricks dynamic tags for the repeater fields. Scalars need nothing —
        // Bricks resolves {cf_<meta_key>} for any key — but {cf_yf_rates}
        // would implode 14 rows into one line, so those five get real tags.
        $this->dynamicTags()->register();

        if (is_admin()) {
            $this->menu()->register();
        }
    }

    /**
     * Infrastructure meta lives here, never inside the JetEngine field blob:
     * that 21 KB serialised definition is owned by JetEngine and rewritten
     * whenever an editor saves the post type. Bricks reads {cf_<key>} straight
     * from post meta, so nothing is lost by staying out of it.
     */
    public function register_meta(): void
    {
        $fields = [
            'yf_id'             => 'integer',
            'yf_last_modified'  => 'string',
            'yf_payload_hash'   => 'string',
            'yf_brochure_hash'  => 'string',
            'yf_synced_at'      => 'string',
            'yf_sync_status'    => 'string',
            'yf_visible'        => 'string',
            'yf_locked_fields'  => 'string',
            'yf_type_derivable' => 'string',
            // Presence markers for template conditions: the count when the
            // yacht has that data, an empty string when it does not, so the
            // template's existing `empty_not` conditions keep working.
            'yf_has_amenities'  => 'string',
            'yf_has_rates'      => 'string',
            'yf_has_crew'       => 'string',
            'yf_has_toys'       => 'string',
            'yf_has_gallery'    => 'string',
        ];

        foreach ($fields as $key => $type) {
            register_post_meta('yacht', $key, [
                'type'          => $type,
                'single'        => true,
                'show_in_rest'  => false,
                'auth_callback' => static fn(): bool => current_user_can(Activator::CAPABILITY),
            ]);
        }

        // yf_raw is large and never needs to be queried; keep it unregistered
        // for REST but documented here.
    }

    /* ------------------------------------------------------------------ *
     * Services
     * ------------------------------------------------------------------ */

    public function settings(): Settings
    {
        return $this->service(Settings::class, static fn(): Settings => new Settings());
    }

    public function logger(): Logger
    {
        return $this->service(Logger::class, fn(): Logger => new Logger($this->settings()->int('log_retention_days', 30)));
    }

    public function lock(): Lock
    {
        return $this->service(Lock::class, static fn(): Lock => new Lock());
    }

    public function budget(): RateBudget
    {
        return $this->service(RateBudget::class, fn(): RateBudget => new RateBudget(
            $this->settings()->int('budget_limit', 600),
            $this->settings()->int('budget_window', 300)
        ));
    }

    public function client(): Client
    {
        return $this->service(Client::class, fn(): Client => new Client($this->settings(), $this->budget(), $this->logger()));
    }

    public function reference(): ReferenceCache
    {
        return $this->service(ReferenceCache::class, fn(): ReferenceCache => new ReferenceCache($this->client(), $this->settings(), $this->logger()));
    }

    public function seasons(): SeasonResolver
    {
        return $this->service(SeasonResolver::class, fn(): SeasonResolver => new SeasonResolver($this->reference()));
    }

    public function areas(): AreaResolver
    {
        return $this->service(AreaResolver::class, fn(): AreaResolver => new AreaResolver($this->reference()));
    }

    public function normalizer(): Normalizer
    {
        return $this->service(Normalizer::class, static fn(): Normalizer => new Normalizer());
    }

    public function mapper(): Mapper
    {
        return $this->service(Mapper::class, fn(): Mapper => new Mapper(
            $this->settings(),
            $this->reference(),
            $this->seasons(),
            $this->areas()
        ));
    }

    public function writer(): Writer
    {
        return $this->service(Writer::class, fn(): Writer => new Writer($this->settings(), $this->logger()));
    }

    public function mediaLedger(): MediaLedger
    {
        return $this->service(MediaLedger::class, static fn(): MediaLedger => new MediaLedger());
    }

    public function media(): MediaImporter
    {
        return $this->service(MediaImporter::class, fn(): MediaImporter => new MediaImporter(
            $this->client(),
            $this->mediaLedger(),
            $this->settings(),
            $this->logger()
        ));
    }

    public function map(): YachtMapStore
    {
        return $this->service(YachtMapStore::class, static fn(): YachtMapStore => new YachtMapStore());
    }

    public function runs(): RunStore
    {
        return $this->service(RunStore::class, static fn(): RunStore => new RunStore());
    }

    public function linker(): Linker
    {
        return $this->service(Linker::class, fn(): Linker => new Linker($this->map()));
    }

    public function orchestrator(): Orchestrator
    {
        return $this->service(Orchestrator::class, fn(): Orchestrator => new Orchestrator($this));
    }

    public function jobs(): Jobs
    {
        return $this->service(Jobs::class, fn(): Jobs => new Jobs($this));
    }

    public function menu(): Menu
    {
        return $this->service(Menu::class, fn(): Menu => new Menu($this));
    }

    public function templateRouter(): TemplateRouter
    {
        return $this->service(TemplateRouter::class, fn(): TemplateRouter => new TemplateRouter($this->settings()));
    }

    public function dynamicTags(): DynamicTags
    {
        return $this->service(DynamicTags::class, fn(): DynamicTags => new DynamicTags());
    }

    public function dir(): string
    {
        return OY_YF_DIR;
    }

    public function url(): string
    {
        return plugin_dir_url(OY_YF_FILE);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable():T $factory
     * @return T
     */
    private function service(string $id, callable $factory): object
    {
        /** @var T */
        return $this->services[$id] ??= $factory();
    }
}
