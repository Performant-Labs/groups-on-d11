# Handoff-F: Phase 5 - #128 SD-3 Archive Demonstrator Seeds

**Date:** 2026-07-23
**Branch:** 128-archive-demo
**Issue:** #128

## What was done

- `docs/groups/scripts/step_700_demo_data.php` (lines 397-400 → 397-401) — removed
  the 4-line "Archive Legacy Infrastructure" block
  (`$g->set("status", 0); $g->save();`) and replaced it with a 5-line comment
  explaining the semantic correction (Archived ≠ Unpublished; the real archive
  semantic is driven by `field_group_type = "Archive"` in
  `step_720_group_types.php`) and pointing at `brief.md`.

This is the ONLY production file changed. No module edits, no config/sync
changes, no HelpText, no CSS — matching the brief's Files In Scope exactly.

## Design decisions

- **Comment wording**: kept close to the brief's suggested text verbatim, with
  one small edit — used `--` instead of an em dash (`—`) inside the PHP comment
  to avoid any encoding ambiguity in a `.php` file that's otherwise
  plain-ASCII, and to match the file's existing comment style (`// Follow user`,
  `// RSVP for events`, etc. — short, plain-ASCII, no smart punctuation
  elsewhere in this file). No semantic difference from the brief's suggested
  wording.
- **No other line touched**: verified via `grep -n 'set("status", 0)'` and
  `grep -n 'Legacy Infrastructure'` across the file before editing that lines
  397-400 were the *only* status mutation and the *only* other Legacy
  Infrastructure reference is the group's creation entry at line 75 (unaffected,
  `status => 1` default at line 83, guarded from re-mutation by the
  `loadByProperties`-then-`continue` idempotency check at lines 78-79). This
  confirms AC-4 (idempotency) is preserved: removing the deletion's *only*
  mutation makes re-runs strictly safer, not less idempotent.
- **Patch method**: applied via a small verified Python script (read exact
  lines 397-400, `assert` they matched the expected literal text byte-for-byte,
  then replace) rather than a manual string edit, specifically to guarantee zero
  risk of an off-by-one line match or stray whitespace drift in a file with no
  other tooling (phpcs is not configured for `docs/groups/scripts/`, so a
  malformed edit would only surface at runtime). The assertion would have
  failed loudly (and did, on my first attempt, due to a shell-escaping mistake
  in my own verification string, not the file) rather than silently patch the
  wrong lines.

## Reuse / extend-vs-new

Pure extension — no new object of any kind. The brief's Reuse map (survey.md
§"Reuse & Analogous-Feature Map") names every mechanism this story depends on
as already-shipped and REUSE-as-is: `field_group_type` Archive tagging
(`step_720_group_types.php:101`), `DoGroupExtrasHooks::{preprocessGroup,
nodeAccess}`, `ArchivePinHooks`/`do_chrome` badge+tooltip rendering, #143's
restore mechanism, and `do_group_pin`'s pin badge. #128's only change is a
*deletion* — removing a redundant, semantically-wrong mutation from the seed
script — not an addition of any new code path. There is no parallel path to
check for; the brief explicitly forbids one (Non-Goals: "No new HelpText, ...
no new module, ... no visual/CSS changes").

## Architecture notes for A

None. Zero layers touched beyond the demo-data seed script itself (a
standalone `drush php:script` file with no service/class/hook of its own — it
calls existing entity APIs directly). No new dependency, no schema change, no
shared component touched. This is as close to a pure data-fixture edit as this
codebase has.

## Deviations from spec / wireframe

None. The diff matches the brief's prescribed change exactly (`git diff --stat`:
1 file, 5 insertions(+), 4 deletions(-)).

## Tier 1 self-check (incl. tests now GREEN)

**Environment repair required before verification** (see "Surprises" below for
full diagnosis): the worktree's `web/modules/custom/` and
`web/autoload_runtime.php` had been cleaned since T's last pass (correct
git hygiene per T's own handoff — these are gitignored-equivalent build
artifacts), which left the live `gm128-archive-demo.ddev.site` container
unable to serve any page (PHP fatal: `autoload_runtime.php` missing). Restored
via `ddev exec bash scripts/ci/assemble-config.sh` (regenerates
`web/modules/custom/`, 13 modules, matches T's original count) and
`ddev composer install` (regenerates the Symfony-runtime scaffold file). No
git-tracked source file was touched by this repair; both are build artifacts
per the project override and were not staged.

**Seed re-apply** (per task instructions, simulating what a fresh seed run
does post-fix): `field_group_type` was reset to the "Archive" term (matching
what `step_720_group_types.php` already tags) and `status` flipped to
published via `drush php:eval`, then `drush cache:rebuild`. (This additionally
served as the reset step needed between Playwright runs, since
`group-restore.spec.ts`'s own round-trip test mutates and then correctly
restores gid=8's `field_group_type` across its 5 steps.)

**Full suite run** (exact command from the task):

```
BASE_URL="http://gm128-archive-demo.ddev.site" npx playwright test \
  tests/e2e/demonstrator-seeds.spec.ts tests/e2e/group-restore.spec.ts tests/e2e/directory-cards.spec.ts \
  --reporter=list
```

Final clean result — **8 passed (26.4s)**:

```
ok 1  AC-1a: anonymous sees Legacy Infrastructure on /all-groups, tagged with the Archive type (1.2s)
ok 2  AC-1b: clicking the Legacy Infrastructure card lands on the group page showing the Archived state (badge + tooltip) (1.6s)
ok 3  AC-1c: content-create is denied on the archived group but allowed on a non-archived control group for the same non-Organizer user (7.8s)
ok 4  AC-2 (regression guard): anonymous sees the Pinned badge + tooltip on the pinned post's canonical page (2.6s)
ok 5  directory-cards.spec.ts: anonymous sees cards with type + visibility badges and member counts (386ms)
ok 6  directory-cards.spec.ts: anonymous gets a "View group" affordance, never a Join button (409ms)
ok 7  directory-cards.spec.ts: a logged-in member sees the "Member" note on groups they belong to (3.3s)
ok 8  group-restore.spec.ts: archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group (7.9s)

8 passed (26.4s)
```

All 4 new `demonstrator-seeds.spec.ts` tests pass (AC-1a, AC-1b, AC-1c, AC-2),
the edited `group-restore.spec.ts` round-trip passes, and all 3
`directory-cards.spec.ts` tests (doc-comment-only edit) pass — exactly the
"all 8 passed" pattern T-RED's cross-check predicted.

**AC-3 static check** (per the brief, diff-verifiable, not a Playwright
assertion):

```
$ grep -n 'set("status", 0)' docs/groups/scripts/step_700_demo_data.php
(no output, exit code 1 — no match)
```

**Final gid=8 state** (post-suite, confirms the round-trip test left it
correctly re-archived):

```
$ ddev drush php:eval '...'
Final gid=8 state: type=Archive published=yes
```

## Surprises

1. **A cold-cache first-hit timeout, diagnosed and resolved without touching
   code or tests.** The FIRST full-suite run showed 7/8 green with
   `group-restore.spec.ts` failing: `page.goto('/group/8/edit')` at Step 4 hit
   `net::ERR_ABORTED` right at the 30s Playwright test-level timeout
   (`playwright.config.ts`: `timeout: 30_000`). Per gotcha #5 ("env-blocked /
   core bug usually masks a test-authorship bug — diagnose, don't
   markTestSkipped"), I did not accept this as flake or file it against the
   test. Extracted and read the Playwright trace's `before`/`after`
   `startTime`/`endTime` pairs: the test's OWN first navigation
   (`goto('/all-groups')` inside `findLegacyInfrastructureGid()`, which uses
   Playwright's default `waitUntil: 'load'`, i.e. wait for every asset) took
   **14,590ms by itself** — nearly half the entire test's 30s budget — leaving
   only ~600ms remaining by the time Step 4's `goto` fired, so the *test-level*
   timeout aborted an in-flight (not hung) request. `curl` timing on the exact
   same route confirmed the mechanism: 9.3s on the first hit right after my
   `cache:rebuild`, then 54-62ms on the next two hits (classic Drupal
   Twig/render-cache/asset-aggregate cold-cache recompilation cost). Re-ran the
   identical 8-test command a second time with no code/test change and the
   cache now warm: **8/8 passed in 26.4s**, with the previously-timing-out test
   alone completing in 7.9s. This is an artifact of my own verification
   sequence (assemble → composer install → cache:rebuild → immediately
   Playwright) colliding with a fixed 30s global timeout and one navigation's
   `waitUntil: 'load'` default — #128's 4-line diff touches no caching,
   rendering, or asset-delivery code path, so there's no plausible mechanism by
   which this story caused it. Full diagnostic trail (trace excerpts, curl
   timings, server logs showing no server-side error at the failure timestamp)
   is in `decisions.md`'s Phase 5 entry for the record. No test or production
   code was changed in response to this — it was purely an environment-state
   artifact of running Playwright immediately after a cache-clearing sequence,
   and does not reproduce on a warm cache.
2. **Two build-artifact files needed regeneration before I could verify at
   all**: `web/modules/custom/` (13 do_* modules) and `web/autoload_runtime.php`
   had been removed from the worktree since T's last pass — correctly, per
   T's own "revert build artifacts before commit" hygiene (these are
   gitignored-equivalent, regenerated-by-`assemble-config.sh` artifacts per the
   project override) — but this left the live DDEV container unable to serve
   any page at all (PHP fatal error) until I re-ran
   `bash scripts/ci/assemble-config.sh` (via `ddev exec`) and
   `ddev composer install`. Neither is a git-tracked source change; neither was
   staged. Worth noting for O/T-green: the live container will need this same
   repair again if it's stopped/restarted or if these artifacts get cleaned
   again before T-green's own verification pass.
3. Confirmed T-RED's "Surprises for F" finding #4 exactly: the one-line seed
   fix (removing the 4-line block) was sufficient ALONE — no other seed,
   config, or module change was needed for any of the 8 tests to go GREEN.

## Tests that look wrong (for T)

None. All three test files (`demonstrator-seeds.spec.ts`,
`group-restore.spec.ts`, `directory-cards.spec.ts`) are exactly as T-RED left
them and all pass as authored. No edit made to any test file.

## Known issues

None relative to the acceptance criteria. One pre-existing, explicitly
out-of-scope gap already flagged by T (not introduced or touched by #128, and
excluded from Files In Scope by the brief's non-goals): the `.gc-directory-card`
component on `/all-groups` renders a plain `.gc-directory-card__type` "Archive"
taxonomy label but not the full `span.group__archived-badge` + tooltip markup
(that only renders on the group's own canonical page via
`hook_preprocess_group`, which the Views-fields row render never invokes).
Worth a follow-up ticket per T's note; no action taken here per the brief's
"no theme/template changes" non-goal.

## Files changed

- `docs/groups/scripts/step_700_demo_data.php` (5 insertions, 4 deletions —
  the sole sanctioned edit)
