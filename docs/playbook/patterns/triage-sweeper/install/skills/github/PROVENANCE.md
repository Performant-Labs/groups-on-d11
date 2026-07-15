# Vendored skills — provenance

These three skills are the **"hands"** of the triage-sweeper: the actual, maintained
GitHub toolset the reference implementation (NousResearch's `hermes-sweeper`) uses to
inspect code and manipulate issues. We vendor them rather than hand-rolling `gh` calls so
the bundle tracks an upstream, MIT-licensed toolset with a clear audit trail.

| Skill | Role in the sweeper | Upstream |
|---|---|---|
| `github-issues/` | View / search / triage / label / comment on issues | `skills/github/github-issues` |
| `github-code-review/` | Diff + commit inspection → the `path:line` + commit-SHA evidence | `skills/github/github-code-review` |
| `codebase-inspection/` | LOC / language pre-scan ("where to look" before diving in) | `skills/github/codebase-inspection` |

- **Source:** https://github.com/NousResearch/hermes-agent (`skills/github/`)
- **License:** MIT — see `LICENSE` in this directory (© 2025 Nous Research)
- **Pinned commit:** `0a593f132c41d35111b1b84f599b3a0316ebaaf8` (2026-06-10)
- **Vendored:** 2026-07-13, unmodified.

## Updating

Re-pull from the same path at a newer commit and update the pin above:

```bash
R=NousResearch/hermes-agent
SHA=<new-sha>
# (use the vendoring snippet in the playbook commit history, or gh api git/trees + git/blobs)
```

Keep them **unmodified** — local edits belong in our own `prompt.md` / `sweeper.py`, not in
the vendored skills, so updates stay a clean re-pull.
