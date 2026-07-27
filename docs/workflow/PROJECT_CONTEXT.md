# Pipeline Project Context — groups-on-d11

**Canonical facts for every coding-pipeline agent (O/D/A/T/F/U/S) working this repo.**
This overrides the generic Node/npm/vitest assumptions baked into the playbook role templates —
groups-on-d11 is a **Drupal 11 / DDEV** project. Read this + the target GitHub issue +
`docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §6 before opening any phase.

> To be visible inside per-story worktrees (which check out `origin/main`), this file must be
> **committed**. Until then, the same facts are embedded in each agent def under
> `~/.claude/agents/*.md` (the "Project override" block), which is always in scope.

---

## Repo & isolation
- **Repo:** `Performant-Labs/groups-on-d11` (the deployable DEMO site — a POC to play with; favor
  visible/demo-credible over production hardening). NOT the drupalcode contrib module — leave that alone.
- **Primary checkout** `~/Projects/groups-on-d11` is a **read-only reference**. Never mutate it.
- **Per-story worktree:**
  ```bash
  git -C ~/Projects/groups-on-d11 worktree add ~/Projects/_worktrees/groups-<slug> -b <n>-<slug> origin/main
  git -C ~/Projects/_worktrees/groups-<slug> remote get-url origin   # must contain "groups-on-d11"
  ```
- Multiple Claude sessions may touch this repo — don't assume exclusive ownership of worktrees/containers.

## The "spec" is the GitHub issue
- Per-story spec: `gh issue view <n> --repo Performant-Labs/groups-on-d11`. There is **no**
  `SPEC.md` / `BUILD_PLAN.md` — the orchestrator's Pre-Flight expectations for those do not apply.
- Roadmap / orientation: `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md`.
- Handoff directory: `docs/planning/handoffs/` (not `docs/handoffs/`).

## Source of truth = `docs/groups/` ONLY
- Modules: `docs/groups/modules/do_*` · Config: `docs/groups/config/*` · Seed/scripts: `docs/groups/scripts/step_*.php`
- **Feature commits are source-only** (`docs/groups/…`). Stage by explicit path; never `git add .`.
- `web/modules/custom/` and `config/sync/` are **gitignored build artifacts** — never edit or commit them.

## Assemble, then verify (the real CI — `.github/workflows/test.yml`)
Always assemble first; CI runs from the **assembled** layout, not source:
```bash
bash scripts/ci/assemble-config.sh    # docs/groups/modules/do_* -> web/modules/custom/ ; docs/groups/config/* -> config/sync/ ; patches core.extension.yml
```
- **Kernel** (core deliverable, must be green):
  ```bash
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    $(find web/modules/custom -type d -path '*/tests/src/Kernel')
  ```
- **Functional** (BrowserTestBase): self-installs a throwaway site per test; needs a served docroot at
  `SIMPLETEST_BASE_URL` (`http://127.0.0.1:8080`). No demo seed.
- **E2E** (Playwright): `npx playwright test` (`tests/e2e/*.spec.ts`) against a **fully seeded** served
  site — assemble → `drush site:install` → `drush cim` → seed `docs/groups/scripts/step_*.php` →
  runserver → playwright. An isolated fixture is NOT representative of the seeded E2E job.
- **Lint:** `php vendor/bin/phpcs` (Drupal / DrupalPractice standard; `vendor/bin/` has phpcs + phpunit).
- **Local env:** DDEV, project `pl-groups-on-d11` — `ddev start`, `ddev composer …`, `ddev drush …`.
  Namespace any extra containers per story (`gm<n>-*`); **never `docker rm` a container you didn't create.**

## Fixtures & test authorship
- Fixtures must be **module-local** (`tests/fixtures/config/`), never a source-relative path like
  `__DIR__/../../../../../config` — that passes in the source tree and fails in CI's assembled layout.
- A red/errored CI job is never "done." "env-blocked / core bug" usually masks a test-authorship bug —
  diagnose it; prefer fixing over `markTestSkipped`.

## Gotchas that cost hours (WAVE-EXECUTION-HANDOFF §6)
1. CI = assembled layout, not source.
2. Edit ONLY `docs/groups/`; assembled copies are overwritten.
3. `drupal/grequest` is unusable on Group 4.0.x → request-to-join (#121) is **bespoke** on the
   customized `group_membership` relationship (status field: active/pending/blocked).
4. New group routes collide with `drupal/group` optional views (e.g. stock `group_members`): delete the
   page display from config AND add a `hook_install()` + `hook_modules_installed()` strip, then call
   `router.builder->rebuild()` **in the same request**.
5. `#type => submit` renders `<input type=submit>`, not `<button>` — e2e locators must account for it;
   `getByLabel(/…/)` can strict-mode-collide on a seeded page.

## Foundation
Drupal 11 + `drupal/group` 4.0.x-dev (Group module, NOT Organic Groups). Group entities, group roles as
the permission mechanism, a customized `group_membership` relationship. MVP visibility model = two axes:
visibility (public/unlisted/private) + join_policy (open/request).

## Review, models, merge
- **Review gate:** `docs/playbook/workflow/dual-review.sh` runs **o4-mini** at the brief + diff gates
  (`OPENAI_API_KEY` in repo `.env`). Single o4-mini rung — do not add a fresh-Opus panel.
- **Model tiers (user is token-constrained):** role frontmatter sets Sonnet for D/F/T/U and Opus for
  O/A/S. Do not silently inherit a heavier model.
- **A human (aangelinsf) merges every PR — never self-merge.** `gh pr create`; disclose AI involvement in
  the PR body; `Co-Authored-By` on commits; assign PR to `aangelinsf`; scoped labels mirroring the issue.
  WCAG 2.2 AA on every UI story.

## Spawn fallback
Some sessions on this workstation do not expose a subagent-spawn tool (the SDK agent registry is fixed at
session start and may not load `~/.claude/agents/`). If spawning genuinely fails, use the orchestrator's
**Human-Relay Mode**: emit paste-ready prompts for the operator and wait for completion reports. State
explicitly that you're falling back, and why.
