# Handoff-T-green: Phase 6 - #141 MC-2 About section

**Date:** 2026-07-23
**Branch:** 141-about
**Issue:** #141
**Handoff-F reviewed:** `docs/planning/handoffs/141-about/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/141-about/handoff-T-red.md`

## GREEN confirmation

**Kernel — `GroupAboutFieldTest.php` (targeted) + full `do_group_extras` kernel suite (regression), re-run independently on a freshly assembled worktree:**

```
cd ~/Projects/_worktrees/groups-about
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_group_extras/tests/src/Kernel --testdox'
```

```
....DDDD.......D....DDD.D..                                       27 / 27 (100%)

Time: 02:45.009, Memory: 10.00 MB

Group About Field (Drupal\Tests\do_group_extras\Kernel\GroupAboutField)
 ✔ Storage exists
 ✔ Instance exists
 ✔ Full display shows field
 ✔ Form display shows field
 ⚠ Renders formatted body
 ⚠ Empty state renders nothing when field never set
 ⚠ Empty state renders nothing when value explicitly empty
 ⚠ Library attached only when about non empty

[... GroupExtrasBehaviorTest, GroupLinksFieldTest, GroupRestoreTest — same as F's baseline ...]

OK, but there were issues!
Tests: 27, Assertions: 809, Deprecations: 4.
```

Independently confirmed **identical** to F's self-reported numbers (`Tests: 27, Assertions: 809, Deprecations: 4`, 0 failures). Re-ran a second time after a full site reinstall to rule out any assemble-drift; result was stable both times.

**Spot-check — test still fails if behavior is removed:** confirmed at RED time (T-red handoff) that `testLibraryAttachedOnlyWhenAboutNonEmpty` failed with "Failed asserting that an array contains 'do_group_extras/group-about'" before F's `preprocessGroup()` extension existed, and now passes against the shipped code — this is a real behavior-pinning test, not a vacuous one. The other 7 tests pin config/render shape directly against `FieldStorageConfig`/`FieldConfig`/`EntityViewDisplay`/`EntityFormDisplay` API calls and rendered HTML content, which cannot pass without the actual shipped YAML + preprocess code (proven at RED: these files did not exist in `docs/groups/config/` before F's commit).

## Seed sequence used

Per the canonical CI sequence in `.github/workflows/test.yml` (`e2e` job), adapted to `ddev exec drush` in place of GH Actions' bare `php vendor/drush/drush/drush.php`:

```
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'drush site:install standard --account-name=admin --account-pass=admin --site-name="Groups on D11" -y'
SRC_UUID=$(grep '^uuid:' config/sync/system.site.yml | awk '{print $2}')
ddev exec "drush config:set system.site uuid $SRC_UUID -y"
# appended `$settings['config_sync_directory'] = '../config/sync';` to
# web/sites/default/settings.php (DDEV's own settings.ddev.php defaults to
# sites/default/files/sync, which does NOT hold the assembled config)
ddev exec 'drush config:import -y'
ddev exec 'drush en -y do_tests do_group_extras do_group_language do_group_mission do_group_pin do_multigroup do_notifications do_profile_stats do_discovery'
ddev exec "drush user:password admin 'admin'"
ddev exec 'drush cache:rebuild -y'

# Demo seed, in CI's order, each run as uid 1 (admin) per the entrypoint's own wrapper:
ddev exec 'drush php:script /tmp/seed/seed-demo.php'     # wraps step_700_demo_data.php
ddev exec 'drush php:script /tmp/seed/seed-types.php'    # wraps step_720_group_types.php
ddev exec 'drush php:script /tmp/seed/seed-persona.php'  # wraps step_790_persona_switcher.php
ddev exec 'drush cache:rebuild -y'
```

(step_780 nav-menu seeding runs inline from within `step_700_demo_data.php`'s own Step 780 block — confirmed in its output, no separate invocation needed.)

**Verified About seed data directly via `drush php:eval`** (querying `field_group_about` on every group):

```
DrupalCon Portland 2026: <p>DrupalCon Portland 2026 is the flagsh...
Drupal France: (empty)
Core Committers: <p>Core Committers is the working group ...
Thunder Distribution: <p>Thunder is a publishing-focused Drupa...
Leadership Council: (empty)
Camp Organizers EMEA: (empty)
Drupal Deutschland: (empty)
Legacy Infrastructure: (empty)
```

3 of 8 seeded groups carry About prose, 5 do not — matches F's handoff claim exactly.

**Rendered-HTML confirmation (`curl`), group/1 = DrupalCon Portland 2026:**

```html
<div class="text-content clearfix field field--name-field-group-about field--type-text-long field--label-above">
  <div class="field__label">About</div>
  <div class="field__item"><p>DrupalCon Portland 2026 is the flagship North American gathering...
  <strong>This group coordinates the planning committee's work</strong> — ...</p></div>
</div>
```

group/2 = Drupal France (no About seeded): `field--name-field-group-about` wrapper and the string "About" (as a field label) are both **absent** from the rendered page — confirmed via `grep`. (An unrelated "About" **tab link** — `gc-group-tabs__link`, part of the group's nav — is present on every group page regardless of About content; this is the pre-existing tab-navigation UI, distinct from the About field section, see below.)

## E2E result

**Genuine RED-time-spec bug found and fixed (T-owned).** The RED-authored spec asserted `page.getByRole('heading', { name: /^About$/i })`. Verified against the real rendered markup that Drupal's default `field--label-above` template renders the field label as a plain `<div class="field__label">About</div>` — **not an accessible heading element** — exactly mirroring the sibling `field_group_links` field, whose own E2E spec (`group-links.spec.ts`) correctly asserts via `getByText(...)`, not `getByRole('heading', ...)`. Additionally, the group page has an unrelated "About" **tab link** in the group nav (`gc-group-tabs__link`, present on every group page unconditionally) that a bare unscoped `getByText('About')` would have collided with.

This is a test-authorship bug, not a production bug: F's shipped markup is correct, idiomatic Drupal field-template output, identical in shape to the already-merged #140 Links & Resources field. **I fixed the spec** (I own tests) to assert presence/absence via the `.field--name-field-group-about` wrapper directly (the same idiom `group-links.spec.ts` uses for its own field), then assert the field's own label text and non-empty body text inside that scoped wrapper. Diff: `tests/e2e/group-about.spec.ts`, +39/-23 lines, no production file touched.

**Run command (after fix), against the fully seeded site:**

```
BASE_URL="https://gm141-about.ddev.site" npx playwright test tests/e2e/group-about.spec.ts --reporter=list
```

```
Running 2 tests using 1 worker

  ok 1 [chromium] › tests\e2e\group-about.spec.ts:82:7 › Group About section (#141 MC-2) › at least one seeded group shows an About section with non-empty prose (2.9s)
  ok 2 [chromium] › tests\e2e\group-about.spec.ts:120:7 › Group About section (#141 MC-2) › at least one seeded group with no About prose shows no About section (2.1s)

  2 passed (5.8s)
```

**Sibling regression — `group-links.spec.ts` (shared display-file edit, same-file collision surface per F's handoff):**

```
BASE_URL="https://gm141-about.ddev.site" npx playwright test tests/e2e/group-links.spec.ts --reporter=list
```

```
  ok 1 [chromium] › tests\e2e\group-links.spec.ts:43:7 › ... anonymous sees a Links & Resources section with a known seeded link (634ms)
  ok 2 [chromium] › tests\e2e\group-links.spec.ts:76:7 › ... every seeded external link carries rel="noopener" (572ms)

  2 passed (2.2s)
```

**Full E2E suite (first pass, one worker, 71 tests):** 70 passed, 1 skipped (pre-existing, unrelated — an axe-core-unavailable documented gap in `group-type-homepage.spec.ts`), 0 failed. `group-about.spec.ts` and `group-links.spec.ts` both green in this run.

**Non-blocking environment note (not a #141 defect):** re-running the full suite a second time back-to-back against the same DB produced 2 unrelated failures in `membership-models.spec.ts` (#121) — traced to that spec's own cross-run state assumption ("sophie_mueller joins Drupal France instantly" expects a Join button that no longer exists once she already joined in the prior run). Confirmed via `drush php:eval` that sophie was already a group member from the first run. This is pre-existing test-fixture statefurness in a sibling story's spec (not touched by #141) that only surfaces under repeated full-suite runs against a non-reset DB — CI's fresh-DB-per-run model does not hit it. Not routed to F; flagging for whichever story next touches `membership-models.spec.ts` state hygiene. Did **not** affect `group-about.spec.ts`, which I additionally re-verified in isolation on both a polluted-DB state (still green) and a freshly reinstalled clean DB (still green).

**Data-hygiene note (my own dev-loop artifact, not a defect):** during verification I ran the full E2E suite twice, which (as designed) creates disposable fixture groups per test; a subsequent `/all-groups` directory check landed on a paginated page because of the accumulated fixture data. I resolved this via a full fresh `site:install` + reseed rather than partial SQL cleanup (a first attempt at raw `DELETE FROM groups WHERE id > 8` left orphaned rows in `groups_field_data`/`groups_revision`, since Group entities span many field tables — entity-API-only deletion or a fresh install are the safe paths). Final state: clean 8-group baseline, re-verified both kernel and the two E2E specs above.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `bash scripts/ci/assemble-config.sh` | clean copy, no errors | `==> assemble-config: done` | PASS |
| Kernel (targeted) | `GroupAboutFieldTest.php` via phpunit | 8/8 green | 8/8, 192 assertions, 2 deprecations (pre-existing Twig notice) | PASS |
| Kernel (full module regression) | `do_group_extras/tests/src/Kernel` via phpunit | 27/27 green | 27/27, 809 assertions, 4 deprecations (pre-existing) | PASS |
| Site install + config:import | drush site:install + config:import | clean import, no errors | `[success] The configuration was imported successfully.` | PASS |
| Demo seed | step_700/720/790 php:script | idempotent, no errors, About set on 3/8 groups | confirmed via drush eval | PASS |
| API smoke (group page renders) | `curl` group/1, group/2 | 200, About present/absent correctly | confirmed | PASS |
| E2E — target spec | `playwright test group-about.spec.ts` | 2/2 green | 2/2 green (after T's own spec fix) | PASS |
| E2E — sibling regression | `playwright test group-links.spec.ts` | 2/2 green | 2/2 green | PASS |
| E2E — full suite (single pass) | `playwright test tests/e2e/` | all green except 1 known skip | 70 passed / 1 skipped / 0 failed | PASS |

## Tier 2 results

- **Test coverage:** every AC-1..AC-7 backed by a named test (see table below). AC-8 is U's; AC-9 verified above; AC-10 verified below.
- **Test quality:** all 8 kernel tests + 2 E2E tests each name a single behavior, fail in isolation for the right reason (confirmed at RED for the kernel suite; confirmed for E2E by the getByRole→wrapper-scoped fix actually changing pass/fail outcome), sit at the cheapest sufficient tier (kernel for config/render/empty-state/library-attach; E2E only for the cross-cutting seeded-visibility contract), and assert behavior (rendered HTML / attached library / component config) not implementation. No redundant tests found — the optional library-attach kernel test (A warn #6) is deliberately narrower than and complementary to the E2E spec (kernel proves the attach *mechanism*, E2E proves the attach's *visible effect*), not a duplicate.
- **Type safety:** N/A — no TypeScript production code touched; `group-about.spec.ts` is plain Playwright TS with no `any` casts.
- **Error handling / data integrity:** empty-state both shapes (never-set, explicit empty value/format tuple) verified GREEN at kernel tier; both shapes render nothing, no partial/broken markup.
- **API contract:** view-display component (`text_default`/`label:above`/`weight:10`) and form-display component (`text_textarea`) verified against the real assembled+imported config, not just the kernel fixture's programmatic stand-in — `curl` output above shows the exact same shape live.
- **Security:** `allowed_formats: {}` on the field instance (no format restriction beyond what filter permissions already gate); `basic_html` sanitization verified via kernel test asserting `<strong>` survives while (implicitly, via the filter's `allowed_html`) unlisted tags would be stripped — matches the sibling field's established pattern, no new attack surface.
- **Migration safety:** N/A — new field addition via config only, no schema migration, no existing data affected.
- **Playwright suite:** ran to completion, exits 0 on both targeted specs and the full single-pass run (70/71, 1 pre-existing skip). No coverage hole identified for the About surface — both presence and absence states are exercised live.

## Acceptance criteria status

| AC | Description | Status | Backing test |
|---|---|---|---|
| AC-1 | storage `text_long`, cardinality 1, translatable | PASS | `testStorageExists` |
| AC-2 | instance on `community_group`, label "About", not required, translatable | PASS | `testInstanceExists` |
| AC-3 | Full display: weight 10, `label: above`, `text_default` | PASS | `testFullDisplayShowsField` (kernel) + live `curl` confirmation |
| AC-4 | form display: `text_textarea`, non-hidden | PASS | `testFormDisplayShowsField` |
| AC-5 | formatted body renders sanitized HTML | PASS | `testRendersFormattedBody` (kernel) + live `curl` confirmation (`<strong>` present in group/1's rendered markup) |
| AC-6 | empty state, both shapes, no label/wrapper | PASS | `testEmptyStateRendersNothingWhenFieldNeverSet` / `...WhenValueExplicitlyEmpty` (kernel) + live E2E negative case (group/2, no wrapper/label rendered) |
| AC-7 | E2e: anonymous sees About + prose on seeded group | PASS | `group-about.spec.ts` test 1 (fixed selector; see "E2E result" above for the fix rationale) |
| AC-8 | WCAG 2.2 AA walkthrough | **N/A for T** — U's job, not exercised here |
| AC-9 | no regression across existing suites | PASS | full kernel (27/27) + full E2E single-pass (70/71, 1 pre-existing skip) |
| AC-10 | source-only commits, no `web/modules/custom/`/`config/sync/` staged | PASS | `git status --short` confirms only `docs/`/`tests/e2e/` changes tracked; `web/modules/custom/` and `config/sync/` remain untracked build artifacts (assemble/install output), never staged by F or T |

**Advisory on AC-7's literal wording:** the brief's AC-7 text says "About **heading**" — the live rendered markup uses a plain `<div class="field__label">`, not a heading element, matching the established sibling convention (`field_group_links`'s own label is likewise a `div`, not a heading, and its own merged E2E spec asserts via `getByText`, not `getByRole('heading', ...)`). This is consistent site-wide convention, not a defect; flagging so S doesn't mistake the brief's shorthand wording for a literal unmet heading-semantics requirement. If a true `<h2>` is desired for AC-8's heading-structure requirement, that is a **U/S-level finding to raise**, not something T's kernel/E2E tests should invent an assertion for absent a explicit accessibility requirement pinned in the brief beyond AC-8's general WCAG walkthrough.

## Blocking issues

None.

## Advisory notes

- The RED-time E2E spec's `getByRole('heading', ...)` assumption was reasonable given no wireframe existed for this story (D was skipped) and no prior art was double-checked against rendered markup before RED — for future stories touching field-label-rendered content, spot-checking one sibling field's actual rendered HTML (not just its kernel fixture) during RED authoring would have caught this before F even started, saving a GREEN-phase round-trip. Recorded here for process improvement, not as a blocker.
- Full-suite Playwright runs against a **non-reset** dev DB accumulate fixture groups across runs (each spec that creates its own group via UI leaves it behind) and can eventually push seeded groups off page 1 of `/all-groups`'s 25-item pager, plus expose `membership-models.spec.ts`'s own cross-run state assumption. CI is unaffected (fresh DB per run). No action needed for #141; noting for anyone doing repeated local dev-loop full-suite runs on a shared DDEV instance.
