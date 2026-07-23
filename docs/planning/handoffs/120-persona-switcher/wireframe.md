# Wireframe — #120 SC-1 Persona Switcher (Browse-as dropdown)

**Mode:** (a) generated low-fi
**Status:** DRAFT — pending human approval (O records sign-off in `decisions.md`)

Low-fidelity structure only: layout, hierarchy, labels, controls, per-state copy. No production CSS
or pixel-accurate visuals. Matches the visual family of #119's `VariantSwitcher` (same tokens: 1px
border / 2px on selected-or-active, `#4da3ff` focus ring, non-color state glyphs, `do-showcase-*`
class prefix) but the widget SHAPE is a `<select>` dropdown, not a radiogroup — dropdown was
specified in the issue itself ("Dropdown chosen over chips for narrow-screen behavior").

---

## 1. Header dropdown widget

### Widget choice: native `<select>` — justified against WCAG 2.2 AA

| AC requirement | Native `<select>` | Custom `<details>`/`<button>+<ul>` |
|---|---|---|
| Keyboard operable (Tab, Enter/Space, Arrow) | Free — browser-native; Space/Enter opens, arrows move, Enter/blur commits | Must hand-roll roving tabindex + Esc-to-close + arrow nav (see #119's radiogroup — took real engineering) |
| Visible focus | Free — native focus ring, still themeable via `:focus-visible` | Must style focus on trigger + each `<li>` |
| Screen-reader announce | Free — SR announces "combobox", label, each option, selected state | Must hand-write `role="listbox"`/`role="option"`/`aria-selected`/`aria-expanded` correctly |
| Non-color state (selected persona) | Free — `<option selected>` is inherently non-visual-only; SR announces it | Must add glyph same as #119 did |
| No-JS fallback | Free — a bare `<select>` inside a `<form>` with a submit still works with zero JS | Needs a `<noscript>` fallback |
| Mobile / narrow screen | Free — native OS picker (large touch targets, no custom overlay to get wrong) | Must hand-roll a touch-friendly popover |

**Decision: native `<select>` inside a one-control `<form method="post">`, auto-submitting on
`change` via a small progressive-enhancement script (submits the form when JS is present; a
visually-hidden "Go" `<button type="submit">` — real `<button>`, not `#type => submit` — covers the
no-JS case, per the `#type => submit` renders `<input>` gotcha in PROJECT_CONTEXT.md).** This is the
simplest thing that satisfies every AC bullet with zero custom ARIA to get wrong. Rejected the
custom disclosure: for a 4-item, single-choice, form-submitting control, `<select>` has no AC gap
to justify the added keyboard/ARIA engineering and re-test surface.

### Collapsed state (default, closed dropdown)

Placed in the site header, header account-menu region (same nav row as #110's account links) —
**append-only** block placement, new block plugin `persona_switcher`, region: header
secondary/account area. Visible to ALL visitors (anonymous AND authenticated-as-persona), since
the banner (section 2) is what shows *current* persona and the dropdown always shows the *switch*
control, including "back to Anonymous."

```
┌──────────────────────────────────────────────────────────────────────┐
│  [Groups logo]     Home  Groups  Streams  /showcase          ⓘ▾ Browse as: [ Anonymous        ▾ ]   [Log in] │
└──────────────────────────────────────────────────────────────────────┘
                                                          ^^^^^^^^^^^^^^^^^
                                                          <select> — 4 options,
                                                          label "Browse as",
                                                          ⓘ tooltip trigger to its left
```

- `<label for="persona-switcher-select">Browse as</label>` — visually present (not sr-only; matches
  #119's visible "Viewing" label convention), text-associated with the `<select>` via `for`/`id`.
- One `ⓘ` tooltip trigger **per option row inside the open dropdown is not possible in native
  `<select>`** (an `<option>` cannot host a `data-do-tooltip`/tippy trigger — options render in the
  browser's own native popup, outside the DOM tooltip engine's reach). **Resolution:** a single
  wrapper-level `ⓘ` (same one-tooltip-per-widget pattern `VariantSwitcher` already established)
  whose copy is a short combined line naming all four personas and what each demonstrates, PLUS —
  to satisfy "each option carries a tooltip" as closely as native HTML allows — every `<option>`
  carries a native **`title` attribute** with that option's specific one-liner. `title` is not a
  tippy/do_chrome tooltip (browsers render their own delayed native tooltip on desktop hover; most
  don't render `title` on mobile) but it is the only per-option hover/host mechanism a real
  `<select>` supports, and it degrades gracefully (present but silent on touch). See "Open
  questions" below — this is flagged for O/human confirmation since it's a slight reinterpretation
  of "per-option tooltips render."

### Expanded state (native OS/browser dropdown popup — not ours to draw pixel-precisely)

```
[ Anonymous                    ▾ ]
  ┌────────────────────────────────────┐
  │ Anonymous                     ●    │  <- current selection, native check/highlight
  │ Elena Garcia — Member              │
  │ Maria Chen — Organizer             │
  │ Groups-Moderate                    │
  └────────────────────────────────────┘
```

Each `<option>` value + label + `title=` (per-option hover text, see resolution above):

| value | visible label | `title=` (native hover text) |
|---|---|---|
| `anonymous` | `Anonymous` | The logged-out visitor's view (default). |
| `elena-garcia` | `Elena Garcia — Member` | An active member across several groups — demonstrates the plain Member view. |
| `maria-chen` | `Maria Chen — Organizer` | Holds the Organizer group role on a seeded group — can edit the group and manage its members. |
| `moderator` | `Groups-Moderate` | Site-wide moderation role — can review the pending-join queue and approve/archive/restore groups it isn't a member of. Nothing beyond that scope. |

Wrapper-level `ⓘ` combined tooltip (do_chrome `data-do-tooltip`, one per widget — matches
`VariantSwitcher`'s one-tooltip-per-wrapper convention):

> "Switch who you're browsing as: Anonymous (logged out), Elena (a Member), Maria (an Organizer),
> or Groups-Moderate (site moderation) — see the demo from each point of view. This never asks for
> a password."

### Control behavior annotations

- `<select id="persona-switcher-select" name="persona">` — **enabled always** (even when a persona
  is already active; selecting `Anonymous` from an active-persona state is the switch-back path).
- Selecting a new value **auto-submits** the enclosing form (progressive enhancement: `change`
  event listener submits; the visually-present `<button type="submit">Go</button>` — real
  `<button>` element per the `#type=>submit` pitfall — is the no-JS fallback, not hidden, placed
  immediately after the select).
- Current persona is pre-selected (`<option selected>`) on every page load — server sets it from
  session state, so a page refresh always shows the true current state, never a stale default.
- No option is ever `disabled` — all 4 personas are fully public per the issue's 2026-07-22 update;
  there is no "coming soon" state for this widget (unlike #119's variants).

---

## 2. Persistent banner (active-persona indicator)

Rendered via `hook_page_top` (same idiom `DoShowcaseHooks::pageTop()` already established for the
POC ribbon — "single global attach point," visible markup, not reshuffling nav DOM) — appended
**below** the existing POC ribbon when a persona (not Anonymous) is active. Two independent
`page_top` elements can coexist (ribbon + persona banner) since `page_top` accepts multiple keyed
children.

### State: no persona active (Anonymous) — banner absent

```
┌──────────────────────────────────────────────────────────────────────┐
│ (POC ribbon only, if not dismissed)                                  │
└──────────────────────────────────────────────────────────────────────┘
[ ... normal page ... ]
```
No banner markup renders at all (not hidden via CSS — truly absent from the DOM) when the visitor
is Anonymous. Truthful-empty-state rule: nothing to announce, so nothing renders.

### State: persona active (e.g. Elena Garcia)

```
┌──────────────────────────────────────────────────────────────────────┐
│ ▶ You're browsing as Elena Garcia — Member.  [Switch back to Anonymous] │
└──────────────────────────────────────────────────────────────────────┘
```

- Wrapper: `<aside role="status" aria-label="Active persona" class="do-showcase-persona-banner">` —
  `role="status"` (not `alert`) because this is a persistent ambient state, not an interrupt; it's
  announced politely once on the page-load pass, not re-announced on every subsequent page nav
  unless the persona changes (browsers announce `role="status"` regions when their content
  *changes*, which naturally happens exactly on persona switch, not on unrelated navigation).
- Position: **inline, top of main content area** (below header/ribbon, above page title) — NOT
  `position: fixed` — the issue doesn't ask for it to survive scroll, and a second fixed bar stacked
  under the (also-fixed) POC ribbon would eat vertical space on mobile. If a fixed banner is
  preferred, flag under Open Questions.
- Non-color status icon: leading `▶` glyph (aria-hidden, decorative — the *text* "You're browsing
  as…" carries the actual meaning, matching #119's rule that color/glyph is never the sole cue).
- Copy is **exact per the issue**: `"You're browsing as {name} — switch back"` — instantiated per
  persona:
  - Elena: **"You're browsing as Elena Garcia — Member — switch back"**
  - Maria: **"You're browsing as Maria Chen — Organizer — switch back"**
  - Moderator: **"You're browsing as Groups-Moderate — switch back"**
- "Switch back" is a real `<a href="/persona-switch/anonymous">` link (not a JS-only affordance,
  not `#type=>submit`), independently Tab-reachable, activates on Enter, carries its own visible
  focus ring (`:focus-visible`, same `#4da3ff` token as the dropdown/#119).
- Background/text: reuse `.do-showcase-ribbon`'s dark/light contrast pairing token (`#1a1a1a` bg /
  `#ffffff` text — already AA-contrast-checked in that stylesheet) so the banner reads as "part of
  the same chrome family," but a **distinct class** (`do-showcase-persona-banner`, not reusing
  `.do-showcase-ribbon` directly) since it's semantically a different, stateful surface — dismiss
  control excluded (this banner has no dismiss; it disappears only via switch-back, since it
  reflects real session state, not a one-time announcement).

---

## 3. Switch flow (per persona)

All four follow one controller path (`PersonaSwitchController`), differing only by target uname
(`NULL` = anonymous/switch-back).

### 3a. Anonymous → Elena Garcia (Member)

1. Visitor on any page, dropdown shows `Anonymous` selected, no banner.
2. Selects `Elena Garcia — Member` → form auto-submits (or clicks `Go` no-JS) →
   `POST /persona-switch/elena-garcia`.
3. Controller: uid-1 guard passes (Elena isn't uid 1) → allowlist check passes → logs the session
   into Elena's account (`user_login_finalize`) → redirects back to the **same URL** the visitor was
   on (`destination=` preserved), or to `<front>` if the prior page requires a permission Elena
   lacks (rare for POC; flagged under Open Questions).
4. Reload: dropdown pre-selects `Elena Garcia — Member`; banner appears: "You're browsing as Elena
   Garcia — Member — switch back."

### 3b. Anonymous → Maria Chen (Organizer)

Same path, target `maria-chen`. Banner: "You're browsing as Maria Chen — Organizer — switch back."
Maria's session now holds the Organizer group role on her seeded group — she sees group-edit /
manage-members controls on that group's page that Elena's session would not show.

### 3c. Anonymous → Groups-Moderate

Same path, target the seeded `groups_moderate_demo` account. Banner: "You're browsing as
Groups-Moderate — switch back." This session can reach the pending-join queue and
approve/archive/restore any group, but `/admin/config`, `/admin/people`, and module pages
**403** (access-layer enforcement, verified by a negative Functional test — not a UI hide, since a
hidden-but-reachable route would fail the "can do nothing beyond that scope" AC).

### 3d. Any active persona → switch back to Anonymous

1. Visitor (in any of the 3 persona sessions) clicks the banner's **"switch back"** link, OR
   re-selects `Anonymous` from the dropdown.
2. `GET /persona-switch/anonymous` (switch-back is idempotent/safe as a GET per masquerade's own
   "unmasquerade" convention — Tester/Feature to confirm against the installed module, matching
   survey gotcha #1) — logs the session out, discarding the persona session entirely (POC scope: no
   nested masquerade-of-masquerade; always a full logout, per survey's "always treat switching as
   logout+login" decision).
3. Redirects to the same URL. Reload: dropdown resets to `Anonymous`, banner disappears, no
   leftover persona state.

### 3e. Persona A → Persona B directly (dropdown re-selection while already a persona)

Selecting a different persona while one is already active (e.g. Elena session, select Maria) is
**not a distinct interaction** from the visitor's point of view — same one-control dropdown, same
auto-submit. Internally: full logout of A, then full login as B (per survey decision: "ALWAYS treat
switching as logout+login"), landing back on the same URL, banner updates to name B.

---

## 4. Tooltip copy (do_chrome `HelpText::all()` — 4 new append-only keys, prefix `persona.*`)

Keys, per brief's suggested naming:

```
'persona.anonymous' => 'The logged-out visitor's view (default) — no session, no persona active.',

'persona.elena' => 'Elena Garcia is an active Member across several groups — the plain-member point of view: can join, post, and leave groups, but cannot manage members or edit group settings.',

'persona.maria' => 'Maria Chen holds the Organizer role on a seeded group — demonstrates what an Organizer can do beyond a plain Member: edit the group and manage its members.',

'persona.moderator' => 'Groups-Moderate is the site-wide moderation persona — can review the pending-join queue and approve, archive, or restore any group, even ones it isn't a member of. Nothing beyond that scope: no user administration, no site configuration.',
```

Plain text, 1–2 sentences each, no HTML (matches `HelpText`'s "keep values plain text —
do_chrome.tooltips.js renders them with allowHTML disabled" rule). Each is honest about POC
boundaries (Maria's is scoped to "a seeded group," Moderator's explicitly states its scope limit).

This is the **wrapper-level combined `ⓘ`** tooltip content-source; the per-`<option>` native
`title=` strings in section 1 are shorter derivatives of the same four keys (kept in sync by
reading from the same `HelpText::get('persona.*')` values at render time, not hand-duplicated
strings — Feature implementor's job to wire, not Designer's to re-author).

---

## 5. A11y checklist (WCAG 2.2 AA)

- **Name/label:** `<label for="persona-switcher-select">Browse as</label>` — programmatically
  associated, visibly present (1.3.1 Info and Relationships; 2.5.3 Label in Name — visible label
  text is a substring of/matches the accessible name).
- **Keyboard operable:** native `<select>` — Tab focuses it, Space/Enter/Down opens the native
  popup, Arrow keys move selection, Enter/Tab commits (2.1.1 Keyboard — free with native control).
  "Switch back" link and "Go" fallback button are both real focusable, Enter-activatable elements
  (no `<div onclick>` anywhere).
- **Visible focus:** `:focus-visible { outline: 2px solid #4da3ff; outline-offset: 2px; }` applied
  to the `<select>`, the "Go" button, and the "switch back" link — same token already used by
  `.do-showcase-variant-switcher` and `.do-showcase-ribbon` (2.4.11 Focus Not Obscured / 2.4.7 Focus
  Visible — consistent, never suppressed).
- **Contrast:** header dropdown text/border against the existing header background must be
  verified ≥ 4.5:1 for text, ≥ 3:1 for the `<select>`'s border (1.4.11 Non-text Contrast) — Feature
  implementor to check against the site's actual header background color (not specified in this
  wireframe; existing header presumably already AA per PROJECT_CONTEXT's "existing suite stays
  green" bar). Banner reuses the ribbon's already-AA dark-bg/white-text pairing (#1a1a1a / #ffffff
  — well above 4.5:1).
- **Non-color status:** banner carries the leading `▶` glyph **and** explicit text ("You're
  browsing as…") — status is never color-only. Dropdown's current selection is carried by the
  native `<option selected>` mechanism (inherently non-visual — SR announces it, sighted users see
  it as the visible select value) — no reliance on a color highlight alone.
- **Screen-reader announce:** `role="status"` on the banner announces politely on the page load
  immediately following a switch (browsers announce a `role="status"` region's content once it's
  present in the DOM on that render — Feature implementor to confirm this fires correctly on a
  full-page navigation, not just a live DOM mutation, since this is a server-rendered banner, not a
  JS-inserted live region).
- **No motion/timeout dependency:** nothing on this surface has a timing requirement (2.2.1) — the
  banner persists until explicit switch-back, no auto-dismiss.

---

## 6. Responsive / narrow-screen behavior

The issue itself specifies: *"Dropdown chosen over chips for narrow-screen behavior."* Native
`<select>` inherits the platform's own touch-friendly picker UI at any viewport width with zero
extra work:

```
Narrow (< 480px) header, collapsed:
┌────────────────────────────┐
│ ☰  [Groups]     [Browse as ▾] [👤] │
└────────────────────────────┘
```

- On narrow screens the visible `<label>` text "Browse as" may collapse to an icon-only affordance
  IF the header's existing nav-collapse pattern already does this for other controls (survey did
  not surface a specific existing narrow-header component to copy exactly — Feature implementor
  should match whatever #110's account-menu area already does at this breakpoint, since this widget
  sits in the same region). The `<select>`'s accessible name (`<label for=…>`) must remain in the
  accessibility tree even if the visible label text is visually hidden at that breakpoint (use
  `sr-only`/visually-hidden CSS, never `display:none` on the `<label>` itself, or the name is lost
  for everyone, not just sighted narrow-viewport users).
- Tapping the `<select>` opens the OS-native picker (full-screen sheet on mobile Safari/Chrome) —
  no custom overlay to build, test, or get an AA violation in.
- The banner (section 2) is inline in normal document flow, so on narrow screens it simply wraps
  onto two lines under the header rather than needing a distinct mobile layout:
  ```
  ┌────────────────────────────┐
  │ ▶ You're browsing as       │
  │   Elena Garcia — Member.   │
  │   [Switch back]            │
  └────────────────────────────┘
  ```

---

## 7. Open questions for approval

1. **Per-option tooltip interpretation** (section 1): native `<select>` cannot host a live
   do_chrome/tippy tooltip on individual `<option>` elements (browser-native popup, outside the
   DOM). This wireframe proposes ONE wrapper-level combined `ⓘ` tooltip (do_chrome-rendered, tippy)
   plus native `title=` attributes per `<option>` as the closest per-option mechanism HTML allows.
   **Confirm this satisfies the issue's "Each option carries a `do_chrome` tooltip" language**, or
   direct a different interpretation (e.g., accept that literal per-option do_chrome tooltips are
   not achievable with a native `<select>` and this is a known, accepted gap of the widget choice).
2. **Banner position — fixed vs inline:** proposed inline (scrolls away with content). Confirm this
   is acceptable, or a fixed/sticky banner is wanted instead (tradeoff: fixed keeps the switch-back
   link always reachable without scrolling, but stacks vertical chrome under the also-fixed POC
   ribbon on mobile).
3. **Switching away from a page the target persona cannot view** (e.g., visitor is on
   `/admin/config` — reachable only as Groups-Moderate is impossible anyway, but consider Elena on
   a page Maria can't reach, or vice versa — unlikely given all 3 authenticated personas share broad
   view access, but not verified against every route). Proposed: redirect to `<front>` if the
   destination 403s post-switch, rather than showing a 403 immediately after a successful persona
   switch. Confirm, or accept the simpler "always redirect to prior URL, let 403 show if it
   happens" (POC-simplest — likely fine given the personas' access is mostly additive, not
   restrictive, relative to each other).

If no objections are raised, item 1's proposed interpretation and items 2/3's proposed defaults are
the working assumptions carried into Architecture/Feature.
