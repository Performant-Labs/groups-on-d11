# Decision Journal — #139 MC-4 Multilingual baseline + RTL

Append-only. One entry per phase.

---

## Phase 1 — O (survey + brief)

**Decided**
- Reuse existing `field_group_language` (type=language) on
  `community_group`. Do NOT create `field_group_primary_language`. Story
  text mandates extend/align.
- Own the language-indicator render in `do_group_language` via
  `hook_entity_view` (no view-display YAML in `docs/groups/config/` today;
  adding one would race with the assembled site).
- Own CSS at `do_group_language/css/group-language.css` (co-located with
  owning module) rather than `do_chrome/css/`; equivalent, better scoped.
- Seed the RTL-primary group by appending to `step_760.php` (idempotent,
  the runbook step that already seeds fr/de groups).
- Use core `LanguageInterface::getDirection()` — never hardcode
  `dir="rtl"`.
- Review rigor: **none** (per issue text). Skip dual-review gates.

**Assumed**
- The assembled/seeded site's Full + teaser view displays render fields
  the module hook targets (view_mode == 'full' or 'teaser'). If the site
  is currently using a custom template that swallows extra render array
  keys, the hook needs a different injection point; T's kernel test will
  catch this before F ships.
- Arabic (`ar`) is installed as RTL in core — verified via `step_640.php`
  which adds `ar`, and Drupal core marks it RTL by default.

**Hedged**
- If `hook_entity_view` on `full`/`teaser` fails to reach the rendered
  page (e.g. minimal group template omits `#pre_render` output), fall
  back to a small `do_group_language_theme_suggestions_group()` +
  template override, or a preprocess. Decision deferred to F if T
  demonstrates the hook is invisible.

**Evidence**
- `docs/planning/handoffs/139-multilang-rtl/survey.md`
- `field.field.group.community_group.field_group_language.yml`
- `do_group_language/src/Plugin/LanguageNegotiation/LanguageNegotiationGroup.php`
- `scripts/step_760.php`, `scripts/step_640.php`

---

## Phase 3 — A (up-front plan review), round 1: BLOCK

**Decided (by A)**
- CONCUR with reuse of `field_group_language`. Creating
  `field_group_primary_language` would be an unjustified parallel path
  → A-dup BLOCK in Phase 7.
- CONCUR with `hook_entity_view` for the `full` view mode and CSS
  co-located in `do_group_language`.
- CONCUR with `getDirection()` API + mixed-direction nesting via `dir`
  on `<span>`.

**Blocked**
- Finding #1 (BLOCK): `views.view.all_groups.yml` line 128 is
  `row: type: fields`. `hook_entity_view` on view_mode `teaser` will
  never fire on `/all-groups`. The Playwright "teaser indicator on
  /all-groups" assertion is architecturally impossible under the
  proposed render approach. Resolution chosen: add `field_group_language`
  as a Views field to `all_groups` (strongest MC-3 forward-compat) and
  drop the `teaser` branch of `hook_entity_view`.

**Advisories rolled in**
- #4: null-language guard when `getLanguage($langcode)` returns NULL.
- #5: step_760 currently only sets language on pre-existing groups; it
  does NOT create groups. New pattern for that file needs explicit
  idempotency contract in the brief.
- #6: Kernel test must declare `field_group_language` as `type: language`
  (production shape), not `type: string` (which is what
  `GroupLanguageNegotiationTest` uses for narrower purposes).

**Actions (O)**
- Amended brief v2: full view mode only; new Views-field deliverable on
  `all_groups`; step_760 idempotency contract spelled out; Kernel test
  storage type pinned to `language`; null-language guard added to
  non-negotiables.
- Re-spawning A on the amended brief.

**Evidence**
- `views.view.all_groups.yml:128` (`row: type: fields`)
- `step_760.php:17-25` (sets language on pre-existing fr/de groups; no
  group creation in file today)
- `GroupLanguageNegotiationTest.php:63-67` (`type: string` for narrower
  test purposes)

---

## Phase 3 — A (up-front plan review), round 2: PASS

**Verdict**: PASS with two `warn` items (folded into brief v3).

**Warns folded**
- Views `language` formatter emits the language *name*, not the raw
  langcode. Playwright directory assertion rewritten against the name
  (`Arabic` / `French` for anonymous English UI).
- Views-field YAML must mirror sibling views' full key shape
  (relationship, group_type, table, field, entity_type, plugin_id, type,
  label, settings.link_to_entity: false). Concrete YAML block now in
  the brief.
- Bonus advisory: copy step_700's `addMember($admin_user,
  ['group_roles' => ['community_group-admin']])` pattern so the new
  Arabic group is testable under Group access policies.

**Decided**
- Advance to T(red) on brief v3. No further A pre-code cycles required.

**Evidence**
- `views.view.group_content_stream.yml`, `views.view.group_members.yml`,
  `views.view.group_nodes.yml` (Views-field key shape)
- `step_700_demo_data.php:77-93` (community_group creation + admin
  membership pattern)

---
## Phase 4 — T (author RED)

**Decided**
- Authored `GroupLanguageIndicatorTest.php` (6 methods) exactly per brief's render/assertion
  contract (view builder `full` mode, raw HTML string assertions on `class`/`lang`/`dir`),
  declaring `field_group_language` as `type: language` per the non-negotiable.
- Authored `group-language.spec.ts` (3 tests) resolving group paths dynamically from
  `/all-groups` (no hardcoded gid), asserting `html[dir]` + `.do-group-language[lang]` for the
  ar/fr cases and the Views language-name column for the directory case.
- Did not run the Playwright spec live against a site at T-red (no seed/hook/Views-field exist
  yet) — verified via `--list` only, per task instructions.

**Assumed**
- The shared DDEV project `pl-groups-on-d11` is mutagen-synced from the main checkout only; this
  worktree needed its own isolated DDEV instance (`gm139-multilang-rtl`) to run PHPUnit/Playwright
  at all. Confirmed via `docker inspect` bind-mount list before spinning one up (namespaced per
  the wave-execution guardrail on container hygiene).
- The brief's literal `phpcs docs/groups/modules/do_group_language` (no `--standard` flag) isn't
  the real lint gate — it falls back to phpcs's PEAR default and flags the pre-existing sibling
  test identically. Used `--standard=Drupal,DrupalPractice` (the project's actual installed
  standard) instead; the new file is clean against it.

**Hedged**
- None — RED confirmed valid on the first authored draft (after two small phpcs fixes: a missing
  `use` import and a doc-comment capitalization).

**Evidence**
- Kernel run: `Tests: 6, Assertions: 135, Failures: 2` — the 2 failures are the positive-assertion
  tests (`testRendersRtlIndicatorForArPrimaryGroup`, `testRendersLtrIndicatorForFrPrimaryGroup`),
  failing on the missing `do-group-language` markup, not a setup/import error.
- Full kernel suite: `Tests: 106, Assertions: 2913, Failures: 2` — only our 2 new RED tests fail;
  all 104 other kernel tests (including `GroupLanguageNegotiationTest`) remain green.
- Playwright `--list`: 3/3 test names resolve with no parse errors.
- `docs/handoffs/139-multilang-rtl/handoff-T-red.md` (full detail).

## Phase 6 — F (implement against T-red)

**Verdict**: GREEN (Tier 1). Indicator suite 6/6; full kernel 106/106; lint clean.

**Deliverables landed**
- `do_group_language.module` (pointer stub, matching sibling wave modules)
- `do_group_language/src/Hook/GroupLanguageIndicatorHooks.php`
  (OO `#[Hook('entity_view')]` — Deviation #1, accepted by O)
- `do_group_language/do_group_language.libraries.yml`
- `do_group_language/css/group-language.css`
- `views.view.all_groups.yml` (append Language column)
- `scripts/step_760.php` (append Drupal العربية + Arabic topics)

**Deviations accepted by O**
- **Deviation #1 — OO `#[Hook]` vs procedural `.module`**: F followed the
  established project convention (sibling modules `do_group_pin`,
  `do_streams`, and `do_chrome/PermissionMatrixPanel` all use
  `#[Hook('entity_view')]` in `src/Hook/*Hooks.php`, auto-discovered by
  Drupal 11.1 HookCollectorPass). Render/cache/suppression contract is
  identical to the brief. Accept — extending the analogous pattern is
  correct.
- **Deviation #2 — site-default-language suppression guard, unspecified
  in brief**: F discovered `type: language` fields cannot be truly
  empty (core `LanguageItem::applyDefaultValue()` back-fills the site
  default `en` unconditionally on `Group::create()` without the field).
  Without the guard, every pre-existing English group would suddenly
  render an "English" pill — UX regression. F added: suppress when
  resolved langcode equals `\Drupal::languageManager()->getDefaultLanguage()->getId()`.
  Verified compatibility with acceptance: `ar` ≠ `en` (Arabic group
  still emits RTL indicator); `fr`/`de` ≠ `en` (still emit). Accept.

**Flagged for T (routed by O)**
- `testNoIndicatorWhenFieldEmpty` docblock now describes a different
  semantic than what it pins. `createGroup()` with no `field_group_language`
  key back-fills to `en`, so the test passes via the new site-default
  guard rather than the "field empty" branch. T should rename/refactor
  the test at T-green to reflect the actual invariant (e.g. split into
  "no indicator when langcode equals site default" + a new test that
  hits the empty-value branch explicitly, or rename the existing test).

**Deferred to T-green**
- Full RUNBOOK site install + reseed + Playwright live run. F confirmed
  syntactic validity of step_760.php (`php -l` clean) and validated the
  create/addMember/set/save/addNode/addRelationship sequence via a
  throwaway Kernel smoke test (29 assertions, 0 failures, then deleted).

**Notes for T**
- Playwright helper `resolveGroupPath()` should work as written — the
  view's label field with `link_to_entity: true` renders `<a>` inside
  the row exactly matching the helper's `getByRole('link', {name, exact:true})`.
- Directory column: Views language formatter emits `getName()`, so
  assertions on `Arabic`/`French` will hold.
- `getMember()` returns `GroupMembership|FALSE`, not `NULL`.

---

## Phase 6 — T (verify GREEN + Tier 2)

**Verdict**: BLOCKED. Kernel suite is fully green (107/107, including the reconciled
`do_group_language` indicator suite) and F's hook logic is independently confirmed CORRECT via a
live seeded site — but two real production bugs prevent full Playwright GREEN, and neither is
fixable within T's edit scope.

**Decided**
- Task 2 reconciliation: took **Option A**. Renamed `testNoIndicatorWhenFieldEmpty` →
  `testNoIndicatorWhenLangcodeIsSiteDefault` (docblock corrected to describe the actual
  site-default suppression branch it pins). Added a new test,
  `testNoIndicatorWhenFieldIsTrulyUnset`, that forces a genuinely empty field via
  `->set('field_group_language', [])->save()` on an already-saved group — confirmed this reliably
  produces `isEmpty() === TRUE` (bypasses `LanguageItem::applyDefaultValue()`, which only fires at
  `create()`-time). Suite is now 7 tests, 163 assertions, 0 failures.
- Stood up a real seeded site (fresh `drush site:install` + `config:import` + full demo-data +
  step_760 seed) inside the isolated `gm139-multilang-rtl` DDEV container to run live Playwright,
  per the deferred-to-T-green task.
- Ran the full kernel suite in module-grouped batches rather than one monolithic
  `find | xargs phpunit` invocation — the brief's literal command took 45+ minutes and was only
  72% done when killed (10 test classes force `#[RunTestsInSeparateProcesses]`, each forking a
  fresh PHP process + full bootstrap). Batched runs reached the identical 107/107 green result
  (0 failures anywhere) in a few minutes total, verified by summing `Tests:`/`Assertions:` across
  all batches and cross-checking against a full per-module test-method count (107, matching the
  T-red 106 baseline + 1 new test).

**Blocked — Bug #1 (F's file, real, fixable by F)**
- `docs/groups/config/views.view.all_groups.yml` line 6: `dependencies.config` lists
  `group__field_group_language` (a *Views table name*), not the actual config entity ID
  `field.storage.group.field_group_language`. This makes a clean-room `config:import` fail
  outright ("depends on configuration that will not exist after import"). Confirmed root cause by
  locally correcting only the dependency entry (not the `table:` key at line 51, which correctly
  stays `group__field_group_language`) in the assembled copy — import then succeeds. T does not
  have write access to `docs/groups/config/`; routed back to F.

**Blocked — Bug #2 (scope/architecture gap, needs an O decision, not a T or F unilateral fix)**
- The `/all-groups` directory's row output is fully overridden by a pre-existing custom Twig
  template (`web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`,
  from story #84/CH-A2) that only prints a curated `gc_directory.*` variable set assembled in
  `groups_chrome.theme`'s preprocess function — it does NOT loop over the view's raw `fields`
  array. Adding `field_group_language` to the view's field list (what F did, correctly per the
  brief's literal instruction) is therefore invisible on the real rendered page — confirmed live,
  zero occurrences of the language text or field markup in the directory HTML. This file lives
  outside `docs/groups/` entirely, so it isn't one of this story's "disjoint files" and neither F
  nor T could touch it under the stated scope. A's plan review did not catch that this view has a
  custom row override that defeats a bare Views-field addition. Needs O to decide: amend brief to
  include a `groups_chrome.theme` + template change, or descope this criterion to a follow-up.

**Noted, not blocking (pre-existing infra bug, outside this story entirely)**
- `docs/groups/scripts/step_640.php` (baseline runbook script, git-blamed to the initial commit)
  creates configurable languages via `$storage->create(['id' => $langcode])->save()`, which does
  NOT populate `direction`/`label` from Drupal's predefined-language data — `ar` resolves to
  `direction: ltr` on a fresh seed, silently invalidating the brief's Phase-1 "Assumed" that
  "Arabic is RTL by default." Manually corrected on the live seeded DB (not committed) to confirm
  F's hook is correct once language metadata is right: `ar` → `dir="rtl"` on both `<html>` and the
  indicator, `fr` → `dir="ltr"`, both confirmed via live curl + Playwright. Flagged to O as a
  separate pre-existing bug; not fixed here (`step_640.php` is untouched by this story).

**Evidence**
- Kernel: 107/107 green (7+6 do_group_language, 48+28+18 other modules), 0 `✘` markers in any
  batch log.
- Playwright: 2/3 pass (`RTL Arabic group renders dir="rtl"`, `LTR French group renders dir="ltr"`
  both green against the live seeded+corrected site); 1/3 fails
  (`directory /all-groups shows language column`) for the diagnosed Bug #2, not a spec defect.
- `docs/handoffs/139-multilang-rtl/handoff-T-green.md` (full detail, repro steps for both bugs).

**Action (routed to O)**
- T-green found blocking issues. F must fix Bug #1 (`views.view.all_groups.yml` dependency).
  O must decide Bug #2's scope (amend brief vs. follow-up issue). Re-run A if the brief/scope
  changes, then T again, before U/S can proceed.

---

## Phase 6 — T (green + Tier 2): BLOCKED with 3 findings

**Kernel**: 107/107 green (added 1 test via Option A refactor of the
semantically-drifted `testNoIndicatorWhenFieldEmpty`).

**Option A taken** (semantic-drift reconciliation): renamed to
`testNoIndicatorWhenLangcodeIsSiteDefault` + added new
`testNoIndicatorWhenFieldIsTrulyUnset` that bypasses
`LanguageItem::applyDefaultValue()` via post-`save()` `->set([])`. Both
tests confirmed to pin distinct suppression branches via inline
fixture-sanity assertions. Added class-level suppression-branches
comment cross-referencing each branch to its pinning test.

**Playwright**: 2/3 (RTL/LTR pass; directory column fails).

**Bugs surfaced (all routed to F, accepted in-scope by O)**
1. `views.view.all_groups.yml` dependencies.config — `group__field_group_language`
   (Views table name) should be `field.storage.group.field_group_language`
   (config entity ID). Breaks clean-room config:import.
2. `/all-groups` custom row template (`web/themes/custom/groups_chrome/`)
   doesn't loop over Views fields — the new Views field addition has
   zero visible effect. In-scope fix: extend
   `groups_chrome.theme` preprocess + template to emit
   `.do-group-language` badge from resolved `gc_directory.language_*`
   keys. Consider a shared trait/service with `GroupLanguageIndicatorHooks`
   to prevent A-dup BLOCK from duplicated resolve-and-suppress logic.
3. `step_640.php` uses `$storage->create(['id' => $langcode])` instead
   of `ConfigurableLanguage::createFromLangcode()`. `direction` never
   populated → `ar` gets LTR silently → RTL acceptance can't be verified
   against clean-room seed without T's manual DB patch. Ships as
   non-reproducible. Fix: `createFromLangcode()` API.

**Also validated**: F's hook is entirely correct (T proved by manually
patching the language config on the live DB — RTL/LTR indicators both
render as intended once language metadata is right).

**Actions (O)**
- Brief v4 appended with all three bug specs + shared-logic guidance
  for Bug #2 (avoid A-dup BLOCK).
- Phase order updated: O-triage → F-fix → T(green-2) → A-dup → U → S.
- Spawning F for the three-bug fix.

**Advisory (future T)**
- `#[RunTestsInSeparateProcesses]` × 10 classes makes the monolithic
  `find | xargs phpunit` invocation impractical (45+ min, killed).
  Batched-by-module invocation is functionally equivalent and finishes
  in minutes. Adopt as project default.

**Evidence**
- `docs/planning/handoffs/139-multilang-rtl/handoff-T-green.md`

---

## Phase 6 — F (three-bug fix, round 2)

**Verdict**: GREEN (Tier 1). All three bugs fixed and verified against a live fresh reseed. 107/107
kernel, lint clean on all touched code, config:import repro succeeds, Bug 2 badge + Bug 3 direction
both confirmed via live curl/drush.

**Bug 1 fix** — `docs/groups/config/views.view.all_groups.yml`: `dependencies.config` entry
corrected from `group__field_group_language` (Views table name) to
`field.storage.group.field_group_language` (real config entity ID). `table:` key at line 51 left
untouched (`group__field_group_language` is correct there). One-line diff.

**Bug 2 fix** — extracted the four-branch suppression logic out of
`GroupLanguageIndicatorHooks::entityView()` into a new public static method,
`GroupLanguageIndicatorHooks::resolveDisplayLanguage(GroupInterface $group): ?LanguageInterface`,
per the brief's "Preferred" option (chosen over a separate service class — a `.theme` file has no
DI container access without a `\Drupal::service()` lookup, so a static method callable directly is
the lower-machinery fit for this project's existing `#[Hook]`-attribute / zero-`services.yml`
convention). `entityView()` now calls the shared helper and only builds the render array from its
result — behaviorally identical to the pre-refactor version (same four early-return conditions, same
order). `groups_chrome_preprocess_views_view_fields__all_groups()` (in
`web/themes/custom/groups_chrome/groups_chrome.theme`) calls the SAME helper and sets three new
`gc_directory` keys (`language_code`/`language_label`/`language_direction`), all `NULL` when the
helper returns `NULL`. The Twig template
(`views-view-fields--all-groups.html.twig`) emits `<span class="do-group-language gc-badge"
lang="{{ code }}" dir="{{ direction }}">{{ label }}</span>` guarded by
`{% if gc_directory.language_label %}`, exactly per the brief's markup spec, positioned after the
type/visibility badges.

**Bug 3 fix** — `docs/groups/scripts/step_640.php`: replaced
`$storage->create(["id" => $langcode])->save();` with
`\Drupal\language\Entity\ConfigurableLanguage::createFromLangcode($langcode)->save();` (added the
`use` import). Idempotency guard (`if (!$storage->load($langcode))`) unchanged.

**Assumed**
- The brief's Bug 1 verify step ("fresh reseed... `config:import -y` should succeed cleanly")
  meant a genuinely fresh `site:install`, not the long-lived, already-drifted live container T-green
  left behind (confirmed via `drush config:status` showing ~30 pre-existing `Different`/`Only in
  sync dir` entries unrelated to this story before I reseeded). Did a full fresh
  `drush site:install` + reseed via the container's own `seed-site.sh` to get a clean baseline for
  both the config:import repro and the Bug 2/3 live-site checks.
- `docs/groups/scripts/step_640.php` is NOT part of `seed-site.sh`'s own sequence (confirmed —
  `seed-site.sh`/`seed-step2.sh`/`seed-step3.sh` never call it), so a bare fresh reseed leaves `ar`
  as an uninstalled langcode (correctly suppressed by the null-language guard, not a crash). Ran
  `step_640.php` explicitly via `drush php:script` afterward, per the task's own instruction #6, to
  produce the RTL-verifiable state.

**Hedged / worked around (not a fix to any of the 3 assigned bugs — flagged for O)**
- The untracked `seed-site.sh` (not part of `docs/groups/`, not one of my assigned files, git-status
  shows it as `??` — an artifact from an earlier phase, not authored by me) does not wrap the
  runbook scripts in the "seed-as-admin" pattern `.github/workflows/test.yml` uses (`$admin =
  User::load(1); \Drupal::currentUser()->setAccount($admin);` before each `require`). Without that
  wrap, `do_group_extras`' `entity_presave` hook sees the *anonymous* user during
  `drush php:script`, not uid 1, and unpublishes every freshly-seeded group (`status = 0`) —
  `/all-groups` rendered its empty state ("No groups yet") until I directly `UPDATE
  groups_field_data SET status = 1` on the 9 already-created groups (a verification-session-only
  DB write, not a code change, not committed, not touching any of my 5 assigned files). This is
  pre-existing environment-harness drift unrelated to any of the three bugs I was asked to fix —
  `seed-site.sh` simply never adopted the admin-wrapper pattern CI already documents at
  `.github/workflows/test.yml:486-488`. Flagging for O/T awareness in case a future fresh reseed of
  this container repeats the same "No groups yet" symptom; the fix (if wanted) is updating
  `seed-site.sh` to match CI's wrapper, which is out of my edit scope this round.

**Verification (Tier 1)**
- `assemble-config.sh`: exit 0, 95 config files, 13 custom modules registered.
- Kernel, do_group_language (both classes): 13/13 (7 `GroupLanguageIndicatorTest` + 6
  `GroupLanguageNegotiationTest`), 0 failures — confirmed the `resolveDisplayLanguage()` extraction
  did not change `GroupLanguageIndicatorTest`'s 7/7 result.
- Full kernel suite, batched (matching T-green's exact groupings): batch 1 (do_tests, do_streams,
  do_notifications, do_group_pin) 48/48; batch 2 (do_profile_stats, do_group_mission,
  do_group_extras, do_discovery) 28/28; batch 3 (do_group_membership, do_multigroup) 18/18. Plus
  do_group_language's 13. **Total 107/107, 0 failures**, matching T-green's baseline exactly (same
  Tests/Assertions counts per batch).
- Lint (`phpcs --standard=Drupal,DrupalPractice`) on all three touched PHP files: `GroupLanguageIndicatorHooks.php`
  and `groups_chrome.theme` fully clean (exit 0, zero output). `step_640.php` reports 21
  pre-existing errors + 1 warning — confirmed via git-diff against HEAD that these are 100%
  pre-existing (same error count/type as the untouched baseline, only shifted by the +7 lines my
  `use` statement + comment added); my added lines introduce zero new violations.
- `drush config:import -y` on the fresh reseed: `[success] The configuration was imported
  successfully.` — `views.view.all_groups` synchronized without the prior dependency error.
- Live site: `ar` language entity `direction: rtl`, `label: Arabic` (both `drush config:get
  language.entity.ar` and `getLanguage('ar')->getDirection()`/`getName()` confirm). `/all-groups`
  directory card for "Drupal العربية" (gid=9) shows `<span class="do-group-language gc-badge"
  lang="ar" dir="rtl">Arabic</span>`; "Drupal France" shows `lang="fr" dir="ltr">French</span>`;
  "Drupal Deutschland" shows `lang="de" dir="ltr">German</span>`. The group's own canonical page
  (`/group/9`) independently confirms the entity-view indicator renders identically
  (`lang="ar" dir="rtl">Arabic</span>`) and `<html lang="ar" dir="rtl">`, proving both call sites of
  the shared helper agree.

**Files changed**
- `docs/groups/config/views.view.all_groups.yml`
- `docs/groups/modules/do_group_language/src/Hook/GroupLanguageIndicatorHooks.php`
- `docs/groups/scripts/step_640.php`
- `web/themes/custom/groups_chrome/groups_chrome.theme` (outside `docs/groups/`)
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
  (outside `docs/groups/`)

**Evidence**
- Live curl/drush transcripts in this handoff's Tier 1 verification section above.
- `docs/planning/handoffs/139-multilang-rtl/handoff-F-fix-round2.md` (this phase's full handoff, if
  written to disk by O) — otherwise see the F agent's final chat response for the complete
  transcript.

**Action (routed to O / next: T green-2)**
- Ready for T(green-2): re-run kernel (should stay 107/107) + Playwright (should now be 3/3, since
  Bug 2's badge markup is live) + re-confirm the config:import repro + RTL acceptance end-to-end.
  Then A-dup (verify `resolveDisplayLanguage()` is the single point of decision, no copy-paste
  duplication between the hook and the theme preprocess) → U → S → PR (hold for human).

---
