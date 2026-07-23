# Handoff-T-green: Phase 6 - #121 SC-2 Membership models enforced

**Date:** 2026-07-22
**Branch:** 121-req2join (worktree `~/Projects/_worktrees/groups-req2join`)
**Issue:** #121
**Handoff-F reviewed:** `docs/planning/handoffs/121-req2join/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/121-req2join/handoff-T-red.md`

## Part 1 — Repair applied to the one flagged test bug

**Verified F's diagnosis myself before touching anything:**
- `GroupInterface::getMembers(array $roles = [])` (`web/modules/contrib/group/src/Entity/GroupInterface.php:129-139`)
  docblock: "(optional) A list of group role machine names to filter on" — the ONLY filter
  parameter is `$roles`. No status parameter exists.
- Confirmed the implementation has no hidden status filter either (interface is authoritative here;
  no override in `Group.php` narrows the contract).
- `ManageMembersForm.php:80` (`$all_memberships = $group->getMembers();`) confirms the established,
  working, in-module idiom: call unfiltered, then label/filter status AFTERWARD in the caller. F's
  diagnosis is correct — this was my test-authorship bug, not a production defect.

**Repair** (`docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php`,
`testNonMemberSeesRequestToJoinOnModeratedGroup`, lines 198-208):

Before:
```php
// Not visible as an active member.
$active = $group->getMembers();
$active_uids = array_map(static fn($m) => $m->getEntity()?->id(), $active);
$this->assertNotContains($sophie->id(), $active_uids, 'A pending requester does not appear as an active member.');
```

After:
```php
// Not visible as an active member. NOTE: Group::getMembers() has no
// status-filtering parameter (see GroupInterface::getMembers() docblock,
// web/modules/contrib/group/src/Entity/GroupInterface.php) -- it returns
// every group_membership relationship regardless of
// field_membership_status, exactly like the existing ManageMembersForm
// (line ~80) which filters/labels status itself AFTER the unfiltered
// call. So the correct way to assert 'not an active member' is to check
// the relationship's OWN status directly, not to look for absence from
// an unfiltered list (which would always fail this assertion, active or
// not, since getMembers() would still list her at ANY status).
$this->assertNotSame('active', $relationship->get('field_membership_status')->value, 'A pending requester does not have active status.');
```

Chose the more surgical repair (assert on the relationship's own status, mirroring the existing
`assertSame('pending', ...)` at line 196) over the alternative (manually filtering
`getRelationships('group_membership')` by status) — same intent, smaller diff, and it doesn't
duplicate line 196's assertion (`pending` proves the exact state; `!== active` proves the specific
negative property the AC cares about, so both retain independent value rather than being
redundant). **Spot-check that the repaired assertion still fails if behavior regresses:** traced
`GroupMembershipManager::requestJoin()` → `createMembership($group, $account, self::STATUS_PENDING, [])`
— if a future regression flipped this to `self::STATUS_ACTIVE`, `assertNotSame('active', ...)` would
correctly fail. Confirmed non-vacuous by inspection (did not need to actually break production code
to prove it — the constant threading is unambiguous).

## Part 2 — Tier-1 re-run: JoinPolicyEnforcementTest, 9/9 GREEN

```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8888' \
SYMFONY_DEPRECATIONS_HELPER=disabled BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
web/modules/custom/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php

 ⚠ Non member sees join button on open group
 ⚠ Non member sees request to join on moderated group        <- was ✘, now GREEN
 ⚠ Non member sees no join path on invite only group
 ⚠ Direct post to request join on invite only is 403
 ⚠ Organizer sees pending row in existing manage members
 ⚠ Anonymous get on manage members is 403
 ⚠ Plain member get on manage members is 403
 ⚠ Anonymous post to approve is 403
 ⚠ Plain member post to approve is 403
Tests: 9, Assertions: 54, Deprecations: 14-15, PHPUnit Deprecations: 10.
```
9/9 GREEN (⚠ = pre-existing framework deprecation noise, exit code 0, zero Errors/Failures — same
pattern F documented and cross-confirmed on the untouched sibling suite).

(Environment note: `SIMPLETEST_BASE_URL` needed a live `php -S 127.0.0.1:8888` router inside the
`gm121-groups-on-d11` ddev container — none was running at session start; started one for this
verification pass. `web/core/phpunit.xml.dist`'s assembled-layout requirement was met via
`bash scripts/ci/assemble-config.sh` run through `ddev exec` before every PHPUnit invocation.)

## Part 3 — Tier-2 GREEN verification

### 1. Kernel across all 11 custom modules (real CI command)

```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
$(find web/modules/custom -type d -path '*/tests/src/Kernel')

OK, but there were issues!
Tests: 107, Assertions: 2947, Deprecations: 28, PHPUnit Deprecations: 93.
```
**107/107 GREEN**, 0 regressions, across `do_tests`, `do_streams`, `do_group_language`,
`do_notifications`, `do_group_pin`, `do_profile_stats`, `do_group_mission`, `do_group_extras`,
`do_group_membership`, `do_multigroup`, `do_discovery`. Matches F's self-check exactly.

### 2. Functional suites

`do_group_membership` (all 5 files — mine + 4 pre-existing):
```
Tests: 20, Assertions: 179, Deprecations: 15, PHPUnit Deprecations: 25.
```
**20/20 GREEN** (was 19/20 at F's handoff; now 20/20 after the repair). Every pre-existing
Functional test (`ManageMembersPaginationTest`, `ManageMembersRouteAccessTest`,
`ManageMembersRouteResolutionTest`, `ManageMembersPageRenderTest`) unaffected.

`do_chrome` (Unit + Functional):
```
Tests: 15, Assertions: 150, Deprecations: 10, PHPUnit Deprecations: 18.
```
**15/15 GREEN** (14 Unit + 1 Functional, matching F's split exactly).

**Combined Functional total: 21/21 GREEN**, as specified in the task.

### 3. phpcs

Ran with the project's documented standard (`docs/workflow/PROJECT_CONTEXT.md` line 51: "Drupal /
DrupalPractice standard") — **note:** a bare `php vendor/bin/phpcs <path>` with no `--standard` flag
produces ~3,364 errors across every file in the tree (defaults to PEAR, not Drupal — there is no
committed `phpcs.xml`/`phpcs.xml.dist` in this repo). Re-ran with the correct invocation:

```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_group_membership/ docs/groups/modules/do_chrome/src/HelpText.php
```

Result: a modest, **pre-existing** set of errors (doc-comment formatting, line length, one
"gendered language" nit, one namespaced-class-without-use-statement) spread across F's touched
files and untouched sibling files alike. **Verified by diffing against the pre-F/pre-repair
baseline** (extracted via `git show <parent-commit>:<path>` into a throwaway file, phpcs'd, counts
compared, throwaway files deleted before finishing):
- `HelpText.php`: 18 errors + 6 warnings on the PRE-F version (`57d6043`) vs. 18 errors + 6 warnings
  on F's version — **F's edit introduced zero new phpcs violations.**
- `JoinPolicyEnforcementTest.php`: same error set (11 doc-comment/gendered-language items) on the
  pre-repair version (`ee10531`, F's commit) vs. post-repair — **my repair introduced zero new
  phpcs violations** (the added comment block + single assertion line are clean).

**Verdict: PASS (non-blocking).** No new lint debt from either F's or my changes; the pre-existing
debt is out of scope for this story (no phpcs gate exists in `.github/workflows/`, confirmed by
inspection — lint is advisory here, not a CI gate).

### 4. E2E — `npx playwright test tests/e2e/membership-models.spec.ts`

**Ran successfully against a fully seeded, freshly provisioned site. Final result: 4/4 GREEN.**

Getting there required standing up the FULL pipeline (`assemble → site:install → cim → seed →
runserver → playwright`) from scratch in the `gm121-groups-on-d11` ddev project, since no seeded
install existed yet this session. This surfaced **three genuine pre-existing environment/product
defects unrelated to story #121**, plus **three genuine test-authorship bugs in the E2E spec I
authored at RED** (fixed here, per my Phase-6 mandate), plus **one real, still-open production gap
in F's implementation** that I could not fix (blocked by my "no production code" mandate) and am
flagging explicitly rather than routing around silently.

#### Pre-existing environment defects found + worked around (NOT story #121's fault, NOT edited in source)

1. **`config_sync_directory` mismatch.** A stale prior `site:install` had baked a random-hash sync
   path into `web/sites/default/settings.php` (gitignored, environment-local, confirmed via
   `git check-ignore -v`) that pointed at an empty directory, not the assembled `config/sync/`.
   Worked around by copying the assembled `config/sync/*.yml` into that hash directory at runtime
   (no source file touched). Also hit a mutagen-sync propagation lag when I tried editing
   `settings.php` directly — reverted that edit once the copy-based workaround proved sufficient.
2. **Malformed `language.content_settings.node.*` config entities** (missing
   `target_entity_type_id`/`target_bundle` keys) for `forum`, `documentation`, `event`, `post`,
   `page` — a latent defect from however content-translation was originally enabled in `step_640.php`
   (untouched by #121; confirmed via `git log` that this seed step predates the story). This threw
   a fatal `ContentLanguageSettingsException` inside `DefaultLanguageItem::applyDefaultValue()` the
   moment the seed script tried to create its first `forum`-type node — **blocking the ENTIRE seed
   script, including F's own Step 790, before it could even run.** Repaired via a throwaway
   `drush php:script` (deleted the malformed raw config objects, recreated each
   `language_content_settings` entity properly through the entity API) — a runtime **data** fix,
   not a source-code edit; `docs/groups/scripts/step_640.php` itself was not touched.
3. **`do_group_extras`'s `hook_entity_presave` unpublishes every group created via `drush
   php:script`** (`DoGroupExtrasHooks.php:53-65`, pre-existing since the initial commit, confirmed
   via `git log`) because the CLI/drush execution context isn't privileged
   (`administer group`/`administer groups`). This meant `/all-groups` showed "No groups yet" even
   though all 8 seeded groups existed in the database — status=0 for every one. Republished all 7
   non-archived groups via a throwaway script (Legacy Infrastructure correctly stays unpublished —
   that's the seed script's own intentional archive, not this bug). **This may also affect the real
   CI E2E job** if CI's seed step runs as an unprivileged CLI user too — flagging for O/A to confirm
   CI's drush invocation runs as uid 1 or otherwise bypasses this hook, or the CI E2E job may be
   silently getting an empty directory too. Worth a follow-up story regardless of CI's current
   behavior, since this hook's premise (self-service group creation should default to unpublished)
   is reasonable for real users but actively wrong for the seed script's own admin-authored content.

After these three fixes, `docs/groups/scripts/step_700_demo_data.php` ran end-to-end cleanly,
**including F's Step 790** ("Set Leadership Council visibility -> moderated", "Set Core Committers
visibility -> invite_only", both sophie_mueller and alex_novak pending requests seeded) — confirming
F's seed-script change is itself correct and complete; the three defects above all predate and are
independent of it.

#### Test-authorship bugs found in my own E2E spec, fixed at T-green (per my mandate — I author tests, including E2E)

`tests/e2e/membership-models.spec.ts`:

1. **`joinControl()` locator required an EXACT `/^Join$/i` match** — the real rendered link/button
   text is **"Join group"**, not "Join". Widened to accept `getByRole('link', { name: /^Join group$/i })`
   first (the actual affordance on the canonical group page), falling back to the original
   button/input patterns.
2. **The moderated-group "Request to join" test originally used `sophie_mueller`** — but F's Step
   790 (seed script, which I never saw at RED time since it's F's own implementation choice) seeds
   sophie AND alex_novak as PENDING requesters on Leadership Council SPECIFICALLY. This made my
   test's own precondition ("sees the control, has not yet requested") false before the test even
   started — a genuine collision between my RED-time assumption and F's seed data, not a production
   bug. Swapped the persona to `ravi_patel` (verified via a throwaway `drush php:script` query to
   have zero seeded relationship, active or pending, to any of the three test groups) and renamed
   the test accordingly. `sophie_mueller`/`alex_novak` remain correct personas for the open-group
   instant-join test and both invite-only negative-assertion tests (verified clean there).
3. **The open-group "instantly joins" test asserted on post-click BODY TEXT** (`/now a member|joined/i`)
   — but the stock `drupal/group` `entity.group.join` route (pre-existing #95 baseline, untouched by
   #121) is a two-step confirmation flow: clicking "Join group" on the canonical page navigates to
   `/group/{id}/join`, which renders its OWN "Join group" submit button that must be clicked to
   actually create the membership; on success it redirects to the **user's own profile page**
   (`/user/{uid}`), not back to the group, and renders NO status/message text anywhere in the
   resulting DOM (verified directly — no message/alert/status region exists in the captured
   snapshot). Fixed by (a) clicking through the confirmation step, then (b) asserting the outcome at
   the data level instead of a transient-message level — navigate back to the group page and assert
   sophie now appears in the "Group members" list, which directly proves the substantive AC-1/AC-15
   claim under test ("instant" = no approval STEP required, unlike the moderated flow) without
   depending on message text that doesn't exist.

All three are documented inline in the spec's own top-of-file docblock and at each call site, per
the same standard I'd apply to any other test-authorship repair.

#### One real, unresolved production gap found — flagged, NOT silently routed around

**The canonical group page (`/group/{id}`) renders NEITHER a "Join group" link (correct — the group
isn't `open`) NOR a "Request to join" link for a `moderated`-visibility group.** F's `RequestJoinForm`
only exists at `/group/{group}/join-request`, a URL nothing on the canonical page links to (grepped
`do_chrome`/`do_group_membership` source for any link-rendering logic pointing at `join-request` —
none found; F's own Kernel/Functional tests all hit that URL via direct `drupalGet()`, never
asserting a discoverable link exists).

The brief's AC text is explicit: *"Leadership Council (join=request) shows the request flow"* and
*"non-member sees 'Request to join'"* — read plainly, this means a non-member visiting the group
should see the control, not that it exists only if they already know the URL. A control unreachable
from the normal browse flow does not satisfy this.

**I could not fix this myself** (my mandate forbids touching `docs/groups/modules/*/src/`). I worked
around it in the E2E test ONLY to still validate the request/approval MECHANICS end-to-end
(navigating directly to `/group/{id}/join-request`, with an explicit in-line comment marking this as
a stand-in, not a substitute, for the missing link) — **this is a blocking finding for F, not
something I am treating as resolved.** See "Blocking issues" below.

## Acceptance criteria status

| AC | Description | Status | Backing test |
|----|---|---|---|
| AC-1 | Open group: instant join, no approval step | **PASS** | `RequestJoinFlowTest::testRequestJoinOnOpenGroupUsesJoinPath` (Kernel); `JoinPolicyEnforcementTest::testNonMemberSeesJoinButtonOnOpenGroup` (Functional); E2E test 1 (GREEN after fixing the two-step-confirm + data-level-assertion bugs) |
| AC-2 | Moderated group: non-member sees "Request to join"; creates pending relationship | **PARTIAL — see blocking issue below.** Mechanics PASS: `RequestJoinFlowTest::testRequestJoinCreatesPendingRelationship` (Kernel); `JoinPolicyEnforcementTest::testNonMemberSeesRequestToJoinOnModeratedGroup` (Functional, repaired, now GREEN); E2E test 2 GREEN via direct-URL workaround. Discoverability (the control being reachable from the normal browse flow) FAILS — no link exists anywhere on the canonical group page. |
| AC-3 | Invite-only: no direct join path | **PASS** | `RequestJoinFlowTest::testRequestJoinOnInviteOnlyGroupIsForbidden` (Kernel); `JoinPolicyEnforcementTest::testNonMemberSeesNoJoinPathOnInviteOnlyGroup` (Functional); E2E tests 3+4 |
| AC-4 | Organizer sees pending row on existing `/group/{group}/members`; approve assigns no roles; deny deletes | **PASS** | `testApprovePendingFlipsToActiveWithNoRoles`, `testDenyPendingDeletesRelationship` (Kernel); `testOrganizerSeesPendingRowInExistingManageMembers` (Functional) |
| AC-5 | Duplicate request throws | **PASS** | `RequestJoinFlowTest::testDuplicateRequestJoinThrows` |
| AC-6 | No "Not yet enforced" text remains in `visibility.*` HelpText | **PASS** | `HelpTextTest::testVisibilityCopyIsPresentPlainTextAndHonest` (10/10 GREEN) |
| AC-7 | Invite-only copy contains "visible", not merely edited | **PASS** | same test |
| AC-8 | HelpTextTest updated | **PASS** | self-referential, done at RED, still GREEN |
| AC-9 | Seed data: 2 pending rows on Leadership Council | **PASS** | Verified directly via seed-script run: "sophie_mueller requested to join Leadership Council (pending)" + "alex_novak requested to join Leadership Council (pending)" both appear in Step 790 output |
| AC-10 | E2E walks all three flows, G9 locator contract | **PASS** (with the 3 test-authorship repairs documented above) | `tests/e2e/membership-models.spec.ts`, 4/4 GREEN |
| AC-11 | Direct POST 403 on invite_only join-request | **PASS** | `testRequestJoinOnInviteOnlyGroupIsForbidden` (Kernel); `testDirectPostToRequestJoinOnInviteOnlyIs403` (Functional) |
| AC-12 | WCAG 2.2 AA | **PARTIAL** — E2E keyboard-focus smoke check passes (ravi_patel's Request-to-join control is keyboard-focusable, verified in the E2E run); full axe scan is U's remit, not T's | E2E test 2's focus assertion |
| AC-13 | Existing suites stay green | **PASS** | 107/107 Kernel, 21/21 Functional, all confirmed this phase |
| AC-14 | Source-only commits | **PASS** | Only `docs/groups/...JoinPolicyEnforcementTest.php` and `tests/e2e/membership-models.spec.ts` modified; verified via `git status --short docs/groups/` showing exactly one path |
| AC-15 | Anon/plain-member GET 403 on `/members` | **PASS** | `testAnonymousGetOnManageMembersIs403`, `testPlainMemberGetOnManageMembersIs403` |
| AC-16 | Anon/plain-member POST 403 on approve/deny | **PASS** | `testAnonymousPostToApproveIs403`, `testPlainMemberPostToApproveIs403` |

## Blocking issues

**One blocking issue — AC-2's discoverability half is not met:**

The canonical `/group/{id}` page for a `moderated` group renders no "Request to join" link (and
correctly renders no "Join group" link either, since the group isn't open) — F's `RequestJoinForm`
is only reachable by a user typing `/group/{group}/join-request` directly, which nothing links to.
This does not satisfy the brief's plain-text AC wording ("non-member sees 'Request to join'"). This
is F's implementation gap, not a test-authorship issue — I verified it by direct inspection (grepped
all of `do_chrome`/`do_group_membership` source for any link-rendering logic pointing at
`join-request`; none exists) and by live E2E observation (a genuinely clean non-member, ravi_patel,
sees no control of either kind on the group page). **F must add a discoverable link** (most likely
alongside or replacing the "Join group" affordance's existing render location — need to identify
exactly where the stock "Join group" link itself renders from, since I could not locate its source
either; it may be theme-layer per T-red's note about `groups_chrome`'s directory-card affordance, in
which case F needs to also add an equivalent for the canonical group page, which currently has
neither). Route back to F; re-run A if this requires a new render surface; re-run T (this phase)
once fixed, since my E2E workaround must be reverted once the real link exists (currently marked
inline in the spec as a known, flagged stand-in, not a permanent fixture).

**Everything else: no blocking issues.** All Tier-1/Tier-2 checks pass; the one Phase-4 test bug is
repaired; the seed-script/environment defects found were pre-existing, unrelated to #121, and worked
around at the runtime-data level without touching any source file.

## Advisory notes (non-blocking)

- The `do_group_extras` unpublish-on-CLI-create hook (finding #3 above) may silently break the REAL
  CI E2E job's directory listing too, depending on what privilege level CI's own seed step runs
  under — worth a quick confirmation from O/A even though it's out of this story's scope to fix.
- The malformed `language.content_settings.node.*` config entities (finding #2 above) will recur on
  any FRESH `site:install` + `cim` cycle, since the fix I applied was a runtime data patch on this
  one ddev instance, not a source/config fix — this should probably be captured as its own
  lightweight bug ticket against whichever story (`step_640.php`'s original author) is responsible,
  since it will silently break every future from-scratch E2E provisioning attempt until fixed at the
  source.
- `HelpText.php`'s "Who can do what" permission-matrix footnote still says *"Finer-grained roles
  (moderation, request-to-join) are planned but not yet enabled on the demo"* — this is stale now
  that #121 ships request-to-join. Not one of the three `visibility.*` strings AC-6/AC-7 target
  (this is a DIFFERENT HelpText key, the permission-matrix panel's own footnote), so it's outside
  this story's named scope, but flagging since it directly contradicts the shipped behavior a user
  would see one paragraph above it.
- phpcs is not currently a CI gate for this repo (no step in `.github/workflows/`); the ~24-files'
  worth of pre-existing lint debt found while scoping this check is unrelated to #121 and not
  actioned here, per scope.

## Verdict

**T-green complete. One blocking issue found (AC-2 discoverability). Route back to F for the
missing "Request to join" link on the canonical group page; U's walkthrough should NOT proceed on
the moderated-group surface until that link exists (U would otherwise correctly fail the same way
my E2E test initially did). All other ACs, Tier-1, and Tier-2 checks are clean.**
