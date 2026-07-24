# Handoff-A: Phase 3 — /my-feed/events (Events + My RSVPs) up-front plan review

**Date:** 2026-07-23
**Branch:** `112-events-rsvps` (stacked on `110-stream-110`, PR #173)
**Brief reviewed:** `docs/planning/handoffs/112-events-rsvps/brief.md`
**Reuse map:** `docs/planning/handoffs/112-events-rsvps/survey.md`
**Wireframe:** `docs/planning/handoffs/112-events-rsvps/wireframe.html` (+ `handoff-D.md`)
**Verdict:** PASS (with 3 advisory `warn` findings F must address in-plan; none block T from writing RED)

## Summary

The Reuse map is correct and disciplined: `MyEventsController` mirrors `MyFeedController`
one-for-one, a new `views.view.my_events.yml` is genuinely warranted (event-only, two displays vs.
`my_feed`'s single multi-bundle display), and iCal is a link-only reuse of `do_discovery` routes.
`?scope=global` as a controller-level `overrideOption('filters', ...)` (no new plugin) matches [B-8]
and MyFeedController's shape. Cache-tag strategy (`DoStreamsHooks::userStreamCacheTag($uid)` +
`user`/`user.roles:authenticated` contexts) is the correct extension of ST-1. No parallel paths, no
new abstraction premature to this PR boundary.

Three advisory items F must resolve in the plan before writing tests, but none of them require O to
amend the brief — they're implementation-shape choices already contemplated by the brief/survey and
can be nailed down at F time:

1. **Two-section shell rendering contract** (warn — highest leverage). The existing
   `do-streams-shell.html.twig` has a **single** `results` slot and a **single** `empty`/`empty_copy`
   branch. handoff-D binds two independent sections inside ONE shell with per-section empty states
   and distinct testids. F must decide **now** (before T writes tests) which of the two legitimate
   options applies, because the tests will be shaped by that choice.
2. **Preprocess-vs-Views-field for the RSVP chip** — cacheability boundary. The brief allows either;
   the preprocess path is cleaner but has a specific cache-context obligation.
3. **`?scope=global` override implementation** — the `overrideOption()` API is not yet used anywhere
   in this codebase; F should copy the shape from core (`ViewExecutable::setDisplay()` +
   `$view->getDisplay()->overrideOption('filters', ...)`) and note the choice in the F handoff so A's
   Phase-7 gate can verify it did not silently morph into a new filter plugin.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | Two-section rendering (brief §Plan step 1 Kernel; handoff-D §Decided "both displays inside ONE shell") | pattern consistency / abstraction level | `do-streams-shell.html.twig` has a **single** `results` slot and a single `empty`/`empty_copy` branch keyed off scope. It cannot natively render "Upcoming (possibly empty) + My RSVPs (possibly empty)" side-by-side without either (a) the controller pre-composing the two sections into ONE `#results` render array (with the section `<h2>`s, per-section empty markup, and distinct testids baked in there) and setting `empty: FALSE` on the shell so the shell's own `.gc-empty` branch never fires — or (b) invoking `do_streams_shell` twice (one per display) as the survey originally suggests. handoff-D §Decided commits to (a); brief §Plan step 1 doesn't spell out which. Ambiguity here will show up as churn in T's Functional/E2E tests. | F must pick one before T writes RED. Recommend **(a) controller-composes**: keep one shell (matches wireframe), pass `empty: FALSE`, build `#results` as a `#type: container` with two child `container`s (`upcoming-events-results` + `my-rsvps-results`), each of which internally renders either the `#type: view` element or the per-section empty markup (`upcoming-events-empty` / `my-rsvps-empty`). The shell's own scope-copy empty branch stays unused on this route — accept that as a documented deviation. Do NOT extend the shell theme hook to accept a `results` array or a second `results` slot from a single-story branch: that's a cross-story contract change and belongs in a shell-owning story, not here. |
| 2 | warn | RSVP chip via `preprocess_node__event__stream_card` (brief §Plan step 2) | cross-cutting concerns / cacheability | The chip's `data-going-count` varies per event (invalidated by any RSVP toggle on that event) and `data-viewer-state` varies per viewing user. A `preprocess_node__event__stream_card` hook that reads flag counts + viewer state MUST attach BOTH: (a) `flagging_list:node:<nid>` (or equivalent flagging cache tag — `flag` core exposes `Flag::getCacheTagsToInvalidate()` on flagging insert/delete) so a new RSVP invalidates the count; AND (b) `#cache['contexts'] = ['user']` on the node's render array so viewer state doesn't leak from one user to another. Missing either is a cache-poisoning bug that WILL slip past kernel tests but surface in E2E as "chip shows the wrong going count" or "logged-in-as-B sees A's viewer-state". A Views custom field has the same obligation but declares it in views_data — either way, spell it out. | F's F-handoff (§Reuse + §Impl notes) must name the exact cache tag + context added, and T's kernel test `MyEventsViewTest` should assert the chip render array includes those (`testChipCacheMetadata` — trivial to add now, expensive to add after a leak is reported). If F picks Views custom field, the same assertion holds — declare `cache_contexts` / `cache_tags` in views_data. |
| 3 | warn | `?scope=global` filter-override via `$display->overrideOption('filters', ...)` (brief §Plan step 2; survey Reuse map row 4) | pattern consistency (new API surface) | `overrideOption` is not used anywhere in `docs/groups` today (grepped: zero hits). The idiom is legitimate core Views API (`\Drupal\views\Plugin\views\display\DisplayPluginBase::overrideOption`), but because it's a new-to-this-codebase call, F is at risk of drifting into either (a) editing the shipped `views.view.my_events.yml` filter set at request time (fine, that's the point) or (b) reaching for a new `do_streams_scope` filter plugin variant (parallel to `MembershipScope` — that's the drift). The brief non-goal "Do NOT add a new scope plugin" is the correct guardrail; F must apply it. | F reads MyFeedController's `Views::getView() → setDisplay() → execute()` shape, then between `setDisplay()` and `execute()` calls `$view->getDisplay()->overrideOption('filters', $filters_without_membership_scope)` when `$request->query->get('scope') === 'global'`. Note the choice in F handoff so A's Phase-7 gate can verify no new plugin file appeared. Keep the "Upcoming" display's SHIPPED config with membership_scope in place; the override is request-time only. |

## Cross-checks that PASS (no findings)

- **Layering / dependency direction:** MyEventsController lives in `do_streams`, links to `do_discovery` routes by name (no cross-module code coupling), reads `flag` via service — same seams MyFeedController uses. No inversion.
- **Two-display view design:** Two `display_plugin: page` would collide with two routes and duplicate route ownership; the survey's chosen "two Views displays (default `page_1` and `page_2` or default + attachment) both invoked from ONE controller via `setDisplay()` twice" is legitimate and matches MyFeedController's own explicit-execute pattern. Either two `display_plugin: default`-style displays with distinct ids (e.g. `default` + `my_rsvps`) OR default + attachment works — no route collisions because the ONE route on `do_streams.routing.yml` owns navigation, per ST-1's convention. Recommend the survey's shape (`default` + `my_rsvps`, both non-page displays).
- **`_user_is_logged_in: 'TRUE'` reuse:** correct per ST-1's verified Drupal-11 pattern (see `do_streams.routing.yml`). Do NOT use `_role`.
- **`#type => view` (not `views_embed_view()`):** correct per MyFeedController's docblock — deprecated in D11.4, and MyFeedController already explains why. Extend that pattern verbatim.
- **iCal reuse:** brief non-goal "Do NOT reimplement iCal generation" + survey row 6 correctly names all three existing routes. `ical_user` requires the `{user}` URL param — controller must build the URL with the current user's uid (`Url::fromRoute('do_discovery.ical_user', ['user' => $this->currentUser()->id()])`), not a hardcoded path. Not a finding because the brief is already correct.
- **#60 (`field_date_of_event` provisioning):** "flag, don't fix" is safe here. Field storage + field config both exist in `docs/groups/config/` (grep confirmed). Seed populates it (`step_700_demo_data.php` lines 193-195 per decisions.md). Defensive fallback for missing dates (hide the date badge, don't 500) is a Q-D2 implementation note, not a design gap — accept as advisory.
- **Cache-tag strategy:** `DoStreamsHooks::userStreamCacheTag($this->currentUser()->id())` + `contexts: ['user', 'user.roles:authenticated']` on the outer shell render array — exact copy of MyFeedController's pattern, correct for a per-viewing-user page. RSVP chip's separate cache obligations are Finding #2.
- **Nav / HelpText:** append-only extensions of ST-1's own additions. Correct, no drift.

## Notes for O

None blocking. Recommend spawning T(RED) now. F must acknowledge advisory findings #1–#3 in its own handoff (which A will re-check at Phase 7 anti-duplication gate). If F wants to escalate finding #1 to a shell-contract change (extend `do_streams_shell` to accept a list of results/empty sections), that IS a cross-story refactor and O should decide whether to broaden this issue's scope or defer to a shell-owning story — this reviewer's read is "keep the shell alone, controller composes", but the operator holds the scope call.

## Patterns referenced

- `docs/groups/modules/do_streams/src/Controller/MyFeedController.php` — controller shape, cache metadata, `Views::getView() + #type: view` deprecation-avoidance pattern.
- `docs/groups/modules/do_streams/do_streams.routing.yml` — `_user_is_logged_in: 'TRUE'` idiom.
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — `userStreamCacheTag()`, shell theme hook, preprocess convention, "guard by view id and return" idiom.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` — single `results`/`empty` slot (source of finding #1).
- `docs/groups/config/views.view.my_feed.yml` — clone shape for `views.view.my_events.yml`.
- `docs/groups/modules/do_discovery/do_discovery.routing.yml` + `Controller/IcalController.php` — link-only reuse; `ical_user` requires `{user}` param.
