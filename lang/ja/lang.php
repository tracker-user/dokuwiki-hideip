<?php
$lang['menu']                  = 'IPを隠す';

// 管理ページ – 説明
$lang['intro_rewrite']         = 'このページは、ディスク上の過去のIPアドレスを %s に書き換えます。';
$lang['intro_realtime']        = '新しい編集は、このプラグインのアクションコンポーネント（すべてのリクエストで読み込まれます）によって既に匿名化されています。';
$lang['intro_preserved']       = 'タイムスタンプと著者情報は保持されます。';

// 警告ボックス
$lang['warn_heading']          = 'この操作は不可逆です。';
$lang['warn_data']             = 'ページおよびメディアの変更履歴とページメタデータに記録された実際のIPアドレスは置き換えられ、これらのファイルから復元することはできません。';
$lang['warn_attic']            = '%s のリビジョンアーカイブは変更されません。ウィキにこれらが保存されている場合、保存されたリビジョン内のIPアドレスはそのまま残ります。';
$lang['warn_backup']           = '回復ポイントが必要な場合は、先にサイトバックアッププラグインでバックアップを取ってください。';

// ボタン
$lang['btn_preview']           = 'プレビュー（件数確認のみ）';
$lang['btn_scrub']             = '今すぐクリーンアップ';

// エラーメッセージ
$lang['err_post_only']         = 'Hide IP: クリーンアップはPOSTで送信する必要があります。';
$lang['err_admin_required']    = 'Hide IP: 管理者権限が必要です。';

// セクションラベル
$lang['section_page_changes']  = 'ページ変更履歴 (data/meta/*.changes)';
$lang['section_media_changes'] = 'メディア変更履歴 (data/media_meta/*.changes)';
$lang['section_page_meta']     = 'ページメタデータ (data/meta/*.meta)';

// 結果の見出し
$lang['heading_preview']       = 'プレビュー';
$lang['heading_scrub_done']    = 'クリーンアップ完了';

// 結果サマリー行（%1$d = IPスロット数、%2$d = ファイル数）
$lang['done_summary']          = '完了。%2$d ファイルで %1$d 件のIPスロットを書き換えました。';
$lang['preview_summary']       = '%2$d ファイルで %1$d 件のIPスロットを書き換える予定です。';

// テーブル列ヘッダー
$lang['col_section']           = 'セクション';
$lang['col_files']             = '影響を受けるファイル';
$lang['col_slots_rewritten']   = '書き換えたIPスロット';
$lang['col_slots_pending']     = '書き換え予定のIPスロット';
$lang['col_errors']            = 'エラー';
$lang['errors_heading']        = 'エラー';
