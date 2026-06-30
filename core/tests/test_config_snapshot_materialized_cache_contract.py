from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def test_config_state_materialized_cache_columns_are_additive():
    migration = read("core/database/migrations/000032_config_snapshot_materialized_cache.sql")
    schema = read("core/database/schema.sql")

    for column in ("dirty", "dirty_at", "published_at", "last_publish_error", "publishing_started_at"):
        assert f"ADD COLUMN IF NOT EXISTS {column}" in migration
        assert column in schema
    assert "DROP TABLE" not in migration
    assert "DELETE FROM config_snapshots" not in migration


def test_edge_config_uses_published_cache_not_legacy_rebuild_route():
    routes = read("core/routes/api.php")
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    sync_command = read("core/app/Console/Commands/CdnEdgeSyncConfigCommand.php")
    edge_controller = read("core/app/Http/Controllers/Api/EdgeController.php")

    assert "EdgeConfigSnapshotService $config" in edge_controller
    assert "$configService->buildSnapshotForVersion(" not in routes
    assert "EdgeConfigSnapshotService" in sync_command
    assert "->publish()" in sync_command
    assert "public function edgeConfig(?int $ifVersion = null): array" in service
    assert "return ['not_modified' => true, 'version' => $activeVersion]" in service
    assert "pg_try_advisory_lock(hashtext('cdnlite_config_publish'))" in service
    assert "stale_while_rebuilding" in service


def test_dirty_marks_preserve_active_snapshot_and_history_is_disabled_by_default():
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    snapshots = read("core/app/Services/ControlPlane/EdgeConfigSnapshotService.php")

    assert "public static function markDirty" in service
    assert "SET dirty = true, dirty_at = :dirty_at" in service
    assert "active_snapshot_version = NULL" not in service
    assert "snapshot_history_enabled" in snapshots
    assert "config_snapshot_history_disabled" in snapshots


def test_count_based_prune_command_is_registered_and_keeps_active_snapshot():
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    console = read("core/routes/console.php")
    command = read("core/app/Console/Commands/CdnConfigSnapshotsPruneCommand.php")

    assert "cdn:config-snapshots:prune" in console
    assert "--keep=2 --batch=5000 --dry-run" in read("docs/setup.md")
    assert "active_snapshot_version AS version" in service
    assert "ORDER BY version DESC LIMIT :keep_last" in service
    assert "DELETE FROM config_snapshots" in service
    assert "dry_run" in command
