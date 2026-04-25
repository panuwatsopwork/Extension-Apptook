<?php if (! defined('ABSPATH')) { exit; }
/** @var array $stats */
/** @var array $monitor_rows */
/** @var array $monitor_detail */
/** @var array $licences */
/** @var array $keys */
/** @var array $available_keys */
/** @var array $all_licences */
/** @var array $all_keys */
Extension_Cursor_Loader::include_view('views/partials/layout.php', compact('stats', 'monitor_rows', 'monitor_detail', 'licences', 'keys', 'available_keys', 'all_licences', 'all_keys'));
