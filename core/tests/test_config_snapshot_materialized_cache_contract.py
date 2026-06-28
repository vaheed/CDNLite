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
    routes = read("core/public_index.php")
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    sync_command = read("core/app/Console/Commands/CdnEdgeSyncConfigCommand.php")

    assert "$configService->edgeConfig(" in routes
    assert "$configService->buildSnapshotForVersion(" not in routes
    assert "->edgeConfig($ifVersion)" in sync_command
    assert "public function edgeConfig(?int $ifVersion = null): array" in service
    assert "return ['not_modified' => true, 'version' => $activeVersion]" in service
    assert "pg_try_advisory_lock(hashtext('cdnlite_config_publish'))" in service
    assert "stale_while_rebuilding" in service


def test_dirty_marks_preserve_active_snapshot_and_history_is_disabled_by_default():
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    routes = read("core/public_index.php")

    assert "public static function markDirty" in service
    assert "SET dirty = true, dirty_at = :dirty_at" in service
    assert "active_snapshot_version = NULL" not in service
    assert "CDNLITE_CONFIG_SNAPSHOT_HISTORY_ENABLED" in routes
    assert "config_snapshot_history_disabled" in routes


def test_count_based_prune_command_is_registered_and_keeps_active_snapshot():
    service = read("core/app/Modules/Proxy/Services/ConfigService.php")
    artisan = read("core/artisan")
    command = read("core/app/Console/Commands/CdnConfigSnapshotsPruneCommand.php")

    assert "cdn:config-snapshots:prune" in artisan
    assert "--keep=2 --batch=5000 --dry-run" in read("docs/setup.md")
    assert "active_snapshot_version AS version" in service
    assert "ORDER BY version DESC LIMIT :keep_last" in service
    assert "DELETE FROM config_snapshots" in service
    assert "dry_run" in command
