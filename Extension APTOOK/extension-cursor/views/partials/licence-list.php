<?php if (! defined('ABSPATH')) { exit; } ?>
<div class="ec-main-steps-grid">
	<?php Extension_Cursor_Loader::include_view('views/partials/step1.php', compact('licences', 'keys', 'available_keys')); ?>
	<?php Extension_Cursor_Loader::include_view('views/partials/step2.php', compact('licences', 'keys', 'available_keys')); ?>
</div>
<?php Extension_Cursor_Loader::include_view('views/partials/step3.php', compact('licences', 'keys', 'available_keys')); ?>
