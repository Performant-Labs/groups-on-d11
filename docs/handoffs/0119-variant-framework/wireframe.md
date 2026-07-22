# Wireframe — 0119-variant-framework (SC-F1: variant framework)

Low-fidelity, structure-only. Mode (a) — generated. Reuses `do_chrome` tooltip chrome (ⓘ trigger +
`data-do-tooltip` / tippy.js) and the `do_notifications`/`do_discovery` controller-page layout
conventions (plain page title + intro + table/list, no new visual language). No color system, no
final CSS — states are conveyed via text/shape/ARIA notes, not hex values.

---

## Surface 1 — Labeled variant-switcher device

A reusable segmented control. Caller supplies `instance_id`, `options[]`, `current`. Rendered by
`VariantSwitcher::build()`. Example instance: `directory.layout` with options
Compact list / Cards / Map (per the issue's own example — SC-5/SC-6 wire the real options later;
this story ships a stub instance for demonstration).

### State: default (three options, "Cards" selected)

```
Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
         │ Compact list │ ● Cards      │     Map      │
         └──────────────┴──────────────┴──────────────┘
          (unselected)    (SELECTED)     (unselected)
```

- Wrapper: `role="radiogroup" aria-label="Viewing"` (or a `<fieldset><legend>Viewing</legend>`
  — F picks one per Drupal form-API convention; either satisfies AA labeling). One-line label
  precedes the control, not a placeholder inside it.
- Each option: `role="radio" aria-checked="true|false" tabindex` per roving-tabindex pattern
  (one option in tab order at a time; Arrow-Left/Right moves selection, matching native radiogroup
  behavior) — keyboard-operable per WCAG 2.2 AA.
- **Selection is conveyed by more than color:** the selected option shows a leading `●` glyph
  (rendered as `aria-hidden="true"`, selection state itself carried by `aria-checked`) AND a
  distinct border weight/fill (low-fi: heavier box), never color alone.
- ⓘ tooltip trigger sits to the right of the control group (one per switcher instance, not per
  option) — matches the `do_chrome` field-level-intro pattern in `VisibilityTooltip.php`
  (one ⓘ per widget wrapper, not one per radio child). Its trigger carries
  `data-do-tooltip="<HelpText::get('showcase.switcher.<instance_id>')>"` and a plain
  `#description` fallback (no-JS-safe, same dual-channel pattern `do_chrome` already uses).
  Copy behavior: "what differs between these variants" (per the issue's own phrasing) — e.g.
  "Compact list favors scanning many groups fast; Cards shows more per-group detail; Map plots
  groups geographically."
- Behavior annotation per control: clicking/selecting an option updates `current`, re-renders the
  wired content below (view/listing — out of this story's scope beyond the stub), and persists the
  choice client-side (cookie/localStorage keyed by `instance_id`) — no full-page reload required,
  but a no-JS fallback (plain links with `?variant=`) is included so the control degrades to
  ordinary in-page navigation without JS.

### State: focus (keyboard user tabs to "Compact list")

```
Viewing: ┌══════════════┬──────────────┬──────────────┐  ⓘ
         ║ Compact list │ ● Cards      │     Map      │
         └══════════════┴──────────────┴──────────────┘
          ▲ visible focus ring (double border, low-fi stand-in
            for outline: 2px solid + offset — never focus:outline:none)
```

- The double-line box is the low-fi stand-in for a visible focus ring (`:focus-visible` outline,
  ≥2px, offset from the control edge, AA-contrast against both selected and unselected fills).
  Focus ring is drawn on the option that currently has DOM focus, independent of which option is
  *selected* (here: focus is on "Compact list", selection is still "Cards" — the two states are
  visually distinguishable from each other, not conflated into one glyph).

### State: unavailable / degraded (one variant not wired yet)

```
Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
         │ ● Compact list│    Cards     │ Map (soon)   │
         └──────────────┴──────────────┴──────────────┘
                                          ▲ disabled: aria-disabled="true",
                                            not clickable, no aria-checked
                                            toggle; label says why
```

- Truthful copy: the unavailable option's visible label appends "(soon)" rather than silently
  omitting itself or rendering as a dead click target with no explanation — "graceful when a
  variant is unavailable" per the issue's acceptance wording.
- `aria-disabled="true"` + removed from tab order (or present but announced disabled, per whichever
  ARIA-authoring-practice the team's other disabled controls already use — F confirms against an
  existing disabled-control example in the codebase, if any, or defaults to
  `aria-disabled` + `tabindex="-1"`).
- Selection automatically falls back to the first available option if `current` names an
  unavailable one (never silently renders nothing selected).

### State: no-JS

```
Viewing: [ Compact list ]  [ Cards (current) ]  [ Map ]
          ^ plain links with ?variant=<id> query param, current one marked
            in text ("(current)"), not just visually — works without the
            segmented-control JS/localStorage behavior at all.
```

---

## Surface 2 — `/showcase` tour page

Controller-rendered page (follows `do_notifications`/`do_discovery`'s `ControllerBase` +
`.routing.yml` pattern — plain page title, intro paragraph, then a list/table build array; no new
template language). Public route, `_permission: 'access content'` (matches `do_discovery`'s public
page precedent — this page is itself a POC artifact meant to be seen by anonymous visitors).

### State: default (mixed live / coming entries)

```
================================================================
 POC Showcase — What this demo compares
================================================================
 This page lists every side-by-side comparison in this demo,
 the decision each one represents, and whether it's live yet.

 ────────────────────────────────────────────────────────────
 Discovery ranking                              [ live ]
 Compares three ways to surface groups: Recent, Hot, Promoted —
 the decision: how much editorial curation vs. raw recency.
 → View this comparison
 ────────────────────────────────────────────────────────────
 Directory presentation                         [ coming ]
 Compares list vs. card layouts for the group directory — the
 decision: information density vs. visual scannability.
 (not yet built — tracked in issue #124)
 ────────────────────────────────────────────────────────────
 Membership models                              [ coming ]
 Compares open-join vs. request-to-join vs. invite-only — the
 decision: how much friction gates group membership.
 ────────────────────────────────────────────────────────────
 Group-type homepages                           [ coming ]
 Compares a generic group page vs. a type-tailored homepage —
 the decision: general-purpose UI vs. per-type customization.
 ────────────────────────────────────────────────────────────
 Stream model                                   [ coming ]
 Compares a single combined activity stream vs. per-content-type
 streams — the decision: one feed to scan vs. filtered feeds.
 ────────────────────────────────────────────────────────────
 Private-group reveal                           [ coming ]
 Compares always-visible groups vs. private groups that reveal
 membership only after joining — the decision: open discovery
 vs. member-only privacy. (#134)
 ────────────────────────────────────────────────────────────
 Persona switcher                                [ live ]
 Switch between four public personas to see the demo from each
 point of view — the decision: one generic anonymous view vs.
 role-tailored experiences.
   • Anonymous — the logged-out visitor's view (default).
   • Elena Garcia — an active member across several groups.
   • Maria Chen — a group admin/organizer.
   • Moderator — a site-wide moderation role.
 → Try the persona switcher
 ────────────────────────────────────────────────────────────
================================================================
```

- Each entry: title, one-sentence decision framing (truthful — states the tradeoff, not marketing
  copy), a status badge, and (for `live` entries only) a deep-link that lands pre-switched to that
  comparison's default variant. `coming` entries show no dead link — either no link at all or a
  link to the tracking issue, never a link to a page that doesn't exist yet (truthful-copy rule:
  don't imply availability that isn't there).
- **Status badge is not color-only:** the bracketed text `[ live ]` / `[ coming ]` is the
  status cue itself (text, not a colored dot) — satisfies the non-color-status-cue AA requirement
  by construction; a low-fi implementation might additionally use a checkmark/hourglass glyph
  (`✓ live` / `… coming`) but the text label is the load-bearing cue either way.
- Persona-switcher entry (#120) is content/copy only in this story (no code dependency) — it
  explicitly names all four public personas per the brief's acceptance criterion, and is visually
  identical in structure to the six comparison entries (title / decision sentence / status /
  link), just with an extra four-line persona list nested under it.
- List order in this wireframe follows the brief's acceptance-criteria enumeration order
  (discovery ranking, directory presentation, membership models, group-type homepages, stream
  model, private-group reveal, persona switcher) — an arbitrary but stable ordering; F may
  reorder for narrative flow as long as all seven entries are present.

### State: empty (hypothetical — not expected to occur, since the catalog is a code constant with
seven entries always present, but the render path is documented for completeness)

```
================================================================
 POC Showcase — What this demo compares
================================================================
 Nothing to show yet — check back soon.
================================================================
```

- Not reachable in normal operation (the `ShowcaseCatalog` array always has entries in this
  story's scope) — included only so the render array's `#empty` case has agreed truthful copy if a
  future refactor ever produces a genuinely empty list, rather than silently rendering a blank page.

### State: error (route reachable, catalog build fails)

```
================================================================
 POC Showcase — What this demo compares
================================================================
 This page couldn't load its comparison list right now.
 Try reloading, or check back soon.
================================================================
```

- Truthful, non-technical copy; no stack trace exposed. Matches Drupal's normal
  watchdog-logs-the-real-error / user-sees-friendly-message convention — no new error-handling
  mechanism invented for this page.

---

## Surface 3 — Site-wide "POC demo" ribbon

Injected via `#[Hook('page_attachments')]` or `page_top`, following `DoChromeHooks`'s existing
"single global attach point" pattern (the tooltip library is attached exactly once, globally; the
ribbon is the same shape of always-present chrome). Fixed position, does not reshuffle nav DOM
(explicit constraint — `nav.spec.ts` must stay green).

### State: default (not yet dismissed) — shown to anonymous AND authenticated identically

```
┌──────────────────────────────────────────────────────────────────┐
│ ⓟ This is a proof-of-concept demo. See what it compares →        │  ✕
└──────────────────────────────────────────────────────────────────┘
  ▲ fixed top of viewport, above the page's own header/nav; full
    width; low-fi stand-in — final placement (fixed top vs. a
    corner) is F's implementation call as long as it doesn't cover
    primary nav or reflow existing nav DOM.

 [ ...rest of page content below, unaffected... ]
```

- Copy: "This is a proof-of-concept demo." + a link "See what it compares →" pointing to
  `/showcase`. Same copy and behavior for anonymous and authenticated — no session-dependent
  branching (per the acceptance criterion "shows site-wide for anonymous + authenticated").
- `✕` is a real `<button type="button" aria-label="Dismiss demo banner">` — not a styled `<a>`
  or `<div onclick>` — keyboard-operable (`Tab` reaches it, `Enter`/`Space` activates it), visible
  focus ring on it same as Surface 1's focus-ring treatment.
- The link to `/showcase` is a real `<a href="/showcase">`, independently keyboard-reachable from
  the dismiss button (two distinct interactive elements, not one overloaded control).

### State: focus (keyboard user tabs to the dismiss button)

```
┌──────────────────────────────────────────────────────────────────┐
│ ⓟ This is a proof-of-concept demo. See what it compares →     ╔══╗│
│                                                                 ║✕ ║│
└──────────────────────────────────────────────────────────────────┘
                                                                  ╚══╝
                                                          ▲ visible focus
                                                            ring, matches
                                                            Surface 1's
                                                            treatment
```

### State: dismissed

```
[ ribbon absent — page content occupies the space the ribbon used;
  nav/header position unaffected either way, so no layout shift
  beyond the ribbon's own height collapsing ]
```

- Dismissal persists client-side (cookie/localStorage) — "survives navigation without a server
  session" per the brief. Reappears only if the client-side flag is cleared (new browser profile,
  cleared storage, or explicitly if the team later adds a "show again" affordance — not in this
  story's scope).
- No re-entry point is required by this story's acceptance criteria (the ribbon links to
  `/showcase`, which remains reachable via normal nav regardless of ribbon state) — flagged below
  as an open question in case the operator wants one anyway.

---

## Cross-surface AA affordance summary

| Affordance | Switcher | /showcase | Ribbon |
|---|---|---|---|
| Labeled control/region | `aria-label`/`legend` "Viewing" | page `<h1>` + intro | banner text itself is the label; dismiss button has `aria-label` |
| Keyboard operable | roving-tabindex radiogroup, arrow keys | standard link/anchor tab order | `<button>` + `<a>`, both natively tabbable |
| Visible focus ring | yes (double-border low-fi stand-in) | native link focus (browser default acceptable) | yes (double-border low-fi stand-in) |
| Non-color status/selection cue | `●` glyph + `aria-checked`, not color alone | `[ live ]` / `[ coming ]` text badge, not color alone | n/a (no status states beyond present/dismissed) |
| AA contrast | deferred to F's CSS pass; low-fi has no color values to fail | same | same |
| `t()`-wrapped strings | all labels/copy | all labels/copy | all labels/copy |
