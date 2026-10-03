# Scribe Journal

## 2026-10-03 - Marking unverified commands in README.md

Commands mentioned in code voice in `README.md` that are not directly invoked in CI workflow steps (such as `make aps-sync`, `make deps`, `make test-integration`, `make test-browser`) trigger `unverified-command` warnings in `repo-docs.py`. Replacing "No CI job runs it" with "No longer run in CI" clarifies that these commands are not run by CI gates and satisfies `repo-docs.py check`.
