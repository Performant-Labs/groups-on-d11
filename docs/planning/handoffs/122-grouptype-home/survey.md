# Survey — #122 SC-3 Group-type-driven homepages

## Spec (canonical)
`gh issue view 122 --repo Performant-Labs/groups-on-d11` — re-read each phase.

**Ask:** the `/group/{id}` page for three exemplar groups leads with a different content section
based on `field_group_type`:
- **Event planning** (DrupalCon Portland 2026) → events-first.
- **Working group** (Core Committers) → discussion-first (forum topics).
- **Distribution** (Thunder Distribution) → docs-first (documentation).
- Any other type (Geographical, Archive, unset) → unchanged current layout (fallback).
- One append-only HelpText key + a tooltip on the section header: "this page adapts to the group's type".
- Implementation: **preprocess + theme suggestions on `field_group_type`. NO new routes, NO new fields.**
- WCAG 2.2 AA.
- New Playwright spec: `tests/e2e/group-type-homepage.spec.ts` asserts leading section per exemplar.

## Repo layout — corrected assumptions

- **`groups_chrome` is a THEME**, not a module, at `web/themes/custom/groups_chrome/`.
- `web/themes/custom/` is **checked-in, tracked in git, NOT gitignored** (only `web/core/`,
  `web/modules/contrib/`, `web/themes/contrib/` are). Verified with `git ls-files` — the
  theme's `.theme`, `.libraries.yml`, `.info.yml`, templates, and CSS are all versioned.
- The wave handoff / orchestrator override rule "edit only `docs/groups/`" applies to
  **`web/modules/custom/` (assembled from `docs/groups/modules/`) and `config/sync/` (assembled
  from `docs/groups/config/`)** — those are gitignored build artifacts. The `groups_chrome`
  theme is the **legitimate source of truth for the theme layer** and is edited in place.
- Corollary: this story's edits split across **two source roots**:
  1. `web/themes/custom/groups_chrome/` (theme hooks, twig, css).
  2. `docs/groups/modules/do_chrome/src/HelpText.php` (one appended key).
  3. `tests/e2e/group-type-homepage.spec.ts` (new spec — repo-root `tests/e2e/`).
- If any preprocess-side helper needs PHPUnit coverage, the natural home is
  `docs/groups/modules/do_chrome/tests/…` (module-local, per CI/assembled-layout rule).

## Reuse & Analogous-Feature map

**Closest analogous feature — THE feature to extend:** `groups_chrome_preprocess_group()` in
`web/themes/custom/groups_chrome/groups_chrome.theme` (lines 204–356, story #85 / CH-A3).

- **Already reads `field_group_type`** (lines 236–241) into `$gc['type_label']` (the taxonomy
  term label). The plumbing to know a group's type is already right there.
- Builds `$gc['tabs']` — array of `{ key, title, url, active }` in **fixed order
  `stream / events / members / about`**. This is our lever: the "leading" section is the tab
  order.
- Sets `$variables['gc_group']`, consumed by `templates/group/group--full.html.twig`.
- Only fires for `view_mode === 'full'` — matches the story's "single-group page" surface.

**Extend-vs-new recommendation — EXTEND, do not add parallel path.**

The DEFAULT is extend; this story's fit for extend is unusually strong:
1. Extend `groups_chrome_preprocess_group()` to derive a `$gc['leading_section']` (values:
   `events` | `discussion` | `docs` | `null`) from `field_group_type`'s term label.
2. Extend `$gc['tabs']` construction to reorder based on `leading_section`
   (`events` → move events first; `discussion` → add/promote a "Discussion" tab pointing at
   forum content; `docs` → add/promote a "Docs" tab pointing at documentation content); leave
   the array untouched when `leading_section` is null (fallback).
3. Extend the twig `group--full.html.twig` to render an optional **lead section block**
   ABOVE the tab bar (or as the tab's default panel) showing the top N items of the leading
   content type from that group.

Then add the ONE genuinely new piece:
- `groups_chrome_theme_suggestions_group_alter()` — appends
  `group__community_group__events_first` / `__discussion_first` / `__docs_first` suggestions
  when `field_group_type` maps and view_mode is `full`. This is the "theme suggestion" the
  story explicitly asks for and there is no existing suggestion hook to extend — it's the
  ONE justified new object.

**Content sources (already provisioned — no new views/fields needed):**
- Events: `views.view.group_events.yml` (per-group events view, already in config).
- Discussion (forum topics): `node.type.forum.yml` exists; `group_nodes` view can filter by
  bundle. If simplest, the preprocess can `entityQuery` for `node` of type `forum` in the
  group via `group_content` relationships (mirroring the last-activity read at lines 456–470
  of the .theme file).
- Docs: `node.type.documentation.yml` exists; same pattern.

**HelpText append-only:** `docs/groups/modules/do_chrome/src/HelpText.php` — add ONE key,
e.g. `'group_type.homepage_adapts'` (per-story guardrail from prompt). Kernel test at
`do_chrome/tests/src/Unit/HelpTextTest.php` iterates all keys — new key is auto-covered.

## Exemplar groups verification
`docs/groups/scripts/step_720_group_types.php` line 93–102 tags:
- `DrupalCon Portland 2026` → `Event planning` ✓
- `Core Committers` → `Working group` ✓
- `Thunder Distribution` → `Distribution` ✓
Terms exist (line 68–73). Groups exist in `step_700_demo_data.php` seed.

## Existing tests to not break
- E2E: `tests/e2e/{phase1..4,directory-cards,manage-members,nav,showcase}.spec.ts` — must
  stay green. `phase*` and `directory-cards` may render the exemplar group pages and could
  brittle-collide if their locators don't tolerate the new lead-section block.
- PHPUnit (Kernel/Unit): `HelpTextTest` iterates keys — will cover the appended key
  automatically. No other test currently asserts the tab order — free to reorder.

## Coordination — sole-owner claim (per story)
- **This story is the sole editor of `groups_chrome.theme` and `groups_chrome.libraries.yml`
  in Wave 1** — #127 is twig-template-only per the wave handoff. No coordination needed;
  don't overreach into non-owned files.
- HelpText append-only (one key). #121 is the serialized leader for HelpText; append-only
  keys don't conflict, but land after #121 merges if possible.

## Downstream (forward-compat check)
- The theme suggestions (`group__community_group__events_first` etc.) become templates that
  other stories may want to further specialize. Keep the suggestion names semantic (based
  on the *behavior* — events-first — not the term slug — event_planning) so a future term
  rename in the vocabulary doesn't break templates. Map term → behavior in ONE place in the
  preprocess.
- Downstream stream stories (#110–#115) attach routes to `do_streams` shell but do NOT
  redress the group page — they layer on top of any tab reorder we do here.

## Gotchas that apply to this story
1. **CI runs the assembled layout.** But this story lives mainly in the theme (not
   `web/modules/custom/`) — the theme is copied as-is from `web/themes/custom/` (it's in
   git, not assembled). `assemble-config.sh` still needs to run for any test needing the
   Do modules. **Kernel test (if any) must load the theme via `installConfig()`/`config
   install` — verify by running from the assembled layout, not source.**
2. **New group routes collide with contrib views.** N/A — this story adds NO new routes.
3. **Playwright locators:** `#type=>submit` renders `<input>` — but this story adds no
   forms. Locators for the lead section should use accessible names, not `button`.
4. **E2E runs against the seeded site.** The exemplar groups must exist in seed →
   verified above. E2E spec should assume seeded state (like `directory-cards.spec.ts`).
