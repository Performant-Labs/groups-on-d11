# Handoff-T-green: Phase 6 - #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer
**Issue:** #144
**Handoff-F reviewed:** `docs/planning/handoffs/144-auto-organizer/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/144-auto-organizer/handoff-T-red.md`

## Fix applied

`docs/groups/modules/do_tests/tests/src/Functional/GroupCreatedPreviewControllerTest.php` was
missing `do_group_membership` from its module list (F's flagged, correctly-NOT-F's-remit defect).
This test does NOT import the real assembled config — it builds its own minimal group type via
`createGroupType()`/`createRoleForGroupType()` — so, unlike `CreateGroupWizardOrganizerTest`, no
additional field-type modules (image/taxonomy/node) were needed, only the module that provides the
route/controller under test.

Before:
```php
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
```

After:
```php
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * {@inheritdoc}
   *
   * T-green fix: this suite hits the NEW
   * do_group_membership.group_created_preview route/controller directly, so
   * the module providing it must be enabled — GroupBrowserTestBase's own
   * $modules is only ['group'], which is why the route genuinely 404s
   * without this override (confirmed by F via a throwaway probe; F did not
   * edit this file — see handoff-F.md). This test constructs its OWN
   * minimal community_group-alike type via createGroupType()/
   * createRoleForGroupType() (not the real assembled config), so no
   * additional field-type modules (image/taxonomy/node) are needed here,
   * unlike CreateGroupWizardOrganizerTest's real-config-import suite.
   */
  protected static $modules = ['group', 'do_group_membership'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';
```

No assertion in this file was touched — confirmed by `git diff HEAD -- <path>` showing a pure
16-line insertion (no deletions, no other hunks).

## GREEN confirmation

Ran from the assembled layout inside `gm144-auto-organizer` DDEV (renamed from the worktree's
stale `pl-groups-on-d11` config for this session only; `.ddev/config.yaml` reverted to its
tracked `pl-groups-on-d11` value afterward — no repo change committed for the rename). All runs
used `SIMPLETEST_DB="mysql://db:db@db:3306/db" SIMPLETEST_BASE_URL="http://localhost"`.

```
bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 95 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

**Unit (`EnsureRoleTest.php`)** — 2/2 GREEN:
```
Ensure Role (Drupal\Tests\do_group_membership\Unit\EnsureRole)
 ⚠ Ensure role appends missing role to existing roles
 ⚠ Ensure role is no op when role already present
OK, but there were issues!
Tests: 2, Assertions: 12, PHPUnit Deprecations: 3.
```

**Kernel (`CreateGroupOrganizerHookTest.php`)** — 4/4 GREEN:
```
OK, but there were issues!
Tests: 4, Assertions: 100, Deprecations: 3, PHPUnit Deprecations: 5.
```
(All 4 tests pass — `testEnsureRoleIsAdditiveOnRealRelationship`,
`testEnsureRoleIsIdempotentOnRealRelationship`, `testInsertHookGrantsOrganizerToCreatorMembership`,
`testInsertHookDoesNotGrantOrganizerToNonOwnerMembership` — matching F's reported 100 assertions
exactly.)

**Full combined `do_group_membership` + `do_tests` suite** (Unit+Kernel+Functional, the whole
story's regression footprint):
```
OK, but there were issues!
Tests: 82, Assertions: 1271, Deprecations: 8, PHPUnit Deprecations: 85.
```
All 82 tests pass — deprecation-notice warnings only (pre-existing Drupal 11.2+/Twig 3.28
deprecations across the codebase, unrelated to this story), zero failures/errors.

**Functional (`GroupCreatedPreviewControllerTest.php`, the file T fixed)** — 2/2 GREEN
(individually, isolated from the combined run):
```
Tests: 2, Assertions: 40, Deprecations: 3.
```
Both `testPreviewPageRendersForOwner` and `testPreviewPageIsForbiddenForUnrelatedUser` pass. This
was 404/403-mismatch RED at T-red time — now genuinely 200/403 GREEN, proving the fix corrected
the right defect (a missing module, not a route/controller bug — F's independent throwaway-probe
finding is now confirmed by T's own real fix + run, not just taken on faith).

**Functional (`CreateGroupWizardOrganizerTest.php`, the real wizard-driven AC-1/AC-2/AC-3
proof)** — 1/1 GREEN:
```
Tests: 1, Assertions: 17, Deprecations: 3.
```
Matches F's reported count exactly (17 assertions). `testWizardCreateGrantsOrganizerAndRedirectsToPreview`
passes: Organizer role additive alongside Admin (AC-1), `hasPermission('edit group')` /
`hasPermission('administer members')` both TRUE for the creator (AC-2), final redirect lands on
`/group/{group}/created` not canonical (AC-3).

**#36 regression pair (must-not-touch)** — 3/3 GREEN:
```
Creator Membership Api (Drupal\Tests\do_tests\Kernel\CreatorMembershipApi)
 ✔ Api save creates no creator membership
 ✔ Add member establishes creator membership
Creator Membership Form (Drupal\Tests\do_tests\Functional\CreatorMembershipForm)
 ⚠ Form create adds creator as member
Tests: 3, Assertions: 54, Deprecations: 1.
```
Matches F's reported count exactly.

**Spot-check — test still fails if behavior is removed:** Confirmed by construction, not
re-run: `GroupCreatedPreviewControllerTest`'s RED-time failure (404/403-instead-of-200/403,
per handoff-T-red.md) was produced by the route genuinely not existing; the fix only added a
module to the test's own `$modules` list, touching no assertion — so removing
`GroupCreatedPreviewController`/the route again would immediately reproduce the same 404 RED.
Same logic for `CreateGroupOrganizerHookTest`/`EnsureRoleTest`: their assertions target
`ensureRole()`'s post-condition and the hook's post-insert role state directly; removing either
production behavior reproduces the exact T-red failure text (undefined method / role absent from
array).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `bash scripts/ci/assemble-config.sh` | exits clean | `==> assemble-config: done` | PASS |
| Unit suite | `phpunit ... EnsureRoleTest.php` | 2/2 pass | `Tests: 2, Assertions: 12` | PASS |
| Kernel suite | `phpunit ... CreateGroupOrganizerHookTest.php` | 4/4 pass | `Tests: 4, Assertions: 100` | PASS |
| Full module regression | `phpunit ... do_group_membership/tests do_tests/tests` | all pass | `Tests: 82, Assertions: 1271` all green | PASS |
| Functional (T-fixed file) | `phpunit ... GroupCreatedPreviewControllerTest.php` | 2/2 pass | `Tests: 2, Assertions: 40` | PASS |
| Functional (wizard) | `phpunit ... CreateGroupWizardOrganizerTest.php` | 1/1 pass | `Tests: 1, Assertions: 17` | PASS |
| #36 regression | `phpunit ... CreatorMembershipFormTest.php CreatorMembershipApiTest.php` | 3/3 pass | `Tests: 3, Assertions: 54` | PASS |
| Lint (production files) | `phpcs --standard=Drupal,DrupalPractice` on the 3 PHP files + 3 YAML files | 0 errors/warnings | exit 0, no output | PASS |

## Tier 2 results

- **Wireframe DOM order:** `GroupCreatedPreviewController::view()` returns `p` (Organizer
  statement) -> `h2` ("What's next?") -> `ul>li>a x3` (edit/manage/view), with the sole `h1`
  supplied via `_title_callback` per the routing.yml. Confirmed by reading the controller and
  routing.yml directly, and independently re-proven by
  `GroupCreatedPreviewControllerTest::testPreviewPageRendersForOwner()`'s own DOM-order string-position
  assertions (h1 before h2 before ul), now GREEN. Matches wireframe exactly. PASS.
- **Acceptance criteria (brief) match:** link texts repeat the group name and destination/action
  (`Edit "%label" details"`, `Manage members of "%label"`, `View "%label"`), never bare "click
  here" — matches wireframe's AC-5 copy notes verbatim. PASS.
- **Cache metadata:** `view()` sets `#cache => ['contexts' => ['user.permissions'], 'tags' =>
  $group->getCacheTags()]`; `access()` calls `->addCacheableDependency($group)->cachePerPermissions()->cachePerUser()`.
  Dependency on the group (busts on rename/delete) and per-user/per-permission variance are both
  present, mirroring `ManageMembersController::access()`'s shape. PASS.
- **No other test file edited beyond the one T-authored fix:** `git diff --stat HEAD --
  docs/groups/modules/*/tests/` against the T-red commit (`5c65504`) shows exactly two files:
  `CreateGroupWizardOrganizerTest.php` (199 changed lines — F's delegated fixture-mechanism-only
  fix, confirmed via `git diff | grep assert` returning zero touched `assert*()` call lines, only
  one docblock prose mention of the word "assertion") and
  `GroupCreatedPreviewControllerTest.php` (16 insertions, 0 deletions — T's fix, this phase). No
  other test file in the diff. PASS.
- **Hook service registration:** `do_group_membership.services.yml` registers
  `Drupal\do_group_membership\Hook\CreateGroupOrganizerHook` FQCN-keyed, `autowire: false`, with
  explicit `- '@do_group_membership.manager'` argument — identical shape to the existing
  `GroupAccessHook` entry immediately above it in the same file. PASS.
- **No unexpected build artifacts left behind:** `config/sync/*.yml` changes produced by this
  phase's own `assemble-config.sh` run were reverted (`git checkout -- config/sync/` +
  `git clean -fd config/sync/`) before finishing. `web/modules/custom/`, `web/sites/simpletest/`,
  and the top-level `sites/simpletest/` PHPUnit-run artifact were removed (all untracked,
  gitignored-equivalent per the project convention). `.ddev/config.yaml` was reverted to its
  tracked `name: pl-groups-on-d11` value (a session-local rename to `gm144-auto-organizer` was
  used only for this phase's own DDEV project naming, per the `gm144-*` container-namespacing
  instruction, and is not part of the diff O will stage). Final `git status --porcelain` shows
  only the expected source/doc changes (no `config/sync/*.yml`, no `web/modules/custom/`, no
  `.ddev/config.yaml`, no `web/sites/simpletest/`). PASS.

## E2E structural review (NOT run — no seeded served site in this phase)

Reviewed `tests/e2e/create-group.spec.ts` against `manage-members.spec.ts`/`phase4.spec.ts`'s
established conventions:

- **Locator conventions match:** same `login()` helper verbatim (`getByLabel('Username')`,
  `getByLabel('Password', {exact: true})`, `getByRole('button', {name: 'Log in'})`), same
  `baseURL` convention from `playwright.config.ts`. PASS.
- **Submit-button gotcha avoided:** `completeWizard()` uses
  `page.getByRole('button', {name: pattern})`, which correctly matches `<input type="submit">`
  via ARIA role mapping (the project's known `#type => submit` renders `<input type=submit>` not
  `<button>` gotcha) — same pattern already proven working in `manage-members.spec.ts` and
  `phase4.spec.ts`. PASS.
- **Full acceptance path walked:** login -> `/group/add/community_group` -> fill Title ->
  `completeWizard()` -> assert URL `/group/{group}/created` (AC-3) -> assert h1/p/h2/ul>li>a x3
  DOM shape (AC-4/AC-5) -> click "Manage members" CTA -> assert creator's row shows Organizer
  (AC-1/AC-7 end-to-end). Matches the brief's AC-7 requirement structurally. PASS.
- **FLAG (structural gap, not run yet):** `create-group.spec.ts`'s `createGroup`-equivalent flow
  only fills the `Title`/label field before calling `completeWizard()` — it does NOT fill
  `field_group_description`, unlike `manage-members.spec.ts`'s own `createGroup()` helper (which
  fills the CKEditor description field) and unlike `CreateGroupWizardOrganizerTest.php`'s own
  empirically-discovered requirement that `field_group_description` is REQUIRED on the real
  assembled `community_group` type. On a real seeded site this will likely stall at step 1 with a
  validation re-render (the same gap T-red's sibling Functional test hit and fixed). **U (or
  whoever next runs this spec against a seeded site) should expect one round of empirical
  correction here** — filling the description field (mirroring `manage-members.spec.ts`'s
  `.ck-editor__editable` pattern) is the likely fix. Not fixed in this phase per the task's explicit
  "review, do NOT run" instruction — flagging per T's remit rather than silently leaving it for a
  confusing E2E failure later.

## Acceptance criteria status

| # | Criterion | Status | Backing test |
|---|---|---|---|
| AC-1 | Additive Organizer grant (Admin + Organizer both present) | PASS | `CreateGroupWizardOrganizerTest::testWizardCreateGrantsOrganizerAndRedirectsToPreview` (real wizard) + `CreateGroupOrganizerHookTest::testInsertHookGrantsOrganizerToCreatorMembership` (Kernel) + `EnsureRoleTest` (Unit) |
| AC-2 | Creator can edit group + administer members immediately | PASS | `CreateGroupWizardOrganizerTest` (`hasPermission` assertions) |
| AC-3 | Final submit redirects to `/group/{group}/created`, not canonical | PASS | `CreateGroupWizardOrganizerTest` (`assertSession()->addressEquals(...)`) |
| AC-4 | Guided-preview renders confirmation + Organizer statement + 3 CTAs | PASS | `GroupCreatedPreviewControllerTest::testPreviewPageRendersForOwner` |
| AC-5 | WCAG heading structure / descriptive links / no new colors | PASS | Same test (h1/h2/no-h3+, DOM order, link-text assertions) + manual review of `create-group.css` (spacing/list-reset only, no hex values) |
| AC-6 | Existing suite stays green (#36, #121, #138) | PASS | Full combined run (82/82) + #36 pair standalone (3/3) |
| AC-7 | E2E walks login -> create -> preview -> manage-members -> Organizer confirmed | STRUCTURALLY SOUND, NOT RUN | `tests/e2e/create-group.spec.ts` reviewed; flagged missing-description-field gap for whoever runs it next |
| AC-8 | Non-owner membership in same request does NOT get Organizer | PASS | `CreateGroupOrganizerHookTest::testInsertHookDoesNotGrantOrganizerToNonOwnerMembership` |

## Blocking issues

None.

## Advisory notes

- The E2E spec's missing `field_group_description` fill (flagged above) is not a blocker for this
  phase (T was instructed to review, not run/fix, the E2E spec) but should be addressed before or
  during U's walkthrough to avoid a confusing first-run failure.
- `phpcs --standard=Drupal,DrupalPractice` on the two changed/fixed TEST files (not required by
  this story's mandatory verification-commands list, which scopes lint to the 3 production PHP
  files + 3 YAML files) shows pre-existing docblock-style findings in both — all confirmed
  pre-existing (present in the T-red commit before this phase's edits) via
  `git show HEAD:<path>` comparison, not introduced by T-green's one-line `$modules` fix. Not
  fixed, per this phase's "SOLE authoring change should be the $modules fix" scope.
- `.ddev/config.yaml`'s tracked name (`pl-groups-on-d11`) collides with the primary checkout's own
  DDEV project name — this phase worked around it locally (session-only rename to
  `gm144-auto-organizer`, reverted before finishing) rather than committing a rename, since O
  handles staging and no naming-convention change to this tracked file was requested. Future
  phases touching this worktree will hit the same collision and should repeat the same
  rename-run-revert pattern (or O may want to make the rename permanent — flagging for O's
  discretion, not a blocker).

## Verdict: GREEN
