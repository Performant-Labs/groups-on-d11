# Architecture Differences: Open Social → Standard Drupal

This document catalogues every significant difference between the Open Social implementation (`pl-opensocial`) and the standard Drupal implementation (`pl-drupalorg`) for groups functionality.

---

## 1. Foundation & Distribution

| Aspect | Open Social (`pl-opensocial`) | Standard Drupal (`pl-drupalorg`) |
|---|---|---|
| **Base** | Open Social 13.0.0 distribution (~100 bundled modules) | Vanilla Drupal 10 + individually installed contrib |
| **Install profile** | `social` (custom installer with private file path requirement) | `standard` or `minimal` |
| **Theme** | `socialblue` / `social_base` (requires ~30 frontend JS libraries) | `bluecheese` (standard Drupal admin/frontend theme) |
| **Frontend libraries** | 30+ manually-copied JS libs (Bootstrap, FontAwesome, select2, photoswipe, etc.) | Managed by Drupal core + Composer |
| **PHP** | 8.3 | 8.3 |
| **DDEV** | `pl-opensocial-rework.ddev.site:8493` | `drupalorg.ddev.site` |

> [!NOTE]
> Open Social is a **distribution** — a pre-assembled package of Drupal core + contrib modules + custom themes + custom modules. Moving to standard Drupal means every dependency must be explicitly installed and configured. The upside is full control over what's included; the downside is more setup work.

---

## 2. User Profiles — The Biggest Difference

### Open Social: Separate `profile` entity

Open Social uses the [Profile module](https://www.drupal.org/project/profile) to create a **separate `profile` entity type** that stores all personal information:

```php
// Open Social — loading profile data
$profiles = \Drupal::entityTypeManager()
  ->getStorage("profile")
  ->loadByProperties(["uid" => $user->id(), "type" => "profile"]);
$profile = reset($profiles);
$first_name = $profile->get("field_profile_first_name")->value;
$org = $profile->get("field_profile_organization")->value;
$bio = $profile->get("field_profile_self_introduction")->value;
```

### pl-drupalorg: Fields directly on the `user` entity

Standard Drupal stores profile data directly on the user entity:

```php
// pl-drupalorg — loading profile data
$user = \Drupal\user\Entity\User::load($uid);
$first_name = $user->get("field_first_name")->value;
$bio = $user->get("field_bio")->value;
```

### Why does Open Social use a profile entity?

1. **Multiple profile types** — you can have different profile sets (e.g., "personal profile" vs. "organization profile") without cluttering the user entity
2. **Access control separation** — profile data can have different visibility rules than the user account (e.g., public bio vs. private email)
3. **Fieldable without touching user entity** — avoids conflicts with other modules that also add fields to the user entity
4. **Revisionable** — profile changes can be tracked independently of account changes

### Why skip it for pl-drupalorg?

- Only one profile type is needed
- Putting fields directly on the user entity is simpler and avoids the extra entity load
- Fewer moving parts = less to maintain
- The tradeoff is you lose multi-profile-type capability, but you gain simplicity

### Field name mapping

| Open Social (`field_profile_*`) | pl-drupalorg (`field_*`) |
|---|---|
| `field_profile_first_name` | `field_first_name` |
| `field_profile_last_name` | `field_last_name` |
| `field_profile_self_introduction` | `field_bio` |
| `field_profile_organization` | `field_organization` (if added) |
| `field_profile_function` | Not available (job title field) |
| `field_profile_image` | `user_picture` (core field) |
| `field_profile_summary` | Not available |
| `field_profile_expertise` | Not available |
| `field_profile_interests` | Not available |

### Code impact

Every module that touches user profile data must be rewritten:

| Module | What changes |
|---|---|
| `do_profile_stats` | `ProfileCompletenessBlock` — must check `user` entity fields instead of loading `profile` entity. Complete rewrite. |
| `do_notifications` | `follow_user` flag targets `user` entity, not `profile` entity |
| Demo data scripts | All profile population uses `$user->set()` instead of `Profile::create()` |

---

## 3. Group Entity & Types

| Aspect | Open Social | pl-drupalorg |
|---|---|---|
| **Group module version** | Bundled with OS (Group 2.x API internally) | `drupal/group ^3.0` (Group 3.x) |
| **Group type** | `flexible_group` (pre-configured with 6+ fields) | `community_group` (created from scratch) |
| **Group admin role** | `flexible_group-group_manager` | `community_group-admin` |
| **Group member role** | `flexible_group-member` | `community_group-member` |
| **Group outsider role** | `flexible_group-outsider` | `community_group-outsider` |
| **Relationship entity** | `group_content` (Group 2.x naming) | `group_relationship` (Group 3.x naming) |
| **Relationship type pattern** | `flexible_group-group_node-topic` | `community_group-group_node-forum` |

### Fields on `flexible_group` that don't exist on `community_group`

These OS-specific fields must be either recreated or replaced with Group module access plugins:

| OS Field | Purpose | pl-drupalorg equivalent |
|---|---|---|
| `field_flexible_group_visibility` | public / community / secret | Group access control plugins |
| `field_group_allowed_join_method` | direct / request / invite | Group access control plugins |
| `field_group_allowed_visibility` | Controls which content visibility is allowed | Not needed (no content visibility field) |
| `field_group_type` | Reference to `group_type` taxonomy | Must be added manually (Step 220) |
| `field_group_language` | Group's preferred language | Must be added manually (Step 630d) |

---

## 4. Access Control — No Content Visibility Field

This is the **second biggest difference** after the profile entity.

### Open Social

Every node has a `field_content_visibility` field with three options:
- `public` — visible to everyone
- `community` — visible only to logged-in users
- `group` — visible only to group members

Open Social's access system reads this field and grants/denies access accordingly.

### pl-drupalorg

This field does not exist. Access control is handled entirely by the **Group module's access control plugins**, which are configured per group type. Content posted to a group inherits the group's access rules.

### Impact

- Remove all references to `field_content_visibility` from code and demo data
- Group access (public/private/secret) is configured at the group type level, not per-node
- Simpler model but less granular (you can't have public content in a private group)

---

## 5. Content Types

| Open Social | pl-drupalorg | Notes |
|---|---|---|
| `topic` | `forum` | Renamed; functionally equivalent |
| `event` | `event` | Same name, different fields |
| `page` | `page`, `documentation`, `post` | OS has 1 type; pl-drupalorg splits into 3 |
| — | `documentation` | New type for working group docs |
| — | `post` | Blog-style posts |

### Total: 3 group-postable types → 5 group-postable types

The `do_multigroup`, `do_discovery`, and `do_notifications` modules all hard-code content type lists that must be expanded:

```php
// Open Social
$bundles = ['topic', 'event', 'page'];

// pl-drupalorg
$bundles = ['forum', 'documentation', 'event', 'post', 'page'];
```

---

## 6. Event System

| Aspect | Open Social | pl-drupalorg |
|---|---|---|
| **Event enrollment** | Custom `EventEnrollment` entity type | `rsvp_event` Flag |
| **Date fields** | `field_event_date` + `field_event_date_end` | `field_date_of_event` (check if daterange) |
| **Enrollment toggle** | `field_event_enroll` (boolean) | Not available (remove) |
| **Anonymous enrollment** | `field_event_an_enroll` (boolean) | Not available (remove) |
| **Max enrollment** | `field_event_max_enroll` + `field_event_max_enroll_num` | Not available (add if needed) |
| **Event type taxonomy** | `event_types` (plural, OS default) | `event_types` (must be created) |
| **Sub-modules** | `social_event_type`, `social_event_managers`, `social_event_an_enroll`, `social_event_max_enroll` | None — standard Drupal fields |

### EventEnrollment → Flag

Open Social has a rich enrollment entity with fields:
```php
// Open Social
$enrollment = \Drupal\social_event\Entity\EventEnrollment::create([
  "field_event" => $node->id(),
  "field_enrollment_status" => 1,
  "field_account" => $user->id(),
  "user_id" => $user->id(),
  "field_request_or_invite_status" => NULL,
]);
```

pl-drupalorg uses a simple flag:
```php
// pl-drupalorg
$flag = \Drupal::service("flag")->getFlagById("rsvp_event");
\Drupal::service("flag")->flag($flag, $event_node, $user);
```

**Tradeoff**: Simpler, but you lose per-enrollment metadata (request/invite status, enrollment notes).

---

## 7. Taxonomy & Tags

| Aspect | Open Social | pl-drupalorg |
|---|---|---|
| **Tag vocabulary** | `social_tagging` | `tags` |
| **Tag field on nodes** | `social_tagging` (entity reference) | `field_group_tags` (references `group_tags` vocabulary) |
| **Group type vocabulary** | `group_type` (OS default) | `group_type` (must be created) |
| **Event type vocabulary** | `event_types` (plural) | `event_types` (must be created) |
| **Tagging module** | `social_tagging` | Not needed (standard taxonomy) |

---

## 8. Roles & Permissions

| Open Social role | pl-drupalorg role |
|---|---|
| `contentmanager` | `content_administrator` |
| `sitemanager` | `site_moderator` |
| `authenticated` | `authenticated` (same) |
| `administrator` | `administrator` (same) |

### Permission differences

| Permission area | Open Social | pl-drupalorg |
|---|---|---|
| **Flag permissions** | Granted to `contentmanager` / `sitemanager` | Grant to `content_administrator` / `site_moderator` |
| **Taxonomy access** | `taxonomy_access_fix` module overrides selection handler; requires `select terms in {vocab}` permission | Standard Drupal entity reference — no extra permission needed |
| **Translation** | `social_language` grants permissions automatically | Must grant `translate any entity`, `create content translations`, etc. manually |

---

## 9. Notification System — External Delivery

### Open Social notification pipeline

```
Node/Comment created
  → hook_entity_insert() creates Activity entity
  → activity_send_email module checks user preferences
  → ActivityDigestWorker batches into daily/weekly digests
  → social_follow_content / social_follow_user / social_follow_tag
     determine who receives notifications
```

Open Social has a complete notification infrastructure: `Activity` entity, `ActivitySendEmail` plugin, digest worker, and `social_follow_*` modules.

### pl-drupalorg approach: event recording only

**Drupal does NOT send email.** Drupal only records "what happened" as a lightweight queue item. An external system handles everything else.

```
Node/Comment created
  → hook_node_insert() records event to `do_notifications` queue
     {event, entity_type, entity_id, bundle, author_uid, group_ids, timestamp}
  → External system reads queue
  → External system reads flagging table (follow_content, follow_user, follow_term)
  → External system checks suppression (State API), mute flags, frequency preferences
  → External system renders and delivers email
```

**One queue item per event, not per recipient.** The external system resolves recipients.

### What Drupal provides (for the external system to query)

| Data | Where stored | Purpose |
|---|---|---|
| Notification events | `queue` table (`do_notifications` queue) | What happened |
| Content follows | `flagging` table (`follow_content` flag) | Who follows what content |
| User follows | `flagging` table (`follow_user` flag) | Who follows which users |
| Term follows | `flagging` table (`follow_term` flag) | Who follows which tags |
| Group muting | `flagging` table (`mute_group_notifications` flag) | Who has muted which groups |
| Per-post opt-out | State API (`do_notifications_suppress_{nid}`) | Author chose not to notify |
| User disable-all | State API (`do_notifications_disabled_{uid}`) | User paused all notifications |
| Frequency preference | `field_notification_frequency` on user entity | immediate / daily / weekly |

### What ports directly

| Component | Ports? | Notes |
|---|---|---|
| `follow_content` flag | ✅ Yes | Same Flag module API |
| `follow_user` flag | ⚠️ Partially | Target changes from `profile` entity → `user` entity |
| `follow_term` flag | ✅ Yes | Same Flag module API |
| `mute_group_notifications` flag | ✅ Yes | Same Flag module API |
| Per-post opt-out checkbox | ✅ Yes | `hook_form_node_form_alter()` + State API (external system checks the flag) |
| Notification settings page | ⚠️ Partially | URL route and controller work; subscription queries need minor changes |
| Email sending | ❌ No | Entirely external — Drupal never sends email |

---

## 10. Multilingual

| Aspect | Open Social | pl-drupalorg |
|---|---|---|
| **Setup** | `social_language` module enables 4 translation modules + grants permissions in one step | Must manually enable `language`, `locale`, `interface_translation`, `config_translation`, `content_translation` |
| **Activity stream** | Translation-aware (activities render in user's preferred language) | Must configure content translation manually for each content type |
| **Language negotiation** | Pre-configured by `social_language` | Must set via `language.types` config (user → group → URL → selected) |
| **Translation permissions** | Auto-granted to Site Manager | Must explicitly grant `translate any entity`, `create content translations`, etc. |

---

## 11. Theme & Block Placement

| Aspect | Open Social (`socialblue`) | pl-drupalorg (`bluecheese`) |
|---|---|---|
| **Sidebar region** | `complementary_bottom` | `sidebar_first` |
| **Content region** | `content` | `content` (same) |
| **Theme machine name** | `socialblue` | `bluecheese` |
| **Frontend libraries** | ~30 manually-copied JS libs | Standard Drupal core JS |
| **CSS strategy** | `social_base` +.libraries.yml per module | Standard `.libraries.yml` per module |

Every block placement command must change the `theme` and `region` values:
```php
// Open Social
"region" => "complementary_bottom", "theme" => "socialblue"

// pl-drupalorg
"region" => "sidebar_first", "theme" => "bluecheese"
```

---

## 12. Views

| View | Open Social | pl-drupalorg |
|---|---|---|
| Group topics stream | `group_topics` (queries `group_content`) | `group_content_stream` (queries `group_relationship`) |
| Group events stream | `group_events` | Needs new view or adapt existing |
| Group directory | `newest_groups` | `all_groups` |
| Tags aggregation | `tags_aggregation` | `tags_aggregation` (same, but vocabulary changes) |
| Hot content | `hot_content` | `hot_content` (same structure) |
| Promoted content | `promoted_content` | `promoted_content` (same structure) |
| RSS feed | `group_rss_feed` | `group_rss_feed` (same structure) |
| Pending groups | `pending_groups` | `pending_groups` (same structure) |

### Key View changes

- **Table/entity name**: `group_content_field_data` → `group_relationship_field_data`
- **Relationship plugin**: Verify `group_relationship` Views plugin exists in Group 3.x
- **Node table alias**: In `group_topics` view, the node table alias is `node_field_data_group_relationship_field_data` (not `node_field_data`) — affects `do_group_pin`'s SQL join

---

## 13. Contributed Modules — Availability Gotchas

| Module | Status | Gotcha |
|---|---|---|
| `flag` | ✅ Available | `flag_count` is a **sub-module** (not a separate Composer package) |
| `statistics` | ⚠️ Deprecated | Deprecated in Drupal 10.3.0, removed in 11.0.0; available as [contrib](https://www.drupal.org/project/statistics) |
| `social_tagging` | ❌ OS-only | Use standard `taxonomy` module |
| `social_event_type` | ❌ OS-only | Create `event_types` vocabulary + terms manually |
| `social_event_managers` | ❌ OS-only | Add event manager field manually if needed |
| `social_event_an_enroll` | ❌ OS-only | Not needed (no enrollment entity) |
| `social_language` | ❌ OS-only | Enable 5 core modules individually |
| `social_follow_content` | ❌ OS-only | Use `flag` module with `follow_content` flag |
| `social_follow_tag` | ❌ OS-only | Use `flag` module with `follow_term` flag |
| `social_follow_user` | ❌ OS-only | Use `flag` module with `follow_user` flag |

---

## 14. Custom Module Portability Summary

| Module | Difficulty | Key changes |
|---|---|---|
| `do_group_extras` | 🟡 Medium | Form IDs, role names (`sitemanager`→`site_moderator`), theme regions |
| `do_multigroup` | 🟡 Medium | Bundle list (`topic`→`forum`), `group_content`→`group_relationship`, theme selectors |
| `do_discovery` | 🟢 Low | Content type in hot score query (`topic`→`forum`) |
| `do_notifications` | 🟢 Low | Remove `activity_send_email` dependency; event recording only (external system delivers email) |
| `do_profile_stats` | 🔴 High | Complete rewrite: `profile` entity → `user` entity for completeness check; `topic`→`forum` for stats |
| `do_group_pin` | 🟡 Medium | Target View ID (`group_topics`→`group_content_stream`), flag creation, node table alias |
| `do_group_mission` | 🟢 Low | Block region change only (`complementary_bottom`→`sidebar_first`) |
| `do_group_language` | 🟢 Low | Bundle name in field load (`flexible_group`→`community_group`) |
| `do_wiki` | 🟢 Low | No code changes needed — 100% Drupal-generic |

---

## 15. Demo Data Adaptations

| Aspect | Open Social DEMO_DATA_PLAN | pl-drupalorg |
|---|---|---|
| **Profile storage** | `profile` entity + `field_profile_*` | `user` entity + `field_*` |
| **Roles** | `contentmanager`, `sitemanager` | `content_administrator`, `site_moderator` |
| **Group type** | `flexible_group` | `community_group` |
| **Group role** | `flexible_group-group_manager` | `community_group-admin` |
| **Content type** | `topic` | `forum` |
| **Tag vocabulary** | `social_tagging` | `tags` |
| **Tag field** | `social_tagging` | `field_group_tags` |
| **Content visibility** | `field_content_visibility` | Not available (remove) |
| **Event dates** | `field_event_date` + `field_event_date_end` | `field_date_of_event` |
| **Event enrollment** | `EventEnrollment` entity | `rsvp_event` flag |
| **Group relationship** | `group_content` + `group_node:topic` | `group_relationship` + `group_node:forum` |
| **Follow user target** | `profile` entity | `user` entity |
