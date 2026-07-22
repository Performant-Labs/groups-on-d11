# O's implementation notes for F (grounding, read-only findings)

These resolve two of the brief-gate / D open questions with concrete evidence so F doesn't
re-discover. F still owns the implementation.

## B-1 — do_chrome tooltip init on the switcher: RESOLVED, no special code needed
`docs/groups/modules/do_chrome/js/do_chrome.tooltips.js` registers `Drupal.behaviors.doChromeTooltips`
and uses `once('do-chrome-tooltip', '[data-do-tooltip]', context)`. Because it's a Drupal behavior
keyed on `context`, it:
- attaches to the server-rendered switcher ⓘ trigger at page load, AND
- re-attaches automatically to any AJAX-inserted DOM (Drupal re-invokes `attach` with the new
  context; `once()` prevents double-binding).
So F just needs to: (a) render the switcher's ⓘ trigger with
`#attributes['data-do-tooltip'] => HelpText::get('<switcher key>')` and `tabindex="0"`, and
(b) attach the `do_chrome/tooltips` library on the /showcase page and wherever the switcher renders
(or rely on DoChromeHooks::pageAttachments which already attaches it site-wide — confirm). NO manual
tippy re-init, NO new tooltip engine. There is also a `drupalSettings.doChrome.controlTooltips`
selector-map channel if a trigger can't carry the attribute, but the attribute path is simpler here.

## Tooltip-trigger precedent (copy this shape)
- `do_chrome/src/Hook/GroupTypeContentHelp.php` (~line 144) and `ArchivePinHooks.php` (~lines 66, 99)
  render an ⓘ trigger with `'tabindex' => '0'` + `data-do-tooltip`.
- `do_chrome/templates/do-chrome-permission-matrix.html.twig` (line 27): `tabindex="0"
  data-do-tooltip="{{ intro_tooltip }}"`.
One ⓘ per widget WRAPPER, not one per option (do_chrome house pattern; D's wireframe follows it).

## Disabled-option / radiogroup a11y: no existing precedent — implement fresh per wireframe
`grep` across `docs/groups/modules` found NO existing `role=radiogroup` / `aria-checked` /
roving-tabindex / segmented-control / `aria-disabled` precedent. So the switcher's a11y pattern is
new. Per resolved open-Q 3: unavailable option = `aria-disabled="true"` + `tabindex="-1"` (removed
from tab order), stays visible with "(soon)" text (non-color, truthful). Selected option =
`aria-checked="true"` + a leading non-color glyph (D's wireframe uses `●`), visible focus ring
distinct from selection. `#disabled`/`tabindex` on render elements is standard Drupal; no core
convention is being violated.

## Ribbon (resolved open questions)
- Fixed-top placement; MUST NOT cover primary nav or reflow nav DOM (keep tests/e2e/nav.spec.ts
  green). A fixed-position element that doesn't push nav down is the safe shape.
- Dismiss = real `<button aria-label="Dismiss demo banner">`, keyboard-operable, client-side persist
  (cookie/localStorage) — NO server session write (keeps anon page cache warm).
- No "re-show ribbon" affordance needed; /showcase stays reachable via normal nav.

## /showcase catalog honesty
- "Membership models — open/request/invite" entry stays `[coming]` (request-to-join is bespoke in
  #121; grequest is incompatible with group 4.0.x). Do not imply it's live.
- Only render a deep-link where status is `live`; `[coming]` entries have no dead link.
