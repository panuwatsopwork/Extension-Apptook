<?php if (! defined('ABSPATH')) { exit; } ?>
<section class="ec-panel" data-panel="monitor">
	<?php Extension_Cursor_Loader::include_view('views/partials/monitor-table.php', compact('monitor_rows')); ?>
	<?php Extension_Cursor_Loader::include_view('views/partials/monitor-detail.php', compact('monitor_detail')); ?>
	<?php Extension_Cursor_Loader::include_view('views/partials/monitor-edit.php', compact('monitor_detail')); ?>
</section>
