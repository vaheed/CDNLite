import subprocess
from unittest.mock import Mock

import pytest

import db_test_utils


def test_php_runner_retries_deadlocks(monkeypatch):
    deadlock = subprocess.CalledProcessError(
        255,
        ["php", "-r", "test"],
        stderr="PDOException: SQLSTATE[40P01]: Deadlock detected",
    )
    run = Mock(side_effect=[deadlock, None])
    sleep = Mock()
    monkeypatch.setattr(db_test_utils.subprocess, "run", run)
    monkeypatch.setattr(db_test_utils.time, "sleep", sleep)

    db_test_utils.run_php_with_deadlock_retry("test", {}, retry_delay=0.25)

    assert run.call_count == 2
    sleep.assert_called_once_with(0.25)


def test_php_runner_does_not_retry_other_failures(monkeypatch):
    failure = subprocess.CalledProcessError(
        1,
        ["php", "-r", "test"],
        stderr="PDOException: SQLSTATE[08006]: Connection failure",
    )
    run = Mock(side_effect=failure)
    sleep = Mock()
    monkeypatch.setattr(db_test_utils.subprocess, "run", run)
    monkeypatch.setattr(db_test_utils.time, "sleep", sleep)

    with pytest.raises(subprocess.CalledProcessError):
        db_test_utils.run_php_with_deadlock_retry("test", {})

    run.assert_called_once()
    assert sleep.call_args_list == []
