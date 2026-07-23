# Decisions — #127 SD-2 Card ⓘ tooltips

Append-only journal. Every phase writes its own entry.

## Phase 1 — O (Orchestrator)
- **Decided:** Skip D per lean POC pipeline — the ⓘ affordance is 100% patterned on #89/#122/#126 (same DOM, same class, same JS). No wireframe adds signal here.
- **Decided:** File set = 2 theme twigs + `groups_chrome.theme` preprocess extension + HelpText append + 1 new e2e spec. Disjoint from #126 (verified by reading `~/Projects/_worktrees/groups-page-tooltips/…/brief.md`).
- **Decided:** Namespace new keys under `card.*` (subnamespaces `card.directory.*` and `card.stream.*`) — clearly disjoint from #126's `page.*` namespace.
- **Decided:** REUSE `visibility.open|moderated|invite_only` for visibility badge ⓘ (issue explicitly requires reuse where copy exists). Add 5 new keys (issue estimated ~8; we're under because we honestly omitted keys for elements whose data doesn't exist yet — language chip, pinned badge, event-date chip).
- **Assumed:** Extending `groups_chrome.theme` preprocess to pass tooltip copy into twig is acceptable — theme file is NOT gitignored, and extending an existing preprocess to add data-passthrough variables is not the same as adding logic. The issue's "twig-template-only in Wave 1" language targets `.libraries.yml` (asset-pipeline coupling with #122), not preprocess data. **Flagged for A confirmation.**
- **Assumed:** Baseline `.do-chrome-info` CSS renders acceptably inside a card without new scoped rules — defer to F's Tier-1 visual check.
- **Evidence:** Read of `HelpText.php`, both twig templates, `groups_chrome.theme` lines 700-800, `DoChromeHooks::pageAttachments()`, #126 brief, WAVE-EXECUTION-HANDOFF §4/§6/§7, PROJECT_CONTEXT.md.

## Phase 3 — A (up-front architecture review)

**Verdict:** PASS — T may proceed to author RED.

### Summary
Plan is architecturally consistent with the established `do_chrome` tooltip pattern (#88/#89/#122/#126). Extend-vs-new posture is correct: the two existing preprocess functions in `groups_chrome.theme` are the right seams to extend, and appending to `HelpText::all()` matches the file's own documented "Extension pattern for a new surface" contract (HelpText.php:18-23). Disjointness with #126 verified. No blocks. Two soft observations for T/F.

### Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested guidance |
|---|---|---|---|---|---|
| 1 | soft | Wave-1 guardrail literal read (Option b: Twig extension) | dependency direction / abstraction level | Option (a) — extending existing preprocess to pass tooltip copy as `$variables['gc_directory']['tooltip_type']` etc. — is the correct call. No Twig extension (`Drupal\Core\Template\TwigExtension` subclass) exists anywhere in `docs/groups/modules/do_*` today (grep confirms zero hits). Introducing one now would invent a new abstraction layer solely for this story — the opposite of the reuse-first discipline. The issue's "no `.theme` edits" line is guarding the **`.libraries.yml` / asset-pipeline coupling with #122**, not preprocess data passthrough. Preprocess extension IS the neighboring pattern (see #122's own `groups_chrome_preprocess_group()` reading `HelpText::get('group_type.homepage_adapts')` — HelpText.php:180-193 documents this exact pattern). **Confirmed acceptable.** | Proceed with Option (a). T's RED can assert `$variables['gc_directory']['tooltip_type']` and `$variables['gc_stream']['tooltip_byline']` (or equivalent) are populated by the extended preprocess. |
| 2 | soft | Naming of new preprocess-array keys | naming | Brief shows `$variables['gc_directory']['tooltip_type']` as illustrative. Recommend a `tooltips` sub-array (e.g. `gc_directory.tooltips.type`, `gc_directory.tooltips.visibility`, `gc_directory.tooltips.members`) so the surface stays scannable and doesn't pollute the top-level `gc_directory` keyspace with 3 more flat siblings alongside `type_label`, `visibility_label`, etc. Same for `gc_stream.tooltips.{byline,type,comments}`. Not a block — flat naming works — but a grouped sub-array reads better next to the existing 12-key `gc_directory` shape. | F's call. If flat is chosen, use `tooltip_*` prefix consistently. |
| 3 | pass | Extend-vs-new (preprocess seams) | layering | The two existing preprocess functions (`groups_chrome_preprocess_views_view_fields__all_groups` at line 721 and `groups_chrome_preprocess_node` at line 76) are the correct objects to extend. Both already assemble `gc_directory` / `gc_stream` metadata by reading from HelpText's neighbors (group entity, term labels, allowed_values). Adding `HelpText::get()` reads to the same shape is a natural extension, not a parallel path. | — |
| 4 | pass | HelpText append-only invariant | pattern consistency | 5 new keys (`card.directory.type`, `card.directory.members`, `card.stream.byline`, `card.stream.type`, `card.stream.comments`) append after `persona.moderator` (HelpText.php:209). Namespace `card.*` is disjoint from every existing prefix (`demo.`, `audience.`, `visibility.`, `archive.`, `pin.`, `promote.`, `follow.`, `group_type.`, `content_type.`, `permissions.`, `showcase.`, `persona.`, and #126's `page.*`). Contract at HelpText.php:11-13 explicitly names this pattern. | — |
| 5 | pass | Disjointness with #126 | dependency direction | File sets disjoint: #126 owns `Hook/PageHelp.php` (NEW) + `page.*` keys; #127 owns 2 twigs + preprocess-extension in `groups_chrome.theme` + `card.*` keys. HelpText is the only shared file — append-only contract makes concurrent appends safe (different namespaces, both after `persona.moderator`). DOM disjoint: card ⓘ is inside `.gc-card`, page ⓘ is inside `page_title.html.twig`'s `title_suffix` slot. No double-tooltip risk. | — |
| 6 | pass | Behavior-changes guardrail (markup + copy only) | pattern consistency | Plan adds inline `<span>` triggers only; no route, entity, field, or schema changes. Language chip / pinned badge / event-date chip correctly omitted — their backing data does not exist in `gc_directory` / `gc_stream` preprocess arrays (verified against `groups_chrome.theme:734-749` for directory, and stream preprocess at line 76+). Adding them would require new preprocess variables and new field reads = scope creep. Honest omission is the right call. | If a follow-up story adds pinned/event/language data, append `card.stream.pinned` / `card.stream.event_date` / `card.directory.language` under the same `card.*` namespace. |
| 7 | pass | A11y DOM baseline | cross-cutting consistency | `tabindex="0"` + `role="note"` + `aria-label="{copy}"` + `data-do-tooltip="{copy}"` matches #89 `GroupTypeContentHelp::infoTrigger()` and #122's `groups_chrome_preprocess_group()` rendering and #126's `PageHelp::preprocessPageTitle()`. Baseline contrast 5.36:1 (AA) established by #122 carries over. | — |

### Notes for T
- RED can assert both preprocess extensions populate their tooltip copy from `HelpText::get('card.…')` (single-source assertion — no duplicated string literals in the spec). Additionally assert the visibility tooltip resolves via `HelpText::get('visibility.' . $variables['gc_directory']['visibility'])` to prove the reuse path.
- E2E should assert 6 elements carry `data-do-tooltip` (3 directory + 3 stream) plus `tabindex="0"`, `role="note"`, non-empty `aria-label`, and at least one tippy-visible on hover, per the brief's AC-6.

### Patterns referenced
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only contract at lines 11-13, 18-23; existing key catalog)
- `docs/groups/modules/do_chrome/src/Hook/GroupTypeContentHelp.php` (ⓘ trigger DOM pattern)
- `web/themes/custom/groups_chrome/groups_chrome.theme:76` (`preprocess_node` — `gc_stream` shape)
- `web/themes/custom/groups_chrome/groups_chrome.theme:721` (`preprocess_views_view_fields__all_groups` — `gc_directory` shape)
- `~/Projects/_worktrees/groups-page-tooltips/docs/planning/handoffs/126-page-tooltips/brief.md` (sibling story — disjointness verified)
