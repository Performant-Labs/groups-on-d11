# Decision Journal — #251 Cache-tag gaps

## Phase 1 — O (brief)
**Decided:** POC lean pipeline (O→A→T→F→T→diff-gate→S→PR→self-merge). Skip D (no UI), U (no UI), A-dup (POC lean), brief-gate (rigor=none). One bundled PR covering all 3 gaps per issue recommendation.
**Assumed:** Audit report §1-§4 is authoritative (evidence-cited to file:line, high-confidence); no re-investigation of gaps needed. Fix 3 (`cache: type: none` in `all_groups.yml`) is a config-only change per audit §4 Rec 3(a). Fix 2 can be done with `comment_insert` hook + `Cache::invalidateTags()` without a Views handler subclass.
**Hedged:** Advisory-hold armed for Fix 2/3 scope explosion (Views subclass, entity_predelete, controller refactor). If either fix needs those, pause for per-gap split.
**Evidence:** `docs/planning/perf/cache-audit-2026-07-24.md` §2 Scenarios 2/3/4, §4 Recs 1/2/3.

## Phase 3 — A (up-front plan review)
**Decided:** PASS. Plan extends the correct analogous objects for all 3 fixes; no parallel paths, no drift.
**Assumed:** `$member` returned by `$relationship->getEntity()` on a `group_membership` plugin-id relationship is a User entity whose `id()` is the correct key for `user_stream:{uid}` — verified against `DoActivityHooks.php:142-155` where the same idiom is used (`$member->id()` treated as user id).
**Hedged:** Fix 2's tag-consumption side (view render must attach `do_discovery:hot_score:{nid}`) is the one place scope could grow; brief's advisory-hold trigger explicitly covers it. Trust the trigger, do not pre-block.
**Evidence:** `do_activity/src/Hook/DoActivityHooks.php:122-157`, `do_streams/src/Hook/DoStreamsHooks.php:465-475`, `views.view.{my_feed,trending,following_feed}.yml` `cache: type: none` precedent.

## Phase 4 — T (RED)
**Decided:** Author cache-tag-checksum / structural-config assertions instead of literal HTTP
render-diffs for Fix 1 and Fix 3, per the task's own "acceptable alternative" clause. Fix 2 keeps a
literal render-order Functional test (no cache layer masks it — `/trending` already declares
`cache: type: none`). No advisory-hold trigger fired for any fix; all three stayed within the brief's
named analogous objects.
**Assumed:** `MyFeedController::buildShell()`'s render array is never itself written to Drupal's
`render` cache bin (no `#cache[keys]` declared) — confirmed empirically via a throwaway probe, not
merely inferred, before committing to the checksum-based test design.
**Hedged:** Fix 3 ships 1 RED (Kernel, structural: `all_groups.yml` must declare `cache: type: none`)
+ 2 already-green Functional acceptance tests (member-vs-non-member correctness; anonymous-leak
negative control) — disclosed explicitly in handoff-T-red.md rather than silently presented as 3
REDs. Both naive HTTP-round-trip designs for Fix 3 (membership join; privacy flip) were probed and
found to be invalid REDs in this harness (authenticated sessions are `UNCACHEABLE` regardless of the
fix; an entity save's own cache-tag invalidation masks the query-alter's real gap) — not silently
substituted, the probes and reasoning are recorded in the handoff.
**Evidence:** 3 diagnostic probe tests (built, run, deleted — not committed) showing
`X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)` / `X-Drupal-Cache: UNCACHEABLE (request
policy)` on every authenticated `/my-feed` and `/all-groups` request in this harness regardless of
fix state; a synthetic-render-array probe confirming Drupal's RenderCache only persists entries with
explicit `#cache[keys]`; a probe confirming `/all-groups`'s anonymous requests DO get genuine
`X-Drupal-Cache: MISS`→`HIT`. All three RED assertions (do_streams checksum, do_discovery score/order,
do_group_extras Kernel config) captured with exact failing output in handoff-T-red.md. Full
Kernel-suite regression run (99 tests, 1 intended failure) confirms no interference with existing
coverage.

## Phase 5 — F (implement, drive RED to GREEN)
**Decided:** Implemented all 3 fixes exactly per the brief's Reuse map — no advisory-hold trigger
fired. Fix 1: 2 new hook methods on `DoStreamsHooks` (`groupRelationshipInsert()`/
`groupRelationshipDelete()`, `group_membership` plugin-id scoped, reusing `userStreamCacheTag()` +
`Cache::invalidateTags()`). Fix 2: new `#[Hook('comment_insert')]` on `DoDiscoveryHooks` delegating to
a new private `recomputeNodeScore(int $nid)` (mirrors `cron()`'s exact query/formula, scoped to one
node) + a new public static `hotScoreCacheTag()` tag-builder, invalidated after the row write. Fix 3:
3-line YAML addition (`cache: {type: none, options: {}}`) to `all_groups.yml`'s default display,
matching the 3 sibling views' exact precedent.
**Assumed:** `CommentInterface::getCommentedEntityTypeId()`/`getCommentedEntityId()` (no entity load)
is the correct, established idiom for resolving a comment's parent without loading the entity —
verified against `DoNotificationsHooks::commentInsert()`'s identical usage in this same codebase
before using it, not merely assumed from Drupal core API familiarity.
**Hedged:** `Cache::invalidateTags(['do_discovery:hot_score:{nid}'])` is called even though
`/trending`'s OWN view already declares `cache: type: none` (no live consumer of that tag exists yet)
— kept per the audit's own §4 Rec 2(a) framing ("a new, node-scoped tag... `/trending`'s
controller/view would need to consume") as forward-looking, zero-cost infrastructure for a future
consumer, not dead code. Did not treat T's Fix-2 render-order test passing as proof this call is
"needed" for that specific test (it isn't — the view's own live re-query is what makes that test
pass); kept the invalidation call because the audit named it as part of the recommended fix shape,
and a future render consumer of this tag should not require a second do_discovery change to start
working.
**Hedged:** Did not edit `do_group_extras`'s module-local test fixture for
`AllGroupsMembershipCacheInvalidationTest` (still lacks Fix 3's `cache: type: none`, now diverging
from the shipped config it was copied from at RED time) — verified (ran both tests, both still pass)
rather than assumed this divergence is harmless, then flagged it in handoff-F.md for T/S rather than
silently editing a test-owned fixture file, per the F role boundary (F writes/edits no tests, and a
fixture under `tests/fixtures/` is a T-authored artifact).
**Hedged:** Used `phpcs --standard=Drupal,DrupalPractice` (this project's own established convention,
confirmed against `docs/handoffs/0119-variant-framework/handoff-F.md` and
`docs/groups/TESTING_STRATEGY.md`) instead of the issue's literal bare `phpcs <dir>...` invocation,
which — confirmed empirically, not assumed — produces 8218 unrelated errors across every file in all
3 modules (a generic non-Drupal default standard, since this repo has no root `phpcs.xml.dist`).
Compared before/after against the git-stashed, in-place (not renamed) original files to get an
artifact-free diff of net-new findings, after first discovering and discarding a false signal from an
earlier renamed-copy comparison approach (a "class name doesn't match filename" error was silently
suppressing further DrupalPractice sniff output on that renamed copy — caught by re-running against
the real in-place baseline via git stash rather than trusting the renamed-copy numbers).
**Evidence:** All 3 previously-RED tests now GREEN (individually and in a combined 6-test run); the
2 already-green Fix-3 Functional tests confirmed still GREEN (non-regression, verified by running,
not assumed); full Kernel regression sweep across the 3 touched modules: 99 tests, 0 failures
(T's own RED baseline: 99 tests, 1 intended failure); full Functional regression sweep: 34 tests,
0 failures (grep-confirmed zero `✘` markers in the raw output). PHPCS before/after line-by-line
comparison against the git-stashed baseline: 0 net-new errors on both changed PHP files, 1 net-new
warning (`\Drupal::time()` call matching 2 pre-existing identical calls in the same file); a
line-length warning found during this pass was fixed by reflowing prose, confirmed fixed by
re-running phpcs. Full command outputs + exact line-shift arithmetic in handoff-F.md.

## Phase 6 — T (GREEN + Tier 2)
**Decided:** All 3 previously-RED tests confirmed GREEN, independently re-run (not just trusted
from F's handoff). Full sibling sweep (99 Kernel + 34 Functional = 133 tests across the 3 touched
modules) confirmed 100% green, 0 failures, matching F's own reported totals exactly. Applied the
fixture-parity fix F flagged (`do_group_extras/tests/fixtures/config/views.view.all_groups.yml`
now carries `cache: type: none`, matching shipped `all_groups.yml`) directly, since this is a
T-owned test artifact — not routed back to F.
**Assumed:** F's phpcs "0 net-new errors, 1 net-new warning" framing is trustworthy as-is; verified
independently by re-running `phpcs --standard=Drupal,DrupalPractice` on both changed files and
matching the same finding at the same line (752 pre-existing error in `DoStreamsHooks.php`; line
275 net-new `\Drupal::time()` warning in `DoDiscoveryHooks.php`, matching 2 pre-existing identical
calls at lines 60/85 in the same file).
**Hedged:** Did not re-run F's exact git-stash-based "before" comparison (trusted the shifted-line
arithmetic in handoff-F.md) — instead independently re-derived the same 2 findings via a direct
current-state phpcs run and confirmed both are consistent with F's characterization (pre-existing
error location shifted by insertion size; 1 net-new warning matching an established in-file
pattern), which is sufficient corroboration without needing to reproduce F's exact stash workflow.
**Evidence:** All 3 Phase-4 RED tests individually re-run to GREEN with exact command + output
transcribed in handoff-T-green.md. Full Kernel sweep: 99/99 green (grep-confirmed 0 `✘`). Full
Functional sweep: 34/34 green (grep-confirmed 0 `✘`; PHPUnit's own exit 1 traced to 3 pre-existing
unrelated "Risky" tests, not a failure). Fixture-drift claim verified both empirically (re-run
GREEN before AND after the fix) and structurally (read both test bodies to confirm the mechanism
each pins is independent of the Views cache-plugin value) before applying the fix. `phpcs` and
`assemble-config.sh` both independently reproduced. Diff review of both hook classes + the YAML
change confirmed shape parity with the audit's §4 recommendations (hook names, plugin-id
discriminator, cache-tag naming) and with sibling-module/sibling-view precedent.

## Phase 9 — S (final spec-audit gate)
**Decided:** PASS. All 3 acceptance criteria from issue #251 trace to passing T-authored tests; all 3 implementations match the audit's §4 Rec 1/2/3 recommended shapes verbatim (`group_relationship_insert`/`_delete` scoped by `group_membership` plugin id reusing `userStreamCacheTag()`; `comment_insert` recomputing single-node hot_score row via a mirror of `cron()`'s query/formula + new node-scoped `hotScoreCacheTag()`; `cache: type: none` YAML block matching 3 sibling views). Zero drift, zero parallel paths, zero new classes/services/views. Cache-tag naming consistent with existing `userStreamCacheTag()`/`rsvpChipCacheTag()` convention. Hook signatures correct (`GroupRelationshipInterface`, `CommentInterface`), plugin-id discriminator uses a private constant matching sibling `DoGroupExtrasHooks::GROUP_MEMBERSHIP_PLUGIN_ID`. Assemble/PHPCS/Kernel(99)/Functional(34) all green in the 3 touched modules. F's 2 non-blocking deviations both acceptable (fixture drift resolved by T in Phase 6; PHPCS invocation uses project's established `--standard=Drupal,DrupalPractice` per `TESTING_STRATEGY.md`).
**Assumed:** Behavioral symmetry between join-side and delete-side hooks is sufficient coverage even though T did not author a separate `groupRelationshipDelete` assertion — the two methods share identical scoping, identical invalidation target, and identical `Cache::invalidateTags()` call, so the join-side test proves the mechanism. Filed as advisory for a possible future symmetric test, not a rework blocker.
**Hedged:** F's forward-looking `Cache::invalidateTags([self::hotScoreCacheTag($nid)])` call in Fix 2 has no live consumer today (`/trending` uses `cache: type: none`), so the tag is invalidated but nothing currently carries it — kept as PASS because this matches the audit's own §4 Rec 2(a) framing ("a new, node-scoped tag... `/trending`'s controller/view would need to consume") and mirrors sibling `rsvpChipCacheTag()`'s own pre-consumer state; not dead code, forward-correct at zero cost.
**Evidence:** Reviewed `git diff --cached` for all 3 production files (YAML: 3-line addition at correct position matching sibling `my_feed.yml`/`following_feed.yml` key order; `DoStreamsHooks.php`: 2 new hook methods + 1 private constant, `use Drupal\group\Entity\GroupRelationshipInterface` added, exact discriminator/invalidation shape from `DoActivityHooks::groupRelationshipInsert()`; `DoDiscoveryHooks.php`: 1 new hook method + 1 private helper + 1 new public static tag-builder, mirroring `cron()`'s query/formula shape single-node-scoped). Reviewed all 4 handoffs (A/T-red/F/T-green) end-to-end. Verified fixture-drift resolution (`do_group_extras/tests/fixtures/config/views.view.all_groups.yml` now carries same 3-line block as shipped config).
