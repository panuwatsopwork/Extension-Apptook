<?php if (! defined('ABSPATH')) { exit; } ?>
<div class="extension-cursor-admin">
	<?php Extension_Cursor_Loader::include_view('views/partials/header.php', compact('stats', 'monitor_rows', 'monitor_detail', 'licences', 'keys', 'available_keys', 'all_keys')); ?>

	<section class="ec-panel is-active" data-panel="main">
		<?php Extension_Cursor_Loader::include_view('views/partials/stats.php', compact('stats')); ?>
		<?php Extension_Cursor_Loader::include_view('views/partials/licence-list.php', compact('licences', 'keys', 'available_keys')); ?>
	</section>

	<section class="ec-panel" data-panel="monitor">
		<?php Extension_Cursor_Loader::include_view('views/partials/monitor-table.php', compact('monitor_rows')); ?>
		<?php Extension_Cursor_Loader::include_view('views/partials/monitor-detail.php', compact('monitor_detail')); ?>
		<?php Extension_Cursor_Loader::include_view('views/partials/monitor-edit.php', compact('monitor_detail', 'all_licences', 'all_keys')); ?>
	</section>

	<?php Extension_Cursor_Loader::include_view('views/partials/scripts.php', compact('stats', 'monitor_rows', 'monitor_detail', 'licences', 'keys', 'available_keys', 'all_licences', 'all_keys')); ?>
</div>
