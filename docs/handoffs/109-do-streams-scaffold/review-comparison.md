# Review-rigor comparison — brief gate (second-opinion) — 109-do-streams-scaffold

**Rung:** second-opinion. Outside reviewer: o4-mini via `dual-review.sh --mode brief`.
**Caveat:** this is a gate-ROI read (o4-mini vs the in-pipeline A/T/S gates that have not run
yet at this point in the cycle), not a controlled model-skill comparison — o4-mini received the
raw brief; A/T/S will receive role-specific prompts with pipeline context later in the cycle.

## What o4-mini caught (round 1, 9 BLOCK + 4 WARN + 4 NIT)

All 9 BLOCK findings were real, load-bearing gaps in the brief as originally written — not noise:

- **[B-4] (follow_term field name)** was the single highest-value catch: the brief's own survey
  had said "follow_term → via the node's tag reference field" without naming it, and the obvious
  guess (`field_tags`, the only tag field visible in a quick `find`) is WRONG for every
  group-relevant bundle (it only exists on `article`, which isn't a `group_node:*` type). Had this
  gone to T/F unresolved, T would likely have authored a test against the wrong field and F would
  have implemented against it — a real defect this gate caught before any code existed.
- **[B-1] (last-activity ranking ambiguity)**, **[B-9] (membership join chain)**, and **[B-6]
  (GROUP BY columns)** were genuine underspecification — the brief said "the pattern," not the
  exact SQL shape; F would have had to invent a shape, and getting it wrong (e.g. sorting
  last-activity by `changed` alone, which is indistinguishable from "recent") would not have
  failed any test T wrote against the same ambiguous spec — a silent correctness bug the pipeline
  might not have caught until S or a much later story noticed "last-activity" and "recent" behaved
  identically.
- **[B-2], [B-3], [B-7], [B-8]** were real scaffolding gaps that would have caused D/T/F to each
  independently guess at the shell contract, ranking-control mechanism, demo view shape, and
  "Trending" tab semantics — exactly the kind of drift the up-front A review (Phase 3) exists to
  catch, but catching it here (before A even spawns) is cheaper: no A rework loop needed.
- **[B-5]** was a false-ish alarm in substance (T-owns-tests is already the pipeline's standing
  rule, stated in `workflow-coding-pipeline.md`) but a legitimate catch of *brief-completeness*:
  the brief itself didn't restate it, so a reader of the brief alone (as F/T are meant to be) would
  have had to cross-reference the pipeline doc. Worth the one-line fix.

## What the in-pipeline gates (A/T/S) had not yet had a chance to catch

None yet — this comparison runs at the brief gate, before A/T/S execute. The [B-1]/[B-6]/[B-9]
findings are exactly the class of thing A's Phase-3 "layering/placement/naming drift" review is
designed to catch, so there is real overlap in *what* these gates protect against — the value of
running dual-review at the brief gate is catching it ~1 phase earlier (before A spawns) rather than
duplicating A's job.

## Net "did the gate earn its cost" read

**Yes, clearly.** [B-4] alone (the field-name error) would likely have cost a full T-red -> F ->
T-green rework cycle if caught at Phase 6 instead of Phase 1 — one wrong assumption (`field_tags`
vs `field_group_tags`) baked into both the authored tests and the implementation. Catching it here
cost one `dual-review.sh` invocation (~1-2 minutes) plus a documentation-only brief amendment. The
other 8 BLOCK findings were lower-severity but still real spec gaps that would have forced F to
either stop-and-ask (burning an Operator-Decision-Threshold escalation) or guess (risking an A-dup
or S rework cycle later). Round 2 confirms all 9 are resolved (PASS, all ACCEPTED) with zero
disagreement — no escalation needed.
