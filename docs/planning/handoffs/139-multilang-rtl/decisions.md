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

## Phase 6 — T (green round 2, post-F-fix): GREEN

**Verdict**: GREEN (Tier 1 + Tier 2, ready for A-dup).

**Kernel**: 107/107 (batched-by-module), 0 failures.
**Config:import**: clean on fresh `drush site:install --existing-config` + explicit `config:import -y` (Bug 1 confirmed fixed).
**Playwright**: **3/3 pass** (RTL + LTR + directory column — the round-1 failure now passes).
**Live-site DOM**:
- `drush ev "print \Drupal::languageManager()->getLanguage('ar')->getDirection();"` → `rtl` (Bug 3 fixed from clean-room seed).
- `/all-groups` cards emit `<span class="do-group-language gc-badge" lang="ar" dir="rtl">Arabic` (plus fr/de); English-primary groups get no badge (site-default suppression working on the directory too).
- `/group/{ar}` emits `<html lang="ar" dir="rtl">` and the entity-view indicator span.

**Anti-duplication pre-check (advisory for A-dup)**
- `GroupLanguageIndicatorHooks::resolveDisplayLanguage()` at lines 107-135
  is the single point of decision for all four suppression branches.
- `entityView()` calls only `self::resolveDisplayLanguage($entity)`.
- `groups_chrome_preprocess_views_view_fields__all_groups()` calls only
  `GroupLanguageIndicatorHooks::resolveDisplayLanguage($group)`.
- Grep of theme file for `und`/`zxx`/`isEmpty()`/`getDefaultLanguage()`
  against `field_group_language` — zero copy-pasted branches. Clean.

**Advisories carried forward (out-of-story-scope, for follow-up)**
- DDEV `SIMPLETEST_DB` must be `mysql://db:db@db:3306/db` (internal
  hostname), not `127.0.0.1` — worth adding to project conventions
  alongside the batched-kernel-invocation note.
- `seed-site.sh` admin-wrap gap: freshly seeded groups land with
  `status=0` because runbook scripts aren't wrapped in the seed-as-admin
  pattern CI's workflow uses. Documented `UPDATE groups_field_data SET
  status=1` workaround is the current mitigation. Follow-up issue
  recommended so this stops recurring every verification cycle.
- `seed-site.sh` does NOT call `step_640.php` — language install
  requires manual invocation. Fresh seed leaves `ar` uninstalled
  (correctly suppressed by hook, not a crash). Same follow-up.
- U should visually verify pill placement + RTL margin/padding flips
  in `group-language.css` — DOM tests don't cover visual correctness.

---

## Phase 7 — A-dup (anti-duplication gate): PASS

**Verdict**: PASS. Two advisories, zero blockers.

**Explicit confirmation**: `resolveDisplayLanguage()` cleanly resolves
the intra-story duplication risk. Both call sites delegate the full
four-branch decision and identically treat NULL as suppress. Neither
site re-checks sentinels or `isEmpty()`. Grep-verified.

**Parallel-path check**: clean.
- Hook shape mirrors do_chrome/PermissionMatrixPanel (`#[Hook('entity_view')]`).
- Directory-card extension is additive within existing
  `groups_chrome_preprocess_views_view_fields__all_groups()`.
- Badge markup lives in existing custom row template (no new override).
- Pointer-stub `.module` matches project idiom.
- `step_640` `createFromLangcode()` aligns with core's own LanguageAddForm.
- `step_760` Arabic seed mirrors fr/de + step_700 admin-membership patterns.

**Advisories (captured as follow-up, NOT blockers)**
1. Pre-existing `LanguageNegotiationGroup::getLangcode()` inlines its own
   sentinel + `isEmpty()` checks. Latent third call site of duplicated
   decision logic. F correctly did NOT touch (out of story scope,
   parallel-agent-coexistence discipline). Follow-up story: promote
   `resolveDisplayLanguage()` (or lean `resolveLangcode()` variant) so
   negotiation delegates too.
2. Views preprocess badge doesn't attach explicit
   `languages:language_interface` cache context or entity language
   cache tags. Consistent with sibling badges in same preprocess
   (all rely on Views row cacheability). Optional hardening: push
   tags/contexts through `$variables['#cache']`.

**Actions (O)**
- Advance to U (playwright-ui-walkthrough).

---

## Phase 6 — U (playwright-ui-walkthrough): REWORK

**Verdict**: REWORK. Story's own UI is 100% correct at DOM/CSS/WCAG level (all 5 scenarios pass,
badges render, RTL propagates, English suppresses, contrast 5.1:1), but 126 raw-looking
`Warning: Undefined array key "source"` messages from Drupal core's `LanguageNegotiationUrl`/
`LanguageNegotiationUrlFallback` render as a black error-message panel above the fold on every
page, including `/user/login` where no group content is involved. Not caused by
`do_group_language`, but #139 is the first story to meaningfully exercise multi-language URL
negotiation in this seeded environment, so it is what surfaces the noise. Routed to F for a
narrow, scoped-tight banner-suppression fix (not a core patch).

---

## Phase 6 — F (round 3, narrow banner fix): GREEN for CI/portable fix; BLOCKED on this
## DDEV container specifically (environment-artifact finding, not a code defect)

**Verdict**: Option 1 landed cleanly (config-only, zero import friction, zero kernel regression).
**However**, a deep root-cause trace proved the banner's actual on-screen gate in this specific
DDEV container is **not** `system.logging.error_level` at all — it is a hardcoded `verbose`
override in DDEV's own auto-generated, gitignored `web/sites/default/settings.ddev.php`, which
architecturally cannot be beaten by any config-storage, module, or runbook-script mechanism. Full
chain documented below with evidence at every link, and the finding is routed to O as a scoped
decision, not silently patched around.

**Root-cause chain (each link independently verified)**
1. `curl` reproduction confirmed 7 occurrences of the raw-looking warning banner on `/` before any
   change — matches U's report exactly (`<details class="error-with-backtrace">` markup, not a
   PHP-native uncaught-error dump).
2. Read `web/core/includes/errors.inc`: `_drupal_log_error()` → `error_displayable()` →
   `_drupal_get_error_level()` reads `\Drupal::config('system.logging')->get('error_level')`.
   `error_displayable()` returns `TRUE` (banner shown) whenever this resolves to `all` or
   `verbose`; the `<details class="error-with-backtrace">` wrapper specifically requires `verbose`
   (line 241/258 of `errors.inc`).
3. `config/sync/system.logging.yml` (the Phase-1 baseline, committed since the initial commit,
   confirmed via `git log`) already contains `error_level: hide`. `drush config:get
   system.logging error_level` on the live DB also read `hide`. Yet the banner persisted — meaning
   the *stored* value was never the actual gate.
4. `drush ev "print \Drupal::config('system.logging')->get('error_level');"` — the live API call
   `_drupal_get_error_level()` actually uses — returned `verbose`, not `hide`. This is the
   smoking gun: something is overriding the stored config at read time.
5. Found it: `web/sites/default/settings.ddev.php` line 58 —
   `$config['system.logging']['error_level'] = 'verbose';` — a DDEV-generated, boilerplate
   "Enable verbose logging for errors" convenience line (linked to a public drupal.org forum post
   in its own comment), present identically in the **main checkout's** `settings.ddev.php` too
   (confirmed via a second `grep` there) — i.e. universal DDEV Drupal-10-recipe scaffolding for
   this whole codebase, not a one-off artifact of this worktree.
6. Read `web/core/lib/Drupal/Core/Config/ConfigFactory.php` (`doGet()`, lines 103-122):
   `$GLOBALS['config'][$name]` (exactly what `settings.ddev.php`'s `$config[...]` assignment
   populates) is applied via `Config::setSettingsOverride()` on every single immutable-config
   fetch — unconditionally, with no caching/skip path.
7. Read `web/core/lib/Drupal/Core/Config/Config.php` (lines 157-161): `moduleOverrides`
   (`hook_config_factory_override`, the only module-level override mechanism Drupal offers) is
   merged **first**, then `settingsOverrides` is merged **on top** via
   `NestedArray::mergeDeepArray()` — meaning a `settings.php`-scoped override always wins over a
   module-level override too. There is no config-level, module-level, or one-shot
   drush-script-level lever that can beat it; `settings.ddev.php` re-applies fresh on literally
   every PHP process bootstrap (web or CLI), so nothing done at drush-invocation time leaves a
   residue a subsequent web request would see differently.
8. **Live experiment (non-destructive, immediately reverted)**: backed up `settings.ddev.php`,
   temporarily commented out line 58, ran `drush cr`, re-checked
   `\Drupal::config('system.logging')->get('error_level')` → now correctly read `hide`, and the
   homepage banner count dropped from 7 → **0**. Restored the file immediately; re-confirmed the
   banner returns once the override is back (git status showed the file as untracked throughout,
   confirming zero residual diff from the experiment). This conclusively proves the theory rather
   than leaving it as a hypothesis.
9. Checked `settings.php` for a `settings.local.php` include (Drupal core's own sanctioned
   override-layering mechanism, meant to run after `settings.ddev.php`): the include block exists
   in the scaffold but is commented out (`#`, never enabled) in this project, and both
   `settings.php` and `settings.local.php` are gitignored, per-environment-provisioned files (not
   `docs/groups/`, not a runbook script, not shippable/trackable) — enabling it would be the same
   category of environment-local hack the issue explicitly steers away from for
   `.ddev/config.gm139.yaml`, and would still need re-provisioning identically on every future
   fresh reseed.
10. Checked CI (`.github/workflows/build.yml`, `.github/workflows/test.yml`): **zero** references
    to `ddev` in either workflow. CI runs `bash scripts/ci/assemble-config.sh` directly on a bare
    Ubuntu runner with a MySQL service container — no `settings.ddev.php` exists there at all, so
    this override plays no role in CI. The stored-config fix (Option 1) is therefore fully correct
    and sufficient for CI and for any non-DDEV clean-room reseed; the DDEV-only override is
    strictly a local-dev-convenience artifact layered on top in this container's own runbook-driven
    demo workflow.

**Decided**
- Took **Option 1**: created `docs/groups/config/system.logging.yml` with
  `error_level: hide` and the exact `_core.default_config_hash` that `drush config:export`
  already produces for the current (unchanged) active value — confirmed via
  `config.storage`'s live hash lookup before writing the file, so there is zero hash-mismatch risk
  on import. This makes the "hide errors on the demo site" requirement an explicit, story-owned,
  traceable config file rather than an implicit inherited fact from the Phase-1 baseline (which
  was already correct and had zero drift — `drush config:export --diff` showed `system.logging`
  absent from the diff both before and after).
- Did **not** create Option 2 (a runbook step doing `->set('error_level','hide')->save()`) —
  redundant with Option 1 once Option 1 was proven to assemble/import cleanly, and a runbook script
  would be equally unable to beat the `settings.ddev.php` override anyway (see chain step 7).
- Did **not** edit `.ddev/config.gm139.yaml`, `settings.ddev.php`, `settings.php`, or any core file
  — all out of scope per the task's explicit constraints, confirmed via `git status`/`git diff`
  showing zero residual changes to any of them after the diagnostic experiment was reverted.
- Removed 5 stale, untracked `config/sync/language.content_settings.node.*.yml` files (confirmed
  via `git status`/`git ls-files` to be leftovers from a prior ad hoc `config:export` in this
  worktree, not part of `docs/groups/config/`'s curated set, not part of `assemble-config.sh`'s
  copy source, and not part of the Phase-1 tracked baseline) — they were breaking `config:import`
  with an unrelated `target_entity_type_id` error that had nothing to do with this fix, and would
  not exist in a genuine CI clean-room checkout.

**Assumed**
- "The demo site" in the task's own framing is this project's standing DDEV-based runbook/demo
  workflow (every `docs/groups/scripts/step_*.php` file's own docblock says `Usage: ddev drush
  php:script ...`, and `seed-site.sh`/`seed-step2.sh`/`seed-step3.sh` all assume a DDEV container)
  — so the DDEV-specific override, while out of my edit scope, is a materially relevant fact for
  O to weigh, not a tangential curiosity.

**Hedged / flagged for O (not fixed here — architecturally unfixable within config/runbook scope)**
- **This container's on-screen banner will NOT disappear from Option 1 alone**, because
  `settings.ddev.php`'s hardcoded `verbose` override always wins over stored config, and that file
  is DDEV-generated, gitignored, identical across every DDEV instance of this codebase (confirmed
  in both this worktree and the main checkout), and out of scope to touch per the task's own
  constraints (which explicitly forbid the sibling `.ddev/config.gm139.yaml` file and, by the same
  reasoning, any equivalent environment-local override). This is not a fragility I failed to route
  around — it is a hard Drupal config-override precedence rule (`settings.php`-scoped overrides are
  deliberately final and unbeatable from config storage or module code; see chain steps 6-7).
  Recommend one of: (a) accept that this specific symptom is DDEV-local-only and does not affect
  CI/production, since Option 1 is correct and sufficient there; (b) a follow-up story/runbook step
  to provision a tracked `settings.local.php` (enabling the currently-commented-out include in
  `settings.php`) that re-asserts `hide` after `settings.ddev.php` runs, if a banner-free *local
  demo* view is required; (c) something else O prefers. Not resolved unilaterally because it
  requires touching files this task explicitly scoped out, and a scope call, not a code call.

**Verification (Tier 1)**
- `assemble-config.sh` (via `ddev exec`, since `php` is not on this Windows host's PATH — CI runs
  it bare on Ubuntu where `php` is native): exit 0, **96 config files** copied (up from 95 in
  F-round-2, confirming the new file is included), 13 modules, core.extension patched.
- Fresh clean-room reseed: `drush sql:drop -y` + `bash seed-site.sh` (full site:install →
  config:import → module enable → demo data → step_760) → `SEED_COMPLETE`. Then explicit
  `step_640.php` (per task instruction) + `UPDATE groups_field_data SET status=1` (per the
  existing decisions.md advisory) applied exactly as directed.
- `drush config:status`: zero drift on `system.logging` (exact-match grep for the config name
  returns nothing) — confirms clean, friction-free import of the new file.
- Effective vs. stored config, post-reseed: `drush config:get system.logging error_level` → `hide`
  (my fix, correct); `drush ev "print \Drupal::config('system.logging')->get('error_level');"` →
  `verbose` (the DDEV override, confirming it is still the active gate on this container even
  after a genuinely fresh reseed with my fix applied).
- `curl` banner counts on this container, post-fix, post-fresh-reseed:
  `/` = 7, `/all-groups` = 7, `/group/9` = 10 (all nonzero — expected, given the chain above; not a
  regression from my change, since the pre-fix baseline was also nonzero for the identical reason).
- Badge spot-check on `/all-groups`, post-fresh-reseed: `ar`/`de`/`fr` all present and correct
  (`lang="ar" dir="rtl">Arabic`, `lang="de" dir="ltr">German`, `lang="fr" dir="ltr">French`) —
  story's own UI unaffected by this round's change, matching every prior GREEN checkpoint.
- Kernel, full suite, batched by module (same groupings as every prior round): batch 1 (do_tests,
  do_streams, do_notifications, do_group_pin) 48/48; batch 2 (do_profile_stats, do_group_mission,
  do_group_extras, do_discovery) 28/28; batch 3 (do_group_membership, do_multigroup) 18/18;
  do_group_language 13/13. **Total 107/107, 0 failures** (all four batches reported "OK, but there
  were issues!" — meaning 0 failures/errors, only pre-existing Drupal 11.2
  `getOriginal()`/Twig-sandbox deprecation notices, identical in kind to every prior round's
  batches). Confirms the config-only change introduced zero regressions.
- Lint: zero PHP files touched this round (the only change is a YAML config file), so
  `phpcs --standard=Drupal,DrupalPractice` has nothing new to check; ran it against the new file
  path anyway per the protocol and it produced no output (YAML is not phpcs-lintable, confirming
  vacuous pass).

**Files changed**
- `docs/groups/config/system.logging.yml` (new — the only file created or modified this round)

**Files explicitly NOT touched (confirmed via `git status`/`git diff`)**
- `.ddev/config.gm139.yaml` — untouched, per constraint.
- `web/sites/default/settings.ddev.php` — restored to its exact original content after the
  diagnostic experiment; confirmed zero residual diff (file is untracked/gitignored so `git status`
  shows nothing either way, but content was byte-compared against the pre-experiment backup).
- All of F-round-2's shipped deliverables (`GroupLanguageIndicatorHooks.php`,
  `groups_chrome.theme`, the Twig template, the CSS, `step_760.php`,
  `views.view.all_groups.yml`) — zero diffs, per constraint.

**Evidence**
- Live curl/drush transcripts throughout the root-cause chain above (this handoff's F-round-3
  chat transcript has the full command-by-command trace).

**Action (routed to O)**
- Decide the scope call in the "Hedged / flagged for O" section above (accept as DDEV-local-only /
  spin up `settings.local.php` provisioning as a follow-up / other). Option 1 is landed and correct
  either way — it is not blocked on that decision, only this specific container's on-screen
  symptom is.

---

## Phase 6 — T-quickcheck round 3 (post-F-r3 banner-fix): GREEN

**Verdict**: no regression.
- Kernel 141/141 across batched invocation (baseline grew from 107 as
  sibling wave stories added tests; grouping unchanged).
- Playwright 3/3 pass (rerun via `BASE_URL=https://gm139-multilang-rtl.ddev.site`).
- `system.logging` config imports with zero drift.
- Pre-existing container drift (`language.content_settings.node.*` "only
  in DB") persists — same class F-r2 flagged; NOT caused by F-r3.

**Env note (project convention advisory)**
- `SIMPLETEST_DB` must be inline `mysql://db:db@db:3306/db` for kernel
  runs in DDEV (T-quickcheck round 3 confirms T-green-2's earlier
  advisory). Not a persistent container env var.

**Actions (O)**
- Advance to U-rerun (must prove clean-room state, not gm139 banner).

---

## Phase 8 — U (UI walkthrough, round 2 with DDEV neutralized): PASS

**Verdict**: PASS on shipped state.

**Method**: temporarily commented `web/sites/default/settings.ddev.php:58`
(the DDEV-only `system.logging = verbose` override) to reproduce what
CI + prod see; ran full 6-scenario walkthrough; restored file byte-identical
after; final sanity-curl confirmed banner returns on gm139 local (proving
restore worked and confirming the DDEV-only nature).

**Sanity-curl `Undefined array key` counts on /**:
- Before neutralization: 126 (DDEV baseline).
- After neutralization: 0 (CI/prod state — banner absent).
- After cleanup: 136 (restored DDEV baseline, minor variance).

**All 5 UI scenarios pass** on the neutralized state: badges present with
correct lang/dir on /all-groups (ar/fr/de) and full-view group pages;
`<html dir="rtl">` on Arabic group; English groups get no badge
(site-default suppression); no console errors, no 404s, no banner in any
screenshot.

**Positive round-1 note closed**: theme's top nav on RTL pages DOES
mirror (round-1's informational "LTR-anchored nav" was inaccurate).

**New informational (out of scope, brief v3)**: 360px + RTL viewport has
horizontal overflow that visually clips left-edge labels ("bout"/"Ionymous"
truncations). Theme-responsive characteristic, not `do_group_language`.
Same class as prior nav note.

**Cleanup verified**:
- `settings.ddev.php:58` byte-identical to original (grep confirms line
  reads `$config['system.logging']['error_level'] = 'verbose';` — no
  comment prefix, no residue).
- No throwaway scripts remain in worktree.
- `git status` diff attributable to this session: none. Pre-existing
  `config/sync/*` artifacts are `assemble-config.sh` history unrelated.

**Actions (O)**
- Advance to S (final spec audit).

---

## Phase 9 — S (spec audit): PASS

**Verdict**: PASS. All 7 acceptance criteria backed by tests, verified
against shipped (CI/prod-equivalent) state.

**Deviation ledger**: every departure from the issue's literal "Owns"
list is justified and journalled:
- Reuse of `field_group_language` (per issue's own extend/align clause).
- `hook_entity_view` (`full` view mode) + Views field + theme preprocess
  for the directory (per A-r1 BLOCK — teaser hook can't reach a
  row:fields view; per Bug 2 — custom row template ignores raw fields).
- CSS co-located in module (not subtheme) — better scoping.
- Additions beyond original list (system.logging.yml, step_640 fix,
  groups_chrome preprocess+template): all load-bearing on acceptance.

**Test quality**: PASS. `GroupLanguageIndicatorTest` has one test per
suppression branch with fixture-sanity guards. Playwright has one test
per acceptance surface (RTL / LTR / directory column). No smells.

**Non-goals verified**: extend-rather-than-duplicate satisfied. Grep
confirms zero re-implementations of the 4 suppression branches outside
`resolveDisplayLanguage()`.

**Documentation quality**: PASS. One pre-existing docblock typo noted
in `step_640.php` (path in usage comment is stale — was never touched
by #139). Non-blocking.

**Follow-ups recorded (non-blocking, in decisions.md)**
1. Promote `resolveDisplayLanguage()` to `LanguageNegotiationGroup`.
2. Cache-context hardening on Views badge.
3. `seed-site.sh` admin-wrap gap.
4. 360px + RTL horizontal overflow (theme).
5. DDEV settings.local.php (per coordinator ruling A — not required for #139).
6. Batched-kernel + SIMPLETEST_DB inline as project convention.

**Actions (O)**
- Push branch and open PR per overnight authorization with S's PR body
  draft (contains the mandated local-dev-caveat paragraph verbatim).

---
