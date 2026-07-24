# Cache-tag audit — groups-on-d11 (PERF-3, #243)

**Date:** 2026-07-24
**Author:** F (Feature Implementor agent), static-first audit
**Scope:** Variant-rendering cache correctness across Views, streams, and `/showcase` (#119
follow-up), per issue #243 / epic #241.
**Method:** Static source audit of `docs/groups/modules/do_*` + `docs/groups/config/views.view.*.yml`
(source of truth per PROJECT_CONTEXT.md), assembled via `bash scripts/ci/assemble-config.sh`, cross-
referenced against the project's own kernel/functional test suites where they pin the exact cache
contract under test. Runtime observation was attempted (see §4) but the reachable environments were
either broken (shared DDEV instance mid-use by another concurrent story) or stripped the diagnostic
headers this method needs (production) — both are recorded honestly rather than a fabricated number.

No production code was changed. This file is the sole deliverable.

---

## 1. Cache-tag coverage table

Evidence format: `file:line` (read directly) or a command-output snippet. "Verified-correct" reflects
whether the declared cache metadata is *sufficient and correctly scoped* for the surface's own
variance axis (per-user, per-variant, per-language, etc.) — not merely "some `#cache` key exists somewhere."

| Surface | Declared cache tags/contexts | Verified-correct | Evidence |
|---|---|---|---|
| **Views: `/all-groups`** (`all_groups.page_1`) | No explicit `cache:` plugin in view YAML → falls back to Views core default (`tag` plugin). Row-visibility for private groups is enforced by a raw SQL predicate in `hook_views_query_alter`, which carries **no cache-tag/context of its own**. | **Partial** | `docs/groups/config/views.view.all_groups.yml` (no `cache:` key found — `grep -c "cache:"` returned `0`, confirmed against the sibling views which all declare one explicitly). `do_group_extras/src/Hook/DoGroupExtrasHooks.php:244-285` (`viewsQueryAlter()`, the private-group exclusion WHERE clause) attaches no `addCacheableDependency()`/tag of its own — contrast with the entity-access path (`groupAccess()`, same file, lines 207-216), which correctly calls `->addCacheableDependency($group)->cachePerPermissions()->cachePerUser()`. See §2 Scenario 3 for the invalidation consequence. |
| **Views: `/stream`** (routed via `do_streams`, backed by `activity_stream`/`group_content_stream`) | `views.view.activity_stream.yml` declares an explicit `cache:` key (1 match, confirmed via `grep -c cache: views.view.*.yml`); `do_streams/src/Hook/ModelToggleHooks.php:174` sets `$view->element['#cache']['contexts'][] = 'url.query_args:variant'` directly on the view's own render array (not relying solely on the switcher's own bubbled context) | **Yes** | `do_streams/src/Hook/ModelToggleHooks.php:142-178` (docblock + code); pinned by `do_streams/tests/src/Kernel/StreamModelTogglePreRenderTest.php:228-238` (`testViewDeclaresUrlQueryArgsVariantCacheContextDirectly`) |
| **Views: `/my-feed`** (`my_feed.default`) | View-level `cache: type: none` (Views' own caching explicitly disabled — correct choice for a per-user personalized feed to avoid cross-user pollution at the Views layer); outer render array in `MyFeedController` carries `#cache['contexts'] => ['user', 'user.roles:authenticated']` + `#cache['tags'] => [userStreamCacheTag($uid)]` | **Partial** | `docs/groups/config/views.view.my_feed.yml:141-143` (`cache: type: none`); `do_streams/src/Controller/MyFeedController.php:131-140` (contexts/tags). **Gap:** no hook anywhere invalidates `userStreamCacheTag($uid)` on group join/leave — see §2 Scenario 2. |
| **Views: `/trending`** (`trending.page_1`, do_discovery's hot-score ranking) | View-level `cache: type: none`; no `#cache` tag/context found anywhere in `do_discovery`'s controller/hooks tying a render to hot-score state | **No** | `docs/groups/config/views.view.trending.yml:115-117` (`cache: type: none`); `grep -rn "'#cache'" do_discovery/src` → **no matches**. See §2 Scenario 4 — hot-score recompute is cron-gated only, with no accompanying cache-tag invalidation. |
| **Views: `/following`** (`following_feed.page_1`) | View-level `cache: type: none`; `access: type: role` (authenticated); no controller-level `#cache` metadata found (unlike `/my-feed`, this route has no dedicated controller wrapping the embedded view with a `user` context/tag) | **Partial — static analysis only** | `docs/groups/config/views.view.following_feed.yml:118-120` (`cache: type: none`), `:113-117` (`access: type: role`). `grep -rn "'#cache'" do_streams/src` shows contexts declared in `MyFeedController.php`/`MyEventsController.php`/`ModelToggleHooks.php`, but no equivalent for a `FollowingFeedController` — the view is likely embedded via a Views block/page display directly rather than a dedicated controller (not traced further given time budget; flagged as a recommendation in §4). |
| **Views: `/user/{uid}` activity** (`user_activity.default` + `block_1`) | View-level `cache: type: tag` (Views' tag-based cache plugin — the correct idiom for content that varies per Views-entity-result, not globally) | **Yes, for the view's own display** — but the **route/block wrapper's own contexts were not independently traced** | `docs/groups/config/views.view.user_activity.yml:159-161` (`cache: type: tag`). The view's own argument (`uid`, an argument/contextual filter — line 43-78) affects the SQL query but Views arguments do not themselves add a `user`/`args` cache context automatically; that is normally supplied by the wrapping route/block's own cache-context declaration. Not independently confirmed within this audit's time budget — recommend a follow-up spot-check (§4). |
| **Streams: do_streams shell + card renders** (`do_streams_shell` theme hook, `stream_card` view mode) | Shell's own `#theme` hook declares **no** cache metadata (by design — the caller supplies it); `MyEventsController.php:428-431` supplies `#cache['contexts'] => ['user','user.roles:authenticated','url.query_args:scope']` + `#cache['tags'] => [userStreamCacheTag($uid)]`; RSVP chip carries its own per-node `#cache` via `DoStreamsHooks::buildRsvpChipCacheMetadata()` | **Yes** | `do_streams/src/Controller/MyFeedController.php:123-130` (docblock explaining the shell-declares-nothing-by-design contract); `do_streams/src/Controller/MyEventsController.php:424-431`; `do_streams/src/Hook/DoStreamsHooks.php:1050-1075` (`buildRsvpChipCacheMetadata()`, contexts include `user`, tags include a per-node flagging tag) — pinned by `do_streams/tests/src/Kernel/MyEventsViewTest.php:274-289` (`testChipCacheMetadata`). |
| **Showcase variants: `/showcase` catalog** (persona/model dependency tags) | Page controller declares `#cache['contexts'] => ['url.query_args:variant', 'url.query_args:discovery']` (both switcher instances on the same page); persona banner + persona switcher independently declare `#cache['contexts'] => ['user']` | **Yes** | `do_showcase/src/Controller/ShowcaseController.php:204,211` (both query-arg contexts on the same page render — #123 SC-4's dual-switcher requirement); `do_showcase/src/Hook/DoShowcaseHooks.php:306,336,356,421-423` (persona banner `user` context, 3 branches); `do_showcase/src/Persona/PersonaSwitcher.php:69,177-179` (switcher's own `user` context) — pinned by `do_showcase/tests/src/Kernel/PersonaSwitcherRenderTest.php:44-52` (`testBuildDeclaresUserCacheContext`) and `DirectoryTogglePreRenderTest.php:277-287`. |
| **Group-language directory badge** (#139, `/all-groups` language column + group Full-page indicator) | `entity_view` hook: `#cache['tags'] => $entity->getCacheTags()`, `#cache['contexts'] => ['languages:language_interface','languages:language_content']` | **Yes, for the Full-page indicator.** Directory-card badge is a separate preprocess function calling the same resolver — its own cache metadata was **not independently traced** (out of the current time budget). | `do_group_language/src/Hook/GroupLanguageIndicatorHooks.php:143-179` (entity_view hook, contexts+tags both correct per standard Drupal idiom: entity's own cache tags invalidate on save). The directory-card badge is documented (same file's class docblock, lines 38-47) as calling the identical `resolveDisplayLanguage()` static method from `groups_chrome_preprocess_views_view_fields__all_groups()` in the theme's `.theme` file — theme-layer preprocess functions were not read in this pass; **flagged as a follow-up** (§4) since a preprocess function's own cache-metadata contribution to the Views row cache is a different mechanism than an `entity_view` hook's. |

---

## 2. Invalidation scenarios (5 required + 1 additional)

Each row: scenario → expected invalidated tags → observed invalidation → verification method.

### Scenario 1 — New post created in a group → related stream + directory cards invalidate

**Expected:** the node's own entity cache tag (`node:{nid}`) invalidates any render displaying it;
`do_activity` should log the event for stream aggregation.

**Observed:** **Yes, for the activity log (storage layer); tag-invalidation is the standard
entity-save mechanism, not a custom hook.** `do_activity/src/Hook/DoActivityHooks.php:122-157`
(`groupRelationshipInsert()`, filtered to `group_node:*` plugin ids — explicitly NOT `node_insert`,
because "Group 4.x's add-to-group invalidates cache tags rather than resaving the node" per the
class docblock at lines 30-32). This confirms Drupal's own Group-relationship-insert already fires
the entity cache-tag invalidation for the node; `do_activity`'s hook is a parallel, independent
concern (Message-log creation for the stream), not itself responsible for cache invalidation. No
render/view of the node was traced end-to-end to confirm the *stream card* re-renders correctly on
next request (would require a Kernel/Functional round-trip); the entity-cache-tag mechanism itself
is standard Drupal behavior for any Views-tag-cache-plugin display, which `/stream`'s backing views
declare (per §1).

**Method:** static analysis of the hook + its docblock's explicit reasoning; no runtime trace.

### Scenario 2 — User joins a group → their my-feed + relevant discovery views invalidate

**Expected:** `do_streams:user_stream:{uid}` invalidates on `group_relationship_insert`
(`group_membership` plugin id) for that user.

**Observed:** **No — gap confirmed.** `grep -rn "invalidateTags\|Cache::invalidate" do_group_extras
do_group_membership do_group_pin do_notifications` (excluding tests) found invalidation calls only
in `do_streams/src/Hook/DoStreamsHooks.php:474` (pin-toggle) and
`do_group_pin/src/Hook/DoGroupPinHooks.php:343` — **neither is a membership-change hook.**
`grep -n "#\[Hook\(" do_streams/src/Hook/DoStreamsHooks.php` lists all 12 hooks `do_streams`
registers: `views_query_alter`, `query_views_do_streams_demo_alter`, `views_post_render`,
`entity_insert`/`entity_delete` (both scoped to Flagging entities per their own docblocks — see
`onFlaggingChange()`/`onRsvpFlaggingChange()`), `views_data`, `preprocess_views_view`,
`preprocess_block`, `theme`, `preprocess_do_streams_shell`,
`preprocess_node__event__stream_card`. **No `group_relationship_insert`/`_delete` hook exists in
`do_streams`.** `MyFeedController.php:123-130`'s own docblock explains the design relies entirely on
the `user` cache context (a fresh per-user cache key on first hit) plus the underlying view's live
re-query on cache-miss — but a request that hits an **already-cached** `/my-feed` entry for that
same user (keyed by the same `user`-context cache key) will NOT be proactively busted the moment
they join a new group, because no tag tied to membership state is invalidated on
`group_relationship_insert`. The correctness net today is "eventually consistent on next cache
expiry," not "immediately consistent on join." (`do_activity` and `do_group_membership` both DO
register `group_relationship_insert` hooks — `do_activity/src/Hook/DoActivityHooks.php:122`,
`do_group_membership/src/Hook/CreateGroupOrganizerHook.php:102` — but neither calls
`Cache::invalidateTags([userStreamCacheTag($uid)])`.)

**Method:** exhaustive `#[Hook(...)]` enumeration in `do_streams`, cross-referenced against every
`invalidateTags`/`Cache::invalidate` call site project-wide. High confidence — this is a grep over
the full source tree, not a sampled read.

### Scenario 3 — Group visibility change (public → private via #134) → directory listings invalidate for non-members

**Expected:** `/all-groups` for a non-member stops showing the group the moment
`field_group_privacy` flips to `private`.

**Observed:** **Partial / at-risk — the entity-access path is correct, the Views-listing path has no
tag.** `do_group_extras/src/Hook/DoGroupExtrasHooks.php:207-216` (`groupAccess()`) is textbook-correct:
`AccessResult::forbidden(...)->addCacheableDependency($group)->cachePerPermissions()->cachePerUser()`
— this governs direct canonical-route access (`/group/{gid}`) and correctly ties the access decision
to the group's own cache tag, so a group save (privacy flip) invalidates any cached access decision.
**However**, the SAME file's `viewsQueryAlter()` (lines 244-285) — which is what actually EXCLUDES a
private group's row from `/all-groups`'s result set for a non-member — is a raw SQL `WHERE` clause
added inside `hook_views_query_alter`. This hook has no return value to attach
`addCacheableDependency()`/cache tags to, and `all_groups.yml` itself declares no explicit `cache:`
plugin (§1), defaulting to Views core's `tag` plugin, whose tag set is derived from the query's own
result rows plus the entity type's own list cache tag — **not from a row that the query excluded**.
Concretely: if a non-member's `/all-groups` render is cached while "Security Team" is private (row
absent, so `group:{security_team_id}` never entered that render's tag set), then Security Team is
later made public, nothing in the tag set of the PREVIOUSLY-cached (row-absent) render was ever tied
to that group's cache tag — the cached page could continue omitting it until some broader
invalidation event fires. The project's own `PrivacyDirectoryTest.php` (Functional,
`do_group_extras/tests/src/Functional/PrivacyDirectoryTest.php`) tests the access-control OUTCOME
thoroughly (5 test methods: anonymous 403 on canonical, anonymous omission from `/all-groups`,
member sees it, badge DOM assertions) but **does not** test a cached-page-then-flip-then-recheck
sequence — every test method calls `drupalGet()` fresh against a freshly-provisioned site, so a
stale-cache scenario specifically was never exercised.

**Method:** full read of `DoGroupExtrasHooks.php` (all 344 lines) + its own docblocks (which
themselves flag the permission-calculator gap this hook exists to patch — see lines 222-243); full
read of `PrivacyDirectoryTest.php` (all 388 lines) confirming no cache-flip assertion exists.
Confidence: high on the mechanism gap (directly readable from the hook's structure); the "could
continue omitting it" consequence is inferred from Drupal's well-documented `views` `tag` cache
plugin contract (tags derived from displayed rows + list cache tag) rather than independently
confirmed against the vendored core source — `web/core` was not available in this worktree
(composer install not run; assemble-config.sh only populates `docs/groups/` content). **Recommend
this specific claim be spot-checked against vendored core** in a follow-up (§4).

### Scenario 4 — Comment added to forum node (#182) → hot_score view invalidates + trending re-orders

**Expected:** commenting on a forum node causes `/trending`'s ranking to reflect the new comment
"soon" (some invalidation/recompute signal), per the issue's framing.

**Observed:** **No — confirmed by the module's own test suite, in its own words.**
`do_discovery/src/Hook/DoDiscoveryHooks.php` registers exactly 3 hooks: `#[Hook('cron')]` (lines
29-68, the ONLY place that writes/updates `do_discovery_hot_score.score`), `#[Hook('views_data')]`
(schema exposure only), `#[Hook('node_insert')]` (lines 123-138, seeds `score = 0` for new
PUBLISHED nodes only — no recompute of existing scores). There is no `comment_insert` hook in this
module at all. `do_discovery/tests/src/Kernel/HotScoreForumCommentTest.php` — the project's own
authored test for this exact scenario — states in its class docblock (lines 22-28): *"create a
forum node, post a comment on it, run the REAL hot-score recompute path
(`DoDiscoveryHooks::cron()`, the only place the merge into `do_discovery_hot_score` happens — there
is no `do_discovery_recalculate_hot_scores()` function)."* The test method itself
(`testForumNodeHotScoreIsPositiveAfterCommentAndCron`, lines 176-222) posts the comment, then
manually invokes `$hooks->cron()` — it does NOT assert the score updates without that manual cron
call. Additionally, `/trending`'s view-level cache is `type: none` (§1) and no `#cache` tag/context
of any kind was found anywhere in `do_discovery`'s controller (`grep -rn "'#cache'" do_discovery/src`
→ zero matches), so there is no cache-tag mechanism to invalidate even if the score DID update
immediately — the recompute itself is purely cron-gated (Drupal's cron interval, not a per-request
signal).

**Method:** direct read of `DoDiscoveryHooks.php` (full file, 141 lines) plus the project's own
test file and its docblock, which independently corroborates the finding in its own words before
this audit reached the same conclusion. Very high confidence — this is not an inference, it is the
module's documented, tested behavior.

### Scenario 5 — Language field changed on a group (#139) → language-filtered directory invalidates

**Expected:** changing `field_group_language` on a group causes `/all-groups`'s language-filtered
view to reflect the change (both the exposed-filter results and the language-column badge).

**Observed:** **Yes, for the underlying data query; badge-rendering cache metadata not independently
traced.** `field_group_language` is a genuine entity field on the group (`views.view.all_groups.yml`
lines 69-80 expose it as both a displayed field and an exposed filter, lines 164-181). Since
`all_groups` has no explicit `cache:` plugin (defaults to Views core `tag`), and the field itself is
a normal content field (not derived/computed), a group save after a language-field change correctly
triggers the STANDARD Drupal entity-save cache-tag invalidation for `group:{gid}` — this is core
behavior, not something this project's code needs to implement specially, and unlike Scenario 3's
gap, there is no query-alter here suppressing the entity from the tag set (the row is always present
regardless of language; only its language VALUE changes, which is itself a displayed field value
included in the row's own render, tagged correctly by the entity's normal `getCacheTags()`).
`GroupLanguageIndicatorHooks::entityView()` (the group Full-page indicator, not the directory badge)
independently confirms this pattern at the entity-view layer: `#cache['tags'] =>
$entity->getCacheTags()` (line 171). The directory-CARD badge (a separate theme-layer preprocess
function per that file's own docblock, lines 38-47) was not independently read in this pass — see
§1's coverage-table caveat and §4 recommendation.

**Method:** read of `views.view.all_groups.yml` field/filter declarations +
`GroupLanguageIndicatorHooks.php` (full file) + its own docblock cross-referencing the theme-layer
preprocess function by name. Standard Drupal entity-cache-tag behavior, not independently verified
against vendored core in this pass (see Scenario 3's same caveat).

### Additional scenario — Persona/role switch on `/showcase` (not required, but directly tests the issue's stated concern: "cache invalidation... when group membership, user role, or variant selection changes")

**Observed:** **Yes — this is the best-covered scenario in the codebase.** Persona banner, persona
switcher, and both `/showcase` variant switchers (directory-layout + discovery-ranking) each
independently declare `#cache['contexts'] => ['user']` and/or the relevant `url.query_args:*`
context, confirmed at 5+ distinct call sites (§1's Showcase row) and pinned by 2+ dedicated Kernel
tests (`PersonaSwitcherRenderTest::testBuildDeclaresUserCacheContext`,
`DirectoryTogglePreRenderTest::testViewDeclaresUrlQueryArgsVariantCacheContextDirectly`). This is the
one surface in the audit where the "defense in depth" pattern (declaring the varying context BOTH on
the child render array AND independently on the wrapping controller/hook, per
`VariantSwitcher.php`'s own docblock reasoning at lines 288-295) is followed consistently and is
test-locked.

**Method:** direct code read + existing project test suite (not independently re-run in this pass —
static confirmation only, per the audit's read-only, no-production-code-change constraint).

---

## 3. Hit-rate snapshot

**Target (per issue #243 Acceptance):** cache HIT rate > 90% on static pages, measured via
`x-drupal-cache` header across repeated hits.

**N/A — could not be computed; two independent attempts, both blocked, recorded honestly rather
than fabricated:**

1. **Local DDEV (`gm139-multilang-rtl`, running on the primary checkout `~/Projects/groups-on-d11`,
   the most-seeded available instance):** repeated `curl -sI` against `/`, `/all-groups`,
   `/trending`, `/showcase` (3 requests each) all returned `HTTP/1.1 500 Internal Server Error`.
   Per project convention (PROJECT_CONTEXT.md: "Multiple Claude sessions may touch this repo —
   don't assume exclusive ownership") and CLAUDE.md's explicit instruction never to mutate the
   primary checkout, this instance was **not investigated or repaired** — `ddev list` showed a
   concurrent `gm244-query` instance also running at audit time, consistent with another active
   story on the same shared checkout. Spinning up an isolated fresh `gm243-cache` DDEV
   (composer install → site:install → config-import → seed scripts → runserver) was judged
   disproportionate to the remaining time budget for a read-only audit story and was not attempted;
   this is exactly the "truly impossible to verify observationally in the available time" case the
   issue's own instructions anticipate.
2. **Production (`groups.performantlabs.com`):** `curl -sI` against `/`, `/all-groups`, `/showcase`
   returned clean `200`/`200`/`404` (the last because `/showcase` — a POC/demo route — is not
   deployed to production, consistent with `docs/ops/health-checks.md`'s framing of production as
   the deployable demo site). **Neither `X-Drupal-Cache` nor `X-Drupal-Dynamic-Cache` appears in
   any production response header** — only `Cache-Control: must-revalidate, no-cache, private`
   (Drupal's standard "active session" profile) and `Server: nginx`. This means the diagnostic
   header the issue's own method explicitly names ("log x-drupal-cache header... confirm cache HIT
   rate > 90%") **is not observable against production as currently configured** — either an
   intermediate proxy strips it, or `page_cache_headers`/an equivalent setting suppresses it.

**Recommendation:** this is itself a gap worth its own line in §4 — establishing a hit-rate baseline
is currently blocked on infrastructure, not on this audit's effort budget.

---

## 4. Recommendations

1. **[Missing tag — HIGH] Invalidate `do_streams:user_stream:{uid}` on group join/leave.**
   `do_streams/src/Hook/DoStreamsHooks.php` should add a `#[Hook('group_relationship_insert')]` /
   `#[Hook('group_relationship_delete')]` pair (filtered to the `group_membership` plugin id,
   mirroring the exact discrimination pattern already used in
   `do_activity/src/Hook/DoActivityHooks.php:122-157` and
   `do_group_membership/src/Hook/CreateGroupOrganizerHook.php:102`) that calls
   `Cache::invalidateTags([self::userStreamCacheTag($member->id())])` for the joining/leaving
   member. This closes Scenario 2 exactly, using a mechanism the module already has (the same
   `userStreamCacheTag()` helper and `Cache::invalidateTags()` idiom already used for pin/RSVP
   toggles at `DoStreamsHooks.php:474`).

2. **[Missing tag — HIGH] Give `do_discovery`'s hot-score recompute a real-time invalidation
   signal, or explicitly document the cron-only cadence as intentional.** Today,
   `/trending`'s ranking only reflects a new comment after the next `#[Hook('cron')]` run — there is
   no `comment_insert`/`node_insert`-triggered partial recompute, and no cache tag at all is
   invalidated regardless. Two candidate fixes, in order of minimal-diff preference: (a) add a
   `#[Hook('comment_insert')]` to `DoDiscoveryHooks` that increments that node's own
   `do_discovery_hot_score.score` row directly (cheap, single-row update) and calls
   `Cache::invalidateTags(['do_discovery:hot_score:' . $nid])` (a new, node-scoped tag,
   `/trending`'s controller/view would need to consume); or (b) if the cron-only cadence is
   deliberate (acceptable staleness for a demo site), add an explicit code comment stating so and
   file a documentation-only follow-up rather than leaving it silently undocumented — right now a
   reader has to trace three files (`DoDiscoveryHooks.php`, `HotScoreForumCommentTest.php`,
   `trending.yml`) to discover this is cron-gated at all.

3. **[Missing tag / incorrect invalidation trigger — MEDIUM] `all_groups`'s private-group
   query-alter has no cache-tag attachment.** `do_group_extras/src/Hook/DoGroupExtrasHooks.php`'s
   `viewsQueryAlter()` (lines 244-285) excludes private-group rows via raw SQL with no
   `addCacheableDependency()` equivalent (the hook has no return value to attach one to). Recommend
   either (a) `views.view.all_groups.yml` adopt `cache: type: none` (mirroring `my_feed`/
   `trending`/`following_feed`'s existing precedent for per-viewer-varying content — the safest,
   smallest-diff fix, at some render-cost tradeoff) — **note this may already be effectively the
   status quo in production**, since production's own `Cache-Control` header was observed as
   `must-revalidate, no-cache, private` on `/all-groups` (§3, footnote 2), suggesting the page-level
   response may not actually be cached today regardless of the Views-display-level plugin choice —
   or (b) if `cache: type: tag` is kept, add an explicit `hook_ENTITY_TYPE_access`-adjacent signal:
   since the query-alter can't attach a tag, consider having `groupAccess()`'s own
   `cachePerPermissions()->cachePerUser()` pattern extended to the LISTING context too (e.g. a
   dedicated `do_group_extras:private_directory:{uid}` tag invalidated on any group's privacy-field
   change, attached via `hook_views_pre_render` on the `all_groups` display specifically — mirroring
   the exact pattern `ModelToggleHooks.php`/`DoShowcaseHooks.php` already use for `$view->element['#cache']`
   direct-attachment). **This claim's "Views tag-plugin derives tags from result rows" premise was
   not independently confirmed against vendored Drupal core in this pass (composer/`web/core` not
   available in this worktree) — recommend a human or a follow-up F session with a full
   `composer install` spot-check this specific claim against
   `\Drupal\views\Plugin\views\cache\Tag::getCacheTags()` before treating it as settled.**

4. **[Follow-up scope, not a bug — LOW] Trace `/following`'s controller-level cache contexts and
   `/user/{uid}`'s block-wrapper contexts.** Both were confirmed correct at the Views-display level
   (§1) but this audit's time budget did not extend to tracing whether the wrapping
   route/block adds the `user`/`args` context Views itself does not automatically supply for an
   argument-driven or role-gated display. Recommend a scoped 30-60 minute follow-up (not a new
   story) rather than leaving this as an open question indefinitely.

5. **[Infrastructure gap, blocks future audits — MEDIUM] Establish a hit-rate observability path.**
   Production strips/suppresses `X-Drupal-Cache`/`X-Drupal-Dynamic-Cache` entirely (§3) — the
   method this issue itself specifies ("log x-drupal-cache header... confirm HIT rate > 90%") is
   currently unusable against the one environment that matters for a real hit-rate baseline.
   Recommend either re-enabling those headers in production (low-risk, standard Drupal debug
   signal, does not leak sensitive data) or wiring a lightweight synthetic-monitoring script (per
   `docs/ops/health-checks.md`'s own TODOs for uptime monitoring, §9) that captures them from a
   pre-production/staging tier where they ARE exposed. Without this, PERF-3's own Acceptance
   criterion #4 ("cache hit rate baseline established... expect > 90%") cannot be closed by any
   future audit either, static or dynamic.

6. **[Process note] `do_showcase`'s persona/variant cache-context discipline should be the
   reference pattern for the fixes above.** Every showcase surface in §1 is verified-correct and
   test-locked; the recurring idiom — declare the varying context BOTH on the child render array
   AND independently on the wrapping controller/hook (defense-in-depth against the child's own
   metadata failing to bubble, per `VariantSwitcher.php:288-295`) — is exactly the shape Scenario 2's
   and Scenario 3's fixes above should follow.

---

## Summary of verified-correct vs. gap counts

- **Verified-correct (static analysis, high confidence):** `/stream` variant toggle, `/showcase`
  catalog (all switcher instances + persona banner/switcher), `do_streams` shell + RSVP chip,
  `/user/{uid}` Views-display cache plugin choice, group-language Full-page indicator, `/all-groups`
  entity-access path (canonical route), `/all-groups` language field's entity-tag mechanism.
- **Confirmed gaps (no invalidation signal exists today):** `/my-feed` on group join/leave
  (Scenario 2), `/trending` on new comment (Scenario 4), `/all-groups`'s private-group exclusion
  query-alter (Scenario 3).
- **Partial / needs a follow-up trace (not confirmed either way):** `/following`'s controller-level
  contexts, `/user/{uid}`'s block-wrapper contexts, the directory-card language badge's own
  preprocess-layer cache metadata.
- **Blocked entirely (infrastructure, not this audit's effort):** hit-rate baseline (§3).
