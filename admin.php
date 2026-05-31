<?php
if (!defined('DOKU_INC')) die();

/**
 * Hide IP — admin component.
 *
 * Admin-only page that walks the historical IP-bearing files DokuWiki has
 * accumulated and rewrites every IP field with the placeholder used by the
 * action component. Scope is intentionally narrow:
 *
 *   - $conf['metadir']/**.changes        page changelogs (per-page + master)
 *   - $conf['mediametadir']/**.changes   media changelogs (per-media + master)
 *   - $conf['metadir']/**.meta           page metadata (last_change.ip)
 *
 * NOT touched (per the project's explicit scope):
 *   - data/attic/, data/media_attic/     historical .gz revision archives
 *   - data/cache/, data/tmp/, data/log/  ephemeral / regenerated
 *
 * Authorship (user field) and timestamps (date field) are preserved; only
 * the IP field is rewritten. File mtimes are preserved across the rewrite.
 *
 * Atomicity: every write goes to a sibling tmp file with a random suffix and
 * is then rename()d into place. rename() is atomic on a single filesystem,
 * so a concurrent reader either sees the old file or the new file.
 *
 * Concurrency: processChangelog() and processMetaFile() hold io_lock() across
 * the full read-modify-write cycle when mutating, so concurrent DokuWiki
 * changelog appends (which also use io_lock) are properly serialized.
 *
 * Idempotent: running scrub twice is a no-op on lines that already hold the
 * placeholder.
 */

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

class admin_plugin_hideip extends AdminPlugin
{
    /** Mirror of action_plugin_hideip::PLACEHOLDER_IP. Kept inline so this
     *  admin component can run without the action component being loaded. */
    public const PLACEHOLDER_IP = '0.0.0.0';

    /** Random suffix length for tmp files; .hideip_tmp_<8 hex>. */
    public const TMP_SUFFIX_BYTES = 4;

    /**
     * @return bool
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * @return int
     */
    public function getMenuSort()
    {
        return 1000;
    }

    /**
     * @param string $language
     * @return string
     */
    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /* ----------------------------------------------------------------- *
     *  Dispatch
     * ----------------------------------------------------------------- */

    /** @var array|null per-section preview results: [section => [files, ipLines]] */
    protected $preview = null;

    /** @var array|null per-section scrub results: [section => [files, ipLines, errors]] */
    protected $scrub = null;

    /**
     * Process form submissions (preview and scrub actions).
     *
     * @return void
     */
    public function handle()
    {
        global $INPUT;

        if (!$INPUT->has('hideip_action')) return;
        if (!checkSecurityToken()) return;

        $action = $INPUT->str('hideip_action');
        if ($action !== 'preview' && $action !== 'scrub') return;

        if ($action === 'scrub' && $INPUT->server->str('REQUEST_METHOD', 'GET') !== 'POST') {
            msg($this->getLang('err_post_only'), -1);
            return;
        }

        if ($action === 'preview') {
            $this->preview = $this->runScan(false);
        } else {
            // Defense-in-depth admin re-check (framework already gates via
            // forAdminOnly + isAccessibleByCurrentUser, but the scrub mutates
            // production data; one more check is cheap).
            if (!auth_isadmin()) {
                msg($this->getLang('err_admin_required'), -1);
                return;
            }
            $this->scrub = $this->runScan(true);
        }
    }

    /**
     * Render the admin page.
     *
     * @return void
     */
    public function html()
    {
        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo '<p>'
            . sprintf($this->getLang('intro_rewrite'), '<code>' . hsc(self::PLACEHOLDER_IP) . '</code>')
            . '<br>'
            . $this->getLang('intro_realtime')
            . '<br>'
            . $this->getLang('intro_preserved')
            . '</p>';

        echo '<p style="background:#fff3cd; border:1px solid #ffeeba; padding:8px; border-radius:4px;">'
            . '<strong>' . $this->getLang('warn_heading') . '</strong><br>'
            . $this->getLang('warn_data') . '<br>'
            . sprintf($this->getLang('warn_attic'), '<code>data/attic/</code>') . '<br>'
            . $this->getLang('warn_backup')
            . '</p>';

        $this->renderForm();

        if ($this->preview !== null) {
            $this->renderResults($this->getLang('heading_preview'), $this->preview, false);
        }
        if ($this->scrub !== null) {
            $this->renderResults($this->getLang('heading_scrub_done'), $this->scrub, true);
        }
    }

    /* ----------------------------------------------------------------- *
     *  Form
     * ----------------------------------------------------------------- */

    /**
     * Render the preview/scrub action form.
     *
     * @return void
     */
    protected function renderForm()
    {
        $form = new Form(['method' => 'POST', 'id' => 'hideip_form']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'hideip');

        $form->addTagOpen('p');
        $form->addButton('hideip_action', $this->getLang('btn_preview'))->val('preview');
        $form->addHTML(' &nbsp;&nbsp; ');
        $form->addButton('hideip_action', $this->getLang('btn_scrub'))->val('scrub');
        $form->addTagClose('p');

        echo $form->toHTML();
    }

    /* ----------------------------------------------------------------- *
     *  Scan/scrub orchestrator
     * ----------------------------------------------------------------- */

    /**
     * Walk all target files and either count IP-bearing entries or rewrite them.
     *
     * @param bool $mutate  false = preview only, true = rewrite on disk
     * @return array[]      [section_label => [files, lines, errors]]
     */
    protected function runScan($mutate)
    {
        global $conf;

        if (function_exists('set_time_limit')) set_time_limit(0);
        if (function_exists('ignore_user_abort')) ignore_user_abort(true);

        $sections = [
            $this->getLang('section_page_changes')  => [
                'root' => $conf['metadir'],
                'kind' => 'changes',
            ],
            $this->getLang('section_media_changes') => [
                'root' => $conf['mediametadir'],
                'kind' => 'changes',
            ],
            $this->getLang('section_page_meta')     => [
                'root' => $conf['metadir'],
                'kind' => 'meta',
            ],
        ];

        $results = [];
        foreach ($sections as $label => $cfg) {
            $results[$label] = $this->walkSection($cfg['root'], $cfg['kind'], $mutate);
        }
        return $results;
    }

    /**
     * Walk one section root, dispatching each candidate file to the right scrubber.
     *
     * @param string $root
     * @param string $kind    'changes' or 'meta'
     * @param bool   $mutate
     * @return array{files:int,lines:int,errors:array}
     */
    protected function walkSection($root, $kind, $mutate)
    {
        $stats = ['files' => 0, 'lines' => 0, 'errors' => []];

        if (!is_dir($root)) return $stats;

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            $stats['errors'][] = $root . ': ' . $e->getMessage();
            return $stats;
        }

        foreach ($it as $info) {
            $path = '?';
            try {
                if (!$info->isFile() || !$info->isReadable()) continue;
                $path = $info->getPathname();
                $base = basename($path);

                // Filter by extension matching the section we're walking.
                if ($kind === 'changes' && !str_ends_with($base, '.changes')) continue;
                if ($kind === 'meta'    && !str_ends_with($base, '.meta'))    continue;

                $count = ($kind === 'changes')
                    ? $this->processChangelog($path, $mutate)
                    : $this->processMetaFile($path, $mutate);

                if ($count > 0) {
                    $stats['files']++;
                    $stats['lines'] += $count;
                }
            } catch (Exception $e) {
                $stats['errors'][] = $path . ': ' . $e->getMessage();
            }
        }
        return $stats;
    }

    /* ----------------------------------------------------------------- *
     *  Changelog (.changes) scrubber — TSV format
     * ----------------------------------------------------------------- */

    /**
     * Process one .changes file.
     *
     * Line format (DokuWiki convention, tab-separated):
     *   timestamp \t ip \t type \t pageid \t user \t summary \t extra \t sizechange \n
     *
     * The IP field is field index 1. We rewrite it to PLACEHOLDER_IP unless it
     * already equals the placeholder (idempotent) or is empty (already scrubbed
     * by an older tool like the GDPR plugin which blanked it).
     *
     * When mutating, io_lock() is held for the full read-modify-write cycle so
     * concurrent changelog appends (which also use io_lock) are serialized.
     *
     * @param string $path
     * @param bool   $mutate  false = count lines that would change, true = rewrite
     * @return int            number of lines counted/changed
     */
    protected function processChangelog($path, $mutate)
    {
        if ($mutate) io_lock($path);
        try {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('cannot read');
            }

            // Use \n split so we can rejoin without modification. Trailing newline
            // (if any) becomes an empty final element we filter when rebuilding.
            $lines = explode("\n", $content);
            $hadTrailingNewline = ($content !== '' && substr($content, -1) === "\n");
            if ($hadTrailingNewline) array_pop($lines);   // drop the empty tail

            $changed = 0;
            foreach ($lines as $i => $line) {
                if ($line === '') continue;                 // skip blank lines in-place
                $fields = explode("\t", $line);
                if (count($fields) < 2) continue;           // malformed; leave alone

                $ip = $fields[1];
                if ($ip === self::PLACEHOLDER_IP) continue; // already scrubbed
                if (trim($ip) === '') continue;             // already blanked (GDPR-style)

                $fields[1] = self::PLACEHOLDER_IP;
                $lines[$i] = implode("\t", $fields);
                $changed++;
            }

            if ($changed === 0) return 0;
            if (!$mutate) return $changed;

            $newContent = implode("\n", $lines);
            if ($hadTrailingNewline) $newContent .= "\n";

            $this->atomicWrite($path, $newContent);
            return $changed;
        } finally {
            if ($mutate) io_unlock($path);
        }
    }

    /* ----------------------------------------------------------------- *
     *  Page metadata (.meta) scrubber — PHP serialize format
     * ----------------------------------------------------------------- */

    /**
     * Process one .meta file.
     *
     * .meta is a serialize()d ['current' => [...], 'persistent' => [...]]
     * structure (see inc/parserutils.php::p_save_metadata). The IP can live
     * under last_change.ip in either branch.
     *
     * When mutating, io_lock() is held for the full read-modify-write cycle so
     * concurrent metadata saves (which also use io_lock) are serialized.
     *
     * @param string $path
     * @param bool   $mutate
     * @return int   number of ip slots changed (0..2 per file)
     */
    protected function processMetaFile($path, $mutate)
    {
        if ($mutate) io_lock($path);
        try {
            $raw = file_get_contents($path);
            if ($raw === false) throw new RuntimeException('cannot read');
            if ($raw === '')    return 0;

            $meta = unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($meta)) return 0;   // corrupt or non-meta - leave alone

            $changed = 0;
            foreach (['current', 'persistent'] as $branch) {
                if (
                    isset($meta[$branch]['last_change']['ip'])
                    && $meta[$branch]['last_change']['ip'] !== self::PLACEHOLDER_IP
                ) {
                    $meta[$branch]['last_change']['ip'] = self::PLACEHOLDER_IP;
                    $changed++;
                }
            }

            if ($changed === 0) return 0;
            if (!$mutate) return $changed;

            $this->atomicWrite($path, serialize($meta));
            return $changed;
        } finally {
            if ($mutate) io_unlock($path);
        }
    }

    /* ----------------------------------------------------------------- *
     *  Safe write helper
     * ----------------------------------------------------------------- */

    /**
     * Write $content to $path atomically, preserving the original mtime.
     *
     * The caller must already hold io_lock($path) when mutating to prevent
     * concurrent writes from being lost by the rename.
     *
     * @param string $path
     * @param string $content
     * @throws RuntimeException on any unrecoverable failure
     */
    protected function atomicWrite($path, $content)
    {
        $origMtime = filemtime($path);
        $tmp = $path . '.hideip_tmp_' . bin2hex(random_bytes(self::TMP_SUFFIX_BYTES));

        $ok = file_put_contents($tmp, $content, LOCK_EX);
        if ($ok === false) {
            if (is_file($tmp)) unlink($tmp);
            throw new RuntimeException('failed to write temp file');
        }

        // Copy permissions from the original so the rename doesn't change them.
        $origPerms = fileperms($path);
        if ($origPerms !== false) chmod($tmp, $origPerms & 0777);

        if (!rename($tmp, $path)) {
            if (is_file($tmp)) unlink($tmp);
            throw new RuntimeException('atomic rename failed');
        }

        if ($origMtime !== false) touch($path, $origMtime);
    }

    /* ----------------------------------------------------------------- *
     *  Presentation
     * ----------------------------------------------------------------- */

    /**
     * Render the results table for a preview or scrub run.
     *
     * @param string  $heading    pre-translated heading string
     * @param array[] $results    [section_label => [files, lines, errors]]
     * @param bool    $wasScrub
     * @return void
     */
    protected function renderResults($heading, array $results, $wasScrub)
    {
        echo '<h2>' . hsc($heading) . '</h2>';

        $totalFiles  = 0;
        $totalLines  = 0;
        $totalErrors = 0;
        foreach ($results as $stats) {
            $totalFiles  += $stats['files'];
            $totalLines  += $stats['lines'];
            $totalErrors += count($stats['errors']);
        }

        if ($wasScrub) {
            echo '<p>' . sprintf($this->getLang('done_summary'), $totalLines, $totalFiles) . '</p>';
        } else {
            echo '<p>' . sprintf($this->getLang('preview_summary'), $totalLines, $totalFiles) . '</p>';
        }

        $colSlots = $wasScrub
            ? $this->getLang('col_slots_rewritten')
            : $this->getLang('col_slots_pending');

        echo '<table class="inline"><thead><tr>'
            . '<th>' . hsc($this->getLang('col_section')) . '</th>'
            . '<th>' . hsc($this->getLang('col_files')) . '</th>'
            . '<th>' . hsc($colSlots) . '</th>'
            . '<th>' . hsc($this->getLang('col_errors')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($results as $label => $stats) {
            echo '<tr>'
                . '<td>' . hsc($label) . '</td>'
                . '<td style="text-align:right;">' . (int)$stats['files'] . '</td>'
                . '<td style="text-align:right;">' . (int)$stats['lines'] . '</td>'
                . '<td style="text-align:right;">' . count($stats['errors']) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

        if ($totalErrors > 0) {
            echo '<h3>' . hsc($this->getLang('errors_heading')) . '</h3><ul>';
            foreach ($results as $stats) {
                foreach ($stats['errors'] as $err) {
                    echo '<li><code>' . hsc($err) . '</code></li>';
                }
            }
            echo '</ul>';
        }
    }
}
