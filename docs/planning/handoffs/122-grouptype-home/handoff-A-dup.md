# Handoff-A-dup: Phase 7 — #122 SC-3 Group-type homepages (anti-duplication + drift gate)

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Diff base:** origin/main (a254035)
**Diff head:** bbd02cd
**Reuse map:** docs/planning/handoffs/122-grouptype-home/survey.md
**Phase-3 handoff:** docs/planning/handoffs/122-grouptype-home/handoff-A.md
**Verdict:** PASS

Cache-tag contract check: ✓
Extend-not-parallel check: ✓

## Summary

F extended `groups_chrome_preprocess_group()` **in place** exactly as the survey and Phase-3
handoff required, added the ONE justified new object (`groups_chrome_theme_suggestions_group_alter()`),
implemented every binding constraint from handoff-A.md verbatim (node-query via
`$group->getRelationships('group_node:{bundle}')` mirroring the last-activity block; see-all
URLs = `/group/{gid}/nodes?type=forum|documentation`; cache metadata contract complete; theme
suggestion name shape `events_first` → `events-first.html.twig`; `.gc-group-lead__help`
margin-only, no color override), and contained the Group-4.0.x 32-char bundle-id contrib bug
workaround entirely to the seed script with rich inline documentation. No parallel path, no
drift, no duplication of Wave-1 primitives. Ready for U → S.

## Findings

No duplication; extension is clean. No drift from Phase-3 binding guidance.

| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| — | info | `web/themes/custom/groups_chrome/groups_chrome.theme:609` | F added a defensive `class_exists(HelpText::class)` guard on the theme→module call, not requested by A. F flagged and justified it (consistent with the file's other try/catch "fail safe" blocks). Cost: one small line; benefit: theme still renders if do_chrome is uninstalled. **Not drift** — it matches the existing pattern in this file. | None; noted for record. |
| — | info | `web/themes/custom/groups_chrome/templates/group/group--full.html.twig:97–158` | Tab `<nav>` moved from inside `<header>` to a true sibling. Grepped CSS + tests for any selector coupling to the old nesting: **none exists** (`.gc-group-tabs` selectors in `group-page.css` use only top-level class, no descendant chain from `.gc-group-header`). Safe DOM restructure. | None. |

## Axis 1 — Duplication / parallel-path drift

- **Extend-in-place:** Verified. All new logic lives inside `groups_chrome_preprocess_group()` (theme.php:313–639). No parallel preprocess function. No `$variables['gc_lead']` twin — data lives on `$gc['lead_*']` keys additively.
- **Node-query mechanism:** Uses `$group->getRelationships('group_node:{bundle}')` (theme.php:489) — the exact mechanism handoff-A.md specified, mirroring the last-activity block at lines 741–753. No parallel `entityQuery`, no rendered View.
- **Suggestion twigs are true passthroughs (option b):** All three `group--community-group--{events,discussion,docs}-first.html.twig` files are 18–22 lines, containing only a docblock + `{% include '@groups_chrome/group/group--full.html.twig' %}`. Zero markup triplication. F's temporary marker check (documented in handoff-F.md) proved suggestion resolution actually fires end-to-end.
- **Wave-1 primitives (grep'd):** `do_streams`, `do_showcase`, `do_group_pin`, `do_group_membership` provide **no** "lead section" primitive. The only `lead*` hits in wave modules are unrelated (`leading glyph` in `do_showcase/VariantSwitcher.php`, `pinned lead` naming in `do_group_pin`, `leading` prose in docs). Nothing for F to have reused.
- **CSS duplication:** `group-type-homepage.css` (103 lines) is BEM-scoped under `.gc-group-lead*`, entirely token-driven from `tokens.css` (all `--gc-space-*`, `--gc-color-*`, `--gc-font-*` values verified to exist), and grep confirms `.gc-group-lead` selectors appear in **only one file**. No token reinvention, no rule duplication.

## Axis 2 — Drift from Phase-3 binding guidance

- **See-all URL shape:** `[$canonical . '/nodes?type=forum', ...]` and `.../nodes?type=documentation'` at theme.php:616–620. Exact match with A's Q1 resolution. Verified in T-green's DOM inspection.
- **Cache metadata contract:** Every required tag/context present (theme.php:556–559, 561–562, 626).
  - Tags: `$group->getCacheTags()` (via `addCacheableDependency($group)`) ✓, `node_list:{plugin_suffix}` (bundle-specific — only the resolved leading_section's bundle) ✓, each rendered node's own tags (via `addCacheableDependency($node)` inside the loop) ✓.
  - Contexts: `user.node_grants:view` ✓, `user.group_permissions` ✓.
  - Max-age: inherited default (correct — no explicit override).
  - T-green's `drush php:script` output confirms these render exactly as A specified.
- **`.gc-group-lead__help` styling:** margin-left only (`group-type-homepage.css:37–39`). Zero color override. Pre-verified 5.36:1 contrast from `.do-chrome-info` (#0067b8 on #ffffff) preserved.
- **Node-access filter:** `$node->access('view', $current_user)` explicitly applied inside the loop (theme.php:496–498). Matches A's mandatory guidance.
- **Theme suggestion name shape:** `'group__' . $group->bundle() . '__' . $behavior_suggestion` with `$behavior_suggestion` ∈ `{events_first, discussion_first, docs_first}` (theme.php:277–283). Filenames on disk use hyphens (`events-first.html.twig`). Correct per Drupal's suggestion→filename resolution.
- **Term→behavior mapping in ONE place:** `_groups_chrome_leading_section_for_type()` (theme.php:225–232) used by both `theme_suggestions_group_alter()` (line 272) and `preprocess_group()` (line 474). Single source of truth honored.
- **Library attach conditional:** `groups_chrome/group_type_homepage` attached inside the same `if (!empty($gc['lead_items']))` conditional that populates the render (theme.php:596–601). Unmapped/empty pages emit zero new CSS/JS payload — fallback byte-identity preserved per wireframe §5.
- **Tab `<nav>` DOM restructure:** Explicitly required by wireframe §2 (lead section as true sibling of BOTH header and tabs). Grep confirms no CSS/test selector coupled to the old header-nested position. Non-regressing.

## Axis 3 — Contrib-bug workaround safety

The Group 4.0.x `getRelationshipTypeId()` 32-char-bundle-cap bug on `community_group-group_node-documentation` (40 chars, silently truncated to `community_group-group_node-doc` at provisioning; `addRelationship()` throws `AssertionError` on re-derivation):

- **Contained to seed script:** Workaround lives ONLY in `docs/groups/scripts/step_700_demo_data.php:239–341`. Production preprocess reads relationships via `$group->getRelationships('group_node:documentation')` which works correctly (it queries by plugin id, not the broken derivation). No leakage.
- **Correctness:** Resolves the actual relationship-type entity by iterating `loadByProperties(['group_type' => 'community_group'])` and matching `getPluginId() === 'group_node:documentation'` (script lines 267–274). This is the same lookup Drupal itself would do if `getRelationshipTypeId()` weren't broken. Cannot silently mis-resolve — `getPluginId()` is deterministic and unique.
- **Documentation:** 20+ lines of inline commentary explaining the root cause, the AssertionError vs. \Exception distinction, the 32-char cap arithmetic (`forum`=32 ✓, `event`=32 ✓, `documentation`=40 ✗), and why the direct `create()` path avoids the recomputation. Any future dev touching this block will not undo it accidentally.
- **Idempotency:** Node-existence and relationship-existence checks are independent (script lines 297–319), so a partially-completed prior run self-heals rather than leaving orphans. F verified across 3 sequential runs.

## Bonus checks

- **Fallback a11y:** Drupal France (unmapped) renders no `.gc-group-lead`, no tooltip markup, no attached library — T-green's direct-HTTP grep confirms zero occurrences. No regression on the fallback surface.
- **Test-couple brittleness:** T's `groupUrlByLabel()` was already re-hardened to use `?search=<label>` instead of `/all-groups` page-1 scanning, resolving the shared-DDEV pollution issue. Real CI runs a fresh `site:install`, so the pollution vector doesn't exist there anyway. Non-issue for CI.

## Notes for O

None. F's implementation matches every binding constraint from handoff-A.md verbatim. The one deviation from A's illustrative snippet (`field_date_of_event` instead of the hedged `field_event_date`) is a fact-check F did against actual config — an improvement, not drift. The `class_exists()` guard is defensively consistent with this file's established try/catch posture. Advance to U.

## Patterns referenced

- `web/themes/custom/groups_chrome/groups_chrome.theme` (extended preprocess, new suggestions hook, mapping helper — all in place)
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` (extended template + tab-`<nav>` reparenting)
- `web/themes/custom/groups_chrome/templates/group/group--community-group--{events,discussion,docs}-first.html.twig` (all three passthroughs)
- `web/themes/custom/groups_chrome/css/group-type-homepage.css` (BEM-scoped, token-driven)
- `web/themes/custom/groups_chrome/groups_chrome.libraries.yml` (single new `group_type_homepage` entry, conditional attach)
- `docs/groups/modules/do_chrome/src/HelpText.php` (one append-only key)
- `docs/groups/scripts/step_700_demo_data.php` (Step 740d — contained contrib-bug workaround)
- `docs/planning/handoffs/122-grouptype-home/handoff-A.md` (binding guidance re-verified)
