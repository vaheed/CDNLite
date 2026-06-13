from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def test_openapi_links_do_not_escape_github_pages_project_base():
    docs = ROOT / "docs"
    files = (
        docs / "index.md",
        docs / "api" / "api.md",
        docs / "examples" / "index.md",
        docs / "setup.md",
    )

    for path in files:
        assert "](/api/openapi.yaml)" not in path.read_text()

    config = (docs / ".vitepress" / "config.mts").read_text()
    assert "`${base}api/openapi.yaml`" in config


def test_readme_uses_published_project_pages_url():
    readme = (ROOT / "README.md").read_text()

    assert "https://vaheed.github.io/CDNLite/api/openapi.yaml" in readme
    assert "https://vaheed.github.io/api/openapi.yaml" not in readme
