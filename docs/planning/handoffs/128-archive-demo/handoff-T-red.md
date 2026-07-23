# Handoff-T-red: Phase 4 - #128 SD-3 Archive Demonstrator Seeds

**Date:** 2026-07-23
**Branch:** 128-archive-demo
**Brief / wireframe reviewed:** `docs/planning/handoffs/128-archive-demo/brief.md`,
`docs/planning/handoffs/128-archive-demo/survey.md`,
`docs/planning/handoffs/128-archive-demo/handoff-A.md` (D skipped, no wireframe — no new UI).

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`). No blocking findings;
one `info` (AC-1c enforcement path deferred to T-RED, as planned) and one `warn`
(RUNBOOK.md doc drift, out of #128's scope, belongs to #133). T proceeded.

## Environment stood up

No existing seeded `gm128-*` site was available, so one was built from scratch in this
worktree, namespaced `gm128-archive-demo` (DDEV project rename in `.ddev/config.yaml`,
**reverted** before commit — build/env state, not a task change):

1. `ddev start` (fresh container, renamed to `gm128-archive-demo`).
2. `ddev composer install` (vendor/autoload.php did not exist yet).
3. `bash scripts/ci/assemble-config.sh` run **inside** the ddev container (`ddev exec`) so
   the script's PHP core.extension patch step has a working `php`/`vendor/autoload.php`.
4. `drush site:install standard` (matching `.github/workflows/test.yml`'s exact recipe —
   the assembled config's `system.site` profile is `standard`, not `minimal`; a `minimal`
   install caused a UUID+profile mismatch on `config:import`).
5. Set `system.site` uuid to the assembled config's uuid; appended
   `$settings['config_sync_directory'] = '../config/sync';` to `web/sites/default/settings.php`
   (drush's out-of-the-box sync dir is a hashed empty dir under `sites/default/files`, which
   made the import a no-op / "would delete everything" until this was set explicitly).
6. `drush config:import -y` — succeeded (95 assembled config files imported).
7. `drush en` the custom `do_*` modules (already enabled via the config import), reset admin
   password, `cache:rebuild`.
8. Ran the full demo seed in the CI's exact order/wrapper (uid=1 impersonation):
   `step_700_demo_data.php` -> `step_720_group_types.php` -> `step_780_nav_menu.php` ->
   `step_790_persona_switcher.php`.

All build artifacts this produced (`config/sync/*` new/modified files, `web/modules/custom/`,
`web/autoload_runtime.php`, the `.ddev/config.yaml` rename) were **reverted/removed before
commit** per gotcha #2 (edit only `docs/groups/` source; `web/modules/custom` and
`config/sync` are gitignored-equivalent build artifacts in spirit, tracked-but-regenerated
files that must not ship in a feature commit). The live DDEV container
(`gm128-archive-demo.ddev.site`) remains running for F/T-green to reuse.

## Tests authored/edited

1. **`tests/e2e/demonstrator-seeds.spec.ts` (NEW).** Anonymous-persona spec, 4 tests:
   - **AC-1a** (unit-equivalent: single anonymous page load + DOM assertion; e2e tier because
     it depends on the real seeded site + real Views rendering) — asserts the
     `.gc-directory-card` for "Legacy Infrastructure" is visible on `/all-groups` and carries
     a `.gc-directory-card__type` badge reading exactly "Archive".
   - **AC-1b** (e2e; requires real navigation + real `hook_preprocess_group` render) —
     clicking the card lands on `/group/{gid}`, asserts `span.group__archived-badge` visible,
     containing "Archived" text, with a truthy `data-do-tooltip` attribute.
   - **AC-1c** (e2e; requires real access-control routing + a real authenticated session) —
     authenticates as `elena_garcia` (seeded non-Organizer, member of Core Committers but not
     Legacy Infrastructure), asserts `/group/{archived-gid}/content/create/group_node%3Aforum`
     returns 403, and the SAME route on `/group/{control-gid=Core Committers}/...` returns 200
     for the SAME user — the differential proves the denial is archive-driven.
   - **AC-2** (e2e; real anonymous session + real flag-driven render) — asserts `/node/1`
     ("Sprint Planning: Portland 2026") shows `span.pin-badge` with "Pinned" text and a
     truthy `data-do-tooltip`. This is a **regression guard**, not a RED-driver (already
     passes on the current seed; nothing in #128 touches the pin flag or DrupalCon Portland's
     visibility).
   - AC-3 (diff-verifiable, not a Playwright assertion) and AC-4 (idempotency, out of e2e
     scope) are explicitly documented as skipped-by-design in the spec's file header, per the
     brief's own guidance — not silently dropped.

2. **`tests/e2e/group-restore.spec.ts` (EDITED).**
   - `findLegacyInfrastructureGid()` simplified from `/admin/group` (the #143 T-green
     workaround) back to `/all-groups`, and the lookup now happens **before** login
     (anonymous surface), matching #128's AC-1 path exactly. Login is kept for Step 4's
     edit-form path only.
   - The stale T-green comment block (old lines 44-59) rewritten to explain the corrected
     semantic (Archive != Unpublished; the group is visible on `/all-groups` once #128 ships).
   - Added a Step 1b comment block (replacing the old lines 86-92 "#143 AC-8" rationale, which
     is now factually superseded — T-RED's empirical probe found the content-create route IS
     archive-gated in this build) pointing to `demonstrator-seeds.spec.ts`'s AC-1c test as the
     authoritative positive-enforcement proof, since this admin-persona spec's own user has the
     `administer group` bypass and cannot exercise the enforced (non-bypassed) path itself.

3. **`tests/e2e/directory-cards.spec.ts` (EDITED, doc comment only).** Line 15-16 updated from
   "8 groups, one archived, so at least the 7 published groups render" to "8 groups; all 8
   published, one is Archive-typed -> all 8 cards render". The `cardCount > 0` assertion is
   unchanged, as instructed.

## RED confirmation

Run command (against the seeded `gm128-archive-demo.ddev.site`, current/pre-fix state —
Legacy Infrastructure `status=0`):

```
BASE_URL="http://gm128-archive-demo.ddev.site" npx playwright test \
  tests/e2e/demonstrator-seeds.spec.ts tests/e2e/group-restore.spec.ts tests/e2e/directory-cards.spec.ts \
  --reporter=list
```

Result — **4 failed, 4 passed** (exactly the pattern predicted by the task brief):

```
x  AC-1a: anonymous sees Legacy Infrastructure on /all-groups, tagged with the Archive type
   -> Error: element(s) not found — locator('.gc-directory-card__title a', {hasText: /Legacy Infrastructure/i})
      (group is status=0/unpublished, all_groups View filters status=1, so the card
      is absent — the RIGHT reason: the feature this story adds has not shipped yet)

x  AC-1b: clicking the Legacy Infrastructure card lands on the group page ...
   -> Error: element(s) not found — same root cause (card absent -> gid never resolved)

x  AC-1c: content-create is denied on the archived group but allowed on a non-archived control ...
   -> Error: element(s) not found — same root cause (card absent -> archivedGid never resolved)

ok AC-2 (regression guard): anonymous sees the Pinned badge + tooltip ...
   -> PASSES today (pre-existing seed data; #128 does not touch it)

ok directory-cards.spec.ts (3 tests) -> unaffected, doc-comment-only edit

x  group-restore.spec.ts round-trip
   -> Error: element(s) not found — getByRole('link', {name: /Legacy Infrastructure/i})
      on /all-groups (the simplified helper correctly fails until F's seed fix ships;
      this is an EXPECTED, intentional RED on a previously-green spec, not a regression —
      see decisions.md Phase 4 entry)
```

Every failure is the **right kind** of failure: a Playwright locator timeout because the
targeted DOM element genuinely does not exist yet (the group is hidden from `/all-groups`),
not an import error, typo, or setup/auth failure. No test is green before the code exists.

**Cross-check (GREEN-state dry run):** to validate these are true REDs and not
mis-authored assertions, Legacy Infrastructure's `status` was temporarily flipped to `1`
(simulating F's one-line fix) via `drush php:eval`, the same 8 tests were re-run, and
**all 8 passed**. `status` was then reverted to `0` before the final RED capture above, so
the seeded site is left in its current (pre-#128) state for F to modify via the real code
change.

## AC-1c enforcement path chosen (and why)

`/group/{gid}/content/create/group_node%3Aforum`, tested with the authenticated
**non-Organizer** persona `elena_garcia` (seeded password `demo_password_2026`), not
anonymous. Full empirical reasoning is in the spec's file header and in `decisions.md`'s
Phase 4 entry; short version: anonymous gets 403 on this route on EVERY group tested
(archived or not) — a truism, not a signal. Authenticated as elena_garcia, the SAME route
differs by archive-state alone (403 on Legacy Infrastructure/Archive-typed, 200 on Core
Committers/non-archive, same user) — proving `DoGroupExtrasHooks::nodeAccess()` (or an
equivalent check on this route) is real, active, observable enforcement in this build. This
contradicts survey.md's flagged concern that this route bypasses `hook_node_access` — in
practice, in this build, it does not. No fallback to badge-only observability was needed.

## Surprises for F

1. **The `.gc-directory-card` on `/all-groups` does NOT carry `span.group__archived-badge`
   or any `data-do-tooltip`** — only a plain `.gc-directory-card__type` "Archive" taxonomy
   label (no tooltip). This is because the `all_groups` View renders rows via Views fields,
   which never invokes `hook_preprocess_group` (where `ArchivePinHooks`/`DoGroupExtrasHooks`
   attach the real badge+tooltip markup). AC-1a's test was adjusted to assert what actually
   (and correctly) renders on the card; the full badge+tooltip assertion lives on AC-1b (the
   group's own canonical page). **This is a pre-existing gap in the card component, outside
   #128's Files In Scope (brief explicitly forbids theme/template/visual changes) — nothing
   for F to fix in this story**, but worth a follow-up ticket per the note in the spec header
   and decisions.md.
2. **`content/create/group_node%3Aforum` IS archive-gated in this build**, contrary to
   survey.md's flagged concern (which assumed it bypasses `hook_node_access` entirely via
   `_group_relationship_create_any_entity_access`). T empirically found it is NOT bypassed —
   so AC-1c's positive-enforcement assertion is a real, meaningful, non-fallback proof.
3. `drush site:install standard` (not `minimal`) is required to match the assembled config's
   `system.site` profile — a `minimal` install fails `config:import` with a profile-mismatch
   error. Also, `config_sync_directory` must be explicitly set in `settings.php` (drush's
   default sync path does not point at the assembled `config/sync`).
4. Confirmed via `drush php:eval` probing that F's fix (removing the 4-line
   `$g->set("status", 0); $g->save();` block at step_700:397-400) is sufficient and
   sufficient ALONE — no other seed/config change is needed for AC-1a/1b/1c/AC-2 to all pass.

## Ready for F

**Confirmed RED is valid.** F may implement against these tests — the sanctioned single-line
(4-line block) deletion in `docs/groups/scripts/step_700_demo_data.php` (lines 397-400, the
"Archive Legacy Infrastructure" block). No other production change is required or expected.
