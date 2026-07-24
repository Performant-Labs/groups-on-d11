# Park note r2 — #139 MC-4 Multilingual + RTL

**Date:** 2026-07-24
**PR:** #162 (branch `139-multilang-rtl`, HEAD `838ab6f`, kept open)
**Follow-up issue:** #191 — Seed pipeline: step_640 + step_795 language + content-language-settings cascade bugs
**Status:** PARKED. Feature is verified working; blocker is entirely in seed-script infrastructure.

## What happened this session (r2 — attempted close-out)

Rebased onto latest `main` (was 11 commits behind). Rebase itself was clean (one conflict in `.github/workflows/test.yml` — resolved by concatenating both seed-block additions). Force-pushed `1a3877e → 96c175f`.

CI on rebased head returned:
- Kernel: PASS
- Functional (BrowserTestBase): PASS
- E2E (Playwright): **FAIL** — a new integration issue, not the inherited `phase3/phase4` failure the r1 park note documented.

Attempted three cascading hotfixes to step_640. Each fix uncovered the next layer of latent bug (details in #191). After the third fix (`838ab6f`), CI never ran — `main` had advanced 6 more commits during rework, and the PR is now `CONFLICTING` again.

## Fix cascade (all commits kept on the branch, not merged)

| SHA | Fix | Result |
|---|---|---|
| `8d535ab` | Install `language` module if missing before `ConfigurableLanguage::save()` | `moduleExists()` returned TRUE in CI (language was enabled at config:import time) — no-op. Same fatal recurred. |
| `1ba0eab` | Defensively create `und`/`zxx` locked-language entities (mirrors `do_activity_feed` pmu/en workaround in `test.yml:488-497`, targeted) | step_640 completed cleanly. New failure surfaced downstream: `step_795` fatalled in `ContentLanguageSettings::__construct` line 108. |
| `838ab6f` | Use `ContentLanguageSettings::loadByEntityTypeBundle()` + `setThirdPartySetting()` instead of raw config write (missing `target_entity_type_id`/`target_bundle`); also install `content_translation` module | **No CI ran** — PR became CONFLICTING when 6 more commits landed on main. |

## Why parking, not continuing

1. **Story #139 own feature is verified working** (Kernel PASS, Functional PASS, own Playwright tests PASS previously). The debt is entirely in shared seed-script infrastructure that #139 exposed but doesn't own.
2. **Rebase treadmill** — main is advancing faster than one hotfix-and-CI cycle. Each of the three fixes took ~10-15 min for CI to return; main added 6 commits in that window.
3. **Fourth latent layer plausible** — the pattern of "each fix uncovers the next" gives no confidence the third is the last.
4. **Better handled by a dedicated F/T cycle** — see #191. F should audit the whole seed script; T should author a smoke test that reproduces the ACTIVE-`core.extension.yml`-lists-language scenario so this bug-class fails fast in the future.

## Also noted

E2E now emits `ERROR: No comment field on forum nodes` at line 3 of every run since #182 merged (~2026-07-24 11:30 UTC). Not fatal, but new. Documented in #191 for triage; feel free to split out if you'd rather.

## Resume conditions

- #191 lands (or its core diagnoses fold into a broader seed-script hardening story).
- Then: rebase #162 onto latest main, expect the rebase to auto-drop or trivially-conflict with the seed-script fixes since they'll already be on main. Should be straightforward from that point.

## Do NOT

- Do not force-push #162 — the three fix commits are kept intact for reference by whoever picks up #191.
- Do not merge #162 in its current state.
