<?php
$lang['menu']                  = 'Hide IP';

// Admin page intro
$lang['intro_rewrite']         = 'This page rewrites historical IP addresses on disk to %s.';
$lang['intro_realtime']        = 'New edits are already anonymised by the action component of this plugin (loads on every request).';
$lang['intro_preserved']       = 'Timestamps and authorship are preserved.';

// Warning box
$lang['warn_heading']          = 'This action is destructive.';
$lang['warn_data']             = 'Real IP addresses recorded in page and media changelogs and in page metadata will be replaced and cannot be recovered from these files.';
$lang['warn_attic']            = 'The %s revision archives are not modified — if your wiki retains those, IPs from saved revisions remain inside them.';
$lang['warn_backup']           = 'Take a backup with the Site Backup plugin first if you want a recovery point.';

// Buttons
$lang['btn_preview']           = 'Preview (count only)';
$lang['btn_scrub']             = 'Scrub now';

// Error messages
$lang['err_post_only']         = 'Hide IP: scrub must be submitted via POST.';
$lang['err_admin_required']    = 'Hide IP: admin access required.';

// Section labels
$lang['section_page_changes']  = 'Page changelogs (data/meta/*.changes)';
$lang['section_media_changes'] = 'Media changelogs (data/media_meta/*.changes)';
$lang['section_page_meta']     = 'Page metadata (data/meta/*.meta)';

// Result headings
$lang['heading_preview']       = 'Preview';
$lang['heading_scrub_done']    = 'Scrub complete';

// Result summary lines (%1$d = IP slots, %2$d = files)
$lang['done_summary']          = 'Done. Rewrote %1$d IP slot(s) across %2$d file(s).';
$lang['preview_summary']       = 'Would rewrite %1$d IP slot(s) across %2$d file(s).';

// Table column headers
$lang['col_section']           = 'Section';
$lang['col_files']             = 'Files affected';
$lang['col_slots_rewritten']   = 'IP slots rewritten';
$lang['col_slots_pending']     = 'IP slots to rewrite';
$lang['col_errors']            = 'Errors';
$lang['errors_heading']        = 'Errors';
