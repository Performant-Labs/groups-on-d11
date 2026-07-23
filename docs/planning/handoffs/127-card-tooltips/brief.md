# Brief — #127 SD-2 Card ⓘ tooltips

## Objective
Add card- and element-level ⓘ tooltips to the two shipped card templates — directory cards on `/all-groups` and stream cards — using the established `do_chrome/tooltips` mechanism. Reuse #88 visibility copy; append 5 new HelpText keys for surfaces without existing copy.

## Scope (from issue #127)

**Directory card (`views-view-fields--all-groups.html.twig`):**
- Type badge → ⓘ, `card.directory.type` (new key)
- Visibility badge → ⓘ, **reuse** `visibility.open|moderated|invite_only` keyed off `gc_directory.visibility`
- Member count → ⓘ, `card.directory.members` (new key)

**Stream card (`node--stream-card.html.twig`):**
- Byline row → ⓘ, `card.stream.byline` (new key)
- Content-type badge → ⓘ, `card.stream.type` (new key)
- Comments footer → ⓘ, `card.stream.comments` (new key)

**Omitted honestly** (data not in current template — no field, no preprocess variable): language chip, pinned badge, event-date chip. Adding them = scope creep (new fields), which the guardrail forbids.

## Design (skip-D justification)
Identical ⓘ affordance to #89/#122/#126 — same DOM shape:
```twig
<span class="do-chrome-info gc-card-info" tabindex="0" role="note"
      aria-label="{{ copy }}" data-do-tooltip="{{ copy }}">ⓘ</span>
```
No new visual pattern. Skipping D per POC pipeline "likely skippable per patterned append".

## Reuse & Analogous-Feature map
See `survey.md`. Summary:
- **Analogous feature:** #126 (page-tooltips, in-flight) uses same DOM, different injection point (`preprocess_page_title` for pages vs. **preprocess arrays into twig** for cards). Disjoint files.
- **Analogous data path:** `groups_chrome_preprocess_views_view_fields__all_groups()` and `groups_chrome_preprocess_node()` (existing) will be **extended** to add tooltip-copy variables into `$variables['gc_directory']` / `$variables['gc_stream']`. This is the extend-not-new choice.
- **HelpText:** append-only new `card.*` section after persona.* block (persona was the last appended in #120).
- **Library:** already globally attached by `DoChromeHooks::pageAttachments()` — no attach.
- **CSS:** none unless baseline visually broken; defer to F's self-check.

## Files
- **EDIT:** `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` — add 3 ⓘ spans
- **EDIT:** `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig` — add 3 ⓘ spans
- **EDIT:** `web/themes/custom/groups_chrome/groups_chrome.theme` — extend the two existing preprocess functions to populate tooltip-copy variables (READ from HelpText::get) — **theme is not gitignored, this is legitimate**. NOTE: brief explicitly says "twig-template-only in Wave 1" (issue text). Preprocess extension is minimal and NECESSARY because Twig has no HelpText access — this is a data-passthrough, not a `.libraries.yml` / logic change. **A: confirm this is acceptable, or direct us to a Twig extension instead.**
- **APPEND-ONLY:** `docs/groups/modules/do_chrome/src/HelpText.php` — new `card.*` keys
- **NEW:** `tests/e2e/element-tooltips.spec.ts` — Playwright spec

## Acceptance criteria (from issue + POC bar)
1. Anonymous visitor on `/all-groups` — hovering the type, visibility, and members elements on a directory card shows a sensible tooltip.
2. Anonymous visitor on `/stream` (or any page with a stream card) — hovering byline, content-type badge, comments footer shows a sensible tooltip.
3. No double-tooltip anywhere page-level (#126) help exists nearby — card-scoped triggers are within `.gc-card` DOM, page-level is after H1 (disjoint).
4. Reused visibility copy is single-sourced (no duplicated strings): visibility badge ⓘ pulls from `HelpText::get('visibility.' . $value)`.
5. Existing suite stays green.
6. `tests/e2e/element-tooltips.spec.ts` asserts `data-do-tooltip` present on 6 elements (3 directory + 3 stream) + tabindex=0 + non-empty aria-label + tippy visibility on one hover.
7. **WCAG 2.2 AA:** keyboard focusable, non-empty accessible name, ≥ AA contrast (baseline 5.36:1 from #122).

## Copy (append to HelpText.php)
- `card.directory.type` → "What kind of group this is — Geographical (local user group), Working group (module or initiative), Distribution (Drupal distro), Event planning, or Archive (read-only)."
- `card.directory.members` → "How many people have joined this group."
- `card.stream.byline` → "Who posted this and which group it appears in. Click the person to see their profile; click a group to visit it."
- `card.stream.type` → "The kind of post — Forum (threaded discussion), Documentation (durable reference), Event (something at a set time), Post (quick update), or Page (standalone info)."
- `card.stream.comments` → "How many replies this post has. Click to open the post and read the discussion."

(Visibility ⓘ **reuses** `visibility.open|visibility.moderated|visibility.invite_only` — no new key.)

## Review rigor
none (per issue)

## Model discipline
D skipped (patterned append). T, F, U spawn with `model: sonnet`. A, S inherit Opus per frontmatter.
