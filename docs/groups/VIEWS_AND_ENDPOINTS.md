# Groups Feature — Views & Endpoints Reference

> **Theme**: `bluecheese`
> **Base URL**: `https://drupalorg.ddev.site`

## Public Views

| View | Path | Description |
|---|---|---|
| Groups Listing | `/groups` | All active groups with exposed filters |
| Activity Stream | `/stream` | Sitewide content feed (stream_card view mode) |
| Hot Content | `/hot` | Content ranked by comment × 3 + views × 0.5 |

## Group Views (per group)

| View | Path | Description |
|---|---|---|
| Group Home | `/group/{gid}` | Group content stream |
| Group Events | `/group/{gid}/events` | Events within the group |
| Group Members | `/group/{gid}/members` | Membership roster |

## Feeds

| Feed | Path | Format |
|---|---|---|
| Group RSS | `/group/{gid}/feed` | RSS 2.0 |
| Site iCal | `/upcoming-events/ical` | text/calendar |
| Group iCal | `/group/{gid}/events/ical` | text/calendar |
| User iCal | `/user/{uid}/events/ical` | text/calendar (RSVP'd events) |

## User Pages

| Page | Path | Description |
|---|---|---|
| Notification Settings | `/user/{uid}/notification-settings` | Manage subscriptions |
| Cancel All | `/user/{uid}/notification-settings/cancel-all` | Bulk unsubscribe |

## Admin Pages

| Page | Path | Permission |
|---|---|---|
| Notification Defaults | `/admin/config/people/notification-defaults` | `administer site configuration` |
| Pending Groups | `/admin/content/pending-groups` | Moderation queue |

## Demo Groups

| GID | Name | Language |
|---|---|---|
| 1 | DrupalCon Portland 2026 | en |
| 2 | Drupal France | fr |
| 3 | Core Committers | en |
| 4 | Thunder Distribution | en |
| 5 | Leadership Council | en |
| 6 | Camp Organizers EMEA | en |
| 7 | Drupal Deutschland | de |
| 8 | Legacy Infrastructure | en (archived) |

## Demo Users

| Username | Role |
|---|---|
| maria_chen | DrupalCon organizer, sprint lead |
| james_okafor | Core committer, infrastructure lead |
| elena_garcia | Module maintainer, community organizer |
| ravi_patel | Thunder distribution contributor |
| sophie_mueller | UX designer, frontend lead |
| alex_novak | Camp organizer, community builder |

Password for all demo users: `demo_password_2026`
