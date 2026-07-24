# Handoff-D: Phase 2 - Model comparison toggle wireframe (ST-8 / #130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Mode:** (a) generated low-fi (ASCII, matches #124's ASCII precedent shape)
**Wireframe:** `docs/handoffs/st8-model-toggle-130/wireframe.md`

## Screens & states covered

- `/stream` default state — switcher header, `Content view (soon)` disabled +
  `● Activity view` selected; rows unchanged (existing `activity_stream:page_1`).
- `?variant=content` fallback — resolves to `activity` (first-available rule), visually
  identical to default; documented as intentionally indistinguishable, not a degraded state.
- `?variant=activity` deep link — also visually identical to default; documented as proving the
  server-side resolution path works ahead of #129 giving it a real second option.
- Wrapper attribute contract: `data-do-stream-model="activity"` on `.views-element-container`
  in every reachable state today (only one available option exists).
- Keyboard behavior: Tab lands on Activity (only tabbable option); Arrow-L/R reaches the
  disabled Content option too (same precedent as #124/SC-F1's Map option) — explicit call made
  per the brief's request to document it for this instance.
- Focus ring — reused verbatim from #119/#124, no new CSS.
- `/showcase` catalog entry, before/after: `coming` -> `live`, new decision_sentence proposed.
- Explicitly excluded: Content view's actual rendering (#129), `/my-feed` (route doesn't exist
  in main, blocked on open #110) — noted as a one-line future hook-target-set edit.

## Tooltip copy proposal (`showcase.switcher.stream.model`)

> "Activity view aggregates everything happening in this scope — posts, comments, flags, pins,
> and membership changes — as one chronological feed of message rows. Content view (coming soon)
> will show just the posts themselves: a leaner model with no aggregated activity noise."

Covers all three required points: what's included in each view (named row types for Activity;
bare posts for Content), the "leaner" framing for Content, and "(coming soon)" qualification.

## Decision-sentence proposal (`/showcase` catalog, `stream-model` entry)

> "Compares a node-content model vs. an activity-log model for /stream — the decision: a lean
> feed of raw posts vs. a richer feed that also surfaces comments, flags, pins, and membership
> events as their own rows."

Replaces the current sentence ("single combined activity stream vs. per-content-type streams"),
which describes a comparison this story does not build.

## Existing components/patterns reused

- SC-F1 `VariantSwitcher::build()` + template + JS — verbatim, new `stream.model` instance,
  2 options instead of 3.
- #124's two-hook shape (`viewsPreRender` + `preprocessViewsView` + guard/helper pair) — same
  structure, new sibling hook class (per survey's "add sibling hooks, don't refactor" call).
- `.views-element-container` JS mirror selector — reused, not reinvented.
- `/showcase` `[ live ]` / `[ coming ]` badge convention — unchanged.
- Existing `activity_stream:page_1` row rendering, pager, empty-region copy — unchanged
  pass-through in every state.

No net-new visual device is introduced by this wireframe — every control, glyph, and layout
convention traces to #119/#124.

## Open questions for approval (flagged in wireframe.md, repeated here for O)

1. **Adjacent `showcase_help.stream-model` key** (the `/showcase` tour page's own per-entry
   orientation tooltip, distinct namespace from the new switcher-tooltip key) has the SAME
   staleness bug as the decision_sentence. Brief's Owns section doesn't name this key. Recommend
   fixing it in the same PR since both describe the same now-wrong comparison, but this widens
   the brief's file list by one HelpText entry — confirm with O/A before F touches it.
2. **Decision-sentence tense** — proposed sentence describes what Content view will show, in a
   `live`-status catalog entry, before Content view exists. This mirrors how `available: FALSE`
   options are always handled truthfully elsewhere (badge + tooltip both say "(soon)"/"(coming
   soon)"), so D's default is to proceed as drafted; flagging only so O/operator can veto the
   framing if they'd rather the sentence itself say "(Content view coming soon)" inline.
3. No icon/glyph rework risk — wireframe is pure ASCII/Unicode (`●`, `ⓘ`, box-drawing chars), no
   hand-authored SVG paths anywhere, consistent with `svg-glyphs.md` guidance by construction.

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>"]
