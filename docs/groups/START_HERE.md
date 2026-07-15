# Groups on Drupal — Start Here

> [!IMPORTANT]
> **Read these before doing anything else:**
> - [`ai_guidance/TROUBLESHOOTING.md`](../../ai_guidance/TROUBLESHOOTING.md) — process hangs, DDEV gotchas, config import locks, opcode cache issues. Consult this before debugging any unexpected behaviour.
> - [`ai_guidance/drupal/BEST_PRACTICES.md`](../../ai_guidance/drupal/BEST_PRACTICES.md) — mandatory module architecture standard (Services over Hooks). All custom modules in this project must follow this pattern.
> - [`ai_guidance/technical_writing/documentation_guidance.md`](../../ai_guidance/technical_writing/documentation_guidance.md) — documentation review checklist. Run through this before committing any doc changes.

This directory contains everything needed for a clean-room installation of Drupal Groups on `pl-groups-on-d11` (standard Drupal 11, no distribution).

---

## Where to start

**[RUNBOOK.md](RUNBOOK.md)** is the primary deliverable and your single entry point. It is a fully self-contained, copy-paste-ready build log covering every phase of the installation — from enabling modules to importing config to verifying the result. Start there.

> [!IMPORTANT]
> Every command in RUNBOOK.md must be run exactly as written. If you discover a deviation, update the runbook immediately before moving on.

---

## Supporting documents

| File | Purpose |
|---|---|
| [ARCHITECTURE_DIFFERENCES.md](ARCHITECTURE_DIFFERENCES.md) | Side-by-side comparison of the Open Social implementation vs. this standard Drupal implementation — explains *why* things are done differently here |
| [GROUPS_CONVERSION_PLAN.md](GROUPS_CONVERSION_PLAN.md) | High-level plan for porting groups features from Open Social to standard Drupal — what was reused, adapted, and rebuilt |
| [TESTING_STRATEGY.md](TESTING_STRATEGY.md) | Which testing tools to use and when (Kernel tests, PHPUnit, manual verification) |
| [VIEWS_AND_ENDPOINTS.md](VIEWS_AND_ENDPOINTS.md) | Quick reference for all Views, public URLs, feeds, and admin pages in the groups implementation |

---

## Supporting assets

| Directory | Contents |
|---|---|
| `config/` | Drupal YAML config files — group types, roles, flags, views, taxonomies, field storage. Imported during the runbook build. |
| `modules/` | Custom `do_` modules — complete source code. Copy each into `web/modules/custom/` before enabling. |
| `scripts/` | PHP scripts run via `ddev drush php:script` — one per runbook step, creates entities programmatically. |

---

## Key conventions

- All configuration is managed as YAML, never through the Drupal UI
- Custom modules follow the **Services over Hooks** pattern — see [`ai_guidance/drupal/BEST_PRACTICES.md`](../../ai_guidance/drupal/BEST_PRACTICES.md)
- If something hangs or behaves unexpectedly, consult [`ai_guidance/TROUBLESHOOTING.md`](../../ai_guidance/TROUBLESHOOTING.md)
- Before committing documentation changes, run through the checklist in [`ai_guidance/technical_writing/documentation_guidance.md`](../../ai_guidance/technical_writing/documentation_guidance.md)
