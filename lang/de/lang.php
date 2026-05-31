<?php
$lang['menu']                  = 'IP verbergen';

// Admin-Seite – Einleitung
$lang['intro_rewrite']         = 'Diese Seite ersetzt historische IP-Adressen auf der Festplatte durch %s.';
$lang['intro_realtime']        = 'Neue Bearbeitungen werden bereits durch die Aktionskomponente dieses Plugins anonymisiert (lädt bei jeder Anfrage).';
$lang['intro_preserved']       = 'Zeitstempel und Autorschaft bleiben erhalten.';

// Warnungsfeld
$lang['warn_heading']          = 'Diese Aktion ist destruktiv.';
$lang['warn_data']             = 'Echte IP-Adressen, die in Seiten- und Medien-Changelogs sowie in Seitenmetadaten gespeichert sind, werden ersetzt und können aus diesen Dateien nicht wiederhergestellt werden.';
$lang['warn_attic']            = 'Die %s-Revisionsarchive werden nicht verändert – falls Ihr Wiki diese aufbewahrt, bleiben IPs aus gespeicherten Versionen darin enthalten.';
$lang['warn_backup']           = 'Erstellen Sie zuerst ein Backup mit dem Site-Backup-Plugin, wenn Sie einen Wiederherstellungspunkt wünschen.';

// Schaltflächen
$lang['btn_preview']           = 'Vorschau (nur zählen)';
$lang['btn_scrub']             = 'Jetzt bereinigen';

// Fehlermeldungen
$lang['err_post_only']         = 'Hide IP: Bereinigung muss per POST gesendet werden.';
$lang['err_admin_required']    = 'Hide IP: Administratorzugriff erforderlich.';

// Abschnittsbezeichnungen
$lang['section_page_changes']  = 'Seiten-Changelogs (data/meta/*.changes)';
$lang['section_media_changes'] = 'Medien-Changelogs (data/media_meta/*.changes)';
$lang['section_page_meta']     = 'Seitenmetadaten (data/meta/*.meta)';

// Ergebnisüberschriften
$lang['heading_preview']       = 'Vorschau';
$lang['heading_scrub_done']    = 'Bereinigung abgeschlossen';

// Ergebniszusammenfassung (%1$d = IP-Einträge, %2$d = Dateien)
$lang['done_summary']          = 'Fertig. %1$d IP-Einträge in %2$d Datei(en) ersetzt.';
$lang['preview_summary']       = 'Würde %1$d IP-Einträge in %2$d Datei(en) ersetzen.';

// Tabellenspaltentitel
$lang['col_section']           = 'Bereich';
$lang['col_files']             = 'Betroffene Dateien';
$lang['col_slots_rewritten']   = 'IP-Einträge ersetzt';
$lang['col_slots_pending']     = 'IP-Einträge zu ersetzen';
$lang['col_errors']            = 'Fehler';
$lang['errors_heading']        = 'Fehler';
