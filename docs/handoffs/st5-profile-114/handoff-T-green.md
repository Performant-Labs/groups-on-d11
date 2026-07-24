# Handoff-T-green: Phase 6 - ST-5 Profile activity stream on `/user/{uid}`

**Date:** 2026-07-23
**Branch:** 114-profile-activity
**Issue:** #114
**Handoff-F reviewed:** `docs/handoffs/st5-profile-114/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/st5-profile-114/handoff-T-red.md`

## Step 1 — Test repair (per F's flagged test-authorship bug)

`UserActivityViewTest::executeUserActivity()` called `$view->execute([$uid])`.
`ViewExecutable::execute($display_id = NULL)`'s only parameter is a **display id**, not a
contextual-arguments array — confirmed independently by reading
`web/core/modules/views/src/ViewExecutable.php` myself (matches F's diagnosis exactly). Applied
the mechanical, minimal fix F recommended:

```php
$view->setDisplay('block_1');
// ViewExecutable::execute()'s only parameter is a display id, not a
// contextual-arguments array — the arguments must be supplied via
// preExecute()/setArguments() instead.
$view->preExecute([$uid]);
$view->execute();
```

This is the only call site in the file (`executeUserActivity()`, called fresh by each of the 6
test methods). No assertion was touched, weakened, or removed — this is a pure API-call fix.
Diff (`git diff docs/groups/modules/do_streams/tests/src/Kernel/UserActivityViewTest.php`) is 2
lines changed + 3 comment lines added, nothing else.

## GREEN confirmation

**Assemble:**
```
ddev exec 'bash scripts/ci/assemble-config.sh'
==> config: copied 130 file(s), excluded 7 env-specific file(s)
==> modules: copied 14 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

**Kernel — UserActivityViewTest.php, all 6, GREEN:**
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/UserActivityViewTest.php'

User Activity View (Drupal\Tests\do_streams\Kernel\UserActivityView)
 ✔ Published only excludes unpublished node
 ✔ Author scoping returns only profile owners nodes
 ✔ Access scoping excludes private group node for non member
 ✔ Access scoping includes private group node for member
 ✔ Results order newest first
 ✔ Duplicate group relationships yield one row per node

Tests: 6, Assertions: 135, Deprecations: 4, PHPUnit Deprecations: 7.
```
The 4 deprecations are the project-wide `#[RunTestsInSeparateProcesses]` notice (present on every
sibling Kernel test in this module, per F's handoff) — pre-existing, not introduced by this fix.

**Spot-check that the tests still fail if behavior is removed:** each test's core assertion
(`assertContains`/`assertNotContains`/`assertSame`/`assertCount`) depends on the real query result
set returned by the shipped `views.view.user_activity.yml` executed with the correct uid argument.
Confirmed via direct script (`Views::getView('user_activity')` + `preExecute([2])` +
`execute()`) that the view returns exactly 3 rows for Maria (uid 2) with correct nids — the
Kernel suite is exercising the real, production config, not a scaffold, so removing/breaking any
of the view's filters (status, node_access, uid argument) would flip these assertions. This
matches T-RED's original design intent (see `handoff-T-red.md`).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `bash scripts/ci/assemble-config.sh` | clean, no errors | clean | PASS |
| Kernel (story test) | `phpunit .../UserActivityViewTest.php` | 6/6 pass | 6/6 pass | PASS |
| Kernel (full do_streams regression) | `phpunit .../do_streams/tests/src/Kernel` | 31/31 pass, 0 errors | `Tests: 31, Assertions: 936, Deprecations: 24` (0 failures/errors) | PASS |
| Unit (do_chrome HelpText regression) | `phpunit HelpTextTest.php HelpTextPageKeysTest.php` | 16/16 pass | 16/16 pass | PASS |
| Lint — `DoStreamsHooks.php` | `phpcs --standard=Drupal,DrupalPractice` | 0 errors | 0 errors, 4 pre-existing `\Drupal::` warnings (lines F did not touch) | PASS |
| Lint — repaired test file | `phpcs --standard=Drupal,DrupalPractice UserActivityViewTest.php` | no NEW errors from my 2-line fix | 11 errors / 1 warning, all pre-existing (CRLF line-ending, docblock style) outside my diff hunk (lines 168-175); confirmed via `git stash`/`git diff` that these are unrelated to the repair | PASS (no regression from my edit) |
| Syntax | `php -l` | clean | clean (implied by PHPUnit successfully parsing/running the file) | PASS |

## Tier 2 results

- **Test coverage vs acceptance criteria:** all 5 brief.md criteria (a-e) have a dedicated Kernel
  test, all GREEN. See mapping in `decisions.md` T-RED entry — unchanged, still accurate.
- **Test quality:** each of the 6 tests names one behavior, uses a distinct fixture shape (own
  group(s)/users/nodes), and asserts on `$view->result` row membership/order — behavior, not
  implementation. No redundant tests found; the suite is proportionate (1 test per AC + 1 sanity
  companion for the two-sided access-scoping AC, which is necessary to rule out a vacuous pass).
  No deletions/merges needed.
- **AA contrast / muted-text token reuse:** confirmed via grep that
  `docs/groups/modules/do_streams/css/profile-activity.css` introduces **zero** new colors —
  `--gc-color-text-muted` appears only inside a code comment explaining the reuse, never
  redeclared or overridden. No new muted shade introduced. PASS.
- **HelpText append-only:** confirmed via `git diff` that the `do_chrome/src/HelpText.php` change
  is a pure append at the end of the `all()` array (after the existing `showcase_help.*` block) —
  zero existing keys edited, reordered, or removed. PASS.
- **Type safety / error handling / data integrity / API contract / security / migration safety:**
  no new PHP logic beyond the additive, guarded `preprocessBlock()` hook and the append-only
  HelpText entry (both self-checked clean by F, re-verified here via lint + regression suites);
  no schema/migration changes; config schema strictly validated by `KernelTestBase`'s
  `$strictConfigSchema = TRUE` across every Kernel run (never threw). PASS.
- **Playwright E2E — structural run:** see below. The suite runs (collects, executes, produces a
  report) but **does not exit 0** — 2/2 tests in `profile-activity.spec.ts` FAIL. This is a new,
  genuine functional blocker (see next section), not a Playwright-tooling problem.

## E2E verification (against a fully assembled + seeded site)

Spun up a complete environment rather than deferring to CI, since the ddev container was already
running:
1. `ddev exec 'bash scripts/ci/assemble-config.sh'`
2. `ddev exec 'php vendor/drush/drush/drush.php site:install standard --account-name=admin --account-pass=admin -y'`
3. Set `system.site` UUID to match assembled config; appended `config_sync_directory` to
   `settings.php`.
4. `ddev exec 'php vendor/drush/drush/drush.php config:import -y'` — imported cleanly, including
   `views.view.user_activity` and `block.block.do_streams_user_activity`.
5. Confirmed all custom modules (incl. `do_streams`, `do_chrome`) already enabled via the import.
6. Seeded demo data: `docs/groups/scripts/step_700_demo_data.php` (which internally also runs
   steps 720/735/736/737/780/790/7xx) as uid 1. Confirmed `maria_chen` (uid 2) exists afterward.
7. `ddev exec 'php vendor/drush/drush/drush.php cache:rebuild -y'`.
8. `BASE_URL="https://gm114-profile.ddev.site" npx playwright test tests/e2e/profile-activity.spec.ts`

**Result: 2 FAILED, 0 passed.**

```
1) "Recent posts" section renders on Maria's profile with her seeded topics, newest first
   Error: expect(locator).toBeVisible() failed
   Locator: getByRole('heading', { name: 'Recent posts', level: 2 })
   — element(s) not found. The "Recent posts" block never renders on /user/2 at all
     (confirmed via the accessibility snapshot: only <h1>maria_chen</h1> and a "Member for"
     article render in the main content area; no do_streams block present anywhere on the page).

2) an anonymous visitor to Maria's profile still sees her public-group topic in "Recent posts"
   Error: expect(received).toBe(expected) — Expected: 200, Received: 403
   — anonymous access to /user/{uid} itself is denied on a stock `standard`-profile install
     (config/sync/user.role.anonymous.yml grants anonymous only 'access comments', 'access
     content', 'access group overview', 'search content' — NOT 'access user profiles'). This is
     a pre-existing baseline-permissions gap unrelated to do_streams; no other spec in this repo
     visits /user/{uid} as anonymous (the closest precedent, phase4.spec.ts's contribution-stats
     test, always logs in first). Flagging for O/A — is anonymous profile visibility an
     assumption of this story's wireframe that was never wired into the site's permission config?
```

### Root-cause diagnosis for failure 1 (the blocking one)

This is **not** a test-authorship bug and **not** a Playwright/environment problem — reproduced
independently via a full, faithful in-process HTTP-kernel request (real login +
`\Drupal::service('router_listener')` matching `/user/2`, confirming the route's `user` parameter
correctly upcasts to the `User` entity #2) and the block plugin's `build()` still returns only
`['#cache' => ...]` — no rows, no markup.

Traced to `web/core/modules/views/src/Plugin/views/argument/ArgumentPluginBase.php`:

```php
public function hasDefaultArgument() {
  $info = $this->defaultActions($this->options['default_action']);
  return !empty($info['has default argument']);
}
```

`defaultActions()` only sets `'has default argument' => TRUE` for `default_action: 'default'`.
**`views.view.user_activity.yml`'s `uid` argument sets `default_action: 'not found'`** (a hard-fail
action — "hide view" — per the same method's `'not found'` entry, `'hard fail' => TRUE`), not
`'default'`. This means:

- `default_argument_type: user` / `default_argument_options: { user: false }` (the config F
  carefully derived from `user.views.schema.yml`) is **entirely orphaned** — it is only ever
  consulted by `ViewExecutable::_buildArguments()` when `hasDefaultArgument()` is true, which
  requires `default_action: 'default'`.
- With `default_action: 'not found'` and no literal arg passed (the block plugin passes `NULL`
  when no block-context mapping supplies a value — confirmed by reading
  `ViewsBlock::build()`/`ViewsBlockBase`), `_buildArguments()` falls through to
  `$argument->defaultAction()` → `defaultNotFound()`, which is documented as a **hard fail that
  hides the view/block entirely**. This is exactly the observed symptom (empty `#cache`-only
  build, block absent from the page for every viewer, including Maria viewing her own profile).

**Verified directly** (throwaway drush `php:script`, not committed):
```
Argument uid has default? NO
  -> getDefaultArgument(): '2'          // the plugin CAN resolve it correctly...
```
...but `_buildArguments()` never calls `getDefaultArgument()` because `hasDefaultArgument()` gates
on `default_action`, which is the wrong value in the shipped YAML.

**The fix belongs to F** (one line in `docs/groups/config/views.view.user_activity.yml`): the
`uid` argument's `default_action` must be `'default'`, not `'not found'`, for the already-present
`default_argument_type: user` to ever take effect. This is a one-line YAML value change, not a
new architecture — F's Design Decisions already correctly derived every other piece of this
argument's config (`default_argument_type`, `default_argument_options`, `validate`,
`validate_options`) from real core schema/source; only `default_action` itself was set to the
wrong enum value.

## Acceptance criteria status

| # | Criterion | Kernel test | Status |
|---|---|---|---|
| a | Published-only | `testPublishedOnlyExcludesUnpublishedNode` | PASS (Kernel) |
| b | Author scoping | `testAuthorScopingReturnsOnlyProfileOwnersNodes` | PASS (Kernel) |
| c | Access-scoping (private group) | `testAccessScopingExcludesPrivateGroupNodeForNonMember` + companion | PASS (Kernel) |
| d | Newest-first ordering | `testResultsOrderNewestFirst` | PASS (Kernel) |
| e | distinct / no fan-out | `testDuplicateGroupRelationshipsYieldOneRowPerNode` | PASS (Kernel) |
| — | Block actually renders "Recent posts" on `/user/{uid}` in a real browser (the point of the story) | E2E test (rev-1) | PASS |
| — | Outsider/non-member access-scoping | Kernel `testAccessScopingExcludesPrivateGroupNodeForNonMember` (E2E case deleted rev-1, see below) | PASS (Kernel) |

The Kernel suite proves the **view's query logic** is entirely correct once a uid argument is
supplied to it directly. It cannot (by design — see `handoff-T-red.md`'s stated layer choice) catch
that the **block's own argument-default wiring** never supplies that uid in the first place. This
is precisely the class of gap the E2E layer exists to catch, and it did.

## Blocking issues

1. **`views.view.user_activity.yml`: `arguments.uid.default_action` must be `'default'`, not
   `'not found'`, for `default_argument_type: user` to take effect.** Without this, the block
   never renders for any viewer, including the profile owner viewing their own page — the core
   deliverable of this story is currently non-functional end-to-end despite the Kernel suite being
   green. Routed back to F.
2. (Non-blocking for this story, flagged for O/A judgment) Anonymous access to `/user/{uid}`
   itself 403s on a stock permission set — `docs/planning/handoffs/st5-profile-114/decisions.md`'s
   D-phase entry frames the empty-state copy as "access-aware" for an anonymous viewer, implying
   anonymous SHOULD be able to reach the page. If that's a real requirement, `'access user
   profiles'` needs granting to `anonymous` somewhere in this story's or a prior story's config
   (not found in `config/sync/user.role.anonymous.yml`). If anonymous access to profiles was never
   intended, the second E2E test's premise is wrong and should be re-scoped (e.g., test against an
   authenticated non-member viewer instead) — needs an O/A call, not a unilateral T fix.

## Advisory notes

- Once blocker 1 is fixed, re-run the full E2E spec (both tests) — test 1 should pass outright;
  test 2 depends on the permissions question above being resolved one way or the other.
- The repaired test file's pre-existing phpcs findings (CRLF line endings, a few docblock style
  nits at lines 129/179-370, unrelated to my change) are cosmetic and out of this story's scope;
  noting for a future lint-cleanup pass, not blocking.

---

## T — Phase 6 rev-1 (re-verify after F's one-line YAML fix, per O's scoping decision)

**Date:** 2026-07-23
**Context:** F applied the one-line fix (`default_action: 'not found'` -> `'default'` in
`docs/groups/config/views.view.user_activity.yml`, see `decisions.md` "F — Phase 5 rev-1").
O additionally scoped the anonymous-viewer question as **out of scope** for #114 (an anonymous
visitor who can't view profiles at all isn't a "viewer of the profile" the AC is about) and
directed re-authoring the outsider-access E2E case against a logged-in non-member persona instead.

### E2E adjustment made

Edited `tests/e2e/profile-activity.spec.ts`:

1. **Outsider-access case:** re-authored to authenticate as `ravi_patel` (seeded member of
   "DrupalCon Portland 2026" only — confirmed via `step_700_demo_data.php` lines 98/99/101, NOT a
   member of "Core Committers" or "Leadership Council", Maria's two private groups) instead of an
   anonymous context. Running it against this repo exposed a **separate, genuine, pre-existing
   site-level permissions gap**: Drupal core's `UserAccessControlHandler::checkAccess()` requires
   `access user profiles` (or `administer users`) to view *any other user's* `/user/{uid}`, and
   this repo's `authenticated` role does not grant it (confirmed via
   `drush role:list --format=json` — `authenticated`'s perms list has no such entry). Every
   non-admin authenticated persona 403s on someone else's profile, not just anonymous — confirmed
   this is not specific to my test by checking `phase4.spec.ts`'s only precedent
   (`do_contribution_stats` test logs in as `admin` and visits `/user/1`, i.e. **its own**
   profile as uid 1, which short-circuits via `administer users` before `access user profiles` is
   ever consulted — no existing spec in the repo actually exercises a non-admin viewer against
   another user's profile).
   Per the task's offered fallback, **deleted this E2E case** as redundant coverage: the exact
   behavior (access-scoped partial render for a non-member viewer) is already fully and correctly
   pinned by the Kernel tests `testAccessScopingExcludesPrivateGroupNodeForNonMember` +
   `testAccessScopingIncludesPrivateGroupNodeForMember`, which exercise the view directly with an
   explicit uid argument and do not depend on the separate (and currently broken) `/user/{uid}`
   route-permission question at all. Fixing that site-level permission gap is outside #114's scope
   and outside T's authority to decide unilaterally — flagged for O/A as a follow-up.

2. **Ordering assertion:** while re-verifying, the surviving "renders on Maria's profile" E2E case
   also failed, on its DOM-order assertion (newest-first). Root-caused via direct SQL query against
   the seeded site: all three of Maria's seeded nodes (`nid` 1, 6, 10) share the **identical**
   `created` timestamp (`1784847624`) because `step_700_demo_data.php` never sets an explicit
   `created` value on these nodes and its sequential `$node->save()` calls complete within the same
   wall-clock second. A tied `created DESC` sort correctly falls back to natural/nid order
   (ascending) — the live page legitimately renders oldest-first for *this* seed data. This is not
   a bug in the view (`created DESC` is the correct, present sort — confirmed by reading
   `views.view.user_activity.yml`) and not a regression from F's fix; it is this E2E assertion's
   premise (strictly-increasing seed-time timestamps) being invalid against the actual seed script.
   Ordering is already fully and rigorously pinned at the correct, cheaper tier by the Kernel test
   `testResultsOrderNewestFirst`, which sets three explicit, strictly-increasing `created` values
   specifically to make the DESC sort observable. **Deleted the invalid DOM-order assertion**
   (kept the heading + all-three-titles-present assertions, which are still valid and still the
   core deliverable this E2E exists to prove) rather than patching the seed script (out of scope)
   or re-authoring a flaky same-second-timestamp workaround.

Net result: `tests/e2e/profile-activity.spec.ts` now contains **one** test (title/comment
docblock updated in place to document both removals and why the remaining coverage is sufficient
— see file for full reasoning).

### Re-verification — GREEN

**Assemble:** `ddev exec 'bash scripts/ci/assemble-config.sh'` — clean, 130 config files copied
(unchanged from prior run).

**Config import (picks up F's rev-1 YAML fix):**
```
ddev exec 'php vendor/drush/drush/drush.php config:import -y'
...
 [notice] Synchronized configuration: update views.view.user_activity.
 [notice] Synchronized configuration: update block.block.do_streams_user_activity.
 [success] The configuration was imported successfully.
ddev exec 'php vendor/drush/drush/drush.php cache:rebuild'
 [success] Cache rebuild complete.
```

**Kernel (sanity, no regression expected):**
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/UserActivityViewTest.php'

 ✔ Published only excludes unpublished node
 ✔ Author scoping returns only profile owners nodes
 ✔ Access scoping excludes private group node for non member
 ✔ Access scoping includes private group node for member
 ✔ Results order newest first
 ✔ Duplicate group relationships yield one row per node

Tests: 6, Assertions: 135, Deprecations: 4, PHPUnit Deprecations: 7.
```
Identical assertion count to the original T-GREEN run — zero regressions from F's one-line fix.

**E2E (against the same fully-assembled + seeded site used in the original T-GREEN run, re-imported):**
```
BASE_URL="https://gm114-profile.ddev.site" npx playwright test tests/e2e/profile-activity.spec.ts

Running 1 test using 1 worker
  ok 1 [chromium] › tests\e2e\profile-activity.spec.ts:116:7 › ST-5 — Profile activity stream (#114) › "Recent posts" section renders on Maria's profile with her seeded topics (2.3s)

1 passed (3.4s)
```
Re-ran twice for stability (first run after the config:import transiently failed on stale
render-cache from the demo "Browse as" persona-switch mechanism colliding with the just-completed
import — a `cache:rebuild` resolved it; both subsequent runs passed cleanly, 9.2s then 2.3s).

**Spot-check (test still fails if behavior is removed):** the surviving assertions
(`<h2>` heading visibility + all three title links visible) depend on the block actually
rendering the view's result rows — this is exactly what was FALSE before F's rev-1 fix (block
rendered nothing at all, confirmed in the original T-GREEN run) and is TRUE now. The assertion is
not vacuous.

### Tier 2 — final status

- **Test coverage vs acceptance criteria:** unchanged from original T-GREEN Kernel-side analysis —
  all 5 brief.md criteria (a-e) have a dedicated, GREEN Kernel test. E2E now covers exactly the
  one behavior it can validate that Kernel cannot (the block/argument-default wiring actually
  renders on the real route) — see "Acceptance criteria status" table above (updated).
- **Test quality / proportionality:** suite is now *more* proportionate than the original T-GREEN
  version — two invalid/redundant E2E assertions (DOM-order on tied timestamps; outsider-access
  duplicating Kernel coverage while blocked by an unrelated site permission gap) were removed
  rather than patched around, per the test-quality mandate ("coverage has a ceiling, not only a
  floor"). The one surviving E2E test names a single behavior (the block renders live, with the
  right content, for the profile owner), fails in isolation for the right reason (confirmed: it
  failed for the correct reason — block absent — before F's rev-1 fix), and does not duplicate the
  Kernel suite (Kernel cannot exercise the block/argument-default layer at all).
- **Playwright structural run:** exits 0. PASS.

### Acceptance criteria — final status

| # | Criterion | Test | Status |
|---|---|---|---|
| a | Published-only | Kernel `testPublishedOnlyExcludesUnpublishedNode` | PASS |
| b | Author scoping | Kernel `testAuthorScopingReturnsOnlyProfileOwnersNodes` | PASS |
| c | Access-scoping (private group / outsider viewer) | Kernel `testAccessScopingExcludesPrivateGroupNodeForNonMember` + `...IncludesPrivateGroupNodeForMember` | PASS |
| d | Newest-first ordering | Kernel `testResultsOrderNewestFirst` | PASS |
| e | distinct / no fan-out | Kernel `testDuplicateGroupRelationshipsYieldOneRowPerNode` | PASS |
| — | Block actually renders "Recent posts" on `/user/{uid}` in a real browser (the point of the story) | E2E "renders on Maria's profile" | **PASS** |

### Blocking issues (final)

None. The one blocker from the original T-GREEN run (`default_action` YAML value) is fixed and
re-verified GREEN.

### Non-blocking, carried forward for O/A (not T's to resolve)

- Baseline `access user profiles` permission is absent from **both** `anonymous` and
  `authenticated` roles in this repo's config — any non-admin visitor, logged in or not, 403s on
  viewing another user's `/user/{uid}`. This is a site-level permissions question outside #114's
  scope (per O's 2026-07-23 decision) but is a real gap if any future story needs a non-admin user
  to view another member's profile page. Flagged, not fixed.

### Verdict

**T-green complete, no blocking issues.** GREEN on Kernel (6/6) and E2E (1/1, structurally exits
0). Ready for U (UI surface — this story adds a new visible block to `/user/{uid}`).
