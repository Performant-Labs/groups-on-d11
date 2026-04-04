# Open Social Platform — Feature Overview

> Items marked **⚙ Custom** are built by Performant Labs. Everything else ships with Open Social 13 or its contrib ecosystem.

---

## Content Creation

### Topics (Discussions)
- Rich-text authoring with **Full HTML** editor (CKEditor 5)
- **Linkit** inline linking — autocomplete entity references in the editor
- File attachments up to **15 MB** (pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv, zip, gz, tar)
- **⚙ Custom — Wiki-style linking** (`[[Page Title]]` auto-links to existing content) via `pl_opensocial_wiki`

### Events
- Date/time, location, and description fields
- **Event Type** taxonomy (User Group Meeting, DrupalCon, Sprint, etc.)
- **Event Managers** — assign co-organizers to any event
- **Anonymous enrollment** — non-logged-in users can sign up
- **Max enrollment** — cap the number of attendees
- **iCal feeds** — site-wide (`/upcoming-events/ical`) and per-group (`/group/{id}/events/ical`)
- Calendar view with month/week/day display

### Pages (Wiki-style)
- Revision log with full history
- Suitable for knowledge-base and reference content

---

## Groups

### Group Types
- **Open** — anyone can join immediately
- **Closed** — membership requires approval from a group manager
- **Secret** — hidden from the group directory; join by invitation only
- **Group Type taxonomy** — categorize groups (Geographical, Working Group, Distribution, Event Planning, Archive)

### Group Admin
- **⚙ Custom — Pin in Group** — managers can pin important topics to the top of a group stream (`pl_group_pin`)
- **⚙ Custom — Group Language** — set a preferred language per group; the UI switches to that language when members visit (`pl_group_language`)
- User language preference overrides group language when set
- **⚙ Custom — Group Mission** — "About this group" sidebar block displaying the group's mission statement (`pl_group_mission`)
- **⚙ Custom — Group Extras** — archived-group badge, pending-group moderation queue, visual enhancements (`pl_group_extras`)
- **Group moderation** — non-admin groups start unpublished; admins review and approve

### Group Content
- Topics and Events can be posted to groups
- **⚙ Custom — Multi-Group Posting** — cross-post a single Topic or Event to multiple groups simultaneously (`pl_multigroup`)
  - "Posted in" links on the full view
  - "Cross-posted" badge on secondary groups
  - No duplicate content in streams

---

## Discovery & Navigation

### Taxonomy & Tags
- **Social Tagging** — tag content with community-defined terms
- **Tags Aggregation view** — browse all tagged content across groups

### Hot Content
- **⚙ Custom — Hot Content scoring** — ranks content by comment count, likes, and recency (`pl_discovery`)
- Filterable by content type and "In my groups"
- Dedicated `/hot` page

### Promoted Content
- **Promote to Homepage** flag — editors can flag content to appear in a homepage promoted block
- Unflagging removes it from the block

### Group Directory
- Filter groups by **type** and **keyword search**
- Archived groups hidden by default (visible with explicit filter)
- **Newest Groups** view (excludes secret groups)

### RSS Feeds
- Per-group RSS feed (`/group/{id}/stream/feed`) for external readers

---

## User Profiles

### Profile Fields
- First name, last name, organization, function/role, self-introduction, summary
- Profile photo and banner image

### ⚙ Custom — Contribution Statistics (`pl_profile_stats`)
- **Contribution Stats block** — displays topics created, comments posted, events organized, and groups joined
- **Profile Completeness block** — shows a percentage score and lists which fields are still missing

---

## Notifications & Subscriptions

### Follow & Subscribe
- **Follow Content** — flag any topic to receive updates on new comments
- **Follow User** — subscribe to another user's activity
- **Follow Tag** — subscribe to a taxonomy tag across all content

### ⚙ Custom — Notification Management (`pl_notifications`)
- Dedicated **Notification Settings** page per user
- View all currently followed content
- **Cancel All Subscriptions** — one-click unsubscribe from everything
- **Temporarily Disable All** notifications
- Opt-out checkbox on content creation forms ("Don't subscribe me to this topic")
- On-site notification stream

---

## Roles & Permissions

| Role | Capabilities |
|------|-------------|
| **Anonymous** | View public content, enroll in open events |
| **Authenticated** | Create topics/events/pages, join groups, follow content, manage own profile |
| **Content Manager** | Promote content to homepage, moderate content |
| **Site Manager** | Approve groups, manage site-wide settings |
| **Administrator** | Full access |

---

## Internationalization

- **12 languages** installed: Catalan, Chinese (Simplified), Danish, Dutch, French, German, Norwegian Bokmål, Portuguese (Brazil), Russian, Spanish, Swedish, Turkish
- **139,000+ translation strings** imported
- **Content translation** — Topics, Events, and Pages can be translated into any installed language
- Site Managers can access the **Translate** tab to manage translations
- **Language negotiation** chain: User preference → Group language → URL → Default

---

## Infrastructure

- **Drupal 10** with **PHP 8.3**
- **Open Social 13** distribution
- **Apache Solr 9** — powers `/search/all` full-text search across content, groups, and users
- **DDEV** local development (nginx-fpm, MariaDB 11.8, Mutagen sync)
- **Drush** CLI for all admin operations
- **Playwright** end-to-end test suite — **64 tests** across 8 phases
- **30 frontend libraries** (Bootstrap, FontAwesome, Select2, PhotoSwipe, etc.)
