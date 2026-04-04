# Implementation Plan: Open Social → groups.drupal.org Feature Parity

**Objective**: Configure and extend Open Social 13 to replicate the features of groups.drupal.org (g.d.o).

**Target environment**: `pl-opensocial` (DDEV, `https://pl-opensocial.ddev.site`)

**Test framework**: Playwright (TypeScript), tests in `tests/e2e/`

**Run tests**: `ddev exec npx playwright test`

---

## Phase Standards

To ensure data safety and traceability, the following procedures **must** be followed for every phase:

1.  **Phase Start**:
    - Backup the database: `ddev export-db --file=backups/phaseN-pre-[timestamp].sql.gz`
    - Record the start in `documents/BUILD_LOG.md`.
2.  **Phase Execution**:
    - Record every significant step (configuration change, module install, code edit) in `documents/BUILD_LOG.md`.
3.  **Phase Verification**:
    - Run the phase-specific Playwright tests.
    - All tests must pass before proceeding.
4.  **Phase Completion**:
    - Add dummy data sourced from `groups.drupal.org` (reproducing relevant g.d.o content structures).
    - Finalize the phase entry in `documents/BUILD_LOG.md`.

---

## Background

groups.drupal.org is a Drupal 7 site running the Organic Groups (OG) module. It provides geographic and working groups, discussion threads, events, wiki pages, multi-group posting, email subscriptions, and a basic hot-content feed. Open Social is a modern Drupal 10 distribution with an overlapping but different feature set.

The plan is organised into eight phases (Phase 1 is DDEV initialisation; Phases 2–8 are feature phases). Each feature phase ends with a Playwright test suite that must pass before the next phase begins.

---

## Test Infrastructure (before Phase 1)

Bootstrap the Playwright harness before any implementation work begins.

```
tests/
  e2e/
    fixtures/
      auth.ts         # login helpers: admin, editor, member, anonymous
      groups.ts       # group creation helpers
      users.ts        # user creation helpers
    phase2-content-types.spec.ts
    phase3-groups.spec.ts
    phase4-discovery.spec.ts
    phase5-multigroup.spec.ts
    phase6-notifications.spec.ts
    phase7-profiles.spec.ts
    phase8-moderation.spec.ts
  playwright.config.ts
```

**`playwright.config.ts` settings**:

- `baseURL`: `https://pl-opensocial.ddev.site`
- `storageState`: saved auth sessions per role (admin, editor, member, anonymous)
- `reporter`: HTML + JUnit XML
- `retries`: 1 on CI, 0 locally
- Mail assertions: Mailpit API at `https://pl-opensocial.ddev.site:8026/api/v2/messages`

---

## Phase 2 — Content Types & Text Formats

**Goal**: Make Topic, Event, and Page behave like Discussion, Event, and Wiki page on g.d.o.

> **Pre-requisite**: Backup database to `backups/phase2-pre.sql.gz` and start build log entry.

### 2.1 Topic → Discussion

- Set Topic body field to accept **Full HTML + Markdown** (`/admin/config/content/formats`)
- Add `[[title]]` wiki-link filter to the text format pipeline (Linkit module)
- Enable **summary/teaser** toggle on Topic body field
- Set max file attachment size to **15 MB**
- Configure allowed file extensions: `jpg jpeg gif png txt xls pdf ppt pps odt ods odp gz tgz patch diff po pot psd html doc csv`

### 2.2 Event

- Add **Event URL** Link field (label: "Event URL"; help text: "If more information is available on another website, add it here")
- Create **Event Type** taxonomy vocabulary with terms:
  - User group meeting
  - Drupalcamp or Regional Summit
  - DrupalCon
  - Online meeting (e.g. IRC meeting)
  - Training (free or commercial)
  - Sprint
  - Related event (not Drupal-specific)
- Add required Term Reference field "Event type" to Event content type
- Add multi-value **Organizers** User Reference field (drag-to-reorder, autocomplete, unlimited cardinality)
- Add file attachments to Event (same 15 MB / extension limits as Topic)
- Enable enrollment (RSVP) by default

### 2.3 Page → Wiki page

- Configure Page edit permissions to allow any authenticated user to edit (not just group members)
- Add file attachments to Page (same limits)
- Enable revision log on Page

---

### Phase 2 Tests — `tests/e2e/phase2-content-types.spec.ts`

| Test ID | Description |
|---|---|
| `discussion.create` | Authenticated user creates a Topic with Markdown body, summary, and file attachment; node saves and renders correctly |
| `discussion.markdown` | Body with `**bold**` and `# Heading` renders as `<strong>` and `<h1>` on the view page |
| `discussion.attachment.size` | Uploading a file over 15 MB is rejected with a validation error |
| `discussion.attachment.type` | Uploading a `.exe` is rejected; uploading a `.pdf` is accepted |
| `event.create` | User creates an Event with start/end date, timezone, event type, organizer, event URL, body, and attachment |
| `event.type.required` | Submitting an Event without an Event Type shows a validation error |
| `event.url.display` | Event URL appears on the Event view page as a clickable link |
| `event.organizers.multivalue` | Two organizer usernames saved on an Event both appear on the Event view page |
| `wiki.create` | Authenticated user creates a Page |
| `wiki.edit.nonmember` | A logged-in user who is **not** a group member can edit a Page node |
| `wiki.revision` | Editing a Page creates a new revision; revision log message is stored |

> **Post-phase**: Add dummy Discussion, Event, and Wiki content from g.d.o; finalize phase 2 log.

---

## Phase 3 — Group Structure & Membership Models

**Goal**: Replicate g.d.o's four membership models, group types, group directory, moderation queue, and archive type.

> **Pre-requisite**: Backup database to `backups/phase3-pre.sql.gz` and update build log.

### 3.1 Group types

Configure five Group Types in Open Social:

| Type | Purpose |
|---|---|
| Geographical | Local user groups by city/region |
| Working group | Module, feature, or initiative coordination |
| Distribution | Drupal distribution projects |
| Event planning | DrupalCon and camp organising |
| Archive | Inactive groups (read-only) |

### 3.2 Membership models

| g.d.o model | Open Social group type | Behaviour |
|---|---|---|
| Open | Open | Instant join, no approval |
| Moderated | Closed | Membership requires admin approval |
| Invite only | Secret | Hidden; admin sends invitation |
| Closed | Secret | Admin-managed roster; hidden from directory |

Add explanatory help text to the group creation form for each option.

### 3.3 Group directory

Configure `/all-groups` View:

- Exposed filter: Group Type
- Exposed filter: keyword search (searches title and description)
- Display columns: group name, description, member count, join/request/invite-only button
- Exclude Secret groups from default listing
- Add RSS feed display

### 3.4 Archive group type

When a group's type is set to Archive:

- Disable new content creation (custom access check hook)
- Display "Archived" badge on group header and directory listing
- Exclude from default directory; visible only when Archive filter is active
- Add "Archive this group" action to group edit form

### 3.5 Group moderation queue

- Install and configure **Content Moderation** for the Group bundle
- Workflow states: `Draft → Needs Review → Published`
- Groups created by non-admins default to "Needs Review"
- Add `/admin/groups/pending` view listing unpublished groups
- Send automated email to site maintainer role when a group enters "Needs Review"

### 3.6 Group submission guidelines

Add a guidelines block to the group creation form matching g.d.o's rules:

- Group names should be brief; avoid the word "Drupal"
- Don't overlap significantly with an existing group
- Provide a meaningful description and welcome message
- Groups should be Drupal-related
- Most groups should be open membership
- All groups are subject to moderator approval

---

### Phase 3 Tests — `tests/e2e/phase3-groups.spec.ts`

| Test ID | Description |
|---|---|
| `group.create.open` | User creates an Open group; a second user can join immediately without approval |
| `group.create.moderated` | User creates a Closed group; second user requests membership; admin approves; second user can now post |
| `group.create.secret` | Secret group does not appear in `/all-groups` to non-members |
| `group.create.invite` | Non-member cannot request to join a Secret group; admin can add member directly |
| `group.directory.filter.type` | Filtering directory by "Working group" returns only groups of that type |
| `group.directory.filter.keyword` | Keyword search returns groups whose name or description contains the term |
| `group.archive.no.post` | A group set to Archive rejects new Topic creation with an appropriate message |
| `group.archive.badge` | Archived group shows "Archived" label in directory listing |
| `group.archive.directory` | Archived group is hidden by default; visible when Archive filter is selected |
| `group.moderation.queue` | Non-admin creates group; group is not publicly visible; appears in admin pending list |
| `group.moderation.approve` | Admin publishes group from pending list; group appears in public directory |
| `group.moderation.email` | Email notification is sent to maintainer role when group enters "Needs Review" |
| `group.guidelines.visible` | Group creation form displays submission guidelines text |

> **Post-phase**: Add dummy groups and moderation entries from g.d.o; finalize phase 3 log.

---

## Phase 4 — Taxonomy, Discovery & Feeds

**Goal**: Sitewide tags aggregation, events calendar with iCal, hot content page, per-group RSS feeds, and manually curated front page content.

> **Pre-requisite**: Backup database to `backups/phase4-pre.sql.gz` and update build log.

### 4.1 Sitewide tags

- Confirm Tag vocabulary is free-tagging on Topic
- Create `/tags/[term-name]` View: lists Topics across all groups sharing that tag, sorted by most recent
- Display tag links on Topic teasers in group stream

### 4.2 Events calendar & iCal

Configure Events overview View (`/upcoming-events`):

- Exposed filter: Event Type
- Exposed filter: "Only events I have signed up for"
- Exposed filter: "Only events in my groups"
- Calendar display mode

Expose iCal feeds:

- `/upcoming-events/ical` — all site events
- `/group/{id}/events/ical` — per-group events
- `/user/{id}/events/ical` — user's RSVPs

### 4.3 Hot content page

- Install **Statistics** module
- Create `/hot` View with composite score: `(comment_count × 3) + (view_count × 0.5)` over a 7-day rolling window
- Exposed filter: Content type (Discussion, Event, Wiki page)
- Exposed filter: "In my groups" (boolean)
- Sorted by composite score descending

### 4.4 Manually curated front page content

- Install **Flag** module
- Create "Promote to homepage" flag available to Editor and Admin roles
- Build "Promoted content" block on front page (View filtered on this flag)
- Manage promoted content at `/admin/content/promoted`

### 4.5 Per-group RSS feeds

- Add RSS display to the group stream View, exposed at `/group/{id}/stream/feed`
- Display RSS icon in group sidebar linking to the feed

---

### Phase 4 Tests — `tests/e2e/phase4-discovery.spec.ts`

| Test ID | Description |
|---|---|
| `tags.crossgroup` | Topic in Group A with tag "accessibility" appears on `/tags/accessibility` alongside a Topic from Group B with the same tag |
| `tags.link` | Tag link on a Topic teaser navigates to the correct aggregation page |
| `events.filter.type` | Filtering by "Drupalcamp or Regional Summit" returns only events of that type |
| `events.filter.mysignups` | "Only my signups" returns only events the current user enrolled in |
| `events.filter.mygroups` | "Only events in my groups" returns only events from the user's groups |
| `events.calendar.display` | Calendar view renders without errors and shows events on correct dates |
| `events.ical.site` | GET `/upcoming-events/ical` returns `text/calendar` with VEVENT entries |
| `events.ical.group` | GET `/group/{id}/events/ical` returns iCal entries scoped to that group only |
| `hot.page.loads` | `/hot` page loads and displays at least one content item |
| `hot.filter.type` | Filtering `/hot` by "Discussion" hides Event and Wiki page entries |
| `hot.filter.mygroups` | "In my groups" on `/hot` shows only content from the user's groups |
| `promote.flag` | Editor flags a Topic as "Promote to homepage"; it appears in the promoted block on the front page |
| `promote.unflag` | Editor removes the flag; Topic disappears from the promoted block |
| `rss.group` | GET `/group/{id}/stream/feed` returns valid RSS XML with `<item>` entries |

> **Post-phase**: Add tagged content and promoted homepage items from g.d.o; finalize phase 4 log.

---

## Phase 5 — Multi-Group Posting

**Goal**: Allow a single Topic or Event to appear in multiple groups simultaneously — the defining feature of g.d.o's posting model.

> **Pre-requisite**: Backup database to `backups/phase5-pre.sql.gz` and update build log.

### 5.1 Group Audience field

- Add a multi-value **Group Audience** entity reference field to Topic and Event
- Filtered to groups the current user is a member of
- Rendered as a collapsible fieldset with checkboxes on create/edit forms
- Label: "Show this post in these groups"
- Help text: "Select all groups where this post should appear"

### 5.2 Group stream query

- Modify the group stream View with an OR condition: include content where the group matches the primary group **or** appears in the Group Audience field
- Ensure no duplicate entries when both conditions match

### 5.3 Content display

- On full view: display "Posted in: [Group A], [Group B]" as linked group names
- On teasers in non-originating groups: display "Cross-posted from [Group X]"

---

### Phase 5 Tests — `tests/e2e/phase5-multigroup.spec.ts`

| Test ID | Description |
|---|---|
| `multigroup.form.shows.membership` | Group Audience fieldset shows only groups the current user belongs to |
| `multigroup.post.appears.in.both` | Topic posted to Group A and Group B appears in both group streams |
| `multigroup.post.appears.once` | The Topic appears exactly once in each stream (no duplicates) |
| `multigroup.crosspost.label` | Topic posted in Group A, cross-posted to Group B shows "Cross-posted from Group A" in Group B's stream |
| `multigroup.posted.in.links` | Full Topic view shows "Posted in: Group A, Group B" with working links |
| `multigroup.nonmember.excluded` | Group Audience checkboxes do not show groups the user is not a member of |
| `multigroup.event.works` | An Event can be cross-posted to multiple groups with identical behaviour |

> **Post-phase**: Add cross-posted Discussions and Events from g.d.o; finalize phase 5 log.

---

## Phase 6 — Notifications & Subscriptions

**Goal**: Match g.d.o's notification model — email subscriptions by group/thread/author/content type, per-post opt-out, configurable frequency.

> **Pre-requisite**: Backup database to `backups/phase6-pre.sql.gz` and update build log.

### 6.1 Notification subscription types

Configure Open Social notifications to support subscriptions by:

- **Group** — all new content posted to a group
- **Content type** — e.g. only Events, or only Discussions
- **Thread** — replies to a specific post
- **Author** — any new content by a specific user

Expose subscription management at `/user/{id}/notifications`.

### 6.2 Notification frequency

Add frequency setting to user notification preferences:

- Immediately *(default)*
- Daily digest
- Weekly digest

### 6.3 Per-post opt-out

- "Do not send notifications for this update" checkbox visible on Topic, Event, and Page create/edit forms
- When checked, suppresses all notification emails for that save operation

### 6.4 Subscription management page

Display at `/user/{id}/notifications`:

- Active subscription count
- Table of all active subscriptions with individual remove action
- "Temporarily disable all" toggle
- "Cancel all subscriptions" action
- Form to add a new subscription (by group, content type, thread, or author)

---

### Phase 6 Tests — `tests/e2e/phase6-notifications.spec.ts`

| Test ID | Description |
|---|---|
| `notify.group.subscribe` | User subscribes to a group; new Topic in that group triggers an email to the subscriber |
| `notify.thread.subscribe` | User subscribes to a thread; a reply triggers an email |
| `notify.optout.post` | Topic created with "Do not send notifications" checked sends no email to group subscribers |
| `notify.frequency.digest` | User with "Daily digest" setting does not receive an immediate email; notification appears in digest queue |
| `notify.manage.page` | Subscription management page shows active subscriptions and allows removal of individual subscriptions |
| `notify.disable.all` | "Temporarily disable all" toggle stops notification emails for that user |
| `notify.cancel.all` | "Cancel all" removes all subscriptions; count drops to 0 |
| `notify.rss.group` | Authenticated user can access `/group/{id}/stream/feed` and it returns current content |

> **Post-phase**: Add dummy user subscriptions and digest settings samples; finalize phase 6 log.

---

## Phase 7 — User Profiles & History Stats

**Goal**: Extended profile fields matching g.d.o, derived history stats, event organizers field, and an Ambassadors page.

> **Pre-requisite**: Backup database to `backups/phase7-pre.sql.gz` and update build log.

### 7.1 Extended profile fields

Enable and configure:

- Personal website (URL)
- Full name
- Birthdate
- Business organisation name
- Organisation website (URL)
- Organisation profile / description

Display all fields on the public profile view.

### 7.2 User history stats

Add two computed display fields to user profile:

- **Co-organised events**: count of Event nodes where the user appears in the Organizers field
- **Organiser of groups**: count of groups where the user holds Manager or Admin role

Display in a "History" section on the profile. Recompute via hook on node save.

### 7.3 Groups list on profile

Display list of groups the user is a member of on their public profile as linked group names.

### 7.4 Member duration

Display "Member for X years Y weeks" on profile, derived from account created date.

### 7.5 Ambassadors / Organizers page

Create `/organizers` View:

- Lists all users who appear on at least one Event's Organizers field
- Columns: user avatar, name, event count, link to profile
- Sorted by event count descending

### 7.6 User account tabs

Ensure user account has tabs: View, Edit, Notifications, Signups.

Signups tab: list all events the user has enrolled in, filterable by status (upcoming/past).

---

### Phase 7 Tests — `tests/e2e/phase7-profiles.spec.ts`

| Test ID | Description |
|---|---|
| `profile.fields.save` | User saves full name, website, org name, description; all appear on public profile view |
| `profile.history.events` | User listed as Organizer on two Events; profile shows "Co-organised events: 2" |
| `profile.history.groups` | User is Manager of one group; profile shows "Organiser of groups: 1" |
| `profile.groups.list` | Profile shows linked names of all groups the user belongs to |
| `profile.member.duration` | Profile displays a "Member for" string with a non-zero duration |
| `profile.signups.tab` | Signups tab lists an event the user enrolled in; past events are distinguishable from upcoming |
| `ambassadors.page` | `/organizers` page lists users who have been named as Event Organizer |
| `ambassadors.count` | User who organised 3 events shows event count 3 on the ambassadors page |

> **Post-phase**: Add dummy ambassador profiles and history stats from g.d.o; finalize phase 7 log.

---

## Phase 8 — Content Moderation & Group Admin

**Goal**: Content pinning within groups, homepage promotion, group-level language override, group organizer sidebar.

> **Pre-requisite**: Backup database to `backups/phase8-pre.sql.gz` and update build log.

### 8.1 Pin content in group

- Install **Flag** module (if not already from Phase 4)
- Create "Pin in group" flag available to group Managers
- Pinned content appears above the chronological stream with a "Pinned" badge
- Managers can unpin via the same flag

### 8.2 Group organizer sidebar

- Display group Managers (users with Manager role) in the group sidebar
- Show avatar and linked username for each

### 8.3 Group-level language

- Install **Language** + **Language Negotiation** (Drupal core modules)
- Add Language field to Group entity
- Implement a language negotiation plugin that reads group context and sets the active language

Available languages: Language neutral, Brazilian Portuguese, Catalan, Chinese Simplified, Danish, Dutch, English, French, German, Norwegian Bokmål, Russian, Spanish, Swedish, Turkish.

### 8.4 Group mission statement

Ensure the group Mission Statement / About content is surfaced:

- In the Open Social "About" tab (already exists)
- As a summary block in the group sidebar

---

### Phase 8 Tests — `tests/e2e/phase8-moderation.spec.ts`

| Test ID | Description |
|---|---|
| `pin.manager.can.pin` | Group Manager pins a Topic; it appears above newer Topics in the stream |
| `pin.member.cannot.pin` | Regular member does not see the pin option |
| `pin.badge` | Pinned Topic displays a "Pinned" visual indicator |
| `pin.unpin` | Manager unpins a Topic; it returns to its chronological position |
| `homepage.promote` | Editor promotes content to homepage; block on front page shows the item |
| `organizer.sidebar` | Group Manager's username and avatar appear in the group sidebar |
| `group.language.fr` | Group language set to French; group homepage UI labels render in French |
| `group.language.user.override` | User with personal locale set to Spanish sees Spanish UI even inside a French-language group |
| `about.mission` | Group Mission Statement is visible on the group homepage |

> **Post-phase**: Add dummy pinned content and mission statements from g.d.o; finalize phase 8 log.

---

## Phase Summary

| Phase | Scope | Complexity | Modules required |
|---|---|---|---|
| 2 | Content types, text formats, file limits | Low | Linkit |
| 3 | Groups, membership models, moderation queue, archive type | Medium | Content Moderation |
| 4 | Tags, calendar, iCal, hot content, RSS, front page promotion | Medium | Statistics, Flag, Calendar |
| 5 | Multi-group posting (audience field + stream query) | High | Custom module |
| 6 | Notifications, subscriptions, frequency settings | Medium | Configuration-heavy |
| 7 | User profiles, history stats, ambassadors page | Medium | Custom Views + hooks |
| 8 | Pinning, language override, organizer sidebar | Medium–High | Custom language negotiation plugin |

---

## Features Not in Scope

The following Open Social features exceed what g.d.o currently offers and are intentionally out of scope for this plan. They may be considered for a later enhancement phase:

- Short-form Post entity (social stream post with visibility controls)
- User following
- Likes / reactions
- @mentions
- Private messaging
- Group cover/hero image
- Group logo/avatar
- Event location / map
- Photo / Album content type
- Profile completeness indicator
- Social sharing buttons
- GDPR / data export tools
- Landing page builder for groups
