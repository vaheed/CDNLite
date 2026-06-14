import json
import os
import subprocess
import uuid
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
BASE_ENV = {
    "DB_HOST": os.getenv("DB_HOST", "127.0.0.1"),
    "DB_PORT": os.getenv("DB_PORT", "5432"),
    "DB_DATABASE": os.getenv("DB_DATABASE", "cdnlite"),
    "DB_USERNAME": os.getenv("DB_USERNAME", "cdnlite"),
    "DB_PASSWORD": os.getenv("DB_PASSWORD", "cdnlite"),
}


def run_php(script: str, env: dict[str, str] | None = None) -> str:
    proc = subprocess.run(
        ["php", "-r", script],
        cwd=str(ROOT),
        env={**os.environ, **BASE_ENV, **(env or {})},
        capture_output=True,
        text=True,
        check=True,
    )
    return proc.stdout


def run_artisan(command: str, database: str) -> dict:
    proc = subprocess.run(
        ["php", "core/artisan", *command.split()],
        cwd=str(ROOT),
        env={**os.environ, **BASE_ENV, "DB_DATABASE": database},
        capture_output=True,
        text=True,
        check=True,
    )
    return json.loads(proc.stdout)


def postgres_available() -> bool:
    try:
        run_php(
            r'''
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass);
'''
        )
        return True
    except (subprocess.CalledProcessError, FileNotFoundError):
        return False


def run_exec(database: str, sql: str) -> None:
    run_php(
        r'''
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$db = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(getenv('CDNLITE_TEST_SQL'));
''',
        env={"DB_DATABASE": database, "CDNLITE_TEST_SQL": sql},
    )


def run_query(database: str, sql: str) -> list[dict]:
    out = run_php(
        r'''
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$db = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$sql = getenv('CDNLITE_TEST_SQL');
$stmt = $pdo->query($sql);
echo json_encode($stmt ? $stmt->fetchAll() : [], JSON_UNESCAPED_SLASHES);
''',
        env={"DB_DATABASE": database, "CDNLITE_TEST_SQL": sql},
    )
    return json.loads(out)


@pytest.fixture
def temp_database():
    if not postgres_available():
        pytest.skip("PostgreSQL is not reachable in this test environment")

    name = f"cdnlite_migration_test_{uuid.uuid4().hex[:12]}"
    run_php(
        r'''
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
$db = getenv('CDNLITE_TEST_DATABASE');
$quoted = '"' . str_replace('"', '""', $db) . '"';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE DATABASE {$quoted}");
''',
        env={"CDNLITE_TEST_DATABASE": name},
    )
    try:
        yield name
    finally:
        run_php(
            r'''
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
$db = getenv('CDNLITE_TEST_DATABASE');
$quoted = '"' . str_replace('"', '""', $db) . '"';
$pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = " . $pdo->quote($db));
$pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
''',
            env={"CDNLITE_TEST_DATABASE": name},
        )


def test_migrations_create_empty_database_and_rerun_as_noop(temp_database):
    first = run_artisan("cdn:db:migrate", temp_database)
    second = run_artisan("cdn:db:migrate", temp_database)
    status = run_artisan("cdn:db:status", temp_database)

    assert first["ok"] is True
    assert any(item["status"] == "applied_now" for item in first["migrations"])
    assert second["ok"] is True
    assert all(item["status"] == "applied" for item in second["migrations"])
    assert status["ok"] is True
    assert status["compatibility"]["missing_tables"] == []

    rows = run_query(
        temp_database,
        "SELECT version, success FROM schema_migrations ORDER BY version",
    )
    assert rows
    assert rows[0]["version"] == "000001"
    assert all(row["success"] is True for row in rows)


def test_migrations_adopt_legacy_schema_without_reimporting_baseline(temp_database):
    legacy_schema = (ROOT / "core/database/schema.sql").read_text()
    run_exec(temp_database, legacy_schema)

    dry_run = run_artisan("cdn:db:migrate --dry-run", temp_database)
    applied = run_artisan("cdn:db:migrate", temp_database)
    rerun = run_artisan("cdn:db:migrate", temp_database)

    assert dry_run["ok"] is True
    assert dry_run["migrations"][0]["status"] == "would_adopt_legacy_baseline"
    assert applied["ok"] is True
    assert applied["migrations"][0]["status"] == "adopted_legacy_baseline"
    assert rerun["ok"] is True
    assert rerun["migrations"][0]["status"] == "applied"

    rows = run_query(
        temp_database,
        "SELECT version, success, error FROM schema_migrations WHERE version = '000001'",
    )
    assert rows == [{"version": "000001", "success": True, "error": None}]
