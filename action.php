<?php
/**
 * Hide IP — action component.
 *
 * Hooks INIT_LANG_LOAD (same point in DokuWiki's boot the anonip plugin uses)
 * and rewrites the server-side IP variables so that every later piece of code
 * — clientIP(), $INPUT->server->str('REMOTE_ADDR'), changelog writers, page
 * metadata, the mailer's X-Originating-IP header, AJAX info, etc. — sees a
 * constant placeholder instead of the real address.
 *
 * Why a constant ('0.0.0.0') rather than a session-hashed pseudo-IPv6:
 *  - This wiki has no anonymous edits; the username field already
 *    differentiates editors. A pseudo-IP adds no investigative value.
 *  - Less surface area for re-identification attacks. The original anonip
 *    leaked the first IPv4 octet via auth_browseruid(); even the fork's
 *    session-hash variant still depends on session-stability assumptions.
 *  - Page locking is not affected: inc/common.php::lock() writes only the
 *    username when REMOTE_USER is set (which it always will be here), and
 *    unlock() has session_id() as a fallback even when it isn't.
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

class action_plugin_hideip extends ActionPlugin
{
    /** The placeholder all anonymised reads will return. */
    const PLACEHOLDER_IP = '0.0.0.0';

    /**
     * @param EventHandler $controller
     */
    public function register(EventHandler $controller)
    {
        // INIT_LANG_LOAD fires after init.php has applied any trusted-proxy
        // X-Forwarded-For rewriting (lines ~500/550 of init.php), so by the
        // time we run, $_SERVER['REMOTE_ADDR'] holds the resolved client IP
        // that DokuWiki would normally record. We clobber it before any
        // downstream code reads it again.
        $controller->register_hook('INIT_LANG_LOAD', 'BEFORE', $this, 'handleAnonymise');
    }

    /**
     * @param Event $event  unused, dispatch signature only
     * @param mixed $param  unused
     */
    public function handleAnonymise(Event $event, $param)
    {
        // Direct $_SERVER write: DokuWiki's dokuwiki\Input\Server class uses
        // $access =& $_SERVER (see inc/Input/Server.php), so $INPUT->server
        // reads pick up the new value without anything else to do.
        $_SERVER['REMOTE_ADDR'] = self::PLACEHOLDER_IP;

        // Also clear every common forwarding header. inc/Ip.php::clientIps()
        // walks HTTP_X_FORWARDED_FOR and appends every IP it finds; if we
        // left these set, the real client address would still leak into
        // clientIP(false) (multi-IP mode) and into header logs/UIs that
        // consume those raw values.
        foreach (
            [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
                'HTTP_CLIENT_IP',
                'HTTP_FORWARDED',
                'HTTP_CF_CONNECTING_IP',   // Cloudflare
                'HTTP_TRUE_CLIENT_IP',     // Akamai / Cloudflare Enterprise
            ] as $header
        ) {
            if (isset($_SERVER[$header])) unset($_SERVER[$header]);
        }
    }
}
