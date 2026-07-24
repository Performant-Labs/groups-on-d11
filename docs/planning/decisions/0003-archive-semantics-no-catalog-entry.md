# 0003 — Archive semantics ship, but get no standalone /showcase catalog entry

**Status:** Documented (2026-07-24)
**Sources:** Issue #219, upstream https://git.drupalcode.org/project/groupsdrupalorg/-/issues/3578797 (item #7)

## Decision

Archive semantics ("Archived = published + read-only," semantically distinct
from unpublished content) **do ship** as part of the moderation workflow.
They are **not** given a dedicated `/showcase` catalog entry.

This is not a rejection in the same sense as
`0002-theme-toggle-rejected.md` — the feature exists and is demonstrable.
The question this record answers is narrower: does it get its own top-level
comparison card on the tour page, or does it stay inside the workflow that
already surfaces it?

## Rationale

- Post-#133 SD-6, every `/showcase` catalog entry is `live` with a real
  routed target — no `coming` slots, no dead links (the honesty-sweep
  invariant). Adding a NEW entry means it must clear that same bar today,
  not aspirationally.
- Archive semantics don't have a demonstrable STANDALONE comparison surface.
  There is no page that puts "archived vs. unpublished" side by side for a
  visitor to see in one view — the distinction is only observable by taking
  a moderation action (archiving something) and then noticing the read-only
  behavior on the result. That is a workflow, not a static comparison.
- The distinction is best experienced inside the moderation workflow that
  actually surfaces it — a Groups-Moderate persona performing archive
  actions on a specific group — not as a top-level catalog card that would
  need to point somewhere before the visitor has taken any action.
- Adding a `live` catalog entry that points at a page which only "kind of"
  demonstrates the distinction would violate the truthful-copy rule: the
  entry copy would either mislead the visitor about what they will see on
  arrival, or the demo would need a new dedicated comparison surface built
  just to justify the entry — out of scope for REL-3.

## Consequences

- `/showcase` has no `archive-semantics` catalog entry, and none is planned
  for REL-3.
- Archive-semantics behavior remains observable via the moderation workflow
  (Groups-Moderate persona actions on a specific group), not via a
  standalone tour card.
- The parity report (`docs/planning/parity/showcase-vs-upstream.md`) records
  this as resolved-via-decision-record, matching the precedent set by #197 /
  `0002-theme-toggle-rejected.md`.

## Implementation

No code change. Documented so the question stays answered.
