# O's response to dual-review-diff.md (round 1) — diff gate, second-opinion (o4-mini)

Verdict received: **BLOCK — 2 findings**. O's adjudication:

## [B-1] scope_tabs missing `url_or_param` — ACCEPTED, real BLOCK → routed to F + T

**Confirmed real.** Verified directly:
- F's `DoStreamsHooks::preprocessDoStreamsShell()` builds each `scope_tabs` entry as
  `{id, label, active}` only (lines ~415-422) — the `url_or_param` field the brief's [B-3] shell
  contract mandates (`scope_tabs` = array of `{id, label, url_or_param, active}`) is dropped.
- T's `StreamsShellTest::testScopeTabsContractAllFourPresentWithCorrectActiveFlag` DOCBLOCK cites
  the `{id, label, url_or_param, active}` contract but the ASSERTION only checks id/label/active —
  so the field went both unimplemented (F) AND unpinned (T). Neither in-pipeline gate caught it;
  the diff gate did. This is exactly the gate earning its cost.

**Disposition:** route back to F to add `url_or_param` to each `scope_tabs` entry (derived from
its `id`, per the "no hardcoded routes" criterion — a query-parameter mapping like `?scope=<id>`,
NOT a hardcoded route path). T repairs `testScopeTabsContractAllFourPresentWithCorrectActiveFlag`
(and the shell-render/no-hardcoded-routes test as needed) to assert every entry carries a
non-empty `url_or_param` that is a parameter mapping, not a literal route path. Cycle re-enters at
F → T(GREEN) → A-dup.

## [B-2] array_unshift ordering hypothesis "unverified against Views core" — MAINTAINED (not a real BLOCK)

**Not routed to F.** The reviewer flags that F's pinned-first
`array_unshift($query->orderby, ...)` relies on the runtime assumption that `views_query_alter`
fires AFTER the view registers its own sorts (so the unshifted pin CASE becomes the PRIMARY sort
key) — "borrowed from do_group_pin, not verified against Views core."

This concern is **already empirically resolved by a passing behavioral test**, which the reviewer
could not see (it reviews the diff + brief + F handoff, not the GREEN test run):
- `StreamsRankingTest::testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker` is a LIVE view-execution
  kernel test (executes the real altered `do_streams_demo` view via
  `Views::getView()->execute()` and asserts on `$view->result`). It seeds a PINNED OLDER node and
  a NEWER unpinned node and asserts the pinned older node LEADS — which is true ONLY IF the pin
  CASE is the PRIMARY sort key (if it were a secondary tie-breaker, as in the historical #52 bug,
  the newer node would lead). This test GREEN-passes in T's Phase-6 run (23/23).
- This is the exact runtime behavior [B-2] asks to "verify," proven end-to-end against real Views
  dispatch — the same way `do_group_pin`'s own `PinnedStreamOrderingTest::testPinnedNodeLeadsStream`
  proves it for the precedent this pattern extends. A static-source verification would be strictly
  weaker than the passing behavioral proof already in the suite.

So the ordering hypothesis is verified — by execution, not by source-reading — and no code change
is warranted. Recorded as MAINTAINED with this evidence; if the reviewer re-raises in round 2
after seeing the test, escalate; otherwise this is resolved.

## WARN findings

- **[W-1] hardcoded join-table list in `queryViewsDoStreamsDemoAlter()`** — noted. F's current
  list (`do_streams_comment_stats`, `do_streams_hot_score`, `do_streams_pin_flagging`) covers
  exactly the joins F's own `viewsQueryAlter()` adds, and A-W1 asked for "generic by table-name
  membership" which F implemented as an explicit membership set (not do_group_pin's single hardcoded
  alias). The reviewer's suggestion (discover from `$query->getTables()`) is a reasonable
  robustness improvement for future joins; folding into the [B-1] F-rework as a NIT-level ask
  (make the aggregate-target discovery iterate the joins this hook added rather than a static
  name list, IF cheap — not blocking, since following-scope is EXISTS not a JOIN in the current
  design so no new join tables are pending).
- **[W-2] per-flagger cache-tag invalidation scope** — this is the SAME design question F and T
  already adjudicated in Phase 5/6 (handoff-F §Design-decisions + handoff-T-green). Per-flagger
  `do_streams:user_stream:<uid>` invalidation is the correct, DERIVABLE, [W-4]/[A-W2]-consistent
  choice (the flagging entity gives no signal to correctly identify OTHER affected viewers without
  an over-broad broadcast). T's rewritten cache-tag test pins exactly this. No change.

## NITs (folded into the F-rework as optional polish)

- **[NIT-1]** `theme()` should merge `$existing` (`return $existing + [...]`) — accept, cheap
  correctness nicety, F applies.
- **[NIT-3]** README `url_or_param` example — accept, F adds a one-line example when it adds the
  field for [B-1].
- **[NIT-4]** comment noting why `storage->get('base_field')` (synthetic field) — accept, F adds.
- **[NIT-2]** SQL indentation in FollowingScope — accept if trivial; not load-bearing.

## Summary

1 real BLOCK ([B-1], routed to F + T), 1 maintained-with-evidence ([B-2], resolved by an existing
passing behavioral test), 2 WARN (both already-adjudicated design choices, [W-1] folded as a NIT),
4 NIT (folded into the F-rework). Cycle re-enters at F for the `url_or_param` fix + NITs, then
T(GREEN) repairs the covering assertion, then A-dup.
