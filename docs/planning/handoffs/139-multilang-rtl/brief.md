# Brief — #139 MC-4 Multilingual baseline + RTL

Branch: `139-multilang-rtl` · Worktree: `~/Projects/_worktrees/groups-multilang-rtl`
Spec (re-read every phase): `gh issue view 139 --repo Performant-Labs/groups-on-d11`
Review rigor: **none**

Amendment history:
- v2 after A-round1 BLOCK — resolved Finding #1 (`/all-groups` teaser
  gap), rolled in advisories #4 (null-language guard), #5 (step_760
  new-group creation pattern), #6 (Kernel test uses `type: language`).
- v3 after A-round2 PASS+warns — folded in Views-field key shape
  (mirror sibling views), Playwright directory assertion pinned to
  language *name* (not raw langcode), and step_700 admin-membership
  advisory for the new group.

## Objective

Deliver the MVP multilingual baseline for the demo:
1. Confirm the primary-language field is present on `community_group` and
   surface it as a language indicator on the group's Full view mode via
   `hook_entity_view`.
2. Expose the language on the `/all-groups` directory listing via a Views
   field on `all_groups` (the view uses `row: fields`, not entity teasers,
   so an entity-level teaser hook cannot reach it).
3. Seed one RTL-primary group so RTL rendering demos end-to-end.
4. Prove RTL correctness with automated tests (Kernel + Playwright).

## Non-negotiables from survey + A review

- **Reuse `field_group_language`**. Do NOT create
  `field_group_primary_language`. See `survey.md` §"Field — REUSE" and
  A's PASS-on-reuse note (unjustified parallel path would BLOCK at A-dup).
- **Never** commit `web/modules/custom/` or `config/sync/`. Source-only in
  `docs/groups/...`.
- Do NOT touch the group-add form display — `field_group_language` is
  edit-form-only (guarded by `do_tests` functional test).
- Use `\Drupal::languageManager()->getLanguage($langcode)->getDirection()`
  — never hardcode `dir="rtl"`. If `getLanguage($langcode)` returns NULL
  (bogus/uninstalled langcode), suppress the indicator entirely — same
  behavior as empty/`und`/`zxx`.
- `step_760.php` edits must be idempotent. The Arabic-primary group is a
  **new pattern for this file** — step_760 today only *sets language on
  pre-existing groups*, it does NOT create them. Guard the new creation
  with `loadByProperties(['label' => 'Drupal العربية'])` + skip-if-exists,
  then set `field_group_language = 'ar'` + save, then seed 1–2 Arabic
  forum topics following the fr/de topic pattern already in the file.
- Kernel test lives in `do_group_language/tests/src/Kernel/`; declares
  `field_group_language` as **`type: language`** (production shape), NOT
  `type: string` (which is what `GroupLanguageNegotiationTest` uses for
  narrower purposes). The render pipeline behaves differently for
  `language`-typed fields.

## Deliverables (disjoint files)

- **NEW** `docs/groups/modules/do_group_language/do_group_language.module`
  — `hook_entity_view($build, $entity, $display, $view_mode)` on `group`
  entities, view mode `full` only. Emit render array element
  `language_indicator` containing a `<span class="do-group-language"
  lang="{code}" dir="{direction}">{native_name}</span>`. Attach
  `do_group_language/indicator` library. No output when:
    - the field is empty
    - the langcode is `und` / `zxx`
    - `\Drupal::languageManager()->getLanguage($langcode)` returns NULL
      (bogus/uninstalled langcode)

  (The `teaser` view mode is intentionally NOT targeted: `all_groups`
  renders via `row: fields`, so a teaser hook would not fire on the
  directory. The directory gets the indicator via a Views field instead
  — see next deliverable.)

- **NEW** `docs/groups/modules/do_group_language/do_group_language.libraries.yml`
  — one library `indicator` pointing at the new CSS.

- **NEW** `docs/groups/modules/do_group_language/css/group-language.css`
  — `.do-group-language` base styles (small pill or badge, showing the
  native language name). Include an `[dir="rtl"] .do-group-language`
  rule for any margin/padding flips. Keep it minimal — the RTL
  correctness comes from `dir` propagation; CSS just needs to not break
  under it.

- **APPEND** `docs/groups/config/views.view.all_groups.yml` — add
  `field_group_language` to `display.default.display_options.fields`
  (positioned after `field_group_description`, before `created`). The
  full key shape must mirror sibling views
  (`views.view.group_content_stream.yml`, `views.view.group_members.yml`,
  `views.view.group_nodes.yml`):
  ```yaml
  field_group_language:
    id: field_group_language
    table: group__field_group_language
    field: field_group_language
    relationship: none
    group_type: group
    entity_type: group
    plugin_id: field
    label: Language
    type: language
    settings:
      link_to_entity: false
  ```
  Add `group__field_group_language` to `dependencies.config`.
  Note: the core Views `language` formatter emits the **language name**
  (e.g. `Arabic` / `العربية` / `Français`), NOT the raw langcode.
  Playwright assertion is written against the name, not the code.

- **APPEND** `docs/groups/scripts/step_760.php` — add an Arabic-primary
  community group ("Drupal العربية", langcode `ar`) with 1–2 Arabic
  forum topics. Idempotency contract for the new pattern:
    - `loadByProperties(['label' => 'Drupal العربية'])`; if empty,
      create via `$group_storage->create(['type' => 'community_group',
      'uid' => 1, 'status' => 1, 'label' => 'Drupal العربية',
      'field_group_description' => ...])` and `->save()`. If found,
      reuse.
    - Add an admin member so the group is accessible for testing —
      copy the pattern from `step_700_demo_data.php:77-93`:
      `$group->addMember($admin_user, ['group_roles' =>
      ['community_group-admin']]);` (guard with a try/catch, matching
      step_700's own idempotency for re-runs).
    - Unconditionally `->set('field_group_language', 'ar')->save()`
      (idempotent).
    - For each Arabic topic: `loadByProperties(['title' => $title])`
      skip-if-exists (matches fr/de pattern), create with `langcode
      => 'ar'`, `addRelationship($node, 'group_node:forum')`.
    - Extend the verification loop at the bottom to include `ar`.
  Do NOT alter existing fr/de blocks.

- **NEW** `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`
  — Kernel test. Declare `field_group_language` as **`type: language`**
  (production shape). Build the `group` entity via
  `entityTypeManager->getViewBuilder('group')->view($group, 'full')`
  and render it; assert:
    - for an `ar`-primary group: output contains `class="do-group-language"`,
      `lang="ar"`, `dir="rtl"`
    - for a `fr`-primary group: `lang="fr"`, `dir="ltr"`
    - for a group with the field empty: NO `do-group-language` element in
      the output
    - for a group with a bogus/uninstalled langcode: NO
      `do-group-language` element (null-language guard)

- **NEW** `tests/e2e/group-language.spec.ts` — Playwright:
    - anonymous visits the seeded Arabic group's canonical path; expect
      `html[dir="rtl"]` (via `language-group` negotiation) AND
      `.do-group-language[lang="ar"]` visible on the page.
    - anonymous visits the seeded Drupal France group; expect
      `html[dir="ltr"]` and `.do-group-language[lang="fr"]` visible.
    - anonymous visits `/all-groups`; the Views-rendered Language
      column emits the language **name** via the core `language`
      formatter (NOT the raw langcode). Assert:
        - the row containing "Drupal العربية" shows the Arabic
          language name (`العربية` if the UI is Arabic, else `Arabic`
          — test whichever the anonymous English UI actually renders;
          most likely `Arabic`)
        - the row containing "Drupal France" shows `French`
      Scope each assertion to the row containing the group label, not
      the whole page, to avoid false positives.

## Acceptance (from issue)

- [ ] Group entity carries primary language; group page shows a language
      indicator; directory (`/all-groups`) exposes the language column
      (for MC-3's directory filter).
- [ ] A seeded RTL-primary group renders right-to-left correctly
      (`html[dir="rtl"]`).
- [ ] WCAG 2.2 AA — `lang` attributes correct on the rendered indicator
      and content; direction correct.
- [ ] Existing kernel + functional suite green (do_tests
      `GroupAddFormFieldsTest` must stay green — the field stays off the
      add form).
- [ ] Playwright `group-language.spec.ts` green vs a seeded site.

## Verification (assembled layout)

Before any test run:
```
bash scripts/ci/assemble-config.sh
```
Kernel:
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  $(find web/modules/custom -type d -path '*/tests/src/Kernel')
```
Lint:
```
php vendor/bin/phpcs docs/groups/modules/do_group_language
```
E2E (seeded site required):
```
npx playwright test tests/e2e/group-language.spec.ts
```

## Phase order

O → A → T(red) → F → T(green) → A-dup → U → S → PR (hold for human)

D skipped: no new UI surface beyond an inline text indicator + a Views
column — the visual design is a small pill; no wireframe needed.
Recorded here as Phase 2: N/A (no meaningful UI surface).

## Model discipline

D/T/F/U spawned with `model: "sonnet"` explicitly. O/A/S inherit Opus.

---

## Amendment v4 — post-T-green scope adjustment (Bugs 1–3)

T-green ran the full Tier 2 verification (kernel 107/107, Playwright 2/3
green, live seeded site in isolated container `gm139-multilang-rtl`) and
surfaced three real bugs. O has evaluated each; all three are in-scope
for this story (Bugs 2 and 3 expand scope beyond the original deliverable
list, but they are load-bearing on the story's own RTL acceptance
criterion — they cannot ship separately).

### Bug 1 — `views.view.all_groups.yml` invalid config dependency (F fix)

**File**: `docs/groups/config/views.view.all_groups.yml`, `dependencies.config`.
**Current**: `- group__field_group_language` (Views *table name* — belongs
at `table:`, not as a config dependency).
**Fix**: replace with `- field.storage.group.field_group_language` (the
real config entity ID). Repro: fresh `drush site:install` + `drush
config:import` fails with "Configuration ... depends on ...
group__field_group_language configuration that will not exist after
import." T verified locally that correcting only this dependency (leaving
line-51 `table:` untouched) makes import succeed.

### Bug 2 — `/all-groups` directory does not render the language column (F fix, expands scope)

**Root cause**: `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
is a custom row template (from story #84/CH-A2) that prints only
curated `gc_directory.*` variables assembled by
`groups_chrome_preprocess_views_view_fields__all_groups()` in
`web/themes/custom/groups_chrome/groups_chrome.theme`. It intentionally
does NOT loop over `fields`. Adding `field_group_language` to the view
YAML (what F did per the brief) has zero visible effect on the rendered
card. The brief v3's assumption that a bare Views-field addition would
surface on this directory was architecturally incorrect for a view with
a custom row override.

**Fix (F)**: extend the theme so the language surfaces on the card.
Both files are tracked source (NOT gitignored, editable):
- `web/themes/custom/groups_chrome/groups_chrome.theme` — in the
  `groups_chrome_preprocess_views_view_fields__all_groups()` hook,
  read the row's group entity's `field_group_language` value, resolve
  to a `ConfigurableLanguage`, and expose to the template as:
    - `gc_directory.language_code` — the langcode (e.g. `ar`), or `NULL`
    - `gc_directory.language_label` — the language name via
      `LanguageInterface::getName()`, or `NULL`
    - `gc_directory.language_direction` — `'ltr'` or `'rtl'` via
      `getDirection()`, or `NULL`
  Apply the SAME four suppression branches as the entity-view hook (empty
  / und / zxx / uninstalled / site-default) so English-primary groups
  don't get a noisy pill on the directory either. Consider extracting
  the resolve-and-suppress logic into a small service or trait shared
  with `GroupLanguageIndicatorHooks` to avoid duplication (A-dup will
  BLOCK a copy-paste second implementation of the same logic).
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
  — inside the badges block (near the type + visibility badges), emit:
  ```twig
  {% if gc_directory.language_label %}
    <span class="do-group-language gc-badge" lang="{{ gc_directory.language_code }}" dir="{{ gc_directory.language_direction }}">{{ gc_directory.language_label }}</span>
  {% endif %}
  ```
  Also update the template's Available doc-block comment (lines 16–28)
  to list the three new keys.

**Playwright**: the existing test 3 (`directory /all-groups shows
language column`) will pass once the template emits `Arabic` for the
Arabic row and `French` for the France row. T does not need to change
the spec.

### Bug 3 — `step_640.php` doesn't set language direction (F fix)

**File**: `docs/groups/scripts/step_640.php` line 13.
**Current**: `$storage->create(["id" => $langcode])->save();`
**Problem**: `$storage->create()` creates a minimal `ConfigurableLanguage`
with only the ID set — `direction` defaults to LTR, `label` is empty.
Drupal's predefined-language data (name, native name, direction) is
never applied. On a freshly seeded site, `ar` therefore resolves to
`direction: ltr` with `label: null`, silently breaking every RTL
acceptance criterion in this story (and any future multilingual work).
T proved this is the cause by hand-patching the `ar` entity on the
live DB to `direction: rtl` — F's hook then rendered correctly.

**Fix (F)**: replace with
`\Drupal\language\Entity\ConfigurableLanguage::createFromLangcode($langcode)->save();`
This populates `direction`, `name`, `label` from Drupal core's
predefined-language table. Idempotency: keep the existing
`if (!$storage->load($langcode))` guard.

**Why this is #139's problem despite being a pre-existing infrastructure
bug**: #139's RTL acceptance criterion cannot be verified end-to-end
against a clean-room seed without this fix. The story would ship with a
non-reproducible RTL demo (only works after manual DB patching), which
violates the project's "never accept env-blocked/CI-will-verify" rule.

### T-green verification process note (advisory, for future T runs)

The single monolithic `find | xargs phpunit` command in the Verification
section is impractically slow (45+ min, killed at 72%) because 10+ test
classes use `#[RunTestsInSeparateProcesses]`. T-green ran the same 107
tests in per-module batches in minutes with identical fidelity. Future T
runs on this project should adopt batched invocation as the practical
default. This does not change the verification contract — the tests
themselves are unchanged.

---

## Phase order (updated after v4)

O → A → T(red) → F → T(green) → **O-triage (bugs 1-3) → F-fix → T(green-2)**
→ A-dup → U → S → PR (hold for human)
