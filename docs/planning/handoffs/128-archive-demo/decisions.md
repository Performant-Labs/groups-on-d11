# #128 — Decision Journal

## Phase 1 (O): brief written, D skipped

- **Decided:** skip D. #128 is a seed semantic correction; the visual (Archive
  badge/tooltip + Pin badge/tooltip + Restore action) shipped in #92/#143 and is
  unchanged. Nothing new to design.
- **Decided:** skip brief-gate o4-mini review (POC lean, "none" rigor). #128 is
  spec-definitive — no plan choices to arbitrate.
- **Assumed:** Sprint Planning post's pin badge already renders on some anonymous-
  reachable page. T-RED will empirically verify and, if false, seed additional
  pin/surface. (Not blocking A up-front — A reviews the plan, T proves the
  premise.)
- **Assumed:** DoGroupExtrasHooks::nodeAccess() is the effective enforcement path
  for at least one anonymous- or non-Organizer-reachable content-create URL on an
  Archive-typed group. T-RED will pick whichever route holds; if none is
  reachable, will document with #143's non-blocking follow-up and re-scope AC-1c
  to "badge visibility as sole observable" (matching #143's PR-time posture).
- **Evidence:** survey.md sections "Key Findings" + "Reuse & Analogous-Feature
  Map"; issue #128 body; step_720:101; step_700:397–400.
