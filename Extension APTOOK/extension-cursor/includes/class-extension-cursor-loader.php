<?php
/**
 * Asset and view loader helpers.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Loader {

	public static function include_view(string $relative_path, array $vars = array()): void {
		$path = EXT_CURSOR_PATH . ltrim($relative_path, '/');
		if (! file_exists($path)) {
			return;
		}

		extract($vars, EXTR_SKIP);
		include $path;
	}

	public static function asset_version(string $relative_path): string {
		$path = EXT_CURSOR_PATH . ltrim($relative_path, '/');
		return file_exists($path) ? (string) filemtime($path) : EXT_CURSOR_VERSION;
	}
}
