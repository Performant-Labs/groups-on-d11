# Brief — #139 MC-4 Multilingual baseline + RTL

Branch: `139-multilang-rtl` · Worktree: `~/Projects/_worktrees/groups-multilang-rtl`
Spec (re-read every phase): `gh issue view 139 --repo Performant-Labs/groups-on-d11`
Review rigor: **none**

## Objective

Deliver the MVP multilingual baseline for the demo:
1. Confirm the primary-language field is present on `community_group` and
   surface it as a language indicator on Full + teaser view modes.
2. Seed one RTL-primary group so RTL rendering demos end-to-end.
3. Prove RTL correctness with automated tests (Kernel + Playwright).

## Non-negotiables from survey

- **Reuse `field_group_language`**. Do NOT create
  `field_group_primary_language`. See `survey.md` §"Field — REUSE".
- **Never** commit `web/modules/custom/` or `config/sync/`. Source-only in
  `docs/groups/...`.
- Do NOT touch the group-add form display — `field_group_language` is
  edit-form-only (guarded by `do_tests` functional test).
- Use `\Drupal::languageManager()->getLanguage($langcode)->getDirection()`
  — never hardcode `dir="rtl"`.
- `step_760.php` edits must be append-only and idempotent (`loadByProperties`
  guard + skip-if-exists pattern already established in that file).
- Kernel test lives in `do_group_language/tests/src/Kernel/`; follows the
  pattern of `GroupLanguageNegotiationTest.php`.

## Deliverables (disjoint files)

- **NEW** `docs/groups/modules/do_group_language/do_group_language.module`
  — `hook_entity_view($build, $entity, $display, $view_mode)` on `group`
  entities, view modes `full` + `teaser`. Emit render array element
  `language_indicator` containing a `<span class="do-group-language"
  lang="{code}" dir="{direction}">{native_name}</span>`. Attach
  `do_group_language/indicator` library. No output when the field is empty
  or `und`/`zxx`.
- **NEW** `docs/groups/modules/do_group_language/do_group_language.libraries.yml`
  — one library `indicator` pointing at the new CSS.
- **NEW** `docs/groups/modules/do_group_language/css/group-language.css`
  — `.do-group-language` base styles (small pill or badge, uppercase
  langcode or native name). Include an `[dir="rtl"] .do-group-language`
  rule for any margin/padding flips. Keep it minimal — the RTL correctness
  comes from `dir` propagation, CSS just needs to not break under it.
- **APPEND** `docs/groups/scripts/step_760.php` — add one Arabic-primary
  community group (label: `Drupal العربية`, langcode `ar`) with 1–2 Arabic
  forum posts. Follow the exact idempotency pattern the file already uses.
  Do NOT alter existing fr/de blocks.
- **NEW** `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`
  — Kernel test: build the `group` entity via the module's view builder
  for view_mode `full` and `teaser`; assert the rendered output contains
  `class="do-group-language"`, `lang="ar"`, `dir="rtl"` for an ar-primary
  group, and `lang="fr"`, `dir="ltr"` for a fr-primary group. Assert
  NO indicator element when the field is empty.
- **NEW** `tests/e2e/group-language.spec.ts` — Playwright:
  - anonymous visits the seeded Arabic group's canonical path; expect
    `html[dir="rtl"]` (via `language-group` negotiation) AND
    `.do-group-language[lang="ar"]` visible on the page.
  - anonymous visits the seeded Drupal France group; expect
    `html[dir="ltr"]` and `.do-group-language[lang="fr"]` visible.
  - the directory (`/all-groups`) shows the Arabic group's teaser with the
    `.do-group-language[lang="ar"]` indicator (teaser render assertion).

## Acceptance (from issue)

- [ ] Group entity carries primary language; group page shows a language
      indicator; teaser exposes it (for MC-3's directory filter).
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

D skipped: no new UI surface beyond an inline text indicator — the visual
design is a one-line span; no wireframe needed. Recorded here as Phase 2:
N/A (no meaningful UI surface).

## Model discipline

D/T/F/U spawned with `model: "sonnet"` explicitly. O/A/S inherit Opus.
