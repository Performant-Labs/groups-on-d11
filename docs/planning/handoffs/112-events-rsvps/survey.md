# Survey — Issue #112 (ST-3: Events + My RSVPs at /my-feed/events with iCal ties)

Branch: `112-events-rsvps` (stacked on `110-stream-110`, PR #173).
Worktree: `C:/Users/aange/Projects/_worktrees/groups-st3-events-112`.

## What the issue asks for

New view `my_events` at `/my-feed/events`, two displays:
1. **Upcoming events** — future event nodes, `field_date_of_event` ASC, with
   Global / My-groups toggle (via existing `do_streams_membership_scope`).
2. **My RSVPs** — the viewer's `rsvp_event` flaggings, date ASC.

Per-event **RSVP chip** (going-count + viewer state) rendered inline via
flag lookups. Link (do not reimplement) existing `do_discovery` iCal feeds
(site / group / user). WCAG 2.2 AA. Existing seed (5 events, 9 RSVPs)
already yields elena_garcia: Upcoming (my-groups) = Barcelona → Keynote →
Sprint; My RSVPs = same 3; Keynote chip = 4 going.

## Reuse & Analogous-Feature map — **default extend**

| Need                                    | Analogous / existing object                                                                          | Recommendation      |
|-----------------------------------------|------------------------------------------------------------------------------------------------------|---------------------|
| `/my-feed/events` route + controller    | `do_streams.my_feed` route + `MyFeedController::build()` (ST-1, PR #173) — same shell-wrap pattern    | **EXTEND**: mirror MyFeedController shape into `MyEventsController` (own file, tiny; the two controllers share NO base class today and adding one across a PR boundary is premature). Route lives in the same `do_streams.routing.yml`. |
| View config                             | `views.view.my_feed.yml` (ST-1) — DEFAULT display only, membership_scope filter, `stream_card` row   | **EXTEND**: new `views.view.my_events.yml` cloned from `my_feed.yml`, but with (a) bundle filter = `event` only, (b) contextual arg for future-only date, (c) two displays: `default` (Upcoming, membership + optional global override) and `my_rsvps` (RSVP flaggings via relationship or entity-query fallback), (d) `field_date_of_event` ASC sort. |
| Membership scope                        | `do_streams_membership_scope` Views filter (#109)                                                     | **REUSE as-is** on Upcoming display.  |
| Global toggle                           | Shell-level `?scope=global` param (per do_streams `url_or_param` convention)                          | **REUSE**: controller reads `?scope=` off request; when `global`, DROPS the membership_scope filter via `$display->overrideOption('filters', ...)` — no new plugin ([B-8]).      |
| RSVP chip (going-count + viewer state)  | `flag.flag.rsvp_event` (bundles: event); Flag service `getFlaggingUsers()` / entity query on flagging | **EXTEND**: small render-time helper on MyEventsController (or a lightweight preprocess) that counts flaggings per event and checks viewer state; attach as `#extra_field` via `preprocess_node__event__stream_card` OR as a Views field. Prefer preprocess of the stream_card render (view-mode-agnostic, works on both displays). |
| iCal ties                               | `IcalController` routes `do_discovery.ical_site`, `.ical_group`, `.ical_user`                          | **REUSE**: emit `<a>` links in the shell (header row) — never reimplement. `ical_site` for Upcoming; `ical_user` (routed with `{user}` = current) for My RSVPs. |
| Shared shell                            | `do_streams_shell` theme hook (#109)                                                                  | **REUSE as-is** — two invocations, one per display, `#active_scope => 'my_feed'` for both, results slot swapped. |
| Nav link                                 | `step_780_nav_menu.php` (ST-1 already added My Feed)                                                  | **EXTEND (append-only)**: keep My Feed → /my-feed; the new /my-feed/events is discovered from within the shell (subnav) or the events page is entered via a link on the /my-feed page. Per spec, no new top-nav slot required. |
| HelpText entry                          | `do_chrome/src/HelpText.php` (ST-1 added `/my-feed` entry)                                            | **EXTEND (append-only)**: add `/my-feed/events` entry.  |
| E2E                                     | `tests/e2e/phase*.spec.ts` and ST-1's `my-feed.spec.ts` (in-PR)                                        | **NEW file** `tests/e2e/my-events.spec.ts` per issue (own file). |

**New objects justified:** (1) `MyEventsController` — parallel to `MyFeedController` but with a distinct display + optional-membership-filter override; extracting a common base class would be a cross-PR refactor. (2) `views.view.my_events.yml` — event-bundle-only, two-display view; the my_feed view is intentionally single-display and multi-bundle. (3) `css/events.css` — issue owns this file. (4) E2E spec file per issue.

## Key files (already read)

- `docs/groups/modules/do_streams/src/Controller/MyFeedController.php` (ST-1) — shape to mirror.
- `docs/groups/modules/do_streams/do_streams.routing.yml` (ST-1) — where to add `do_streams.my_events` route.
- `docs/groups/config/views.view.my_feed.yml` (ST-1) — shape to clone.
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — shell contract, `preprocessDoStreamsShell`, `theme` hook, `do_streams_shell` variables.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` — shell template (ST-1 tweaks).
- `docs/groups/modules/do_discovery/src/Controller/IcalController.php` — 3 iCal routes to link.
- `docs/groups/modules/do_discovery/do_discovery.routing.yml` — route names.
- `docs/groups/config/flag.flag.rsvp_event.yml` — RSVP flag ID/bundles.
- `docs/groups/scripts/step_700_demo_data.php` (lines 179-320) — seed events + RSVPs (append-only if needed).
- `docs/groups/config/field.field.node.event.field_date_of_event.yml` — event date field.
- `docs/groups/config/group.relationship_type.community_group-group_node-event.yml` — group-event relationship.

## Forward-compat check

No downstream Phase-1 story appears to consume a new abstract EventsController base or event-shell partial from this issue — subsequent stories (#113/#114/#115 running in siblings) attach their own views to the same shell contract. No new abstract exported.

## Risks / notes

- **#60 (`field_date_of_event` provisioning)** — the field storage exists in config; the seed writes it. Verify seed still populates it in a fresh assemble+install. If a rendered event has no date, gracefully hide chip's date badge (don't 500).
- **flag_service getFlaggingUsers** — count via `entityTypeManager()->getStorage('flagging')->getQuery()->condition('flag_id','rsvp_event')->condition('entity_id',$nid)->count()` — cheap, cached per event render.
- **AA contrast** — the chip needs verified contrast on the shell background; per #145 baseline tokens.
- **Stacked-PR** — this branch is stacked on `110-stream-110` (PR #173). When #173 merges, rebase onto `main` before opening #112's PR.
