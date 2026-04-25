<?php
/**
 * Application service layer.
 *
 * @package Extension_Cursor
 */

if (! defined('ABSPATH')) {
	exit;
}

final class Extension_Cursor_Service {

	private Extension_Cursor_Repository $repository;

	public function __construct(?Extension_Cursor_Repository $repository = null) {
		$this->repository = $repository ?? new Extension_Cursor_Repository();
	}

	public function get_dashboard_stats(): array { return $this->repository->get_dashboard_stats(); }
	public function get_licences_for_ui(): array { return $this->repository->get_licences_for_ui(); }
	public function get_all_licences_for_ui(): array { return $this->repository->get_all_licences_for_ui(); }
	public function get_keys_for_ui(): array { return $this->repository->get_available_keys_for_ui(); }
	public function get_all_keys_for_ui(): array { return $this->repository->get_all_keys_for_ui(); }
	public function get_assigned_licences_for_key(int $key_id): array { return $this->repository->get_assigned_licences_for_key($key_id); }
	public function get_monitor_rows(): array { return $this->repository->get_monitor_rows(); }
	public function get_monitor_detail(?int $key_id = null): array { return $this->repository->get_monitor_detail($key_id); }
	public function import_licences(array $rows): array { return $this->repository->insert_multiple_licences($rows); }
	public function save_key(array $row): bool { return $this->repository->insert_key($row); }
	public function update_key(int $id, array $row): bool { return $this->repository->update_key($id, $row); }
	public function assign_licences_to_key(int $key_id, array $licence_ids): array { return $this->repository->assign_licences_to_key($key_id, $licence_ids); }
	public function unassign_licences_from_key(int $key_id, array $licence_ids): int { return $this->repository->unassign_licences_from_key($key_id, $licence_ids); }
	public function replace_licences_for_key(int $key_id, array $licence_ids): array { return $this->repository->replace_licences_for_key($key_id, $licence_ids); }
	public function save_runtime_snapshot(array $row): bool { return $this->repository->insert_runtime_snapshot($row); }
	public function delete_licence(int $id): bool { return $this->repository->delete_licence($id); }
	public function delete_key(int $id): bool { return $this->repository->delete_key($id); }
}
