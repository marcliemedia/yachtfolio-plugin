<?php

declare(strict_types=1);

namespace Otium\Yachtfolio\Update;

use Otium\Yachtfolio\Plugin;
use Otium\Yachtfolio\Support\Secrets;

/**
 * Serves plugin updates from GitHub releases.
 *
 * Mechanism: the plugin header carries `Update URI: https://github.com/<owner>/<repo>`,
 * so WordPress routes its update check to `update_plugins_github.com` instead of
 * wp.org. That is deliberate — it makes it impossible for a wp.org plugin with a
 * colliding slug to ever be offered as an update for this plugin.
 *
 * The repository may be private. When a token is configured every call is
 * authenticated and the release asset is pulled through the REST API, because
 * `browser_download_url` is not usable on a private repository. Without a token
 * the public endpoints are used unchanged, so making the repository public later
 * needs no code change.
 *
 * Network calls happen only inside WordPress's own update cycle and are cached,
 * so a normal admin request never talks to GitHub.
 */
final class GitHubUpdater
{
    public const OWNER = 'marcliemedia';
    public const REPO  = 'yachtfolio-plugin';

    /** Directory the plugin must live in, whatever the archive happens to contain. */
    public const DIR = 'otium-yachtfolio-sync';

    private const CACHE_KEY   = 'oy_yf_gh_release';
    private const ERROR_KEY   = 'oy_yf_gh_error';
    private const CACHE_TTL   = 6 * HOUR_IN_SECONDS;
    private const FAIL_TTL    = 15 * MINUTE_IN_SECONDS;

    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'download'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'flush_after_update'], 10, 2);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
    }

    /* ------------------------------------------------------------------ *
     * Update check
     * ------------------------------------------------------------------ */

    /**
     * @param array<string,mixed>|false $update
     * @param array<string,mixed>       $plugin_data
     * @return array<string,mixed>|false
     */
    public function check(array|false $update, array $plugin_data, string $plugin_file): array|false
    {
        if ($plugin_file !== self::basename()) {
            return $update;
        }

        $release = $this->release();
        if ($release === null) {
            return $update;
        }

        // The release is returned even when it is not newer. WordPress compares
        // the versions itself and files the result under `response` or
        // `no_update`; returning false when up to date would leave the plugin in
        // neither bucket, which is what hides the "Enable auto-updates" control
        // on the Plugins screen.
        return [
            'id'           => 'github.com/' . self::OWNER . '/' . self::REPO,
            'slug'         => self::DIR,
            'plugin'       => self::basename(),
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.4',
            'requires_php' => '8.1',
            'tested'       => get_bloginfo('version'),
        ];
    }

    /**
     * The "View details" modal. Without this WordPress sends the user to
     * wordpress.org/plugins/otium-yachtfolio-sync, which does not exist.
     *
     * @param object|array<string,mixed>|false $result
     * @param array<string,mixed>|object       $args
     */
    public function details(mixed $result, string $action, mixed $args): mixed
    {
        $slug = is_object($args) ? ($args->slug ?? '') : ($args['slug'] ?? '');
        if ($action !== 'plugin_information' || $slug !== self::DIR) {
            return $result;
        }

        $release = $this->release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name'          => 'Otium Yachtfolio Sync',
            'slug'          => self::DIR,
            'version'       => $release['version'],
            'author'        => '<a href="https://marclie.com/">Marclie Agency</a>',
            'homepage'      => $release['url'],
            'requires'      => '6.4',
            'requires_php'  => '8.1',
            'tested'        => get_bloginfo('version'),
            'last_updated'  => $release['published_at'],
            'download_link' => $release['package'],
            'sections'      => [
                'description' => esc_html__(
                    'One-way sync from the Yachtfolio Public API into the yacht post type.',
                    'otium-yachtfolio-sync'
                ),
                'changelog'   => $release['notes'] !== ''
                    ? wpautop(wp_kses_post($release['notes']))
                    : esc_html__('No release notes were published.', 'otium-yachtfolio-sync'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Download
     * ------------------------------------------------------------------ */

    /**
     * A private repository cannot be downloaded by `download_url()`, which sends
     * no credentials. When a token is configured the transfer is done here with
     * the Authorization header attached.
     *
     * @param mixed $reply false to let WordPress download normally
     * @return mixed string local file path, WP_Error, or the untouched $reply
     */
    public function download(mixed $reply, string $package, mixed $upgrader): mixed
    {
        $token = $this->token();
        if ($token === '' || !str_starts_with($package, 'https://api.github.com/')) {
            return $reply;
        }

        // `skin` is a property, not a method; the guard only has to prove the
        // upgrader actually carries one before reporting progress through it.
        if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin)) {
            $upgrader->skin->feedback(__('Downloading the release from GitHub…', 'otium-yachtfolio-sync'));
        }

        $tmp = wp_tempnam($package);
        if (!$tmp) {
            return new \WP_Error('oy_yf_tmp', __('Could not create a temporary file for the download.', 'otium-yachtfolio-sync'));
        }

        $response = wp_remote_get($package, [
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $tmp,
            'headers'  => $this->headers($token) + ['Accept' => 'application/octet-stream'],
        ]);

        if (is_wp_error($response)) {
            @unlink($tmp);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($tmp);
            return new \WP_Error(
                'oy_yf_download',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('GitHub refused the download (HTTP %d). Check that the token is valid and can read this repository.', 'otium-yachtfolio-sync'),
                    $code
                )
            );
        }

        return $tmp;
    }

    /**
     * GitHub archives never unpack to the plugin's own directory name: a release
     * asset unpacks to whatever it was zipped as, and a source archive unpacks to
     * `<repo>-<tag>`. WordPress installs the directory it finds, so it is renamed
     * here or the plugin would be installed twice under two slugs.
     *
     * @param array<string,mixed> $hook_extra
     * @return string|\WP_Error
     */
    public function rename_source(string $source, string $remote_source, mixed $upgrader, array $hook_extra = []): mixed
    {
        $plugin = (string) ($hook_extra['plugin'] ?? '');
        if ($plugin !== self::basename()) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . self::DIR;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            return $source;
        }

        if ($wp_filesystem->exists($desired)) {
            $wp_filesystem->delete($desired, true);
        }

        if (!$wp_filesystem->move($source, $desired)) {
            return new \WP_Error(
                'oy_yf_rename',
                __('Could not normalise the unpacked plugin directory name.', 'otium-yachtfolio-sync')
            );
        }

        return trailingslashit($desired);
    }

    /** @param array<string,mixed> $hook_extra */
    public function flush_after_update(mixed $upgrader, array $hook_extra): void
    {
        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = (array) ($hook_extra['plugins'] ?? []);
        if (in_array(self::basename(), $plugins, true)) {
            $this->flush();
        }
    }

    /* ------------------------------------------------------------------ *
     * Release lookup
     * ------------------------------------------------------------------ */

    public function flush(): void
    {
        delete_transient(self::CACHE_KEY);
        delete_transient(self::ERROR_KEY);
    }

    public function last_error(): string
    {
        return (string) (get_transient(self::ERROR_KEY) ?: '');
    }

    /**
     * Newest release, or null when there is none / the call failed.
     *
     * @return array{version:string,package:string,url:string,notes:string,published_at:string,tag:string}|null
     */
    public function release(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached['version'] === '' ? null : $cached;
            }
            if ($cached === 'none') {
                return null;
            }
        }

        $token = $this->token();
        $pre   = $this->plugin->settings()->bool('update_prereleases');

        // /releases/latest hides pre-releases and drafts; the list endpoint is
        // needed when pre-releases are opted into.
        $endpoint = $pre
            ? sprintf('https://api.github.com/repos/%s/%s/releases?per_page=10', self::OWNER, self::REPO)
            : sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPO);

        $response = wp_remote_get($endpoint, [
            'timeout' => 20,
            'headers' => $this->headers($token),
        ]);

        if (is_wp_error($response)) {
            $this->fail($response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 404) {
            $this->fail($token === ''
                ? __('No public release found. If the repository is private, add a GitHub token in Settings.', 'otium-yachtfolio-sync')
                : __('No release found, or the token cannot read this repository.', 'otium-yachtfolio-sync'));
            return null;
        }
        if ($code === 401 || $code === 403) {
            $this->fail(sprintf(
                /* translators: %d: HTTP status code */
                __('GitHub rejected the request (HTTP %d). The token may be missing, expired or rate limited.', 'otium-yachtfolio-sync'),
                $code
            ));
            return null;
        }
        if ($code !== 200 || !is_array($body)) {
            $this->fail(sprintf(
                /* translators: %d: HTTP status code */
                __('Unexpected reply from GitHub (HTTP %d).', 'otium-yachtfolio-sync'),
                $code
            ));
            return null;
        }

        $raw = $pre ? $this->first_usable($body) : $body;
        if ($raw === null || !empty($raw['draft'])) {
            set_transient(self::CACHE_KEY, 'none', self::CACHE_TTL);
            return null;
        }

        $release = $this->shape($raw, $token !== '');
        if ($release === null) {
            set_transient(self::CACHE_KEY, 'none', self::CACHE_TTL);
            return null;
        }

        delete_transient(self::ERROR_KEY);
        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * @param array<int,mixed> $releases
     * @return array<string,mixed>|null
     */
    private function first_usable(array $releases): ?array
    {
        foreach ($releases as $release) {
            if (is_array($release) && empty($release['draft'])) {
                return $release;
            }
        }
        return null;
    }

    /**
     * Turns a GitHub release into what the upgrader needs.
     *
     * A built asset is preferred over the source archive: the source archive of
     * this repository carries the repo-only files and unpacks under a tag name,
     * while an attached zip is the exact tree that belongs in wp-content/plugins.
     *
     * @param array<string,mixed> $raw
     * @return array{version:string,package:string,url:string,notes:string,published_at:string,tag:string}|null
     */
    private function shape(array $raw, bool $authenticated): ?array
    {
        $tag = (string) ($raw['tag_name'] ?? '');
        if ($tag === '') {
            return null;
        }

        $version = ltrim($tag, 'vV');
        if (!preg_match('/^\d+(\.\d+)*/', $version)) {
            return null;
        }

        $package = '';
        foreach ((array) ($raw['assets'] ?? []) as $asset) {
            if (!is_array($asset) || !str_ends_with(strtolower((string) ($asset['name'] ?? '')), '.zip')) {
                continue;
            }
            // A private repository serves assets only through the API URL.
            $package = $authenticated
                ? (string) ($asset['url'] ?? '')
                : (string) ($asset['browser_download_url'] ?? '');
            if ($package !== '') {
                break;
            }
        }

        if ($package === '') {
            $package = $authenticated
                ? sprintf('https://api.github.com/repos/%s/%s/zipball/%s', self::OWNER, self::REPO, rawurlencode($tag))
                : (string) ($raw['zipball_url'] ?? '');
        }

        if ($package === '') {
            return null;
        }

        return [
            'version'      => $version,
            'tag'          => $tag,
            'package'      => $package,
            'url'          => (string) ($raw['html_url'] ?? ''),
            'notes'        => (string) ($raw['body'] ?? ''),
            'published_at' => (string) ($raw['published_at'] ?? ''),
        ];
    }

    private function fail(string $message): void
    {
        set_transient(self::ERROR_KEY, Secrets::scrub($message), self::FAIL_TTL);
        // Short cache on failure so a broken token does not hammer the API on
        // every update cycle, but recovery is still quick.
        set_transient(self::CACHE_KEY, 'none', self::FAIL_TTL);
    }

    /* ------------------------------------------------------------------ *
     * Credentials
     * ------------------------------------------------------------------ */

    /**
     * wp-config wins over the database, exactly like the Yachtfolio passkey, so
     * a production token never has to be stored in wp_options.
     */
    public function token(): string
    {
        if (defined('OY_YF_GITHUB_TOKEN') && (string) constant('OY_YF_GITHUB_TOKEN') !== '') {
            return (string) constant('OY_YF_GITHUB_TOKEN');
        }
        return trim((string) $this->plugin->settings()->get('github_token', ''));
    }

    public function token_source(): string
    {
        if (defined('OY_YF_GITHUB_TOKEN') && (string) constant('OY_YF_GITHUB_TOKEN') !== '') {
            return 'wp-config.php';
        }
        return $this->token() !== ''
            ? __('database', 'otium-yachtfolio-sync')
            : __('not set', 'otium-yachtfolio-sync');
    }

    public function masked_token(): string
    {
        return Secrets::mask($this->token());
    }

    /** @return array<string,string> */
    private function headers(string $token): array
    {
        $headers = [
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'otium-yachtfolio-sync/' . OY_YF_VERSION,
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    public static function basename(): string
    {
        return plugin_basename(OY_YF_FILE);
    }

    public static function repository_url(): string
    {
        return sprintf('https://github.com/%s/%s', self::OWNER, self::REPO);
    }
}
