# Otium Yachtfolio Sync

One-way sync from the [Yachtfolio](https://www.yachtfolio.com/) Public API (MYBA yacht data feed) into an existing WordPress `yacht` post type.

**Version 0.3.1** · WordPress ≥ 6.4 · PHP ≥ 8.1 · proprietary, all rights reserved.

## What it does, and what it refuses to do

| | |
|---|---|
| Direction | **Read-only against Yachtfolio.** Nothing is ever written back to the vendor. |
| Publishing | **Never publishes on its own.** An imported yacht arrives as a draft; a human ticks *Visible*. |
| Hand-entered content | **Protected.** With `write_mode = fill_empty_only` the feed only fills fields that are empty. Refusals are logged, never silent. |
| Galleries | A yacht whose gallery was curated by hand keeps it. Feed yachts write normally. |
| Rate limit | Self-throttling at 600 calls per 300 s, under the vendor's documented 800. |

The restraint is the point: this plugin runs against a live commercial catalogue where a broker's hand-written description is worth more than a feed value.

## Install

Drop the folder into `wp-content/plugins/` and activate. `vendor/` is committed, so there is **no build step** — deployment is a file copy.

```bash
git clone https://github.com/marcliemedia/yachtfolio-plugin.git \
  wp-content/plugins/otium-yachtfolio-sync
```

To rebuild the dependency tree instead (`composer.lock` pins the same versions):

```bash
composer install --no-dev --optimize-autoloader
```

### Keeping this repository in step with a working copy

The repository deliberately lives **outside** the WordPress install. With `.git`
inside `wp-content/plugins/`, a file-copy deployment would carry it onto the live
server, and an exposed `.git` hands the whole source history to anyone who
requests `…/otium-yachtfolio-sync/.git/config`. This plugin *is* deployed by
copying files, so that is a real exposure, not a theoretical one.

```bash
python sync-from-local.py             # mirror the working copy, show what changed
python sync-from-local.py --commit    # mirror and commit at the plugin's own version
```

It mirrors rather than copies — a file deleted upstream disappears here too — and
it **refuses to stage anything if a credential literal appears in the source**,
naming the file and line. Point it elsewhere with `OY_YF_SRC=/path/to/plugin`.

Action Scheduler is bundled via Composer. It self-registers and the newest loaded copy wins, so it coexists with WooCommerce or any other plugin shipping its own.

## Configuration

The API passkey is **never stored in this repository.** Put it in `wp-config.php`:

```php
define( 'OY_YF_PASSKEY_LIVE', '…' );   // live feed
define( 'OY_YF_PASSKEY_TEST', '…' );   // vendor sandbox
```

A constant always wins over the value saved in *Settings*; the admin screen shows which source is active. Ask Yachtfolio support for a passkey — the live key is per-account and silently revocable.

Endpoints default to the vendor's documented URLs and are overridable in *Settings*:

| Purpose | Default |
|---|---|
| Basic / detail | `https://www.yachtfolio.com/api/api_basic.cgi` |
| Brochure | `https://www.yachtfolio.com/api/api_brochure.cgi` |
| Media | `https://www.yachtfolio.com/api/media/res` |

The importer is **unscheduled by default** (`schedule = off`). Turn it on only once a dry run looks right.

## Admin

Six screens under the top-level **Yachtfolio** menu:

| Screen | Slug | Purpose |
|---|---|---|
| Dashboard | `oy-yf-dashboard` | connection, passkey source, budget, recent errors |
| Yachts | `oy-yf-yachts` | the catalogue; sync, dry run, publish, view, show JSON per yacht |
| Mapping | `oy-yf-mapping` | field and taxonomy mapping |
| Logs | `oy-yf-logs` | every run, every refusal |
| Tools | `oy-yf-tools` | one-off maintenance |
| Settings | `oy-yf-settings` | credentials, endpoints, buckets, write mode |

## WP-CLI

`wp otium-yf <command>` is the primary control surface — the first full import should be run here, not in a browser.

| Command | Purpose |
|---|---|
| `check [--format=<format>]` | probe every endpoint; verifies the passkey before anything writes |
| `index [--scope=<scope>] [--no-enqueue]` | refresh the catalogue index |
| `select` | mark yachts for detailed sync |
| `sync` | import detail records (`--yacht=<yf_id>` or `--all-linked`) |
| `media` | import galleries |
| `link` | link a feed record to an existing post |
| `status` | current state of the catalogue |
| `log` | recent run output |
| `purge_logs` | trim the log table |
| `coverage` | which API fields reach the page, and which are stored but never shown |
| `taxonomy_report` | term usage across the feed |
| `reference` | vendor reference lists (seasons, areas, equipment) |
| `budget` | rate-limit accounting |

Subcommand names carry underscores, not hyphens (`taxonomy_report`, `purge_logs`) — that is how WP-CLI derives them from the method names.

Start with `wp otium-yf check` — a failed probe there is a credential or endpoint problem, not a data problem.

## Layout

```
otium-yachtfolio-sync.php   bootstrap, PHP/autoload guards
src/
  Api/          HTTP client, rate budget, typed errors
  Domain/       payload objects, normaliser, hashing
  Mapping/      field, taxonomy and amenity mapping; rate tables
  Sync/         orchestrator, jobs, yacht↔post map
  Media/        gallery import, format sniffing, WebP encoding
  Write/        writer, ownership rules, meta formatting
  Frontend/     dynamic tags and template routing
  Admin/        the six screens
  Cli/          WP-CLI commands
  Support/      settings, logger, secret scrubbing
  Reference/    vendor reference data
vendor/         Action Scheduler + Composer autoloader (committed)
assets/         admin CSS/JS
uninstall.php   honours `remove_data_on_uninstall`, default false
```

## Notes for whoever picks this up next

- **Ownership is one accessor, not a rule repeated per caller.** `Ownership::origin_of()` decides whether the feed may write a given post. Adding a second code path that bypasses it is how hand-entered data gets destroyed.
- **`yf_visible` is a human curation flag**, set from the admin only. The importer reads it and never sets it.
- **Amenity switchers are mapped by phrase**, in `Mapping/AmenityText.php`. Adding a phrase there makes a switcher writable by the feed — do not widen that set anywhere else.
- **Secrets are scrubbed from logs** by `Support/Secrets`. Any new log call that interpolates a request URL must go through it.
- The vendor returns **HTTP 200 with an `errors[]` body** on failure. Never trust the status code alone.
