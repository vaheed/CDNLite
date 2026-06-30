from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def test_api_auth_class_contract():
    middleware = (REPO_ROOT / "core/app/Http/Middleware/AdminBearerAuth.php").read_text()
    guard = (REPO_ROOT / "core/app/Services/Auth/AdminSessionGuard.php").read_text()
    routes = (REPO_ROOT / "core/routes/api.php").read_text()

    assert "bearerToken()" in middleware
    assert "AdminSessionGuard::class" in middleware
    assert "admin_auth_required" in middleware
    assert "admin.auth" in routes
    assert "userForToken" in guard
    assert "hash('sha256', $token)" in guard
