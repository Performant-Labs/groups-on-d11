# Brief — #122 SC-3 Group-type-driven homepages

**Repo:** `Performant-Labs/groups-on-d11`
**Branch:** `122-grouptype-home` (worktree `~/Projects/_worktrees/groups-grouptype-home`)
**Spec (canonical):** `gh issue view 122 --repo Performant-Labs/groups-on-d11`
**Wave handoff:** `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §4/§6/§7
**Project context:** `docs/workflow/PROJECT_CONTEXT.md`
**Survey:** `docs/planning/handoffs/122-grouptype-home/survey.md` (read this first)
**Decisions journal:** `docs/planning/handoffs/122-grouptype-home/decisions.md` (append after each phase)
**Review rigor:** `none` (per story tail line)
**UI surface?** YES → full pipeline O → D → A → T(red) → F → T(green) → A-dup → U → S.

## Objective

The `/group/{id}` full-page render adapts to `field_group_type`:
- **Event planning** → events-first lead section.
- **Working group** → discussion-first (forum topics) lead section.
- **Distribution** → docs-first (documentation) lead section.
- **Any other type (Geographical, Archive) or unset** → unchanged current layout (fallback).
- Section header carries a tooltip: "this page adapts to the group's type".
- **NO new routes. NO new fields.** Preprocess + theme suggestions only.
- WCAG 2.2 AA on the new surface.

## Files this story owns (disjoint)

- **`web/themes/custom/groups_chrome/groups_chrome.theme`** — extend
  `groups_chrome_preprocess_group()`; add ONE new `groups_chrome_theme_suggestions_group_alter()`.
  (Sole owner in Wave 1 per story; #127 is twig-template-only and does not touch this file.)
- **`web/themes/custom/groups_chrome/groups_chrome.libraries.yml`** — add ONE new library
  entry for the lead-section CSS (sole owner in Wave 1).
- **`web/themes/custom/groups_chrome/templates/group/group--full.html.twig`** — extend to
  render the optional lead section above the tab bar.
- **New:** `web/themes/custom/groups_chrome/templates/group/group--community_group--events-first.html.twig`,
  `…--discussion-first.html.twig`, `…--docs-first.html.twig` (theme-suggestion targets that
  inherit the base and set the lead-section variant).
  *(D may decide a single template + variant flag is cleaner — the story just requires the
  suggestion mechanism exists.)*
- **New:** `web/themes/custom/groups_chrome/css/group-type-homepage.css` (isolated component
  CSS, attached only when a lead section renders).
- **`docs/groups/modules/do_chrome/src/HelpText.php`** — append ONE key
  `group_type.homepage_adapts` (append-only; #121 leads HelpText serialization; append-only
  keys don't conflict).
- **New:** `tests/e2e/group-type-homepage.spec.ts` — asserts leading section per exemplar
  group; asserts unmapped-type group is unchanged; keyboard/focus + AA-contrast checks.

## Files this story does NOT touch

- `web/modules/custom/**` and `config/sync/**` — gitignored build artifacts (never commit).
- Any file outside the ownership list above (other themes, other Do modules, other views).
- Existing tab wiring for `stream/events/members/about` (reorder OK; don't remove tabs).

## Acceptance criteria

- [ ] `groups_chrome_preprocess_group()` derives `$gc['leading_section']` from
      `field_group_type` term label with the mapping:
      `Event planning → events`, `Working group → discussion`, `Distribution → docs`, else
      `null`.
- [ ] `groups_chrome_theme_suggestions_group_alter()` returns
      `group__community_group__{events,discussion,docs}_first` when `leading_section` is
      set and `view_mode === 'full'`.
- [ ] `group--full.html.twig` (or the suggestion-targeted twigs) render a lead-section
      block ABOVE the existing tab bar when `leading_section` is set; nothing new when
      `leading_section` is `null`.
- [ ] The section header carries the append-only HelpText tooltip
      (`group_type.homepage_adapts`), attached via the existing `do_chrome/tooltips`
      pattern (data attribute) and readable by keyboard (focusable trigger, AA contrast).
- [ ] DrupalCon Portland 2026 → visibly leads with events (top N upcoming/recent event
      titles + links from the group).
- [ ] Core Committers → visibly leads with forum topics (top N forum-type nodes in the
      group).
- [ ] Thunder Distribution → visibly leads with documentation (top N documentation-type
      nodes in the group).
- [ ] An unmapped-type or untyped group renders identically to today (no lead section, no
      new library attachment, `gc_group.tabs` unchanged order).
- [ ] Existing E2E specs stay green (`tests/e2e/*.spec.ts`).
- [ ] New spec `tests/e2e/group-type-homepage.spec.ts` covers all four AC bullets above
      (three exemplars + one fallback) plus a keyboard/focus/aria check on the tooltip
      trigger.
- [ ] WCAG 2.2 AA: axe check passes on all four rendered pages; the tooltip trigger has a
      visible focus ring and accessible name; the lead section has an `<h2>`/landmark
      hierarchy consistent with the rest of the page (page title stays the sole `<h1>`).
- [ ] `php vendor/bin/phpcs` passes on modified files.
- [ ] Kernel/Functional PHPUnit suites remain green in the assembled layout.

## Constraints / non-goals

- **No new routes, no new fields, no new schema.** The page URL stays canonical
  `/group/{id}`. If a lead-section item needs a "See all X" link, use the existing per-group
  view path (`/group/{id}/events` etc.).
- **Extend, don't parallel-path.** The existing `groups_chrome_preprocess_group()` already
  reads `field_group_type` and builds tabs. Fold the new logic INTO it. If F ships a
  parallel preprocess or a bypass of `gc_group`, A-dup blocks.
- **Theme suggestion names are behavior-based** (`events_first`), not term-slug-based, so a
  future term rename doesn't break templates. The term→behavior mapping lives in ONE place
  in the preprocess.
- **Fallback is silent.** Unmapped types must render exactly as today — no empty container,
  no attached library. Verified by a diff snapshot against a baseline (E2E asserts no new
  DOM inside `.gc-group-page` above the tab bar).
- **Model tiers:** spawn D/T/F/U with `model: "sonnet"` explicitly. A/S/O inherit Opus from
  frontmatter. Never let D/T/F/U silently inherit Opus.
- **Namespaced Docker containers:** `gm122-*`. Never `docker rm` a container this story
  did not create.

## Verification path (real CI)

1. `bash scripts/ci/assemble-config.sh` (idempotent; run before PHPUnit).
2. Kernel: `php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
   $(find web/modules/custom -type d -path '*/tests/src/Kernel')` — must stay green;
   `HelpTextTest` will cover the appended key.
3. Functional: BrowserTestBase self-installs; keep suite green.
4. E2E: `npx playwright test tests/e2e/group-type-homepage.spec.ts` against a **seeded**
   site (assemble → drush site:install → drush cim → `docs/groups/scripts/step_*.php`
   seed → runserver → playwright). Plus the full `tests/e2e/*.spec.ts` regression run.
5. Lint: `php vendor/bin/phpcs` on changed files.

## Handoff locations

- Survey: `docs/planning/handoffs/122-grouptype-home/survey.md`
- Decisions journal: `docs/planning/handoffs/122-grouptype-home/decisions.md` (each phase
  appends a `## <role> — Phase N` entry with Decided / Assumed / Hedged / Evidence)
- Wireframe (D): `docs/planning/handoffs/122-grouptype-home/wireframe.md`
- A up-front review: `docs/planning/handoffs/122-grouptype-home/handoff-A.md`
- T(red): `docs/planning/handoffs/122-grouptype-home/handoff-T-red.md`
- F: `docs/planning/handoffs/122-grouptype-home/handoff-F.md`
- T(green): `docs/planning/handoffs/122-grouptype-home/handoff-T-green.md`
- A-dup: `docs/planning/handoffs/122-grouptype-home/handoff-A-dup.md`
- U: `docs/planning/handoffs/122-grouptype-home/handoff-U.md`
- S: `docs/planning/handoffs/122-grouptype-home/handoff-S.md`
