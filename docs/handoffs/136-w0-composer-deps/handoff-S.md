# Handoff-S: Phase 9 - #136 W0: Dependency pre-story (light audit)

**Date:** 2026-07-22
**Branch:** 136-w0-composer-deps
**Issue:** #136
**Handoff-A reviewed:** docs/handoffs/136-w0-composer-deps/handoff-A.md
**Handoff-F reviewed:** docs/handoffs/136-w0-composer-deps/handoff-F.md
**Operator-facing report:** N/A (non-visual story)

## Audit scope note

Review rigor declared `none`; story is thin/mechanical (manifest-only, no PHP/behavior
change). This is a **light** audit: spec-compliance checks against the issue's acceptance
criteria + basic manifest/lock sanity checks. No UI surface (D and U are N/A). No A-dup
gate to reconcile (per handoff-A's note 2: manifest diffs have no analogous object to
duplicate against; O confirmed this as a no-op for this story).

## A precondition

Confirmed: A returned **PASS** (handoff-A, Phase 3). No `block`/`warn` findings; two
non-blocking notes only (resolver-risk deferral to F, and Phase-7 A-dup no-op), both
already addressed/acknowledged.

## T precondition

N/A for this story's declared shape — no traditional T(red)/T(green) authored-test cycle
applies (no new PHP/behavior to unit-test; brief and A both confirm this substitution is
appropriate for a manifest-only change). F's own verification (real resolver run,
enable-state proxy check, `composer validate`) stands in for T. This is a declared,
A-approved pipeline shape, not a skipped gate — treated as satisfied for precondition
purposes.

## Direct verification performed by S

1. **`composer.json` require-block diff** — read directly. Exactly 5 new entries present,
   correctly alphabetized within the existing `sort-packages: true` block:
   `drupal/geofield: ^10.3`, `drupal/geofield_map: ^11.1`, `drupal/masquerade: ^2.2`,
   `drupal/message: ^1.8`, `drupal/message_notify: ^1.5`. No other structural change
   (no `repositories`/`config`/`extra` edits). Matches survey.md's predicted constraints
   exactly.
2. **`drupal/grequest` absence** — confirmed directly: not present in `composer.json` or
   `composer.lock`. Cross-checked survey.md's evidence trail: grequest's own
   `composer.json` at its latest release (3.2.4) requires `drupal/group: ^3.0`, hard-
   incompatible with this project's `drupal/group: 4.0.x-dev`; Group 4.0.x core's own
   `modules/` tree (checked against git.drupalcode.org) contains no bundled
   membership-request submodule. This is a well-evidenced finding (specific version
   numbers, specific repo paths checked), not a hand-waved assertion.
3. **`composer.lock` sanity** — parsed as JSON and inspected package entries directly:

   | Package | Locked version | Declared requires |
   |---|---|---|
   | drupal/masquerade | 2.2.0 | drupal/core |
   | drupal/message | 1.8.0 | drupal/core |
   | drupal/message_notify | 1.5.0 | drupal/core, drupal/message |
   | drupal/geofield | 10.3.4 | drupal/core, itamair/geophp |
   | drupal/geofield_map | 11.1.9 | drupal/core, drupal/geofield |

   These are real, internally-consistent entries with plausible transitive deps
   (`itamair/geophp` as geofield's PHP geometry library; `message_notify` correctly
   depending on `message`) — not a hand-edited stub. `drupal/grequest` confirmed absent
   from the 98-package lock. This matches F's handoff claim of a real resolver run, not a
   metadata-only edit.
4. **`composer validate`** — run directly by S: clean, only the pre-existing
   `drupal/group` exact-version-constraint warning (present on `main` before this change
   too, per F's handoff and confirmed by the warning text referencing `drupal/group`
   specifically, not any of the 5 new packages).
5. **Diff scope** — `git status --short` / `git diff --stat` confirmed directly: only
   `composer.json` (+5/-0 net lines) and `composer.lock` modified. No `.info.yml`, no
   `config/sync/*.yml`, no other files touched. The single untracked entry (`docs/handoffs/`)
   predates this phase and is unrelated.
5. **No module enabled** — grepped `config/sync/core.extension.yml` directly for all 5
   new package names: zero matches. Combined with the diff-scope check (no config file
   touched at all), this independently confirms F's enable-state-unchanged claim rather
   than just trusting the handoff's proxy reasoning.
6. **Verification notes in survey.md** — confirmed present and evidence-based for all
   three required findings:
   - masquerade D11-compat (#120): cites the specific release (`8.x-2.2`,
     `core_compatibility: ^10.3 || ^11.0 || ^12.0`) and notes the legacy branch-naming
     quirk. Adequately evidenced.
   - grequest/Group-4.x incompatibility (#121): cites grequest's own composer constraint
     (`^3.0`) vs. the project's pin, and the absence of a Group-4.x branch/port. Adequately
     evidenced — this is the strongest-evidenced finding of the three.
   - geofield_map formatter choice (#125): notes a drupal.org project-API query for a
     standalone Leaflet-views package returned zero results, and cites geofield_map's own
     composer.json (`drupal/core: ^10.3 || ^11`, `drupal/geofield: ^1.31 || ^10.3`) as
     clean/uncoupled. Adequately evidenced.

## Test-suite disclosure (F's sandbox limitation)

F's handoff states the DB-backed kernel/functional/e2e suites could not be run locally (no
MySQL/MariaDB, no assembled `web/modules/custom/` in this sandbox) and that CI will be the
actual gate. S's environment has the same limitation (no DB backing this worktree either).
Per the task instructions, this is treated as an honestly-disclosed limitation, not a
defect — F substituted valid proxies (JSON-validity of both manifest files, a clean
`composer install --ignore-platform-req=php` reporting "Nothing to install, update or
remove" against the regenerated lock, i.e. the lock is internally consistent and
reproducible) and stated plainly what CI will additionally verify. No rework required on
this point.

## Spec compliance

| Acceptance criterion | Compliant | Notes |
|---|---|---|
| Exactly 5 new require entries, alphabetically sorted | YES | Verified directly in composer.json |
| `drupal/grequest` NOT added | YES | Verified directly (absent from both files); evidence well-documented in survey.md |
| `composer.lock` regenerated via real resolver | YES | Lock entries are real, plausible, cross-checked; F's handoff explains the `--ignore-platform-req=php` rationale (pre-existing PHP 8.3-vs-8.4 host/target mismatch, reproduced on unmodified `main` via `git stash`, unrelated to the 5 new packages) |
| `composer install`/`validate` clean on fresh checkout | YES | `composer validate` run by S directly: clean, only pre-existing `drupal/group` warning |
| No module enabled (enable-state unchanged) | YES | Verified directly via `core.extension.yml` grep + full diff scope, independent of F's proxy reasoning |
| No behavior change (diff scoped to composer.json/composer.lock only) | YES | `git diff --stat`/`--name-only` confirm |
| Verification notes recorded (#120, #121, #125) | YES | All three present in survey.md with specific, checkable evidence (not hand-waved) |

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Access / security (Drupal) | N/A | No routes/permissions/entities touched; manifest-only |
| Config / schema | PASS | No config touched; correctly out of scope per brief |
| Error handling | N/A | No runtime code |
| UI/UX match to spec | N/A | No UI surface |
| Accessibility | N/A | No UI surface |
| Architecture gate | PASS | A returned PASS with no blocking findings |
| Code organization | PASS | Extends existing require block in place, matches established `flag`/`group` precedent; no stray scaffold-file drift left in the diff (F explicitly reverted composer-scaffold side effects) |
| Docs (Keystatic-editable, links) | N/A | Not a docs-site story |
| Naming consistency | PASS | Package names match drupal.org canonical composer names; constraint style (`^x.y`) matches existing entries |
| Test quality | N/A | No traditional test suite authored for this story (declared, A-approved shape); F's resolver-run + validate + diff-scope checks serve as the verification artifact and were independently re-run by S |

## Scope check

F delivered exactly the phase scope: 5 package additions, no enables, no config, no seed
data, no UI. No over-delivery (no bonus refactors, no scaffold-file drift left behind — F
proactively reverted composer-scaffold's incidental re-touch of `web/.htaccess` etc. to
keep the diff clean) and no under-delivery (all 5 packages present, grequest correctly
excluded with a recorded rationale). One item remains correctly deferred to O per the
brief's own division of labor: posting the #120/#121/#125 verification-note comments and
including them in the PR description is O's Phase-11 responsibility, not F's — the
underlying findings are fully documented in survey.md for O to draw from. This is not a
gap in F's delivery.

## Verdict

**PASS** — all acceptance criteria met, spec-compliant, quality acceptable for a
mechanical manifest-only change under `review rigor: none`. Ready for O to open the MR.

Note for O (not a blocking finding, just a scope reminder): remember to include the three
verification notes (#120 masquerade D11-compat, #121 grequest/Group-4.x incompatibility,
#125 geofield_map formatter choice) in the PR description and post the corresponding
follow-up comment on issue #121, per the issue's own acceptance criteria — the content for
all three is ready-to-use in survey.md.

## Advisory notes

None.
