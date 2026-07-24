# Park note — #245 PERF-5 asset optimization audit

**Parked:** 2026-07-24
**Branch:** `245-perf5-asset-audit` (at `401354d`, same as origin/main at parking; branch is now 3 commits behind main after subsequent merges)
**Reason:** F subagent went silent for 3+ hours with no progress signal after two orchestrator nudges. Kill-restart-if-ugly authorization invoked; work parked rather than left running indefinitely.

## Phase reached

**Phase F (implementation / audit gathering).** No prior phases produced output either — no brief, no survey, no decisions journal, no partial report was committed. Working tree at park time was clean.

## What got stuck

The `feature-implementor` subagent (agentId `ad9c7070f06f3dce0`) accepted the audit task and never surfaced any progress, blocker report, or partial output. Two SendMessage nudges from O went unanswered before the coordinator called park. Root cause unknown from the outside — plausibly:

- Live-demo asset enumeration via `curl` + grep-based parsing over many pages became a long serial loop with no periodic check-in.
- DDEV spin-up may have hung; no stderr surfaced.
- Subagent may have entered a long tool call (large curl batch, mass file walk) that exceeded practical response cadence for a POC audit.

No specific tool failure was reported — the failure mode was **silence**, not a named error.

## Partial findings worth preserving

**None committed.** The subagent produced no artifacts that survive parking. The task brief itself (in the coordinator's kickoff message) remains the best starting point for the next attempt.

## Recommendation for next attempt

1. **Split the audit into explicit sub-steps with hard time budgets** rather than one open-ended F prompt. Suggested breakdown:
   - **Step A (5 min, static):** Grep the worktree for CDN references (`unpkg.com`, `cdn.jsdelivr.net`, `cdnjs`) and confirm `web/libraries/leaflet/` exists + is referenced by a `*.libraries.yml`. This alone satisfies acceptance criterion "Leaflet vendoring confirmed" and needs no live site.
   - **Step B (10 min, static):** Enumerate all module/theme `*.libraries.yml` files under `web/modules/custom/`, `web/themes/`, and list CSS/JS assets they declare. Sum bytes with `wc -c`. This gives a shipped-asset inventory without a live site.
   - **Step C (15 min, static):** Read `config/sync/system.performance.yml` — record CSS/JS aggregation state. If aggregation is off, that's a 1-line inline fix.
   - **Step D (optional, live):** DevTools Coverage / per-page HTTP request counts against `https://groups.performantlabs.com`. Defer to manual pass if it looks like it'll take more than 30 min.

2. **Cap the whole story at 90 min wall-clock.** If not done by then, ship what's static and file the runtime measurements as a follow-up.

3. **Have F commit early and often** — a WIP commit at each step means the next park has something to build on. Explicitly instruct: "commit the partial report after each of steps A/B/C before moving to the next."

4. **Consider doing this yourself (orchestrator-run) rather than delegating to F.** The audit is mostly grep + read + write — no complex implementation. The delegation overhead exceeded the actual work.

5. Before restarting, `git pull` to fast-forward the branch to current `main` (currently 3 behind).

## Files not created

- `docs/planning/perf/asset-audit-2026-07-24.md` (the target deliverable) — not started.
- Any inline config fix — none applied.

## Handoff to Monday

User can either restart with a fresh F prompt following the recommendation above, or run the static portions directly.
