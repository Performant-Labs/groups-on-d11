# Decision Journal — #111 ST-2 Following feed

Append-only. One entry per phase.

## O — Phase 1 (survey + brief) — 2026-07-23

**Decided:**
- Skip D. No novel UI surface (visual atoms owned by ST-F1 shell + shared `stream_card`); only new artefact is one view YAML + one small CSS file + seed additions.
- Clone `views.view.activity_stream.yml` (analogous, at `/stream`) rather than extending it or extending `views.view.do_streams_demo.yml`. Disjoint file ownership matches the epic's parallel-story contract; extension would collide with sibling #110.
- Access: `access.type: role, role: authenticated`. Idiomatic Drupal way to get anonymous → 403 without a custom controller.
- Sort: `created DESC` (drop `last_comment_timestamp` from the analogous view). Predictable in tests; recency by publication date matches spec "Recent ranking".
- Dedupe: rely on `distinct: true` + EXISTS-based scope filter (no LEFT JOIN fan-out per FollowingScope.php:29). No GROUP BY needed.
- Group-access enforcement: rely on Views SQL rewrite (default) + group module's grant system. Explicit T coverage required (kernel test preferred).
- Seed additions (three, append-only): Elena→Maria (follow_user), Elena→drupalcon (follow_term), Sophie→Paragraphs tutorial (follow_content). Matches spec verbatim.

**Assumed:**
- `activity_stream.yml`'s `access: perm, perm: access content` works for anonymous today, and the switch to `role: authenticated` will cleanly produce 403 for anon. T's anonymous-access test verifies.
- The `drupalcon` and `paragraphs` tags already exist as taxonomy terms in the seed (they're in the `$tags` list at `step_700_demo_data.php:53` and used on nodes at :140-142, :146). F should look them up by name, not create them.
- HelpText catalog may not exist in the current codebase; if not, defer to SD-6 (#133) backstop — do not invent a new file just for one entry.
- ST-F1 (#109) is truly merged into origin/main (fetched at worktree creation).

**Hedged:**
- Kept CSS attachment mechanism (`hook_preprocess_views_view__following_feed` vs `libraries.yml` + `#attached`) up to F. Both are valid; F picks whichever matches the module's existing convention.

**Evidence:**
- `docs/groups/config/views.view.activity_stream.yml` (analogous view)
- `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml:123-152` (page_following display — scope-filter wiring pattern)
- `docs/groups/modules/do_streams/src/Plugin/views/filter/FollowingScope.php` (EXISTS-based, no LEFT JOIN fan-out — comment at :29-33)
- `docs/groups/scripts/step_700_demo_data.php:369-395` (existing follow seeds — append point)
- #110 spec confirms disjoint file ownership.

## A — Phase 3 (up-front plan review) — 2026-07-23

**Decided:** PASS. Plan is architecturally consistent with existing patterns.

**Assumed:**
- FollowingScope's `addWhereExpression()` on the base query (not a fresh subquery replacement) preserves Views SQL rewrite → node access + group grants still filter the base row set. Verified against FollowingScope.php:59-110.
- `activity_stream`'s `filters.status` block will be preserved verbatim in the clone (guards against seeing followed-but-unpublished nodes).
- Sibling #110 will honor the append-only contract on `step_700_demo_data.php` as documented in both briefs; merge conflict, if any, will be trivial to resolve line-by-line.

**Hedged (warn-level, non-blocking):**
- CSS attachment mechanism left to F. Preferred: register a `do_streams/following` library entry in `do_streams.libraries.yml` and attach via a single-line `hook_preprocess_views_view__following_feed`. Noted so U/S don't retry-block.
- HelpText step correctly deferred to SD-6 (#133) if catalog doesn't exist. No follow-up needed.

**Evidence:**
- `docs/groups/modules/do_streams/src/Plugin/views/filter/FollowingScope.php:59-110` (query contract — filter, not query replacement)
- `docs/groups/config/views.view.activity_stream.yml:43-56, 91-99` (status filter + query options)
- `docs/groups/modules/do_streams/config/install/views.view.do_streams_demo.yml:123-152` (proven filter-wiring pattern for the same plugin)
- `docs/groups/scripts/step_700_demo_data.php:368-395` (append point)

**Output:** `handoff-A.md` written. Verdict PASS → proceed to T (RED).

## T(RED) — Phase 4 — 2026-07-23

**Decided:**
- Authored `tests/e2e/following.spec.ts` (5 tests: anonymous-access, elena's 4-branch dedupe, ravi's follow_user, sophie's follow_content, no-follows empty state) and `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` (2 tests: group-access negative + sanity companion), per the brief's test-file assignments.
- Used `alex_novak` as the "no follows" persona instead of runtime self-registration — confirmed via grep of `step_700_demo_data.php`'s Step 750 flag block that alex is never a flagging viewer (only elena/ravi/sophie are), and self-registration is environment-sensitive (email verification/admin approval) in a way that isn't the acceptance criterion under test.
- Corrected the kernel test's shipped-config lookup from a naive `getPath('do_streams') . '/../../config'` FileStorage path (which would silently break in CI's assembled layout, per the project override's explicit warning) to the walk-up-from-`__DIR__` pattern already proven by `IcalFeedsTest::shippedConfigDir()`, checking both `docs/groups/config` (source tree) and `config/sync` (assembled CI layout).
- Verified RED via LIVE execution, not static reasoning alone: started DDEV, ran `ddev composer install`, assembled config (`bash scripts/ci/assemble-config.sh` run inside the container since `php` isn't on the host PATH), and ran the kernel test with `SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web` — confirmed it fails at `shippedConfigDir()`'s `$this->fail()` (view YAML absent), not at an import/setup error. Cross-checked the existing `StreamsScopeTest.php` still passes (7/7, deprecations only) against the same assembled environment, proving no regression from the new test file. For Playwright, ran `ddev drush site:install minimal` (unseeded — full assemble+cim+seed judged disproportionate for RED verification of artifacts that provably don't exist anywhere in the repo) and confirmed all 5 tests fail, isolating the anonymous-access case to show it fails because `/following` returns neither 403 nor a redirect (route doesn't exist).
- Tore down the DDEV environment (`ddev stop`) after verification to leave the worktree clean for F.

**Assumed:**
- The persona login-failure RED (vs. a route-404 RED) is an acceptable substitute for full seed-data verification at this phase, since a full assemble→config:import→seed run before any code exists would not exercise anything materially different (the view and seed lines are absent either way) and is disproportionate effort for RED confirmation. T(GREEN) will re-verify against the fully seeded/assembled site once F's changes land.

**Hedged:**
- Flagged (non-blocking, for F, not for A/O) a genuine seed-data gap discovered while authoring: `FollowingScope`'s follow_term branch joins `field_group_tags`, but the existing seeded forum topics (including the `core`-tagged ones the brief calls "existing follow_term, seeded") only populate `field_tags`. F's seed-append step will need to also populate `field_group_tags` on the relevant nodes, beyond the three `flag()` lines literally specified in the brief, or the follow_term e2e assertions cannot pass regardless of implementation correctness.

**Evidence:**
- Live kernel-test run output (both failures at `FollowingFeedTest.php:184` inside `shippedConfigDir()`, "Could not locate shipped views.view.following_feed.yml").
- Live `StreamsScopeTest.php` regression-check output (7 tests, 273 assertions, 0 failures).
- Live Playwright run output (5/5 failed; anonymous-access case isolated showing `status === 403` is false and no login redirect).
- `docs/groups/scripts/step_700_demo_data.php:139-152` (existing forum-topic seed only sets `field_tags`, never `field_group_tags`) vs. `FollowingScope.php:85-93` (follow_term branch reads `node__field_group_tags`).
- `docs/groups/modules/do_discovery/tests/src/Kernel/IcalFeedsTest.php:170-184` (`shippedConfigDir()` walk-up pattern reused for the CI-safe fixture path fix).

**Output:** `handoff-T-red.md` written. STATUS: RED_CONFIRMED → F may implement.

## O — Phase 5 handoff to F — 2026-07-23

**Decided:**
- Fold two T(RED) findings into F's marching orders:
  1. **Fixture-path pattern.** Any test/config lookup that traverses relative to `getPath('module')` will silently break under the assembled CI layout (`scripts/ci/assemble-config.sh` puts modules in `web/modules/custom/` and config in top-level `config/sync/`). Use the walk-up-from-`__DIR__` pattern established by `IcalFeedsTest::shippedConfigDir()` and now `FollowingFeedTest`. F: do not undo T's pattern.
  2. **Seed data — field_group_tags population.** `FollowingScope`'s follow_term branch joins `node__field_group_tags`. Existing tagged forum topics populate `field_tags` only. F's seed step MUST also populate `field_group_tags` on the specific nodes the tests assert follow_term matches — at minimum: the `core`-tagged node Elena needs to see, and the `drupalcon`-tagged node the new Elena→drupalcon follow_term seed points at. Do this as part of the append-only seed additions in `step_700_demo_data.php` (either by mirroring the existing field_tags values into field_group_tags on those specific nodes, or by ensuring newly-created tag associations use both fields). Preserve strict append-only — do not rewrite existing node lines; use targeted field updates keyed by node title.

## F — Phase 5 (implementation) — 2026-07-23

**Decided:**
- Implemented `views.view.following_feed.yml` as the exact clone-with-deltas the brief specifies, PLUS one additional correction: `cache.type: none` instead of the brief-specified `type: tag` (see "Hedged"/"Evidence" below — this correction was necessary, discovered only via live testing, and is documented prominently in handoff-F.md so A/O can weigh it explicitly rather than it being silently buried in a diff).
- CSS attachment: registered `do_streams/following` in a NEW `do_streams.libraries.yml` (module had none before), attached via a single new `#[Hook('preprocess_views_view')]` method on `DoStreamsHooks`, guarded on `$view->id() === 'following_feed'` — A's preferred mechanism (handoff-A.md finding §1), matching the class's existing view-id-guarded preprocess convention.
- Seed script (`step_700_demo_data.php`, append-only, new "Step 751" block after the existing Step 750 flags): the 3 brief-specified flags, PLUS a `field_group_tags` backfill on 3 named nodes (per O's Phase-5 fold-in), PLUS one additional corrective `follow_term` flag on the `group_tags`-vocabulary "core" term for Elena (see "Hedged" below) — beyond the literal fold-in instruction's wording, but necessary for the same node/tests it names to actually pass.
- Deliberately deviated from the brief's literal "look up $drupalcon_term the same way $core_term is looked up" instruction: looked up/auto-created the term in the `group_tags` vocabulary instead of the `tags` vocabulary the literal instruction points at, because `field_group_tags`'s `handler_settings.target_bundles` restricts it to `group_tags` only — a `tags`-vocabulary term id can never appear in the column `FollowingScope`'s follow_term EXISTS branch reads, regardless of how faithfully the brief's literal wording is followed.
- Did NOT edit `FollowingFeedTest.php`'s `testFollowedNodeInInaccessibleGroupIsExcluded` despite diagnosing precisely why it fails (its own `setUp()` grants blanket group-type-wide outsider-scope view access, copied from `StreamsScopeTest.php`'s opposite-direction test, which defeats its own negative-case premise) — flagged in handoff-F.md "Tests that look wrong" for T to fix, per F's "write no tests" mandate.
- Did NOT edit `tests/e2e/following.spec.ts`'s empty-state test despite diagnosing precisely why it fails (an ambiguous `/stream/i` locator matches BOTH links in the brief's own approved, byte-for-byte-implemented empty-state copy) — flagged in handoff-F.md "Tests that look wrong" for T to fix.
- Did NOT edit `do_chrome`'s `PageHelp.php`/`HelpText.php` despite discovering a route-name mismatch (`view.following.page_1` pre-registered vs. the actual `view.following_feed.page_1` this story's view generates) — out of files-owned scope, would be a drive-by on an already-shipped, unrelated story (#126); flagged in handoff-F.md for O to route as a possible follow-up.
- Did NOT edit `FollowingScope.php` (#109, ST-F1) despite it being the root cause of the cache-context gap — fixed entirely inside my own owned file (`following_feed.yml`'s `cache.type`) instead; flagged in handoff-F.md as a possible follow-up ticket (add `getCacheContexts(): ['user']` to `FollowingScope`/`MembershipScope` at the plugin level).

**Assumed:**
- The operator's Phase-5 fold-in instruction ("ensure the specific nodes ... have field_group_tags populated ... BOTH the existing core-tag Elena-sees-RFC case AND the new drupalcon seed") implicitly authorized whatever seed-level correction was necessary to make "the existing core-tag Elena-sees-RFC case" actually work end-to-end, not merely the literal field_group_tags population — since populating the field alone, without a flag on the correct-vocabulary term, provably cannot make that acceptance criterion pass. Flagged prominently rather than silently folded in, given it goes beyond the fold-in's literal text.
- `alex_novak`, `drupalcon`/`paragraphs`/`tutorial` tags, and the 3 field_group_tags-backfill target nodes were exactly as T's test comments and O's fold-in named them — confirmed sufficient via live test execution (no additional nodes needed backfilling).

**Hedged:**
- `views.view.following_feed.yml`'s `cache: type: none` (vs. the brief's `type: tag`) is flagged for A/O explicit review, not silently applied. Rationale: `FollowingScope::query()` reads `\Drupal::currentUser()->id()` but never declares a `user` cache context; with Drupal's standard `dynamic_page_cache` active, this let one authenticated user's rendered `/following` page get served to a different authenticated user — reproduced directly across multiple full-suite Playwright runs (elena_garcia intermittently seeing another user's empty-state render instead of her own follows), and resolved by disabling the view's own cache plugin. Verified stable (zero recurrence) across 10 repeated runs post-fix. This is a genuine upstream (#109/FollowingScope) gap, not a #111-specific one — any other consumer of `do_streams_following_scope` (or likely `do_streams_membership_scope`) would hit the identical issue. Recommend a follow-up ticket to add `getCacheContexts()` at the plugin level so future consumers don't need to independently rediscover this.
- The second corrective `follow_term` flag (Elena → `group_tags` "core" term) is hedged as "beyond the literal fold-in instruction but necessary" — flagged explicitly in handoff-F.md rather than silently added, since it's a seed-script decision an O/A reviewer might reasonably want visibility into.
- Both test-authorship defects (kernel negative-case fixture over-grant; e2e empty-state locator ambiguity) are hedged as "confident diagnosis, not fixed" — full reasoning + suggested one-line fixes recorded in handoff-F.md for T, per F's "flag, don't edit" mandate.

**Evidence:**
- Live kernel-test run: `testFollowedNodeInAccessibleGroupIsIncluded` PASS, `testFollowedNodeInInaccessibleGroupIsExcluded` FAIL ("Failed asserting that an array does not contain 1" at `FollowingFeedTest.php:241`) — isolated via direct entity-access check (`$node->access('view', $viewer, TRUE)` returns Allowed for the "inaccessible" group's node) and via `GroupTestTrait::createGroupRole()`'s source (group-type-wide policy, not group-instance-scoped) plus `PermissionScopeInterface`'s scope-id definitions.
- Live SQL/Views inspection: raw follow_term EXISTS branch alone correctly returns nid 2/4/5 (all 3 nodes with `field_group_tags` backfilled); full compiled view SQL (`$view->build_info['query']` post-`execute()`) shows the real `group`-module `GroupQueryAlter` LEFT-JOIN-based access rewrite (aliases `gcfd`/`gcfd_2`), confirming node-access rewrite IS active and IS the layer excluding rows — not `FollowingScope` itself.
- Live cache-contexts dump (`$render['#cache']['contexts']` on the built `following_feed` `page_1` render array): `["user.roles","url.query_args","user.node_grants:view","languages:language_interface","theme","user.permissions","timezone","user.group_permissions"]` — no per-uid-varying context present. Confirmed `FollowingScope.php` has no `getCacheContexts()` override. Confirmed `page_cache`/`dynamic_page_cache` both enabled (`standard` profile default).
- 10 total Playwright runs of `following.spec.ts` across 3 invocations post-cache-fix (2 solo runs + 1 `--repeat-each=2` run): 8/10 pass, the only 2 failures both the SAME known empty-state-locator defect, zero recurrence of cross-user content leakage.
- phpcs baseline comparison: `git show HEAD:docs/groups/scripts/step_700_demo_data.php` piped through `--standard=Drupal,DrupalPractice` → 240 pre-existing errors/6 warnings, vs. 276/6 on my modified version — 36-violation delta attributable to matching the file's own established single-line-brace convention, not a new anti-pattern.
- Full regression sweep (live DDEV): `StreamsScopeTest` + `StreamsInstallTest` + `StreamsRankingTest` + `StreamsShellTest` + `PinnedStreamOrderingTest` = 29/29 green; `demonstrator-seeds.spec.ts` + `phase1.spec.ts` = 12/12 green.
- `git status --short` confirms exactly the intended file set (5 production files created/modified; T's 2 test files correctly untouched and still untracked; `.ddev/config.yaml` `gm111-stream` rename intact, unreverted); `ddev list` confirms no other worktree DDEV project was disturbed.

**Output:** `handoff-F.md` written. STATUS: READY_FOR_T_GREEN → T re-verifies GREEN in Phase 6, applying the two suggested one-line test fixes documented in handoff-F.md's "Tests that look wrong" section.

## O — Phase 6 handoff to T(GREEN) — 2026-07-23

**Decided:**
- Accept F's two deviations as sound (both discovered via live testing, both stay inside F's owned files):
  1. `cache.type: none` on `following_feed` view — chosen over touching shared `FollowingScope` plugin to add a `user` cache context. This is a scope-preservation call; a follow-up MAY later add a `user` context to the plugin and switch this view back to `type: tag`, but that widens ST-F1's shared surface and is out of scope for ST-2.
  2. Follow_term seeds must target the `group_tags` vocabulary (not `tags`), and one corrective follow_term flag was added beyond the brief's literal 3 lines to make the pre-existing "core-tag" acceptance case actually match. The brief was wrong about the vocabulary — `FollowingScope` structurally requires `group_tags`. F's fix is right; brief text is retroactively outdated.
- Route F's two test-authorship defects back to T(GREEN) for repair — they are T's files (`FollowingFeedTest::testFollowedNodeInInaccessibleGroupIsExcluded` setUp granting blanket outsider access; `following.spec.ts` empty-state `/stream/i` locator matching two links). Both are test-only fixes.

## T(GREEN) — Phase 6 — 2026-07-23

**Decided:**
- Repaired both test-authorship defects F flagged, per F's own diagnosis and suggested fixes (both confirmed correct on inspection, not merely trusted):
  1. `FollowingFeedTest.php` `setUp()`: removed the `OUTSIDER_ID`-scope `createGroupRole()` call, kept only `INSIDER_ID`. Rewrote the class doc comment and the negative test's doc comment to state explicitly why this test's grant policy must be the opposite of `StreamsScopeTest.php`'s (which needs the blanket outsider grant for the converse reason) — a defensive note against reintroducing the bug via copy-paste from the sibling test in future.
  2. `following.spec.ts` empty-state test: changed `emptyState.getByRole('link', { name: /stream/i })` to `emptyState.getByRole('link', { name: 'stream', exact: true })`, unambiguously targeting the inline link (accessible name exactly `"stream"`) and excluding the button (accessible name `"Browse the stream"`). Documented in-line why the approved copy intentionally has two `/stream`-matching links.
- Ran the full Phase-6 verification live: kernel `FollowingFeedTest.php` 2/2 GREEN (78 assertions, 0 failures); Playwright `following.spec.ts` 20/20 across 3 invocations (5+5+10 with `--repeat-each=2`), 0 failures, 0 flake — including the previously-failing empty-state test passing all 3 times.
- Tier 2 regression sweep: all `do_streams` kernel tests (25/25 GREEN, 801 assertions), `do_group_pin`'s `PinnedStreamOrderingTest` (6/6 GREEN, matching F's own regression-sweep scope), and a Playwright subset (`directory-cards`, `demonstrator-seeds`, `group-links`, `phase1` — 13/13 GREEN) confirming zero regression from the append-only seed additions or the new preprocess hook/library.
- Did NOT touch any file outside the two test files. Did NOT act on F's two flagged upstream/follow-up items (FollowingScope missing cache context; do_chrome PageHelp route mismatch) — both out of T's scope, reaffirmed in handoff-T-green.md for O's visibility.

**Assumed:**
- F's diagnosis of both test defects was accurate before applying the fix — verified independently by reading `StreamsScopeTest.php`'s own setUp() rationale (confirmed it needs the blanket outsider grant for the converse reason: proving its own scope filter, not Group's access layer, causes the exclusion) and by inspecting the exact rendered empty-state HTML against brief.md's byte-for-byte approved copy (confirmed two genuinely `/stream`-matching accessible names exist by design).
- phpcs findings on `FollowingFeedTest.php` (inline-comment capitalization, missing docblock descriptions on `nidsInOrder()`) pre-date this GREEN pass (from T-red authoring) and match the same violation class already present in the merged sibling `StreamsScopeTest.php` — not introduced by, and not in scope for, this Phase-6 pass's defect fixes.

**Hedged:**
- None beyond what's already in handoff-T-green.md's "Advisory notes."

**Evidence:**
- Live kernel run: `FollowingFeedTest.php` 2/2 GREEN, `Tests: 2, Assertions: 78, Deprecations: 21` (deprecation-only, 0 failures).
- Live Playwright runs: 3 invocations of `following.spec.ts`, 20/20 total pass, 0 flake (5 solo, 5 solo, 10 via `--repeat-each=2`).
- Live regression sweep: `do_streams` Kernel suite 25/25 GREEN (`Assertions: 801`); `do_group_pin` `PinnedStreamOrderingTest` 6/6 GREEN (`Assertions: 149`); Playwright subset (`directory-cards`, `demonstrator-seeds`, `group-links`, `phase1`) 13/13 GREEN.
- `git status --short` confirms only the two test files carry intentional edits; all `config/sync/*`/`web/modules/custom/*` changes are expected assemble-script/config-import build artifacts, not manual edits; `.ddev/config.yaml`'s `gm111-stream` identity untouched.
- phpcs baseline comparison: `StreamsScopeTest.php` (already-merged sibling) carries the identical violation class (inline-comment capitalization, doc-comment short-description gaps) confirming `FollowingFeedTest.php`'s 3 remaining findings are consistent with, not a regression from, established module convention.

**Output:** `handoff-T-green.md` written. STATUS: GREEN_CONFIRMED → ready for U (UI surface — `/following` route + empty-state markup).

## U — Phase 8 — 2026-07-23

**Decided:**
- Ran as stand-in for the `playwright-ui-walkthrough` subagent (model-config issue on the dedicated agent).
- Option A (Playwright walkthrough spec) — Playwright was cooperative from T-green's site state, so we produced reproducible artefacts rather than falling back to curl+HTML inspection. New file `docs/handoffs/111-stream-following/walkthrough.spec.ts` (7 scenarios: 5 persona walks + 2 regression cross-checks) kept out of `tests/e2e/` so it does not enter CI; copied into `tests/e2e/_walkthrough_111.spec.ts` only for the run duration, then removed. Screenshots + traces stored under `docs/handoffs/111-stream-following/screenshots/` and `playwright-output/`.
- Verdict: **ADVISORY** — 6/7 walkthrough scenarios PASS; all brief.md §Acceptance criteria met. The single non-passing scenario is not against the acceptance criteria and not an implementation regression: the empty-state copy links to `/tags`, which is 404 on this build (the only tags-path view is `views.view.tags_aggregation.yml`, `path: tags/%`, requires a term-slug argument). Route this to O for a resolution call — fix copy in this story, add a bare-slug tags landing in a sibling, or ship as-is with a follow-up ticket.

**Assumed:**
- The DDEV site's seed state from T-green's most recent run was still valid — confirmed empirically by all persona walks resolving to the expected content on the first try, matching handoff-T-green.md's `following.spec.ts` results 1:1.
- The `/tags` route was intended to resolve to a bare tags-index (making this an implementation gap somewhere in the routing surface) rather than the empty-state copy being wrong — but this is genuinely ambiguous from brief.md alone, hence ADVISORY not REWORK. O to decide.

**Hedged:**
- Did NOT modify any production file. Did NOT modify `following_feed.yml`'s empty-state copy or add a `/tags` route — both would be out-of-scope drive-bys on either #111's own view (already approved copy) or on `do_discovery`/tags-owner shared code. Recorded the finding for O.
- Did NOT add the walkthrough spec to `tests/e2e/` — it would fail CI on the `/tags` 404 until the advisory is resolved. Kept in the handoff dir as evidence only.

**Evidence:**
- Live Playwright run (1 invocation, 7 tests, gm111-stream / BASE_URL override): `6 passed, 1 failed (13.2s)`. Failure was `/tags returns 200` `Expected: 200 Received: 404` — reproduced separately via `curl -sk -o /dev/null -w "%{http_code}" https://gm111-stream.ddev.site/tags` returning `404`.
- Grep for `path:\s*['"]?tags['"]?` under `docs/groups`: only `views.view.tags_aggregation.yml` matches, with `path: tags/%` at line 108.
- Full-page screenshots for scenarios 1-7 (except scenario 5 which halted at the /tags assertion — but the empty-state markup was captured pre-assertion during earlier verification and matches brief.md line 47 byte-for-byte per rendered HTML dump).
- Anonymous `/following` returns a well-formed HTML 403 page (`<!DOCTYPE html>...Drupal 11...`) — no WSOD, no PHP error output, no stack trace.
- `/stream` regression: 200, `<h1>` present, no visual regression from `.following-feed`-scoped CSS.
- `/my-feed`: 404 as expected (sibling #110 unmerged).

**Output:** `handoff-U.md` written. VERDICT: ADVISORY → O to decide whether to (a) fix empty-state copy here, (b) add bare-slug tags route in a sibling, or (c) ship + follow-up ticket. All #111 acceptance criteria pass regardless of that call.

## O — Phase 8 ADVISORY resolution — 2026-07-23

**Decided (per operator via coordinator):** Option (b) — drop the `/tags` link from the empty-state copy; keep `/stream` only. Rationale: a live 404-linked prompt in shipping demo copy is worse UX than losing one of two discoverability targets. The `/tags` landing gap is a real product hole a future story will address; #111 does not paper over it. No follow-up issue filed (POC posture: `/tags` is future story scope, not latent debt).

**Actions:**
- F: edit `views.view.following_feed.yml` empty-state `content` string — remove the `/tags` link.
- T: drop the `/tags`-link assertion from `tests/e2e/following.spec.ts` empty-state test.
- U: re-verify empty-state renders without `/tags` link.
- S: full audit against final delta.
- PR body: one-line "/tags landing route deferred; empty-state copy now links to /stream only."

## F — Phase 8 delta — 2026-07-23

**Decided:**
- Applied O's Phase-8 ADVISORY resolution (option (b)) verbatim: edited `docs/groups/config/views.view.following_feed.yml`'s `empty.area_text_custom.content` string to drop the `" or explore <a href=\"/tags\">tags</a>"` clause, keeping the `/stream` inline link and the `gc-button--primary` "Browse the stream" button unchanged. Used a scripted exact-string replace (assert `count(before) == 1`, then substitute) rather than a manual edit, given the YAML-escaped-apostrophe/single-quoted-HTML-string context on that line.
- Touched no other file. Not in scope for this delta: `following.spec.ts` (T's Phase-8 delta drops the `/tags`-link assertion), CSS, seed script, hooks class, `.ddev/config.yaml`.

**Assumed:**
- None beyond the resolution's own wording — this delta is mechanical (remove one clause from one already-approved string) with no ambiguity to interpret.

**Hedged:**
- None. This is a same-day, same-story follow-up to F's own Phase-5 file; no new risk surface introduced.

**Evidence:**
- `grep -c "/tags"` returns 0 on both `docs/groups/config/views.view.following_feed.yml` and its assembled copy `config/sync/views.view.following_feed.yml` post-edit.
- `bash scripts/ci/assemble-config.sh` (run inside `gm111-stream`'s DDEV `web` container, per the project's `php`-not-on-host-PATH constraint) — exit 0, ran twice, both clean.
- Byte-for-byte `git status --short` diff (captured immediately before vs. immediately after the edit, including after the assemble run) shows zero change in the file list — the target file was already untracked from Phase 5 and stays untracked; no other path was touched.

**Output:** `handoff-F.md` appended with "F — Phase 8 delta (drop /tags link) — 2026-07-23". STATUS: READY_FOR_T_GREEN_DELTA → T to drop the corresponding `/tags`-link assertion from `following.spec.ts`'s empty-state test, then U re-verifies.

## T(GREEN) — Phase 8 delta — 2026-07-23

**Decided:**
- Applied O's Phase-8 ADVISORY resolution to `tests/e2e/following.spec.ts`'s empty-state test:
  dropped the `/tags`-link assertions (`tagsLink` locator, its visibility/href assertions, and its
  focus/keyboard-focus assertions), keeping the `/stream`-link exact-name assertion and its
  focus/keyboard-focus check intact. Updated the file-header docstring bullet and the in-test
  comment block to reflect the revised copy and to point future readers at decisions.md's
  "O Phase-8 ADVISORY resolution" entry and F's Phase-8 delta, rather than leaving a stale reference
  to a `/tags` link that no longer exists in the approved copy.
- Re-verified live: `ddev exec bash scripts/ci/assemble-config.sh` (in-container) + `drush cim -y`
  (confirmed `views.view.following_feed` synchronized) + `drush cr`, then ran the full
  `following.spec.ts` suite 3 times (2 solo + 1 `--repeat-each=2`) — 20/20 total, 0 failures, 0 flake,
  including the empty-state test passing every time post-edit.
- Did not touch any other file. Did not re-run the kernel `FollowingFeedTest.php` suite (unaffected
  by this delta — it asserts group-access scope, not empty-state copy — and was already GREEN at
  Phase 6 with no production change to it since).

**Assumed:**
- F's Phase-8 delta (removing the `/tags` anchor from `views.view.following_feed.yml`'s
  `empty.area_text_custom.content`) was applied to both the source (`docs/groups/config/`) and would
  correctly propagate to the assembled/imported active config via the standard
  assemble→cim→cr pipeline — confirmed directly rather than assumed blind (grepped both the source
  YAML and `config/sync/` post-assemble for zero `/tags` occurrences, and confirmed `drush cim -y`
  listed the view as synchronized).

**Hedged:**
- None new. Same posture as Phase 6: two upstream/follow-up items (do_streams cache-context gap;
  do_chrome route-map mismatch) remain out of T's scope and unaffected by this delta.

**Evidence:**
- `grep -c "/tags" docs/groups/config/views.view.following_feed.yml` and the same on
  `config/sync/views.view.following_feed.yml` post-assemble → both `0`.
- Live Playwright: 3 invocations of `following.spec.ts`, `5 passed`, `5 passed`, `10 passed`
  (`--repeat-each=2`) — 20/20, 0 flake.
- `curl -sk -o /dev/null -w "%{http_code}" https://gm111-stream.ddev.site/following` → `403`
  (anonymous-access path unaffected by the empty-state copy delta).
- `git status --short` confirms only `tests/e2e/following.spec.ts` (T's file, untracked) and
  `docs/groups/config/views.view.following_feed.yml` (F's file, already accounted for) carry
  intentional edits for this delta.

**Output:** `handoff-T-green.md` appended with "T(GREEN) — Phase 8 delta re-verify — 2026-07-23".
STATUS: GREEN_CONFIRMED_DELTA → ready for U to re-verify the empty-state renders without the /tags
link on the live UI.

## U — Phase 8 delta — 2026-07-23

- Re-verified empty state on live UI after F dropped the `/tags` link from the empty-state copy. Rendered HTML confirms the link is gone; both remaining CTAs (`stream` inline + `Browse the stream` button) resolve to `/stream` 200, are focusable, and show a visible focus outline. Single `<h1>`, no PHP error.
- Updated `docs/handoffs/111-stream-following/walkthrough.spec.ts` scenario 5 to drop the `/tags` visibility/href/navigation/focus assertions and add a negative `a[href="/tags"]` count check inside `.gc-empty`. All other scenarios untouched.
- No production files modified. No git operations. No changes under `tests/e2e/` (temporary copy used for the run was deleted). No changes under `docs/groups/`.
- Delta screenshot: `docs/handoffs/111-stream-following/screenshots-delta/05-empty-state-delta.png`.
- VERDICT: PASS.

## S — Phase 9 — 2026-07-23

**Decided:** VERDICT: PASS. All 9 acceptance criteria (from issue #111 + brief.md §Acceptance) map to a T-authored test that ran live-green in T(GREEN) + Phase-8 delta re-verify, and where UI-observable, to a live-walkthrough observation in U's handoff (+ Phase-8 delta re-verify). Ready for O to commit + PR. PR-body one-liner (per O's Phase-8 ADVISORY resolution): "/tags landing route deferred; empty-state copy now links to /stream only."

**Assumed:**
- The visual/browser precondition does not apply as a hard S-tier gate for this story: the UI surface is a small config-only view with a text empty state and no VR baseline defined for this project. U already produced the live rendered-DOM walkthrough with screenshots (7 scenarios, 6 pass + 1 advisory pre-Phase-8, and the Phase-8 delta re-verified live), which is the equivalent operator-facing visual evidence for this scope.
- The `git diff --stat origin/main...HEAD` being empty (no commits yet on this branch) is intentional: F/T/U all confirmed no commits made, and O told S not to commit either — all changes live in the worktree, staged/untracked, awaiting O to commit and push.

**Hedged:**
- Two latent-debt items surfaced (do_chrome PageHelp route-name mismatch from #126; FollowingScope/MembershipScope missing user cache context from #109) — recorded in handoff-S.md §"Latent debt surfaced" for O's awareness ONLY, per POC memory `feedback_poc_no_follow_ups.md` (surface once, no follow-up issues filed).

**Evidence:**
- Read all 7 handoff docs (survey, brief, A, T-red, F, T-green, U) end-to-end including Phase-8 delta sections; read decisions.md end-to-end.
- Read all 5 shipping artefacts (view YAML, following.css, do_streams.libraries.yml, DoStreamsHooks.php F-additions via `git diff HEAD`, step_700_demo_data.php F-appended block via `git diff HEAD`) and both T-authored test files as referenced by T-green.
- Verified `filters.status` block preserved byte-for-byte between activity_stream.yml and following_feed.yml (empty `diff` output — A's warn #2 satisfied).
- Verified `/tags` completely removed from both source `docs/groups/config/views.view.following_feed.yml` and assembled `config/sync/views.view.following_feed.yml` post-Phase-8 delta (`grep -c "/tags"` = 0 on both).
- Verified `.ddev/config.yaml` diff is only the intended `pl-groups-on-d11` → `gm111-stream` project rename (no other keys touched).
- Verified Step 751 seed block sits at line 397 between Step 750 (line 340) and Step 780 (line 564) — strict-append discipline for the sibling-#110 disjoint-file contract preserved.
- Verified F's diff on step_700_demo_data.php is purely additive (145 added lines, 0 removed) via `git diff HEAD`.
- `assemble-config.sh` on host: expected non-zero due to documented `php`-not-on-host-PATH constraint; host copy steps succeeded (`104 file(s) copied`, `13 modules copied`); full-run verification via `ddev exec` already covered by F, T-red, T-green, T-green delta.
- No new environment side-effects; no code written; no commits made; no git operations at all.

**Output:** `handoff-S.md` written. VERDICT: PASS → O to rebase (if needed), stage, commit (Co-Authored-By line per project convention), push, and open PR with the one-line body noted above.
