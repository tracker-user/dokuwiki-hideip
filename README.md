# Hide IP plugin for DokuWiki

Removes IP addresses from everywhere a DokuWiki admin might see them. Two parts that work together:

- **Real-time anonymisation** — an action plugin that rewrites `$_SERVER['REMOTE_ADDR']` to `0.0.0.0` and clears every common forwarding header before any DokuWiki code reads them. From the moment the plugin is enabled, every new changelog entry, page metadata write, mailer header, and `$INFO['client']` value carries `0.0.0.0` instead of the real address.
- **Historical scrub** — an admin page that walks the IP-bearing files DokuWiki already has on disk (changelogs and page metadata) and rewrites them. Authorship (user field) and modification timestamps are preserved; only the IP field changes.

Tested on DokuWiki `2025-05-14b "Librarian"`.

## Why not the existing plugins?

There are three related plugins on dokuwiki.org. None of them quite fit this use case.

**`anonip`** (Andreas Gohr, 2016, [dokuwiki.org/plugin:anonip](https://www.dokuwiki.org/plugin:anonip)) was the canonical answer. It generates a pseudo-IPv6 from `auth_browseruid()`. The problem, [as discussed on the DokuWiki forum](https://forum.dokuwiki.org/d/18284-dont-store-ip-addresses), is that `auth_browseruid()` mixes in the first IPv4 octet of the client address, which makes the hash brute-forceable: an attacker iterating through ~256 octet values and a list of common User-Agent strings can recover the user's first IP octet and User-Agent. A community [fork by adakaleh](https://github.com/adakaleh/dokuwiki-plugin-anonip/tree/replace_auth_browseruid) swaps in `session_id()` instead — better, but adds session-stability assumptions and still produces a pseudo-IP for an admin to see when usernames already differentiate edits.

**`gdpr`** (Michael Große, 2019, [dokuwiki.org/plugin:gdpr](https://www.dokuwiki.org/plugin:gdpr)) takes a different approach: it lets real IPs be recorded as users edit, then strips them from changelog entries older than `$conf['recent_days']` (default 90 days). This works for GDPR retention but doesn't fit a "no IPs anywhere, ever" policy, and it leaves IPs visible in the Recent Changes view for up to three months. It also touches only changelog files and skips the master changelog (`_dokuwiki.changes`) entirely, leaving IPs visible in Recent Changes after old per-page entries are cleaned.

**This plugin** combines what works from both: anonip's real-time interception (simplified to a constant `0.0.0.0` since the wiki has named users) plus a one-shot historical scrub that does cover the master changelogs and page metadata.

## What gets anonymised

### In real time (action component)

`$_SERVER['REMOTE_ADDR']` becomes `0.0.0.0`. These forwarding headers are removed: `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, `HTTP_CLIENT_IP`, `HTTP_FORWARDED`, `HTTP_CF_CONNECTING_IP`, `HTTP_TRUE_CLIENT_IP`. The User-Agent is left alone (it's not an IP).

The hook fires on `INIT_LANG_LOAD`, after DokuWiki's `init.php` has applied any trusted-proxy `X-Forwarded-For` rewriting, but before any other code reads the IP. Every downstream consumer — `clientIP()`, `$INPUT->server->str('REMOTE_ADDR')`, changelog writers, metadata, mailer `X-Originating-IP`, AJAX page-info — sees the placeholder.

### On disk (admin component, on demand)

| File pattern | What it is | What changes |
| --- | --- | --- |
| `data/meta/**/*.changes` | Per-page change history; master `_dokuwiki.changes` | TSV field 2 (IP) rewritten to `0.0.0.0` |
| `data/media_meta/**/*.changes` | Per-media change history; master | Same |
| `data/meta/**/*.meta` | Page metadata (serialized) | `$meta[*]['last_change']['ip']` rewritten to `0.0.0.0` |

Timestamps, page IDs, usernames, summaries, and size deltas are preserved verbatim. File mtimes are restored after each write.

**Out of scope by design:**

- `data/attic/` and `data/media_attic/` — historical gzip archives of page revisions. Admins generally don't view these; the project's filesystem owner has access to logs anyway. Rewriting them would be slow and require gzip handling for marginal benefit.
- `data/cache/`, `data/tmp/`, `data/log/`, `data/locks/` — ephemeral or regenerated.

## Why `127.0.0.1` shows up (and why it's left alone)

You may notice `127.0.0.1` in changelog entries (often summarised `external edit`) or in a page's `last_change` metadata, even with real-time anonymisation active and after a scrub. **This is not a real visitor IP and nothing is leaking.**

`127.0.0.1` is a value DokuWiki **hardcodes itself** (`inc/ChangeLog/ChangeLog.php`) as its "external edit" marker — it stamps it whenever a page's on-disk `.txt` modification time no longer matches its changelog, i.e. the file was created or edited directly on disk rather than through the wiki. This is common in container / bind-mounted setups (volume operations, restores, `git` checkouts, an editor touching a file) and also applies to pages DokuWiki ships without a changelog, such as `wiki:syntax`.

Because it's a literal written by core — not derived from `$_SERVER` — the real-time action component cannot intercept it, and core re-creates it on the next view (into page metadata via `pageinfo()`) and on the next save (into the changelog via `detectExternalEdit()`). Scrubbing it would therefore be an endless treadmill, and it's a loopback address that identifies no one.

So **the preview and scrub deliberately ignore `127.0.0.1`** (alongside the `0.0.0.0` placeholder and already-blank values): it is never counted and never rewritten. Real visitor IPs are still anonymised to `0.0.0.0` exactly as before — only this benign loopback marker is left as-is.

## Page locking still works

For each lock, DokuWiki writes the username if one is set, otherwise IP and session-id. From `inc/common.php`:

```php
if ($INPUT->server->str('REMOTE_USER')) {
    io_saveFile($lock, $INPUT->server->str('REMOTE_USER'));
} else {
    io_saveFile($lock, clientIP() . "\n" . session_id());
}
```

If your wiki has no anonymous edits (the use case this plugin is built for), every lock uses the username and IP isn't consulted. Even if you do allow anonymous edits, `unlock()` checks `session_id()` as a fallback, so a constant `0.0.0.0` still releases your own lock correctly.

## Install

In your wiki:

1. **Admin → Extension Manager → Manual Install**
2. Upload `hideip.zip`, click **Install**
3. Real-time anonymisation is now active. To scrub existing data: refresh the Admin page and open **Hide IP** under Additional Plugins.

Or drop the directory into `lib/plugins/hideip/` directly.

## Run the historical scrub

1. **Admin → Hide IP**
2. Click **Preview (count only)** to see how many files and IP slots would be rewritten.
3. Click **Scrub now** to execute. The scrub is atomic per file (tmp file + rename) and preserves mtimes. It's also idempotent — running it again is a no-op once everything is at the placeholder.

Take a backup with the Site Backup plugin first if you want a recovery point — the scrub is intentionally destructive.

## Security model

- **Admin-only.** `forAdminOnly() = true`, plus an explicit `auth_isadmin()` check inside the scrub method.
- **CSRF-protected.** All actions go through `checkSecurityToken()`.
- **POST-only scrub.** The scrub action rejects GET / HEAD so a stray link or prefetch can't trigger it.
- **Atomic writes.** Every file write goes through a sibling `.hideip_tmp_<8 hex>` file and is `rename()`d into place. A concurrent reader sees either the old file or the new file, never a half-written state.
- **File mtimes preserved.** The on-disk modification time of each file is restored after the scrub, so it doesn't look like everything was just edited.
- **Idempotent.** Re-running scrub is safe — already-anonymised entries are skipped, no double-writes.

## Trusted-proxy caveat

The action component replaces `$_SERVER['REMOTE_ADDR']` at `INIT_LANG_LOAD`, which fires after DokuWiki's base-URL constants (`DOKU_URL`, `DOKU_BASE`) are already frozen into place (init.php:103-104). However, runtime calls to `is_ssl()` after init — used by some URL-building helpers — also check `REMOTE_ADDR` against `$conf['trustedproxy']` to decide whether to trust an `HTTP_X_FORWARDED_PROTO` header. If your wiki is behind a reverse proxy that relies on this check, set `$conf['baseurl']` explicitly in `conf/local.php` so DokuWiki does not consult `REMOTE_ADDR` for URL or SSL construction.

## Compatibility

- DokuWiki `2025-05-14b "Librarian"` and onwards (uses the modern namespaced `dokuwiki\Extension\AdminPlugin` / `ActionPlugin` base classes).
- PHP 8.0+ (`str_ends_with()` used in the admin component).
- No external dependencies.

## License

GPL-2.0-or-later, matching DokuWiki itself.
