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
