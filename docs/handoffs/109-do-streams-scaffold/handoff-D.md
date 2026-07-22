# Handoff-D: Phase 2 - do_streams shared stream shell (issue #109)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Mode:** (a) generated low-fi
**Wireframe:** docs/handoffs/109-do-streams-scaffold/wireframe.html

## Screens & states covered

All 6 states required by the brief's "Approved wireframe" section, in one scrollable HTML file
(section-labeled `State N of 6`) for legibility rather than 6 separate files:

1. **Default (Global / Recent, many results).** Global tab active, Recent ranking active, 3
   simplified stream_card-shaped rows. Baseline state — no empty-state or scope-restriction copy
   needed.
2. **My Feed tab active.** Membership-scope selected; 2 rows, both from groups the (illustrative)
   viewing user belongs to. Demonstrates the ranking control stays independently selectable
   (Recent still shown active) regardless of scope tab.
3. **Following tab active.** 1 row shown deliberately to visualize the OR-of-3-flags dedupe
   guarantee ([B-9]/acceptance criterion) — a node that matched more than one follow branch still
   appears once.
4. **Trending tab active.** Per [B-8], annotated explicitly as Global scope + Hot ranking
   forced/defaulted — the Hot pill in the ranking control is rendered `is-active` even though the
   user did not click it, specifically to make the default visible. This is the state most likely
   to need operator sign-off since it is a behavioral default, not just a visual choice (see Open
   questions).
5. **Ranking control toggled to Hot under a non-Trending tab (My Feed).** Proves the control is
   orthogonal to scope selection ([B-2]) — same 2-row scope set as state 2, reordered by hot
   score, ranking pill switched independent of which tab is active.
6. **Empty state.** `empty: true` — zero results, `.gc-empty` block with scope-specific truthful
   copy ("You're not following any content, people, or tags yet...") and a CTA naming the actual
   prerequisite (follow something) rather than a generic "no results" message, per the D
   playbook's truthful-copy requirement.

All 6 states show the shell wrapping simplified `stream_card`-shaped rows (avatar-initial, author
name + link, group badge, timestamp, title, snippet, comment count) as a black-box row shape only
— the card is NOT redesigned; its real rendering stays entirely owned by
`node--stream-card.html.twig` / `groups_chrome_preprocess_node()`, which this wireframe does not
touch.

## Existing components/patterns reused

- **Tab pattern:** modeled on the already-shipped `.gc-group-tabs` / `.gc-group-tabs__link` /
  `is-active` pattern in `group--full.html.twig` (Stream/Events/Members/About tabs on the
  single-group page) — same active-state convention (bottom border + bold + primary-700 text),
  applied to the shell's 4-item scope tab row.
- **Empty state:** reuses the EXISTING `.gc-empty` / `.gc-empty__title` / `.gc-empty__text` block
  already defined in `groups_chrome/css/chrome.css` (documented there as used by the all_groups
  directory and group_nodes stream empty-text areas) — the wireframe's empty state is a
  copy-adapted instance of this pattern, not a new visual language.
- **Card row shape:** simplified/black-boxed version of the real `gc-card` / `gc-badge` /
  `gc-stream-card__*` classes and structure already in
  `node--stream-card.html.twig` + `groups_chrome.theme`'s `gc_stream` variable (author, group
  badge, snippet, comment count) — same visual shape, not re-implemented as production markup.
- **Design tokens:** color, spacing, radius, font-size values approximate the actual custom
  properties in `groups_chrome/css/tokens.css` (`--gc-color-primary`, `--gc-color-bg-subtle`,
  `--gc-radius-lg`, `--gc-space-*`, etc.) so the mockup reads as belonging to the same design
  system even though it is a standalone low-fi file, not wired into the theme's actual CSS
  pipeline.
- **Ranking control (pill toggle):** net-new shape — no existing precedent for a two-way segmented
  control was found in the subtheme survey. Modeled loosely on the `.gc-badge--pill` radius token
  and general primitives (pill shape, primary-color active state) rather than inventing an
  unrelated visual language.

## No-hardcoded-routes confirmation

Every tab and the ranking control is annotated with its preprocess-variable origin
(`scope_tabs[n].id` / `ranking_control[n].id`) rather than a literal href/path. A legend at the
top of the wireframe states this explicitly. No `<a href="/some/literal/path">` appears anywhere
in the mockup — controls are rendered as plain labeled elements (`<span>`), consistent with "the
control's link/action target is built from the `url_or_param` value the preprocess function
supplies," not hardcoded in Twig.

## Icon/glyph note

No hand-authored SVG icon geometry was used. The only glyph is a Unicode pin character (via CSS
`content: "\1F4CC"`) used decoratively in state 4 to hint at ranking emphasis — not load-bearing,
easily dropped by F. All other controls are plain text/pill labels per the low-fi convention.

## Verification note

This wireframe was **not visually rendered in a browser** during this session — per this
project's standing instruction to work headlessly (no visible-preview tools unless explicitly
requested), only structural validation was performed: automated tag-balance checks (div/article/
nav/span/h3/p/ul/li all balanced), a count confirming exactly 6 `state` sections and exactly one
`gc-empty` block. The harness's own auto-preview panel may render the file separately; if visual
inspection surfaces any layout defect (e.g. a pill button overflowing, a badge wrapping oddly), a
human reviewer doing the approval pass should flag it before sign-off, since it is unverified by
me.

## Open questions for approval

1. **State 4's Trending/Hot default visualization.** I chose to render the Hot pill as
   `is-active` in the Trending state to make [B-8]'s "ranking forced/defaulted to hot" behavior
   visible, even though nothing in the brief specifies whether Trending should still let the user
   manually switch back to Recent (the brief says "forced/defaulted," which is ambiguous between
   "locked" and "pre-selected but changeable"). The wireframe shows Hot pre-selected but does not
   visually indicate whether Recent remains clickable under Trending — O/the human approver should
   confirm which is intended, since it affects whether F wires the Recent pill as disabled or just
   unselected-by-default under that tab.
2. **Empty-state copy varies by scope** (Following's copy differs from what Global's or My Feed's
   empty state should say — e.g. Global returning zero results is a very different, probably
   error-adjacent situation from Following returning zero because the user hasn't followed
   anything yet). Only one empty-state variant (Following) is mocked; the annotation flags that
   the real preprocess needs per-scope copy branches, not a single generic message. This is noted
   rather than designed for all 4 scopes to keep the wireframe focused per the brief's explicit
   ask ("a simple centered message is fine" for the empty state) — if the human approver wants all
   4 scope-specific empty copies mocked before sign-off, say so and I'll add them.

## Approval

**Approved by operator 2026-07-22T09:55:00Z** (via coordinator relay). D-gate accepted: covers all
6 states, reuses `.gc-group-tabs` / `.gc-empty` patterns (no new visual language), annotates every
control with its `scope_tabs[n].id` / `ranking_control[n].id` origin (satisfies "no hardcoded
routes" at design stage).

Both open questions resolved by the operator (F implements these; not re-opened):
1. **Trending's Recent pill = ENABLED (unselected but clickable), NOT locked/disabled.** Rationale
   ([B-2] orthogonality): ranking is independent of scope; a lock would contradict that. Trending
   DEFAULTS to Hot; the user may still switch to Recent (global+recent, harmless). So F wires the
   Recent pill under Trending as a normal unselected-but-clickable control, never `disabled`.
2. **Per-scope empty copy: F must provide DISTINCT empty-state copy per scope** (global / my_feed /
   following / trending), NOT one shared string. The Following copy mocked here is the model; F
   writes the other three with scope-appropriate CTAs (e.g. Global empty must NOT say "browse
   groups to follow"). Bake this into the preprocess as a per-scope branch.
