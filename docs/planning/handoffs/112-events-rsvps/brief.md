# Brief — Issue #112 (ST-3: /my-feed/events with iCal ties)

**Review rigor:** none (per issue).
**Stacked on:** `110-stream-110` (PR #173). Rebase onto main before opening.
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st3-events-112`
**Branch:** `112-events-rsvps`
**DDEV project (if needed):** `gm112-events`

## Objective

Ship `/my-feed/events` as a two-display page (Upcoming events + My RSVPs)
that reuses the do_streams shell, the existing rsvp_event flag, and the
do_discovery iCal feed routes. Demo-ready for `elena_garcia` on the
seeded site with correct ordering and a Keynote chip showing "4 going".

## Files this issue owns (disjoint)

- `docs/groups/config/views.view.my_events.yml` (new)
- `docs/groups/modules/do_streams/do_streams.routing.yml` (extend: append route)
- `docs/groups/modules/do_streams/src/Controller/MyEventsController.php` (new)
- `docs/groups/modules/do_streams/css/events.css` (new)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (extend: register events lib)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extend: RSVP-chip preprocess only if chosen impl)
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only: /my-feed/events entry)
- `tests/e2e/my-events.spec.ts` (new)
- Optional kernel/functional test files under `docs/groups/modules/do_streams/tests/src/{Kernel,Functional}/` (new files, own the naming)

## Plan (test-first)

1. **T(RED)** authors:
   - Kernel: `MyEventsViewTest` — assemble+install → the shipped
     `my_events` view has `default` + `my_rsvps` displays; each renders
     with correct filters/sorts/relationships; membership_scope on
     Upcoming; rsvp_event relationship on My RSVPs; bundle filter =
     `event` on both; `field_date_of_event` ASC on both.
   - Functional: `MyEventsRouteTest` — `/my-feed/events` returns 200 for
     an authenticated viewer, 403 (or login-redirect) for anonymous. Two
     display sections render; global-toggle link present; iCal links
     present and 200 on GET.
   - E2E `tests/e2e/my-events.spec.ts` — as `elena_garcia` visit
     `/my-feed/events`: Upcoming lists Barcelona/Keynote/Sprint in date
     order; My RSVPs lists Keynote/Sprint/Barcelona (or date ASC);
     Keynote card shows chip "4 going" and viewer-state "You're going";
     global toggle widens Upcoming to include Thunder + Governance;
     iCal links present with correct hrefs (`/upcoming-events/ical`,
     `/user/<uid>/events/ical`).

2. **F** implements the plan above using the Reuse map — extend, don't
   duplicate. Route uses `_user_is_logged_in: 'TRUE'` (per ST-1's
   verified Drupal-11 pattern, not `_role`). Controller uses
   `Views::getView()` + `#type => view` (no deprecated
   `views_embed_view()`). RSVP chip via a lightweight
   `preprocess_node__event__stream_card` OR a Views custom field —
   whichever passes T's tests with less new code.

3. **T(GREEN)** verifies all tiers + AA (axe on the new page).

4. **U** walks through as elena_garcia on a seeded local site.

5. **S** audits against issue #112 acceptance criteria.

## Acceptance criteria (mirror the issue's "Acceptance" list)

- [ ] Renders for elena_garcia: Upcoming (my-groups) shows
      DrupalCamp Barcelona → Keynote → Code Sprint in date ASC.
- [ ] My RSVPs lists elena's three RSVPs.
- [ ] Keynote's RSVP chip shows "4 going" and viewer-state indicator.
- [ ] Past events excluded (future-date filter).
- [ ] Global toggle widens Upcoming beyond elena's memberships.
- [ ] iCal links present on both displays and resolve (200).
- [ ] Playwright rendered-DOM spec green on seeded site.
- [ ] Existing e2e + kernel + functional suites stay green.
- [ ] HelpText entry appended for `/my-feed/events`.
- [ ] WCAG 2.2 AA (labels, keyboard, focus visible, contrast, non-color
      status).

## Non-goals

- Do NOT reimplement iCal generation — link to do_discovery routes.
- Do NOT fix #60 here unless blocking rendering.
- Do NOT add a new top-nav link (entry via the /my-feed shell suffices).
- Do NOT add a new scope plugin — `?scope=global` is a filter-override.

## Delivery per epic

Branch → assemble config → kernel+functional+E2E green in namespaced DDEV
throwaway (or CI) → rebase-and-CI-check → PR → self-merge on green.
