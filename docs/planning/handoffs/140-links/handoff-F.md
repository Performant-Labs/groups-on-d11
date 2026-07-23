# Handoff-F: Phase 5 - #140 MC-1 Links & Resources field + rendering

**Date:** 2026-07-23
**Branch:** 140-links
**Issue:** #140

## What was done

- `docs/groups/config/field.storage.group.field_group_links.yml` (new) — `field_group_links`
  storage on the `group` entity type, type `link`, cardinality `-1`. Mirrors
  `field.storage.group.field_group_description.yml`'s shape.
- `docs/groups/config/field.field.group.community_group.field_group_links.yml` (new) — bundle
  instance on `community_group`. Label "Links & Resources", `required: false`, `translatable:
  true`. `settings.link_type: 17` (`LinkItemInterface::LINK_GENERIC` = internal + external, per
  core's `LinkItemInterface` bitmask: `LINK_INTERNAL=0x01`, `LINK_EXTERNAL=0x10`,
  `LINK_GENERIC=0x11=17`). `settings.title: 2` (`LinkTitleVisibility::Required`) — see "Deviations"
  below for why this differs from the task prompt's literal `title: 1`.
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` (edited) — added
  the `field_group_links` component, widget `link_default`, weight 4 (after image at weight 3).
  Existing components (description/image/visibility) and the `hidden:` block are untouched.
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` (new — no such
  file existed before this story) — the group Full display. `field_group_links` component at
  weight 20, formatter `link` with `trim_length: 80`, `url_only: false`, `url_plain: false`, `rel:
  noopener`, `target: _blank`, `label: above` (this is the section's H2 source — no template
  wrapper). Description=0, visibility=1, image=2, weight 10 reserved (commented) for #141 About.
  Header comment states section markers are cosmetic/source-tree-only per A's warn #4.
- `docs/groups/modules/do_group_extras/do_group_extras.info.yml` (edited) — added `drupal:link`
  to `dependencies`, alphabetized the whole 3-entry list (`group`, `link`, `taxonomy`).
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` (edited) — added a
  `group-links` library (`css/group-links.css`).
- `docs/groups/modules/do_group_extras/css/group-links.css` (new) — minimal list layout + spacing
  for `.field--name-field-group-links`, plus a `:focus-visible` outline on the anchors for WCAG
  2.2 AA (2.4.11 focus-appearance).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (edited) — extended the
  existing `preprocessGroup()` method (the same seam that already attaches
  `do_group_extras/do_group_extras` for archived groups) to unconditionally attach
  `do_group_extras/group-links` whenever the rendered group's bundle is `community_group`. No new
  hook method, no preprocess_field fallback needed (see "Which rel-mechanism path" below).
- `docs/groups/scripts/step_700_demo_data.php` (edited, append-only) — new idempotent "Step 735:
  Group Links & Resources (#140)" block at the end (before "Demo data complete"), seeding the
  **exact** 6 canonical title/URL pairs from `handoff-T-red.md` on DrupalCon Portland 2026 / Core
  Committers / Thunder Distribution (2 links each). Guarded by
  `$group->get("field_group_links")->isEmpty()` so a re-run never duplicates deltas.

## Design decisions

- **Formatter settings only, no preprocess fallback.** Read core's
  `LinkFormatter::settingsForm()` / `buildUrl()` (`web/core/modules/link/src/Plugin/Field/
  FieldFormatter/LinkFormatter.php`): the `rel` setting is a plain string written verbatim into
  `$options['attributes']['rel']`, and `AttributeXss::sanitizeAttributes()` does not filter `rel`
  (`web/core/modules/link/src/AttributeXss.php` explicitly whitelists `rel` from protocol
  filtering). So `rel: noopener` + `target: _blank` on the formatter alone produces the exact
  `rel="noopener"` / `target="_blank"` attributes A's plan anticipated — confirmed empirically (see
  "Kernel test output" below). No `preprocess_field__field_group_links` hook was added.
- **`link_type: 17` for both internal + external.** Verified against
  `Drupal\link\LinkItemInterface`'s bitmask constants (`LINK_INTERNAL=0x01`, `LINK_EXTERNAL=0x10`,
  `LINK_GENERIC=0x11=17`) rather than assuming the value.
- **Attach `group-links` library via the existing `preprocessGroup()` method, unconditionally for
  `community_group` bundle, not view-mode-scoped.** The codebase has no existing view-mode-scoping
  precedent in this hook (the archived-class branch doesn't check view mode either), and attaching
  a small CSS library that only ever styles a field wrapper class is harmless when the field is
  empty or the display is a non-Full view mode. This is the "extend, don't duplicate" seam A
  identified (reuse map: extend `do_group_extras`'s existing preprocess_group hook, not a new one).
- **All existing group fields included in the new Full display**, not just `field_group_links`:
  description (`label: hidden`, `text_default`, weight 0 — description already renders unlabeled
  elsewhere, matching the description field instance's existing `required: true` UX), visibility
  (`label: above`, `list_default`, weight 1), image (`label: hidden`, `image`, weight 2),
  type/language hidden (mirroring the form display's `hidden:` block). This satisfies the task's
  "include all existing group fields at their current display state" instruction — there was no
  prior Full display to diff against (confirmed: `docs/groups/config/` had no
  `core.entity_view_display.group.*` file before this story), so I built a first-principles
  reasonable default per field type rather than guessing at exact settings that were never
  exported anywhere.

## Reuse / extend-vs-new

Extended `do_group_extras` (survey.md's Reuse & Analogous-Feature map: "use existing
`do_group_extras` module for the small render tweak... do NOT spin a new `do_group_links` module —
no earned complexity"). No new module was created. The one PHP change — attaching a CSS library —
landed on the exact existing `preprocessGroup()` method that already does the analogous archived-
group library attach, rather than a new hook method. `field_group_links`'s storage/instance shape
directly mirrors `field_group_description` (the brief's named closest analogue) file-for-file.

## Architecture notes for A

- **Layers touched:** config-only (field storage/instance/displays) + one small PHP hook extension
  (CSS attach) + a CSS file + an append-only seed-data addition. No new services, no new routes, no
  new classes.
- **New dependency:** `link` (core module) — added to `do_group_extras.info.yml`. Note: `link` was
  **already enabled** in the committed baseline `config/sync/core.extension.yml` (confirmed via
  `git show HEAD:config/sync/core.extension.yml`), so this dependency declaration is honest/correct
  Drupal practice but wasn't load-bearing for THIS assembled environment. Flagging for visibility:
  `scripts/ci/assemble-config.sh`'s core.extension-patching step only auto-enables the *custom
  module directory names themselves* (`+ flag + language`, hardcoded) — it does **not** walk a
  custom module's own `.info.yml` `dependencies:` list to transitively enable declared deps. This
  is pre-existing tooling behavior (not something in this story's scope to change) and is a
  non-issue here only because `link` happened to already be enabled at baseline. Worth a note to
  O/A in case a *future* story adds a dependency that is NOT already enabled at baseline — that
  story would need to either add its own module (already-transitively-enabled) or extend the
  assemble script.
- **Shared config file:** `core.entity_view_display.group.community_group.default.yml` is new in
  this story; #141 About will edit it next (reserved weight 10, per the brief's coordination note).
  Section-marker comments are labeled cosmetic-only in the file's own header comment.
- No shared/other-agent-owned code was touched outside the explicitly-scoped `do_group_extras`
  extension point.

## Deviations from spec / wireframe

- **`field_field_settings.title` = `2` (`LinkTitleVisibility::Required`), not the task prompt's
  literal `title: 1`.** The brief's acceptance criterion #2 states "title required per delta"
  verbatim, and survey.md's Reuse map states "Widget: `link_default` (**title required**; URL
  required; external allowed)." Checked `Drupal\link\LinkTitleVisibility`
  (`web/core/modules/link/src/LinkTitleVisibility.php`): `Disabled=0`, `Optional=1`,
  `Required=2`. `title: 1` would make the title *Optional*, contradicting both the acceptance
  criterion and the WCAG-AA criterion ("title used as accessible name; not 'click here'" — an
  optional title would let an editor author a link with no discernible name). I used `title: 2`
  to match the brief's explicit written criterion. **Please double-check this reading is what was
  intended** — if `title: 1` was actually deliberate (e.g. to allow URL-only links that fall back
  to the raw URL as link text, which is still a discernible-if-ugly accessible name), this is a
  one-line value change in `field.field.group.community_group.field_group_links.yml`, no other
  file is affected by it (no test asserts on this setting's literal value).
- Everything else matches the task's file list and shapes as given.

## Tier 1 self-check (incl. tests now GREEN)

**Important finding on T's `GroupLinksFieldTest.php` — see "Tests that look wrong" below.** The 6
config-shape/rendering tests in the real, unmodified `GroupLinksFieldTest.php` **cannot pass as
authored**, independent of any production code, because the test's `setUp()` never installs or
programmatically builds the `field_group_links` config (see full root-cause below). I did **not**
edit the test. Instead I built a **throwaway, never-committed diagnostic copy** in the gitignored
assembled tree (`web/modules/custom/do_group_extras/tests/src/Kernel/ZZDiagLinksFieldTest.php`,
deleted immediately after use, never staged) that adds the one missing ingredient — the
`FieldStorageConfig::create()` / `FieldConfig::create()` / `EntityViewDisplay::create()` /
`EntityFormDisplay::create()` calls that `GroupExtrasBehaviorTest` and `GroupRestoreTest` (the two
sibling kernel tests in this same module) already do for `field_group_type` — to prove my
production config/CSS/hook code is correct in isolation:

```
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/ZZDiagLinksFieldTest.php'

ZZDiag Links Field (Drupal\Tests\do_group_extras\Kernel\ZZDiagLinksField)
 ✔ Storage exists
 ✔ Instance exists
 ✔ Full display shows field
 ✔ Form display shows field
 ⚠ Renders external link with rel noopener   (passes; ⚠ = pre-existing Twig-sandbox deprecation, not a failure)
 ⚠ Internal link rendered                     (same)
 ⚠ Empty state renders nothing                (same)

Tests: 7, Assertions: 165, Deprecations: 2.
OK, but there were issues!
```

**All 7 pass** with my exact production config values (identical `link_type`/`title`/formatter
settings to the shipped YAML). This is conclusive evidence the production code is correct; the RED
persisting on the *real* test file is a test-harness gap, not a code defect.

**Real (unmodified) `GroupLinksFieldTest.php` run** (for the record — unchanged from T's RED):

```
$ ddev exec bash scripts/ci/assemble-config.sh   # exit 0
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php'

Group Links Field (Drupal\Tests\do_group_extras\Kernel\GroupLinksField)
 ✘ Storage exists            — Failed asserting that null is not null.
 ✘ Instance exists           — Failed asserting that null is not null.
 ✘ Full display shows field  — Failed asserting that null is not null.
 ✘ Form display shows field  — Failed asserting that null is not null.
 ✘ Renders external link with rel noopener  — (no field_group_links output at all; field doesn't exist in this test's DB)
 ✘ Internal link rendered    — (same)
 ⚠ Empty state renders nothing — passes (vacuously true either way; unaffected by the setup gap)

Tests: 7, Assertions: 156, Failures: 6, Deprecations: 2.
```

**No-regression check — full custom-module Kernel suite** (11 modules, `do_discovery` through
`do_tests`, matching the task's exact self-check `find` command):

```
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_discovery/tests/src/Kernel web/modules/custom/do_group_extras/tests/src/Kernel web/modules/custom/do_group_language/tests/src/Kernel web/modules/custom/do_group_membership/tests/src/Kernel web/modules/custom/do_group_mission/tests/src/Kernel web/modules/custom/do_group_pin/tests/src/Kernel web/modules/custom/do_multigroup/tests/src/Kernel web/modules/custom/do_notifications/tests/src/Kernel web/modules/custom/do_profile_stats/tests/src/Kernel web/modules/custom/do_streams/tests/src/Kernel web/modules/custom/do_tests/tests/src/Kernel'

Tests: 118, Assertions: 3249, Failures: 6, Deprecations: 28, PHPUnit Deprecations: 93.
```

The 6 failures are **exactly and only** `GroupLinksFieldTest`'s 6 (confirmed by extracting the
failing-test-name list) — zero regressions anywhere else across all 11 custom modules.

**phpcs** (Drupal standard explicitly, since no `phpcs.xml` exists at repo root and the bundled
default standard fails wholesale on pre-existing merged code like `RestoreGroupForm.php` — verified
this is pre-existing noise unrelated to my diff, and CI does not gate on phpcs at all —
`.github/workflows/{build,test}.yml` has zero phpcs references):

```
$ ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install \
    docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php \
    docs/groups/modules/do_group_extras/do_group_extras.module

DoGroupExtrasHooks.php: FOUND 0 ERRORS AND 4 WARNINGS (all 4 on pre-existing lines I didn't touch —
  confirmed via `git diff --stat`: this file is +10/-1, exactly my preprocessGroup() addition)
do_group_extras.module: FOUND 0 ERRORS AND 1 WARNING (pre-existing line; git diff shows 0 changes
  to this file at all)
```

Zero errors, zero new warnings on any line I actually wrote.

**assemble-config.sh:** exits 0; the 4 new/edited config files are present in the assembled
`config/sync/` (`field.storage.group.field_group_links.yml`,
`field.field.group.community_group.field_group_links.yml`,
`core.entity_form_display.group.community_group.default.yml`,
`core.entity_view_display.group.community_group.default.yml`), and `do_group_extras`'s assembled
copy under `web/modules/custom/` carries the new CSS file + updated `.info.yml`/`.libraries.yml`.

**YAML syntax:** all 4 new/edited config YAML files parse cleanly via
`Symfony\Component\Yaml\Yaml::parseFile()` (checked via a throwaway scratch script, deleted after
use, never staged).

**`drush cex --diff` belt-and-suspenders check (A's suggestion):** **not run** — this worktree's
site is not installed (`drush status` shows no bootstrap/database line; only kernel-DB-prefix
tables exist from phpunit runs), and a full `site:install` is a multi-hundred-step RUNBOOK
undertaking outside Tier-1/F's self-check scope (this is explicitly marked optional in the task
instructions, and the equivalent-config diagnostic-test run above already gives strong evidence the
YAML shape is exactly correct — `FieldStorageConfig::save()`/`FieldConfig::save()`/
`EntityViewDisplay::save()` perform their own internal validation, and would have thrown on a
malformed settings shape).

## Tests that look wrong (for T)

**`docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` — `setUp()` is
missing the config-bootstrapping calls; as authored, 6 of its 7 tests cannot pass under ANY
production code change.**

Root cause, fully traced (not guessed):

1. `GroupLinksFieldTest::setUp()` only calls `parent::setUp(); $this->installConfig(['field']);`.
2. `installConfig(['field'])` calls `ConfigInstaller::installDefaultConfig('module', 'field')`
   (`web/core/lib/Drupal/Core/Config/ConfigInstaller.php:111`), which installs **only** the
   `field` module's own default config — nothing about `do_group_extras`.
3. `EntityKernelTestBase::setUp()` (a grandparent, `web/core/tests/Drupal/KernelTests/Core/Entity/
   EntityKernelTestBase.php:87`) already calls this exact same `installConfig(['field'])` — so the
   test's own explicit call is redundant, not additive.
4. Listing `do_group_extras` in `static::$modules` only registers it via `DrupalKernel::
   updateModules()` (module-list/namespace/container registration) — confirmed by reading
   `KernelTestBase::bootKernel()` and `DrupalKernel::updateModules()` directly. This **never**
   invokes `hook_install()` nor auto-installs a module's `config/install/` directory (that only
   happens via `ModuleInstallerInterface::install()`, which kernel tests never call for the
   `static::$modules` list — `enableModules()`'s own docblock confirms: "hook_install() is not
   invoked").
5. Confirmed via the two **sibling** kernel tests in this exact module —
   `GroupExtrasBehaviorTest::setUp()` and `GroupRestoreTest::setUp()` — both of which need a
   config-only field (`field_group_type`, entity_reference) and both build it **programmatically**
   in their own `setUp()` via `FieldStorageConfig::create([...])->save();
   FieldConfig::create([...])->save();`. This is the established, working convention for this
   exact situation in this exact module. `GroupLinksFieldTest` omits the equivalent calls for
   `field_group_links` (storage + instance + both displays).

I did **not** add these calls to the test file (that's editing a test, which I must not do). I
proved my production code is correct via a throwaway, never-staged diagnostic copy (see "Tier 1
self-check" above) that adds exactly the missing calls and otherwise runs the identical assertions
— all 7 pass. **Recommended fix for T:** add to `GroupLinksFieldTest::setUp()` (after
`installConfig(['field'])`) the same shape of calls
`GroupExtrasBehaviorTest`/`GroupRestoreTest` use for `field_group_type`, but for
`field_group_links`/its two displays — using the exact settings values I shipped in
`docs/groups/config/{field.storage.group.field_group_links.yml, field.field.group.
community_group.field_group_links.yml}` so the kernel fixture and the real shipped config never
drift apart. Happy to hand over the throwaway diagnostic file's exact `setUp()` body as a starting
point if useful — it is not preserved anywhere (deleted per the "never edit/stage tests" rule) but
is reproducible from this handoff's description above.

## Known issues

- See "Deviations" above re: `title: 2` vs. the task prompt's literal `title: 1` — please confirm
  this reading, it's a one-line value if `1` was actually intended.
- `link` module dependency note (see "Architecture notes for A") — `assemble-config.sh` does not
  currently walk a custom module's declared `.info.yml` dependencies to auto-enable them; a
  non-issue for this story only because `link` was already enabled at baseline.
- E2E was intentionally NOT run (per task instructions — that's T's GREEN pass against a seeded
  runserver). The seed data (Step 735) is in place and idempotent; T should re-assemble, install,
  import, seed, and run `npx playwright test tests/e2e/group-links.spec.ts` to confirm the full
  path end to end.

## Files changed

- `docs/groups/config/field.storage.group.field_group_links.yml` (new)
- `docs/groups/config/field.field.group.community_group.field_group_links.yml` (new)
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` (edited)
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` (new)
- `docs/groups/modules/do_group_extras/do_group_extras.info.yml` (edited)
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` (edited)
- `docs/groups/modules/do_group_extras/css/group-links.css` (new)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (edited)
- `docs/groups/scripts/step_700_demo_data.php` (edited, append-only)

No test files were created or modified. `.ddev/config.yaml` (T's local DDEV rename) was left
unstaged/untouched, as instructed.
