import os
import subprocess
import time
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
DEADLOCK_SQLSTATE = "SQLSTATE[40P01]"


def run_php_with_deadlock_retry(
    script: str,
    env: dict[str, str],
    *,
    attempts: int = 3,
    retry_delay: float = 0.1,
) -> None:
    for attempt in range(attempts):
        try:
            subprocess.run(
                ["php", "-r", script],
                cwd=str(REPO_ROOT),
                capture_output=True,
                text=True,
                check=True,
                env={**os.environ, **env},
            )
            return
        except subprocess.CalledProcessError as error:
            if DEADLOCK_SQLSTATE not in (error.stderr or "") or attempt == attempts - 1:
                raise
            time.sleep(retry_delay * (attempt + 1))
