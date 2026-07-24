# Brief — #194 profile_activity.section consumer wiring

## Objective
Wire the orphaned `profile_activity.section` HelpText key (authored by ST-5 #114 at HelpText.php:403) to its intended consumer surface: the "Recent posts" block on `/user/{uid}`. Add a `data-do-tooltip` trigger + the tooltips library attachment so the ⓘ affordance actually fires in the browser.

## Surface (single, narrow)
`DoStreamsHooks::preprocessBlock()` at `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php:612-620` — already scoped to `USER_ACTIVITY_BLOCK_PLUGIN_ID` (`views_block:user_activity-block_1`). Only method touched by F.

## Fix (minimal diff)
1. In `DoStreamsHooks::preprocessBlock()` after the existing wrapper-class attach:
   - `$variables['attributes']['data-do-tooltip'] = HelpText::get('profile_activity.section');`
   - `$variables['attributes']['tabindex'] = '0';` (keyboard reachability — matches PermissionMatrixPanel + PageHelp precedent)
   - `$variables['#attached']['library'][] = 'do_chrome/tooltips';`
2. Add `use Drupal\do_chrome\HelpText;` import.
3. Add `do_chrome:do_chrome` to `do_streams.info.yml` `dependencies:` list.

Rationale — attribute on block wrapper, not a separate ⓘ badge: matches the wrapper's existing role as the block's outer container (heading + rows are siblings under it, per the class docblock at lines 597-605). Whole-section hover matches the SD-2/SD-4 pattern used elsewhere (`.do-chrome-perm-matrix__intro`).

## Reuse map
- Analogous consumer: `PermissionMatrixPanel` + `do-chrome-permission-matrix.html.twig` (adds `data-do-tooltip` + `tabindex="0"` on wrapper, attaches `do_chrome/tooltips`). Same pattern, applied via preprocess hook rather than twig override.
- HelpText contract: append-only, key already present at line 403 — no HelpText.php edit.
- Library: `do_chrome/tooltips` already binds tippy.js to `[data-do-tooltip]` globally (js/do_chrome.tooltips.js:20). Reused verbatim.
- Extend vs new: EXTEND — the existing `preprocessBlock` method already scopes to the correct plugin_id, is 3 lines long, and adds a wrapper class + library. Adding two attribute lines + one library line is the smallest possible extension.

## Contention with sibling #193
Sibling #193 (SD-4 tooltip consumers) wires `stream.*` + `chrome.stream_switcher` keys onto `node--*--stream-card.html.twig` variants and `do-streams-shell.html.twig`. Different files, different keys. Both PRs will likely add `do_chrome:do_chrome` to `do_streams.info.yml` — treat that line as a rebase conflict to resolve by union (add once).

## Acceptance criteria
- [ ] Rendered block markup on `/user/{uid}` contains `data-do-tooltip="This person's recent published posts…"` on the block's outer wrapper.
- [ ] `tabindex="0"` present on the same wrapper (keyboard reachability).
- [ ] `do_chrome/tooltips` library appears in `#attached['library']` when the block renders.
- [ ] Existing `do-streams-profile-activity` wrapper class + `do_streams/profile_activity` library attachment preserved (no regression on #114).
- [ ] `do_chrome` present in `do_streams.info.yml` dependencies.
- [ ] Kernel test asserts all four attributes/attachments in one render pass.

## Non-scope
- No do_chrome edits (HelpText key already present).
- No twig template override (preprocess-attribute pattern only).
- No new tooltip badges/glyphs — the whole block wrapper is the trigger.
- Sibling #193 handles stream-card + shell keys.

## Review-rigor
`none` — one-attribute fix, deterministic surface, mirrors existing pattern.

## Branch / worktree
`194-profile-activity-consumer` @ `C:/Users/aange/Projects/_worktrees/groups-profile-activity-consumer-194` (base `d014e29`).
