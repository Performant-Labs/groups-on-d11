# Handoff-F: Phase 5 - #143 MC-5 Group archiving RESTORE action

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`)
**Issue:** #143

## What was done

- `docs/groups/modules/do_group_extras/do_group_extras.routing.yml` (new) — `do_group_extras.restore` route, `GET|POST /group/{group}/restore`, `_form: RestoreGroupForm`, `_custom_access: RestoreGroupAccess::access`, `{group}` upcast via `entity:group` param converter.
- `docs/groups/modules/do_group_extras/do_group_extras.links.task.yml` (new) — `do_group_extras.restore` local task, `base_route: entity.group.canonical`, `weight: 30` (after Members' 20).
- `docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php` (new) — `_custom_access` callback; `isArchived` gate + `'edit group'`/`'administer group'` perm expression per the A-r2-PASSed design; cacheability via `addCacheableDependency($group)->cachePerPermissions()->cachePerUser()` (no `url.path`, per brief).
- `docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php` (new) — `ConfirmFormBase`-derived confirm form; empty-vocab guard; target-type `<select>` defaulting to "Working group"; race-guard re-check in `submitForm`; try/catch save pattern mirroring `RemoveMemberForm`.

## Design decisions

- **Form-state key name: `group_type`, not `target_type_tid`.** My task brief specified the select/value key as `target_type_tid`, but T's authored Kernel test (`GroupRestoreTest.php` lines 177, 214, 241) sets and asserts the key `group_type` throughout — as the `FormState::setValue()` key in two tests and as the literal render-array key in `assertArrayNotHasKey('group_type', $built, ...)` in the empty-vocab test. Per my role ("implement against the failing tests — those tests are the contract"), I implemented using `group_type`. This is **not a T-issue** — the tests are internally consistent and correct; the discrepancy was in the paraphrased task brief I was given, not in T's work. No test edits were made.
- **`submitForm` cannot rely solely on `$this->group`.** T's Kernel test calls `$this->restoreForm()->buildForm(...)` and `$this->restoreForm()->submitForm(...)` as two separate calls, and `restoreForm()` constructs a **fresh** `RestoreGroupForm` instance each time (`RestoreGroupForm::create($this->container)`). This bypasses Drupal's real `FormBuilder`, which normally guarantees `buildForm()`/`submitForm()` run on the same instance. I stash the group id into `$form_state->set('restore_group_id', $group->id())` in `buildForm()` and have `submitForm()` fall back to reloading the group from storage via that id if `$this->group` is NULL, so the form is correct under both invocation patterns (real HTTP via `FormBuilder`, and direct two-instance test calls). This also incidentally strengthens the race-guard's "always reload fresh" posture.
- **Real `<button type="submit">` required a `#pre_render` override — the brief/A-r1's "no override needed" claim is factually wrong for this codebase.** Traced to source: `Submit extends Button`; `Button::getInfo()` sets `'#theme_wrappers' => ['input__submit']`; core's `input.html.twig` is `<input{{ attributes }} />` — a genuine self-closing `<input>`, not `<button>`. No theme in this project's vendored core (including Olivero) overrides this. `RemoveMemberForm`'s test suite never actually asserts the tag name (`grep` for `button[type=` and `elementExists.*button` in `do_group_membership/tests` returns zero hits) — so the "shipped GREEN in #138" claim in the brief was never actually verified by a real assertion; it was an unfounded but plausible-sounding assumption. Since T's tests (`GroupRestoreAccessTest::testConfirmFormRendersRealSubmitButton`, `testConfirmButtonAriaDescribedbyPointsToExistingId`) correctly assert `button[type="submit"]` per AC-4/AC-6, I fixed this in production code rather than treating it as a T-issue: `RestoreGroupForm` now implements `\Drupal\Core\Security\TrustedCallbackInterface` and overrides `$form['actions']['submit']['#pre_render']` to `[[Button::class, 'preRenderButton'], [self::class, 'preRenderAsButtonTag']]` — the first entry preserves core's normal attribute/class computation, the second (`preRenderAsButtonTag`, a new static method) converts the finished element into a hand-built `<button>…label…</button>` `#markup` string, wrapped in `Markup::create()` (required — otherwise `Renderer::ensureMarkupIsSafe()` runs the string through `Xss::filter()` against `Xss::getAdminTagList()`, which does not include `button` and strips the whole tag, leaving only bare text — reproduced and confirmed via an isolated debug Kernel test, not left as a guess). No new theme hook or template file was introduced; the fix is entirely local to `RestoreGroupForm.php` and reuses core's own computed attributes.
  - **Recommend correcting `decisions.md`'s A — Phase 3 entry** (line ~103–105: "`#type => 'submit'` renders `<button>`: CONFIRMED for Drupal 10/11 core... No override needed. #138 GREEN evidence supports.") — this is recorded as settled fact but is incorrect for this codebase's actual theme stack, and the "#138 GREEN evidence" cited doesn't exist (no assertion of the tag name in that suite). Future stories relying on this record should not assume `#type=>submit` alone yields `<button>`.
- **Term lookup:** `loadByProperties(['vid' => 'group_type'])` on `taxonomy_term` storage (returns full `Term` entities directly) rather than `loadTree()` (which, without `$load_entities = TRUE`, returns lightweight stdClass-like tree objects, not entities) — simpler and matches the fixture shape the tests construct with `Term::create()`.
- **Archive detection:** used `$term->label() === 'Archive'` per the pinned brief text (equivalent to `DoGroupExtrasHooks`'s `getName()` for `Term` entities — both resolve to the same underlying field value).

## Reuse / extend-vs-new

Extended `do_group_extras` (already owns archive enforcement via `DoGroupExtrasHooks`) with a new form (`RestoreGroupForm`, analogous to `do_group_membership`'s `RemoveMemberForm`) and a new access controller (`RestoreGroupAccess`, analogous to `ManageMembersController`) — exactly the brief's Reuse map recommendation. No new module, no parallel state field; `field_group_type` is the only state model touched, and only via reassignment (`$group->set('field_group_type', $tid)`), the same mechanism `step_720`'s widget already uses.

## Architecture notes for A

- New route + local task in `do_group_extras` (previously had neither — only hooks). No `.install` file needed: verified no `drupal/group` contrib optional-config collision exists at `/group/{group}/restore` (only `views.view.group_members.yml` collides with `do_group_membership`'s `/group/{group}/members`, an unrelated path).
- `RestoreGroupForm` now `implements TrustedCallbackInterface` (new for this module) — required by core's render-pipeline security check for any class supplying a `#pre_render` callback. This is a narrow, well-established core contract (one static method, `trustedCallbacks()`), not a new abstraction layer.
- No new services registered — `RestoreGroupForm`/`RestoreGroupAccess` follow the exact same DI pattern as `RemoveMemberForm`/`ManageMembersController` (constructor promotion + static `create()`/direct instantiation, no `services.yml` entry, matching how Drupal wires Forms and `_custom_access` string callbacks).
- No shared/other-agent-owned files touched. `DoGroupExtrasHooks.php` was read (for the Archive-detection idiom) but not modified.

## Deviations from spec / wireframe

1. **Form-state/select key name `group_type` instead of the task brief's `target_type_tid`** — see Design decisions above. Matches T's authored tests exactly; the wireframe's own mockup never named the internal key, only the visible label ("Set group type to"), so this is a naming-only deviation from the *task brief's paraphrase*, not from the wireframe or brief.md itself (neither names an internal key).
2. **Added a `#pre_render`/`TrustedCallbackInterface` override not mentioned in the brief or A's plan review** — necessary to actually satisfy AC-4/AC-6 and T's button-tag assertions, since core's default `#type=>submit` rendering does not produce `<button>` in this codebase (see Design decisions above for full evidence trail). No custom markup was invented beyond changing the wrapping tag of the confirm control; the wireframe's "no custom markup invented... core's native rendering" intent is honored in spirit (same attributes/classes/label core already computes) even though the literal claim "no override needed" turned out to be wrong.

## Tier 1 self-check (incl. tests now GREEN)

**Kernel** (`GroupRestoreTest.php`) — 4/4 pass:
```
Group Restore (Drupal\Tests\do_group_extras\Kernel\GroupRestore)
 ✔ Archived group preconditions
 ⚠ Submit restores archived group
 ✔ Submit is no op when group no longer archived
 ✔ Build form refuses when no non archive term exists

Tests: 4, Assertions: 146, Deprecations: 2.
```
(The `⚠` is the pre-existing `getOriginal()` core deprecation notice T's RED handoff already flagged as unrelated framework noise — not a failure.)

**Functional** (`GroupRestoreAccessTest.php`) — 10/10 pass:
```
Group Restore Access (Drupal\Tests\do_group_extras\Functional\GroupRestoreAccess)
 ⚠ Anonymous gets access denied
 ⚠ Unprivileged authenticated user gets access denied
 ⚠ Organizer can restore
 ⚠ Groups moderate can access restore
 ⚠ Site admin can access restore
 ⚠ Organizer gets access denied on non archived group
 ⚠ Site admin gets access denied on non archived group
 ⚠ Confirm form renders real submit button
 ⚠ Confirm button aria describedby points to existing id
 ⚠ Cancel link goes to group canonical

Tests: 10, Assertions: 59, Deprecations: 5, PHPUnit Deprecations: 11.
```
(All `⚠` are deprecation-only — Twig sandbox signature, `RunTestsInSeparateProcesses` attribute, `getOriginal()` — same pre-existing framework noise, zero `✘` failures.)

**Existing suite still green** (AC-7, `GroupExtrasBehaviorTest.php`) — 8/8 pass, unaffected by this change:
```
Tests: 8, Assertions: 305, Deprecations: 2.
```

**Combined final run** (all three files together): `Tests: 22, Assertions: 510, Deprecations: 6, PHPUnit Deprecations: 11.` Zero failures.

**phpcs** (`--standard=Drupal,DrupalPractice`) against my two production files only:
```
$ ddev exec 'php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php'
(no output — 0 errors, 0 warnings)
```
(Note: an unqualified `phpcs` run with no `--standard` flag falls back to phpcs's bundled `PEAR`/default standard, not Drupal's — this project has no root `phpcs.xml` pinning one. Ran with the explicit standard per instructions; also spot-checked that this flags zero issues in pre-existing sibling files that already shipped, confirming the standard choice is correct, not just convenient.)

**YAML lint** — both new YAML files parse cleanly via both PECL `yaml_parse_file()` and (more authoritatively, matching Drupal's own route/task builders) `Symfony\Component\Yaml\Yaml::parseFile()`; structure confirmed to match the intended route/task shape exactly (see raw parsed output captured during verification).

## Tests that look wrong (for T)

None. All three test files (`GroupRestoreTest.php`, `GroupRestoreAccessTest.php`, `group-restore.spec.ts`) are correct and internally consistent as written; T's `group_type` key-naming choice is what I implemented against (see Design decisions — this was a mismatch with my *task brief's* paraphrase, not a defect in T's tests).

## Known issues

None against the acceptance criteria covered by Kernel + Functional tiers (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7, AC-9, AC-10 all exercised and GREEN). AC-8 (e2e round-trip) and AC-11 (seed-step coordination) are out of my Tier 1 scope per the task brief — `tests/e2e/group-restore.spec.ts` was not run (per instructions, deferred to T-green/U against a live seeded site) and `step_700_demo_data.php` was not touched.

## Files changed

- `docs/groups/modules/do_group_extras/do_group_extras.routing.yml` (new)
- `docs/groups/modules/do_group_extras/do_group_extras.links.task.yml` (new)
- `docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php` (new)
- `docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php` (new)

No test files were created, edited, or deleted.
