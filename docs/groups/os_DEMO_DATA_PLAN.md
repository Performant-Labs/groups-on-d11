# Demo Data Runbook

All data created via `ddev drush php:eval` for reproducibility.

> Items marked **⚙** require custom module features.

---

## Log Protocol

Run **before every phase**:
```bash
ddev exec bash -c 'truncate -s 0 /tmp/logpipe'
ddev drush watchdog:delete all -y
```

Run **after every phase**:
```bash
ddev exec bash -c 'cat /tmp/logpipe | grep -i "error\|fatal\|warning" | tail -20'
ddev drush watchdog:show --severity=3 --count=10
ddev drush watchdog:show --severity=4 --count=10
```

---

# Phase 1 — Clean Slate

Delete all existing content entities (see BUILD_LOG Step 1200):

```bash
ddev drush php:eval '
$storage = \Drupal::entityTypeManager();
$nodes = $storage->getStorage("node")->loadMultiple();
$storage->getStorage("node")->delete($nodes);
echo "Deleted " . count($nodes) . " nodes\n";

$groups = $storage->getStorage("group")->loadMultiple();
$storage->getStorage("group")->delete($groups);
echo "Deleted " . count($groups) . " groups\n";

$users = $storage->getStorage("user")->loadMultiple();
$test_users = [];
foreach ($users as $u) {
  if ($u->id() > 1) { $test_users[] = $u; }
}
if ($test_users) { $storage->getStorage("user")->delete($test_users); }
echo "Deleted " . count($test_users) . " test users\n";

$flaggings = $storage->getStorage("flagging")->loadMultiple();
if ($flaggings) { $storage->getStorage("flagging")->delete($flaggings); }
echo "Deleted " . count($flaggings) . " flaggings\n";

try {
  $enrollments = $storage->getStorage("event_enrollment")->loadMultiple();
  if ($enrollments) { $storage->getStorage("event_enrollment")->delete($enrollments); }
  echo "Deleted " . count($enrollments) . " enrollments\n";
} catch (\Exception $e) { echo "No enrollments\n"; }
'
```

```bash
ddev drush cr
```

Snapshot empty database:
```bash
ddev export-db --file=backups/demo-empty-$(date +%Y%m%d-%H%M).sql.gz
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
echo "Nodes: " . count($s->getStorage("node")->loadMultiple()) . "\n";
echo "Groups: " . count($s->getStorage("group")->loadMultiple()) . "\n";
$users = $s->getStorage("user")->loadMultiple();
$non_admin = array_filter($users, fn($u) => $u->id() > 1);
echo "Users (non-admin): " . count($non_admin) . "\n";
echo "Flaggings: " . count($s->getStorage("flagging")->loadMultiple()) . "\n";
'
```
Expected: Nodes=0, Groups=0, Users (non-admin)=0, Flaggings=0

---

# Phase 2 — Users & Taxonomy

## Step 2.1 — Generate profile photos

Use `generate_image` to create 5 realistic headshot portraits:
- `admin_photo.png` — Middle-aged man, professional, neutral background
- `maria_chen_photo.png` — East Asian woman, 30s, professional headshot
- `james_okafor_photo.png` — Black man, 40s, professional headshot
- `elena_garcia_photo.png` — Latina woman, 30s, friendly professional headshot
- `sophie_mueller_photo.png` — European woman, 30s, professional headshot
- `ravi_patel_photo.png` — South Asian man, late 20s, professional headshot

Save photos to: `tests/fixtures/photos/`

> **alex_novak** intentionally has NO photo (demonstrates ⚙ profile completeness).

## Step 2.2 — Create user accounts

```bash
ddev drush php:eval '
$users_data = [
  ["maria_chen", "demo123", ["contentmanager"]],
  ["james_okafor", "demo123", ["sitemanager"]],
  ["elena_garcia", "demo123", []],
  ["ravi_patel", "demo123", []],
  ["sophie_mueller", "demo123", []],
  ["alex_novak", "demo123", []],
];

foreach ($users_data as [$name, $pass, $roles]) {
  $user = \Drupal\user\Entity\User::create([
    "name" => $name,
    "mail" => $name . "@example.com",
    "pass" => $pass,
    "status" => 1,
  ]);
  foreach ($roles as $role) { $user->addRole($role); }
  $user->save();
  echo "Created uid=" . $user->id() . " $name roles=" . implode(",", $roles) . "\n";
}
'
```

## Step 2.3 — Set user language preferences

> With `social_language` enabled, each user's preferred language is visible
> on their account settings page and the activity stream / notification
> emails will render in that language.

```bash
ddev drush php:eval '
foreach (["sophie_mueller" => "de", "elena_garcia" => "es"] as $name => $lang) {
  $user = user_load_by_name($name);
  $user->set("preferred_langcode", $lang);
  $user->save();
  echo "$name language set to $lang\n";
}
'
```

## Step 2.4 — Populate profiles

```bash
ddev drush php:eval '
$profiles = [
  "admin" => [
    "field_profile_first_name" => "André",
    "field_profile_last_name" => "Angelantoni",
    "field_profile_organization" => "Performant Labs",
    "field_profile_function" => "Platform Director",
    "field_profile_self_introduction" => ["value" => "Building community platforms on Open Social. Passionate about open source and collaborative tools.", "format" => "basic_html"],
    "field_profile_summary" => "Platform Director at Performant Labs",
  ],
  "maria_chen" => [
    "field_profile_first_name" => "Maria",
    "field_profile_last_name" => "Chen",
    "field_profile_organization" => "Community Builders Inc",
    "field_profile_function" => "Content Manager",
    "field_profile_self_introduction" => ["value" => "Experienced content strategist specializing in community platforms. I help teams create engaging digital spaces.", "format" => "basic_html"],
    "field_profile_summary" => "Content Manager at Community Builders",
  ],
  "james_okafor" => [
    "field_profile_first_name" => "James",
    "field_profile_last_name" => "Okafor",
    "field_profile_organization" => "DevOps Solutions Ltd",
    "field_profile_function" => "Site Manager",
    "field_profile_self_introduction" => ["value" => "Infrastructure and site management specialist. I keep platforms running smoothly and help teams adopt best practices.", "format" => "basic_html"],
    "field_profile_summary" => "Site Manager at DevOps Solutions",
  ],
  "elena_garcia" => [
    "field_profile_first_name" => "Elena",
    "field_profile_last_name" => "Garcia",
    "field_profile_organization" => "Open Source Collective",
    "field_profile_function" => "Developer Advocate",
    "field_profile_self_introduction" => ["value" => "Full-stack developer and community contributor. I organize sprints, write documentation, and mentor newcomers to open source.", "format" => "basic_html"],
    "field_profile_summary" => "Developer Advocate at Open Source Collective",
  ],
  "ravi_patel" => [
    "field_profile_first_name" => "Ravi",
    "field_profile_last_name" => "Patel",
    "field_profile_organization" => "",
    "field_profile_function" => "",
    "field_profile_summary" => "",
    // Intentionally NO self_introduction — demonstrates incomplete profile
  ],
  "sophie_mueller" => [
    "field_profile_first_name" => "Sophie",
    "field_profile_last_name" => "Müller",
    "field_profile_organization" => "Digitale Agentur Berlin",
    "field_profile_function" => "Frontend-Entwicklerin",
    "field_profile_self_introduction" => ["value" => "Frontend-Entwicklerin mit Schwerpunkt Drupal-Theming. Ich arbeite an barrierefreien, mehrsprachigen Community-Plattformen.", "format" => "basic_html"],
    "field_profile_summary" => "Frontend Developer in Berlin",
  ],
  "alex_novak" => [
    "field_profile_first_name" => "Alex",
    "field_profile_last_name" => "Novak",
    // Minimal profile — name only
  ],
];

foreach ($profiles as $username => $fields) {
  $uid = $username === "admin" ? 1 : user_load_by_name($username)->id();
  $profile = \Drupal::entityTypeManager()->getStorage("profile")->loadByProperties(["uid" => $uid, "type" => "profile"]);
  $profile = reset($profile);
  if (!$profile) {
    $profile = \Drupal\profile\Entity\Profile::create(["type" => "profile", "uid" => $uid]);
  }
  foreach ($fields as $field => $value) {
    $profile->set($field, $value);
  }
  $profile->save();
  echo "Profile saved: $username\n";
}
'
```

## Step 2.5 — Upload profile photos

> This step uploads photos generated in Step 2.1 to each user's profile.
> Photos must exist at `tests/fixtures/photos/` before running.

```bash
# Ensure target directory exists inside the container
ddev exec mkdir -p /var/www/html/web/sites/default/files/profile-photos
```

```bash
ddev drush php:eval '
$photo_map = [
  "admin" => "admin_photo.png",
  "maria_chen" => "maria_chen_photo.png",
  "james_okafor" => "james_okafor_photo.png",
  "elena_garcia" => "elena_garcia_photo.png",
  "sophie_mueller" => "sophie_mueller_photo.png",
  "ravi_patel" => "ravi_patel_photo.png",
];

foreach ($photo_map as $username => $filename) {
  $uid = $username === "admin" ? 1 : user_load_by_name($username)->id();
  $source = "/var/www/html/tests/fixtures/photos/$filename";
  if (!file_exists($source)) {
    echo "SKIP $username — photo not found: $source\n";
    continue;
  }
  $data = file_get_contents($source);
  $file = \Drupal::service("file.repository")->writeData($data, "public://profile-photos/$filename", \Drupal\Core\File\FileExists::Replace);
  $profile = \Drupal::entityTypeManager()->getStorage("profile")->loadByProperties(["uid" => $uid, "type" => "profile"]);
  $profile = reset($profile);
  $profile->set("field_profile_image", ["target_id" => $file->id(), "alt" => "$username profile photo"]);
  $profile->save();
  echo "Photo uploaded: $username\n";
}
'
```

## Step 2.6 — Create taxonomy tags

```bash
ddev drush php:eval '
$tags = ["sprint","drupalcon","logistics","theme","frontend","process","core","roadmap","thunder","paragraphs","tutorial","drupalcamp","recap","budget","migration","d10","welcome","community","policy","standup"];
$storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
foreach ($tags as $tag) {
  $existing = $storage->loadByProperties(["vid" => "social_tagging", "name" => $tag]);
  if (!$existing) {
    $storage->create(["vid" => "social_tagging", "name" => $tag])->save();
    echo "Created tag: $tag\n";
  } else {
    echo "Exists: $tag\n";
  }
}
echo "Total tags: " . count($storage->loadByProperties(["vid" => "social_tagging"])) . "\n";
'
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
$users = $s->getStorage("user")->loadMultiple();
$non_admin = array_filter($users, fn($u) => $u->id() > 1);
echo "Users (non-admin): " . count($non_admin) . "\n";

$maria = user_load_by_name("maria_chen");
echo "maria_chen roles: " . implode(",", $maria->getRoles()) . "\n";

$james = user_load_by_name("james_okafor");
echo "james_okafor roles: " . implode(",", $james->getRoles()) . "\n";

$sophie = user_load_by_name("sophie_mueller");
echo "sophie_mueller lang: " . $sophie->getPreferredLangcode() . "\n";

$elena = user_load_by_name("elena_garcia");
echo "elena_garcia lang: " . $elena->getPreferredLangcode() . "\n";

// Check ravi profile is incomplete
$ravi_uid = user_load_by_name("ravi_patel")->id();
$ravi_profile = $s->getStorage("profile")->loadByProperties(["uid" => $ravi_uid, "type" => "profile"]);
$ravi_profile = reset($ravi_profile);
$intro = $ravi_profile ? $ravi_profile->get("field_profile_self_introduction")->value : "NULL";
echo "ravi_patel self_introduction: " . ($intro ?: "EMPTY") . "\n";

$tags = $s->getStorage("taxonomy_term")->loadByProperties(["vid" => "social_tagging"]);
echo "social_tagging terms: " . count($tags) . "\n";
'
```
Expected: Users=6, maria=contentmanager, james=sitemanager, sophie lang=de, elena lang=es, ravi intro=EMPTY, tags=20

---

# Phase 3 — Groups & Memberships

## Step 3.1 — Create groups

```bash
ddev drush php:eval '
// Load group_type terms by name
$term_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
$type_map = [];
foreach (["Geographical","Working Group","Distribution","Event Planning","Archive"] as $name) {
  $terms = $term_storage->loadByProperties(["vid" => "group_type", "name" => $name]);
  $term = reset($terms);
  if ($term) { $type_map[$name] = $term->id(); }
  else { echo "WARNING: group_type term not found: $name\n"; }
}

$groups = [
  [
    "label" => "DrupalCon Portland 2026",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "direct",
    "field_group_type" => $type_map["Event Planning"] ?? NULL,
    "field_group_description" => ["value" => "Planning committee for DrupalCon Portland 2026. Everyone is welcome to contribute to session proposals, logistics coordination, and volunteer sign-ups.", "format" => "basic_html"],
  ],
  [
    "label" => "Drupal France",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "direct",
    "field_group_type" => $type_map["Geographical"] ?? NULL,
    "field_group_language" => "fr",
    "field_group_description" => ["value" => "Communauté Drupal francophone. Échangez sur les projets, partagez vos retours d'\''expérience et participez aux événements locaux.", "format" => "basic_html"],
  ],
  [
    "label" => "Core Committers",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "added",
    "field_group_type" => $type_map["Working Group"] ?? NULL,
    "field_group_description" => ["value" => "Coordinate core development sprints, review patch queues, and discuss architectural decisions. Membership by invitation only.", "format" => "basic_html"],
  ],
  [
    "label" => "Thunder Distribution",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "direct",
    "field_group_type" => $type_map["Distribution"] ?? NULL,
    "field_group_description" => ["value" => "Build and maintain the Thunder CMS distribution. Discuss roadmap, contribute modules, and share best practices for media-rich publishing.", "format" => "basic_html"],
  ],
  [
    "label" => "Leadership Council",
    "field_flexible_group_visibility" => "members",
    "field_group_allowed_join_method" => "added",
    "field_group_type" => $type_map["Working Group"] ?? NULL,
    "field_group_description" => ["value" => "Board-level discussions on platform strategy, governance, and organizational planning. Confidential group for leadership team.", "format" => "basic_html"],
  ],
  [
    "label" => "Camp Organizers EMEA",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "direct",
    "field_group_type" => $type_map["Event Planning"] ?? NULL,
    "field_group_description" => ["value" => "Coordinate European and Middle Eastern DrupalCamps. Share resources, templates, and lessons learned from previous events.", "format" => "basic_html"],
  ],
  [
    "label" => "Legacy Infrastructure",
    "field_flexible_group_visibility" => "public",
    "field_group_allowed_join_method" => "direct",
    "field_group_type" => $type_map["Archive"] ?? NULL,
    "field_group_description" => ["value" => "Archived: Drupal 7 module maintenance coordination. This group is no longer active but preserved for reference.", "format" => "basic_html"],
  ],
];

foreach ($groups as $data) {
  $group = \Drupal\group\Entity\Group::create(array_merge([
    "type" => "flexible_group",
    "uid" => 1,
    "status" => 1,
    "field_group_allowed_visibility" => ["public", "community", "group"],
  ], $data));
  $group->save();
  echo "Created gid=" . $group->id() . " " . $group->label() . "\n";
}
'
```

## Step 3.2 — Add memberships

```bash
ddev drush php:eval '
// Build lookup maps
$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$groups_all = $group_storage->loadMultiple();
$gid = [];
foreach ($groups_all as $g) { $gid[$g->label()] = $g; }

$uid = ["admin" => 1];
foreach (["maria_chen","james_okafor","elena_garcia","ravi_patel","sophie_mueller","alex_novak"] as $name) {
  $u = user_load_by_name($name);
  if ($u) { $uid[$name] = $u->id(); }
}

// Membership matrix: [username, group_label, role]
// role: "member" or "manager"
$memberships = [
  // Portland (7 members)
  ["admin", "DrupalCon Portland 2026", "manager"],
  ["maria_chen", "DrupalCon Portland 2026", "member"],
  ["james_okafor", "DrupalCon Portland 2026", "member"],
  ["elena_garcia", "DrupalCon Portland 2026", "member"],
  ["ravi_patel", "DrupalCon Portland 2026", "member"],
  ["alex_novak", "DrupalCon Portland 2026", "member"],
  // France
  ["admin", "Drupal France", "member"],
  ["maria_chen", "Drupal France", "member"],
  ["elena_garcia", "Drupal France", "member"],
  ["sophie_mueller", "Drupal France", "member"],
  // Core
  ["admin", "Core Committers", "manager"],
  ["maria_chen", "Core Committers", "member"],
  ["james_okafor", "Core Committers", "manager"],
  // elena_garcia: pending — handled separately
  // Thunder
  ["admin", "Thunder Distribution", "member"],
  ["maria_chen", "Thunder Distribution", "manager"],
  ["james_okafor", "Thunder Distribution", "member"],
  ["elena_garcia", "Thunder Distribution", "member"],
  ["ravi_patel", "Thunder Distribution", "member"],
  // Council (secret)
  ["admin", "Leadership Council", "manager"],
  ["james_okafor", "Leadership Council", "member"],
  // EMEA
  ["admin", "Camp Organizers EMEA", "member"],
  ["maria_chen", "Camp Organizers EMEA", "manager"],
  ["james_okafor", "Camp Organizers EMEA", "member"],
  ["elena_garcia", "Camp Organizers EMEA", "member"],
  ["sophie_mueller", "Camp Organizers EMEA", "member"],
  // Legacy
  ["admin", "Legacy Infrastructure", "member"],
  ["maria_chen", "Legacy Infrastructure", "member"],
  ["elena_garcia", "Legacy Infrastructure", "member"],
];

foreach ($memberships as [$username, $group_label, $role]) {
  $group = $gid[$group_label];
  $user = \Drupal\user\Entity\User::load($uid[$username]);

  // Admin is auto-added as creator — skip if already a member
  if ($group->getMember($user)) {
    echo "SKIP $username already member of $group_label\n";
    if ($role === "manager") {
      $membership = $group->getMember($user)->getGroupRelationship();
      $membership->set("group_roles", ["flexible_group-group_manager"]);
      $membership->save();
      echo "  -> upgraded to manager\n";
    }
    continue;
  }

  $values = ["group_roles" => []];
  if ($role === "manager") {
    $values["group_roles"] = ["flexible_group-group_manager"];
  }
  $group->addMember($user, $values);
  echo "Added $username to $group_label ($role)\n";
}
'
```

## Step 3.3 — Create elena's pending membership request in Core Committers

```bash
ddev drush php:eval '
$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$groups = $group_storage->loadByProperties(["label" => "Core Committers"]);
$core = reset($groups);
$elena = user_load_by_name("elena_garcia");

// Create a membership request (group_content_type = group_content_type_7fcb76fdf61a9)
$request = \Drupal::entityTypeManager()->getStorage("group_content")->create([
  "type" => "group_content_type_7fcb76fdf61a9",
  "gid" => $core->id(),
  "entity_id" => $elena->id(),
  "grequest_status" => 0,
]);
$request->save();
echo "Created pending membership request: elena_garcia -> Core Committers (id=" . $request->id() . ")\n";
'
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
$groups = $s->getStorage("group")->loadMultiple();
echo "Groups: " . count($groups) . "\n";

foreach ($groups as $g) {
  $members = $g->getMembers();
  echo $g->label() . ": " . count($members) . " members";
  $vis = $g->get("field_flexible_group_visibility")->value;
  echo " (vis=$vis)";
  $lang = $g->get("field_group_language")->value;
  if ($lang) { echo " lang=$lang"; }
  echo "\n";
}

// Check elena pending in Core
$core_groups = $s->getStorage("group")->loadByProperties(["label" => "Core Committers"]);
$core = reset($core_groups);
$elena = user_load_by_name("elena_garcia");
$is_member = $core->getMember($elena);
echo "\nelena_garcia in Core Committers: " . ($is_member ? "MEMBER" : "NOT MEMBER") . "\n";

// Check pending request exists
$requests = $s->getStorage("group_content")->loadByProperties([
  "type" => "group_content_type_7fcb76fdf61a9",
  "gid" => $core->id(),
  "entity_id" => $elena->id(),
]);
echo "elena_garcia pending requests: " . count($requests) . "\n";
'
```
Expected: Groups=7, Portland=6 members, Council=2 (vis=members), France lang=fr, elena NOT MEMBER of Core, 1 pending request

> **Note:** Admin is auto-added as group creator, so member counts are 1 less than the matrix rows
> (e.g., Portland matrix shows 7 rows but admin is already counted = 6 distinct members).

---

# Phase 4 — Content (Topics, Events, Pages)

## Step 4.1 — Create topics

```bash
ddev drush php:eval '
$tag_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
$group_storage = \Drupal::entityTypeManager()->getStorage("group");

// Build tag lookup
$tag_id = [];
$all_tags = $tag_storage->loadByProperties(["vid" => "social_tagging"]);
foreach ($all_tags as $t) { $tag_id[$t->getName()] = $t->id(); }

// Build group lookup
$gid = [];
foreach ($group_storage->loadMultiple() as $g) { $gid[$g->label()] = $g; }

// Build user lookup
$uid = ["admin" => 1];
foreach (["maria_chen","james_okafor","elena_garcia","ravi_patel","sophie_mueller","alex_novak"] as $name) {
  $u = user_load_by_name($name);
  if ($u) { $uid[$name] = $u->id(); }
}

$topics = [
  [
    "title" => "Sprint Planning: Portland 2026",
    "uid" => $uid["admin"],
    "body" => "Let us coordinate the sprint sessions for DrupalCon Portland 2026. Please add your proposed topics and time slots below.",
    "tags" => ["sprint", "drupalcon"],
    "groups" => ["DrupalCon Portland 2026"],
  ],
  [
    "title" => "Venue Logistics Update",
    "uid" => $uid["maria_chen"],
    "body" => "Quick update on the venue booking status. We have confirmed the main hall and two breakout rooms. Still waiting on AV equipment quotes.",
    "tags" => ["logistics"],
    "groups" => ["DrupalCon Portland 2026"],
  ],
  [
    "title" => "Nouveau thème pour drupal.fr",
    "uid" => $uid["elena_garcia"],
    "body" => "Nous travaillons sur un nouveau thème pour le site communautaire drupal.fr. Vos retours sur la maquette en pièce jointe sont les bienvenus.",
    "tags" => ["theme", "frontend"],
    "groups" => ["Drupal France"],
  ],
  [
    "title" => "Patch Review Process RFC",
    "uid" => $uid["james_okafor"],
    "body" => "Proposing a revised process for patch reviews. Key changes: mandatory CI checks before review, 48-hour review SLA, and automated merge for green patches with two approvals.",
    "tags" => ["process", "core"],
    "groups" => ["Core Committers"],
  ],
  [
    "title" => "Thunder 7.0 Roadmap",
    "uid" => $uid["maria_chen"],
    "body" => "The Thunder 7.0 roadmap includes Layout Builder integration, media library improvements, and a new editorial dashboard. Timeline targets Q3 2026.",
    "tags" => ["roadmap", "thunder"],
    "groups" => ["Thunder Distribution", "DrupalCon Portland 2026"],
  ],
  [
    "title" => "Getting Started with Paragraphs",
    "uid" => $uid["elena_garcia"],
    "body" => "A beginner-friendly guide to using the Paragraphs module for flexible content layouts. Covers installation, field configuration, and common patterns.",
    "tags" => ["paragraphs", "tutorial"],
    "groups" => ["Thunder Distribution"],
  ],
  [
    "title" => "Camp Barcelona Recap",
    "uid" => $uid["elena_garcia"],
    "body" => "Great turnout at DrupalCamp Barcelona! Over 200 attendees, 30 sessions, and 5 sprints. See the attached PDF for the full report and photos.",
    "tags" => ["drupalcamp", "recap"],
    "groups" => ["Camp Organizers EMEA"],
  ],
  [
    "title" => "Budget Proposal Q3",
    "uid" => $uid["admin"],
    "body" => "Attached is the Q3 budget proposal covering infrastructure costs, event sponsorships, and community outreach programs. Please review before our next meeting.",
    "tags" => ["budget"],
    "groups" => ["Leadership Council"],
  ],
  [
    "title" => "Migration Guide: D9 to D10",
    "uid" => $uid["maria_chen"],
    "body" => "Step-by-step guide for migrating from Drupal 9 to Drupal 10. Covers deprecated API replacements, theme compatibility, and module updates.",
    "tags" => ["migration", "d10"],
    "groups" => ["Legacy Infrastructure"],
  ],
  [
    "title" => "Welcome to Open Social",
    "uid" => $uid["admin"],
    "body" => "Welcome to our community platform! Check out [[Getting Started with Paragraphs]] for tips on creating rich content.",
    "tags" => ["welcome"],
    "groups" => [],
  ],
  [
    "title" => "Community Code of Conduct",
    "uid" => $uid["admin"],
    "body" => "Our community is committed to providing a welcoming and inclusive environment for everyone. Please read and follow these guidelines in all interactions.",
    "tags" => ["community", "policy"],
    "groups" => [],
  ],
  [
    "title" => "Weekly Standup Notes",
    "uid" => $uid["james_okafor"],
    "body" => "Summary of this week'\''s cross-team standup. Key updates from Portland planning and EMEA camp coordination. Next standup: same time next week.",
    "tags" => ["standup"],
    "groups" => ["DrupalCon Portland 2026", "Camp Organizers EMEA"],
  ],
];

foreach ($topics as $data) {
  $tag_refs = [];
  foreach ($data["tags"] as $tag_name) {
    if (isset($tag_id[$tag_name])) {
      $tag_refs[] = ["target_id" => $tag_id[$tag_name]];
    }
  }

  $node = \Drupal\node\Entity\Node::create([
    "type" => "topic",
    "title" => $data["title"],
    "uid" => $data["uid"],
    "status" => 1,
    "body" => ["value" => $data["body"], "format" => "basic_html"],
    "field_content_visibility" => "public",
    "social_tagging" => $tag_refs,
  ]);
  $node->save();

  // Add to groups
  foreach ($data["groups"] as $group_label) {
    if (isset($gid[$group_label])) {
      $gid[$group_label]->addRelationship($node, "group_node:topic");
      echo "  -> added to: $group_label\n";
    }
  }

  echo "Created nid=" . $node->id() . " " . $data["title"] . "\n";
}
'
```

## Step 4.2 — Create dummy PDF and attach to "Camp Barcelona Recap"

```bash
# Create a simple PDF file
ddev exec bash -c 'echo "%PDF-1.0
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj
xref
0 4
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
trailer<</Size 4/Root 1 0 R>>
startxref
206
%%EOF" > /tmp/camp-barcelona-recap.pdf'

ddev drush php:eval '
$nodes = \Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["title" => "Camp Barcelona Recap"]);
$node = reset($nodes);
if ($node) {
  $data = file_get_contents("/tmp/camp-barcelona-recap.pdf");
  $file = \Drupal::service("file.repository")->writeData($data, "public://camp-barcelona-recap.pdf", \Drupal\Core\File\FileExists::Replace);
  $node->set("field_files", ["target_id" => $file->id(), "display" => 1, "description" => "DrupalCamp Barcelona 2026 Report"]);
  $node->save();
  echo "Attached PDF to Camp Barcelona Recap (fid=" . $file->id() . ")\n";
} else {
  echo "ERROR: Camp Barcelona Recap topic not found\n";
}
'
```

## Step 4.3 — Create events

```bash
ddev drush php:eval '
$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$gid = [];
foreach ($group_storage->loadMultiple() as $g) { $gid[$g->label()] = $g; }

$uid = ["admin" => 1];
foreach (["maria_chen","james_okafor","elena_garcia"] as $name) {
  $u = user_load_by_name($name);
  if ($u) { $uid[$name] = $u->id(); }
}

// Load event_type terms
$term_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
$event_types = [];
foreach ($term_storage->loadByProperties(["vid" => "event_types"]) as $t) {
  $event_types[$t->getName()] = $t->id();
}

$now = new \DateTime();

$events = [
  [
    "title" => "DrupalCon Portland Keynote",
    "uid" => $uid["admin"],
    "body" => "Opening keynote for DrupalCon Portland 2026. Join us for announcements about the future of Drupal and the community.",
    "event_type" => "DrupalCon",
    "date_offset" => "+30 days",
    "duration_hours" => 2,
    "max_enroll" => 500,
    "groups" => ["DrupalCon Portland 2026"],
  ],
  [
    "title" => "Code Sprint: Migrate API",
    "uid" => $uid["james_okafor"],
    "body" => "Hands-on sprint focused on improving the Drupal Migrate API. Bring your laptop and a willingness to write patches!",
    "event_type" => "Sprint",
    "date_offset" => "+31 days",
    "duration_hours" => 8,
    "max_enroll" => 30,
    "groups" => ["DrupalCon Portland 2026"],
  ],
  [
    "title" => "Réunion mensuelle Paris",
    "uid" => $uid["elena_garcia"],
    "body" => "Réunion mensuelle du groupe Drupal France à Paris. Présentations, ateliers et networking.",
    "event_type" => "User group meeting",
    "date_offset" => "+14 days",
    "duration_hours" => 3,
    "max_enroll" => 0,
    "groups" => ["Drupal France"],
  ],
  [
    "title" => "Core Committer Sync",
    "uid" => $uid["james_okafor"],
    "body" => "Weekly sync meeting for core committers. Agenda: release blockers, security issues, and mentoring updates.",
    "event_type" => "Related event (not Drupal-specific)",
    "date_offset" => "+7 days",
    "duration_hours" => 1,
    "max_enroll" => 0,
    "groups" => ["Core Committers"],
  ],
  [
    "title" => "DrupalCamp Barcelona",
    "uid" => $uid["maria_chen"],
    "body" => "DrupalCamp Barcelona 2026 — two days of sessions, sprints, and community building on the Mediterranean coast.",
    "event_type" => "Drupalcamp or Regional Summit",
    "date_offset" => "+60 days",
    "duration_hours" => 16,
    "max_enroll" => 0,
    "groups" => ["Camp Organizers EMEA", "DrupalCon Portland 2026"],
  ],
];

foreach ($events as $data) {
  $start = clone $now;
  $start->modify($data["date_offset"]);
  $start->setTime(9, 0);
  $end = clone $start;
  $end->modify("+" . $data["duration_hours"] . " hours");

  $values = [
    "type" => "event",
    "title" => $data["title"],
    "uid" => $data["uid"],
    "status" => 1,
    "body" => ["value" => $data["body"], "format" => "basic_html"],
    "field_content_visibility" => "public",
    "field_event_date" => $start->format("Y-m-d\\TH:i:s"),
    "field_event_date_end" => $end->format("Y-m-d\\TH:i:s"),
    "field_event_enroll" => 1,
    "field_event_an_enroll" => 1,
  ];

  if (isset($event_types[$data["event_type"]])) {
    $values["field_event_type"] = $event_types[$data["event_type"]];
  }

  if ($data["max_enroll"] > 0) {
    $values["field_event_max_enroll"] = 1;
    $values["field_event_max_enroll_num"] = $data["max_enroll"];
  }

  $node = \Drupal\node\Entity\Node::create($values);
  $node->save();

  foreach ($data["groups"] as $group_label) {
    if (isset($gid[$group_label])) {
      $gid[$group_label]->addRelationship($node, "group_node:event");
      echo "  -> added to: $group_label\n";
    }
  }

  echo "Created event nid=" . $node->id() . " " . $data["title"] . "\n";
}
'
```

## Step 4.4 — Create pages

```bash
ddev drush php:eval '
$uid = ["admin" => 1, "maria_chen" => user_load_by_name("maria_chen")->id()];

// Page 1: Getting Started
$page1 = \Drupal\node\Entity\Node::create([
  "type" => "page",
  "title" => "Getting Started with Open Social",
  "uid" => $uid["admin"],
  "status" => 1,
  "body" => ["value" => "<h2>Welcome!</h2><p>Open Social is a community engagement platform built on Drupal. Here you can create groups, organize events, share knowledge, and connect with others.</p><h3>First Steps</h3><ul><li>Complete your profile</li><li>Join a group that interests you</li><li>Create your first topic</li><li>Follow other members</li></ul>", "format" => "full_html"],
  "field_content_visibility" => "public",
]);
$page1->save();
echo "Created page nid=" . $page1->id() . " Getting Started\n";

// Page 2: Contribution Guidelines (with 3 revisions)
$page2 = \Drupal\node\Entity\Node::create([
  "type" => "page",
  "title" => "Contribution Guidelines",
  "uid" => $uid["maria_chen"],
  "status" => 1,
  "body" => ["value" => "<p>Version 1: Initial contribution guidelines. Be respectful and constructive in all interactions.</p>", "format" => "full_html"],
  "field_content_visibility" => "public",
]);
$page2->save();
echo "Created page nid=" . $page2->id() . " Contribution Guidelines v1\n";

// Revision 2
$page2->set("body", ["value" => "<p>Version 2: Updated guidelines. Added sections on code review etiquette, issue queue best practices, and communication channels.</p>", "format" => "full_html"]);
$page2->setRevisionLogMessage("Added code review and issue queue sections");
$page2->setNewRevision(TRUE);
$page2->save();
echo "  -> revision 2 saved\n";

// Revision 3
$page2->set("body", ["value" => "<h2>Contribution Guidelines</h2><p>Thank you for contributing! Please follow these guidelines:</p><h3>Code Contributions</h3><ul><li>Follow Drupal coding standards</li><li>Include tests for new functionality</li><li>Write clear commit messages</li></ul><h3>Community Participation</h3><ul><li>Be respectful and constructive</li><li>Help newcomers get started</li><li>Share knowledge and resources</li></ul>", "format" => "full_html"]);
$page2->setRevisionLogMessage("Restructured with proper headings and lists");
$page2->setNewRevision(TRUE);
$page2->save();
echo "  -> revision 3 saved\n";

// Page 3: FAQ
$page3 = \Drupal\node\Entity\Node::create([
  "type" => "page",
  "title" => "Platform FAQ",
  "uid" => $uid["admin"],
  "status" => 1,
  "body" => ["value" => "<h2>Frequently Asked Questions</h2><h3>How do I join a group?</h3><p>Visit the Groups page and click Join on any open group.</p><h3>How do I create an event?</h3><p>Navigate to your group and select Create Event from the group menu.</p><h3>Can I post to multiple groups?</h3><p>Yes! When creating a topic or event, you can select multiple groups to cross-post to.</p>", "format" => "full_html"],
  "field_content_visibility" => "public",
]);
$page3->save();
echo "Created page nid=" . $page3->id() . " Platform FAQ\n";
'
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
$nodes = $s->getStorage("node")->loadMultiple();
$by_type = [];
foreach ($nodes as $n) { $by_type[$n->bundle()][] = $n; }
echo "Topics: " . count($by_type["topic"] ?? []) . "\n";
echo "Events: " . count($by_type["event"] ?? []) . "\n";
echo "Pages: " . count($by_type["page"] ?? []) . "\n";
echo "Total: " . count($nodes) . "\n";

// Check cross-posts
$thunder_roadmap = $s->getStorage("node")->loadByProperties(["title" => "Thunder 7.0 Roadmap"]);
$roadmap = reset($thunder_roadmap);
$gc = $s->getStorage("group_content")->loadByProperties(["entity_id" => $roadmap->id(), "type" => "flexible_group-group_node-topic"]);
echo "\nThunder 7.0 Roadmap group_content entries: " . count($gc) . "\n";

$standup = $s->getStorage("node")->loadByProperties(["title" => "Weekly Standup Notes"]);
$standup = reset($standup);
$gc2 = $s->getStorage("group_content")->loadByProperties(["entity_id" => $standup->id(), "type" => "flexible_group-group_node-topic"]);
echo "Weekly Standup group_content entries: " . count($gc2) . "\n";

// Check revisions
$guidelines = $s->getStorage("node")->loadByProperties(["title" => "Contribution Guidelines"]);
$guidelines = reset($guidelines);
$revisions = $s->getStorage("node")->revisionIds($guidelines);
echo "Contribution Guidelines revisions: " . count($revisions) . "\n";

// Check wiki link
$welcome = $s->getStorage("node")->loadByProperties(["title" => "Welcome to Open Social"]);
$welcome = reset($welcome);
$body = $welcome->get("body")->value;
echo "Welcome body contains wiki link: " . (strpos($body, "[[Getting Started") !== false ? "YES" : "NO") . "\n";
'
```
Expected: Topics=12, Events=5, Pages=3, Total=20, Roadmap groups=2, Standup groups=2, Guidelines revisions=3, Wiki link=YES

---

# Phase 5 — Comments & Enrollments

## Step 5.1 — Create comments

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$uid = ["admin" => 1];
foreach (["maria_chen","james_okafor","elena_garcia","ravi_patel"] as $name) {
  $u = user_load_by_name($name);
  if ($u) { $uid[$name] = $u->id(); }
}

$comments_data = [
  ["Venue Logistics Update", [
    [$uid["elena_garcia"], "Can we get a budget estimate for the AV equipment? We need to finalize the purchase order by end of month."],
    [$uid["james_okafor"], "I'\''ll check with the venue this week and get back with exact numbers. They mentioned a package deal for the full setup."],
    [$uid["maria_chen"], "Budget approved ✓ — James, go ahead with the venue'\''s package deal. Let'\''s lock it in before prices change."],
  ]],
  ["Patch Review Process RFC", [
    [$uid["elena_garcia"], "What about automated checks? We could integrate static analysis tools to catch common issues before human review."],
    [$uid["ravi_patel"], "+1 on automation. PHPStan and PHPCS should be mandatory gates in the CI pipeline."],
    [$uid["james_okafor"], "Let'\''s add CI requirements as a formal step. I can set up the GitHub Actions workflow for this."],
    [$uid["admin"], "Merged into the RFC. Thanks all! The updated process will take effect starting next sprint cycle."],
  ]],
  ["Thunder 7.0 Roadmap", [
    [$uid["ravi_patel"], "Excited about the Layout Builder integration! This will make the editorial experience much more intuitive."],
  ]],
];

foreach ($comments_data as [$title, $comment_list]) {
  $nodes = $node_storage->loadByProperties(["title" => $title]);
  $node = reset($nodes);
  if (!$node) { echo "ERROR: Node not found: $title\n"; continue; }

  foreach ($comment_list as [$comment_uid, $body]) {
    $comment = \Drupal\comment\Entity\Comment::create([
      "entity_type" => "node",
      "entity_id" => $node->id(),
      "field_name" => "field_topic_comments",
      "uid" => $comment_uid,
      "comment_type" => "comment",
      "subject" => "",
      "comment_body" => ["value" => $body, "format" => "basic_html"],
      "status" => 1,
    ]);
    $comment->save();
    echo "Comment on \"$title\" by uid=$comment_uid\n";
  }
}
'
```

## Step 5.2 — Create event enrollments

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$uid = [];
foreach (["elena_garcia","ravi_patel","sophie_mueller","alex_novak"] as $name) {
  $u = user_load_by_name($name);
  if ($u) { $uid[$name] = $u->id(); }
}

$enrollments = [
  ["DrupalCon Portland Keynote", ["elena_garcia", "ravi_patel", "sophie_mueller", "alex_novak"]],
  ["Code Sprint: Migrate API", ["elena_garcia", "ravi_patel"]],
  ["Réunion mensuelle Paris", ["sophie_mueller"]],
  ["DrupalCamp Barcelona", ["elena_garcia", "ravi_patel", "alex_novak"]],
];

foreach ($enrollments as [$title, $usernames]) {
  $nodes = $node_storage->loadByProperties(["title" => $title]);
  $node = reset($nodes);
  if (!$node) { echo "ERROR: Event not found: $title\n"; continue; }

  foreach ($usernames as $username) {
    $enrollment = \Drupal\social_event\Entity\EventEnrollment::create([
      "field_event" => $node->id(),
      "field_enrollment_status" => 1,
      "field_account" => $uid[$username],
      "user_id" => $uid[$username],
      "field_request_or_invite_status" => NULL,
    ]);
    $enrollment->save();
    echo "Enrolled $username in \"$title\"\n";
  }
}
'
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
$comments = $s->getStorage("comment")->loadMultiple();
echo "Total comments: " . count($comments) . "\n";

// Comments by node
$node_storage = $s->getStorage("node");
foreach (["Venue Logistics Update", "Patch Review Process RFC", "Thunder 7.0 Roadmap"] as $title) {
  $nodes = $node_storage->loadByProperties(["title" => $title]);
  $node = reset($nodes);
  $cs = $s->getStorage("comment")->loadByProperties(["entity_id" => $node->id()]);
  echo "$title: " . count($cs) . " comments\n";
}

$enrollments = $s->getStorage("event_enrollment")->loadMultiple();
echo "\nTotal enrollments: " . count($enrollments) . "\n";
'
```
Expected: Comments=8, Venue=3, RFC=4, Roadmap=1, Enrollments=10

---

# Phase 6 — Flags, Notifications & Archive

## Step 6.1 — Pin "Sprint Planning" in Portland

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$nodes = $node_storage->loadByProperties(["title" => "Sprint Planning: Portland 2026"]);
$node = reset($nodes);
$admin = \Drupal\user\Entity\User::load(1);

$flag = \Drupal::service("flag")->getFlagById("pin_in_group");
\Drupal::service("flag")->flag($flag, $node, $admin);
echo "Pinned: Sprint Planning (nid=" . $node->id() . ")\n";
'
```

## Step 6.2 — Promote content to homepage

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$maria = user_load_by_name("maria_chen");
$flag = \Drupal::service("flag")->getFlagById("promote_homepage");

foreach (["Getting Started with Paragraphs", "Community Code of Conduct"] as $title) {
  $nodes = $node_storage->loadByProperties(["title" => $title]);
  $node = reset($nodes);
  \Drupal::service("flag")->flag($flag, $node, $maria);
  echo "Promoted: $title (nid=" . $node->id() . ")\n";
}
'
```

## Step 6.3 — Follow content

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$flag = \Drupal::service("flag")->getFlagById("follow_content");

$elena = user_load_by_name("elena_garcia");
$nodes = $node_storage->loadByProperties(["title" => "Patch Review Process RFC"]);
$node = reset($nodes);
\Drupal::service("flag")->flag($flag, $node, $elena);
echo "elena follows: Patch Review Process RFC\n";

$ravi = user_load_by_name("ravi_patel");
$nodes = $node_storage->loadByProperties(["title" => "Thunder 7.0 Roadmap"]);
$node = reset($nodes);
\Drupal::service("flag")->flag($flag, $node, $ravi);
echo "ravi follows: Thunder 7.0 Roadmap\n";
'
```

## Step 6.4 — Follow tag and follow user

```bash
ddev drush php:eval '
// elena follows tag "core"
$elena = user_load_by_name("elena_garcia");
$term_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
$terms = $term_storage->loadByProperties(["vid" => "social_tagging", "name" => "core"]);
$core_term = reset($terms);
$flag = \Drupal::service("flag")->getFlagById("follow_term");
\Drupal::service("flag")->flag($flag, $core_term, $elena);
echo "elena follows tag: core\n";

// ravi follows user maria_chen
$ravi = user_load_by_name("ravi_patel");
$maria = user_load_by_name("maria_chen");
$maria_profile = \Drupal::entityTypeManager()->getStorage("profile")->loadByProperties(["uid" => $maria->id(), "type" => "profile"]);
$maria_profile = reset($maria_profile);
$flag = \Drupal::service("flag")->getFlagById("follow_user");
\Drupal::service("flag")->flag($flag, $maria_profile, $ravi);
echo "ravi follows user: maria_chen\n";
'
```

## Step 6.5 — Disable notifications for sophie

```bash
ddev drush php:eval '
$sophie = user_load_by_name("sophie_mueller");
// Disable email notifications via message_notify user settings
$config = \Drupal::configFactory()->getEditable("message_notify.settings");
// Set user-level override via user data
\Drupal::service("user.data")->set("social_notifications", $sophie->id(), "notifications_enabled", FALSE);
echo "sophie_mueller notifications disabled\n";
'
```

## Step 6.6 — Mark Legacy Infrastructure as archived

```bash
ddev drush php:eval '
$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$groups = $group_storage->loadByProperties(["label" => "Legacy Infrastructure"]);
$group = reset($groups);

// Mark as unpublished (archived)
$group->set("status", 0);
$group->save();
echo "Legacy Infrastructure archived (status=0, gid=" . $group->id() . ")\n";
'
```

**Assert:**
```bash
ddev drush php:eval '
$s = \Drupal::entityTypeManager();
$flaggings = $s->getStorage("flagging")->loadMultiple();
$by_flag = [];
foreach ($flaggings as $f) { $by_flag[$f->getFlagId()][] = $f; }
echo "pin_in_group: " . count($by_flag["pin_in_group"] ?? []) . "\n";
echo "promote_homepage: " . count($by_flag["promote_homepage"] ?? []) . "\n";
echo "follow_content: " . count($by_flag["follow_content"] ?? []) . "\n";
echo "follow_term: " . count($by_flag["follow_term"] ?? []) . "\n";
echo "follow_user: " . count($by_flag["follow_user"] ?? []) . "\n";

$groups = $s->getStorage("group")->loadByProperties(["label" => "Legacy Infrastructure"]);
$legacy = reset($groups);
echo "\nLegacy Infrastructure status: " . ($legacy->isPublished() ? "published" : "UNPUBLISHED/ARCHIVED") . "\n";
'
```
Expected: pin=1, promote=2, follow_content=5 (2 explicit + 3 auto-follows from commenting), follow_term=1, follow_user=1, Legacy=UNPUBLISHED

## Step 6.7 — Create content translations

> Requires `social_language` (enables `content_translation`). Demonstrates
> that content can be translated and that users see translated versions
> when their preferred language matches.

```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");

// Translate "Nouveau thème pour drupal.fr" into English
$nodes = $node_storage->loadByProperties(["title" => "Nouveau thème pour drupal.fr"]);
$node = reset($nodes);
if ($node && !$node->hasTranslation("en")) {
  $translation = $node->addTranslation("en", [
    "title" => "New theme for drupal.fr",
    "body" => ["value" => "We are working on a new theme for the drupal.fr community site. Your feedback on the attached mockup is welcome.", "format" => "basic_html"],
  ]);
  $translation->save();
  echo "Added EN translation: Nouveau thème -> New theme for drupal.fr\n";
} else {
  echo "SKIP: already translated or not found\n";
}

// Translate "Welcome to Open Social" into French
$nodes = $node_storage->loadByProperties(["title" => "Welcome to Open Social"]);
$node = reset($nodes);
if ($node && !$node->hasTranslation("fr")) {
  $translation = $node->addTranslation("fr", [
    "title" => "Bienvenue sur Open Social",
    "body" => ["value" => "Bienvenue sur notre plateforme communautaire ! Consultez [[Getting Started with Paragraphs]] pour créer du contenu riche.", "format" => "basic_html"],
  ]);
  $translation->save();
  echo "Added FR translation: Welcome -> Bienvenue sur Open Social\n";
} else {
  echo "SKIP: already translated or not found\n";
}

// Translate "Welcome to Open Social" into Spanish
$nodes = $node_storage->loadByProperties(["title" => "Welcome to Open Social"]);
$node = reset($nodes);
if ($node && !$node->hasTranslation("es")) {
  $translation = $node->addTranslation("es", [
    "title" => "Bienvenido a Open Social",
    "body" => ["value" => "¡Bienvenido a nuestra plataforma comunitaria! Consulta [[Getting Started with Paragraphs]] para consejos sobre contenido enriquecido.", "format" => "basic_html"],
  ]);
  $translation->save();
  echo "Added ES translation: Welcome -> Bienvenido a Open Social\n";
} else {
  echo "SKIP: already translated or not found\n";
}
'
```

**Assert:**
```bash
ddev drush php:eval '
$node_storage = \Drupal::entityTypeManager()->getStorage("node");

$nodes = $node_storage->loadByProperties(["title" => "Nouveau thème pour drupal.fr"]);
$node = reset($nodes);
echo "French topic EN translation: " . ($node->hasTranslation("en") ? "YES" : "NO") . "\n";
if ($node->hasTranslation("en")) {
  echo "  EN title: " . $node->getTranslation("en")->getTitle() . "\n";
}

$nodes = $node_storage->loadByProperties(["title" => "Welcome to Open Social"]);
$node = reset($nodes);
echo "Welcome FR translation: " . ($node->hasTranslation("fr") ? "YES" : "NO") . "\n";
echo "Welcome ES translation: " . ($node->hasTranslation("es") ? "YES" : "NO") . "\n";
'
```
Expected: French topic EN=YES ("New theme for drupal.fr"), Welcome FR=YES, Welcome ES=YES

---

# Phase 7 — Index, Snapshot & Verify

```bash
ddev drush cron
ddev drush search-api:index
ddev drush cr
```

> `ddev drush cron` triggers `pl_discovery` hot score computation so `/hot` works.

Snapshot complete demo database:
```bash
ddev export-db --file=backups/demo-complete-$(date +%Y%m%d-%H%M).sql.gz
```

**Assert:**
```bash
ddev drush search-api:status
```
Expected: 0 items remaining to index

```bash
ddev drush search-api:search "Portland" social_all 2>&1 | head -5
```
Expected: Returns results

```bash
# iCal feed — should return BEGIN:VCALENDAR
ddev drush php:eval '
$gids = \Drupal::entityTypeManager()->getStorage("group")->loadByProperties(["label" => "Camp Organizers EMEA"]);
$gid = reset($gids)->id();
echo "EMEA gid=$gid\n";
'
curl -sk "https://pl-opensocial-rework.ddev.site:8493/upcoming-events/ical" | head -3
curl -sk "https://pl-opensocial-rework.ddev.site:8493/group/{gid}/events/ical" | head -3
```
Expected: Both return `BEGIN:VCALENDAR` header

```bash
# RSS feed — should return valid XML
curl -sk "https://pl-opensocial-rework.ddev.site:8493/group/{gid}/stream/feed" | head -5
```
Expected: Returns `<?xml` or `<rss` header

---

# Verification Checklist

Browse the site and confirm:

- [ ] Homepage shows promoted content block (2 items)
- [ ] `/hot` ranks content by engagement
- [ ] `/all-groups` shows 6 groups (not Secret, not Archive)
- [ ] `/all-groups` with Archive filter shows Legacy group with ⚙ badge
- [ ] Search (`/search/all`) returns results via Solr
- [ ] Drupal France group renders French UI labels
- [ ] sophie_mueller in France group sees German UI (override)
- [ ] elena_garcia sees Spanish UI labels (preferred_langcode=es)
- [ ] "Nouveau thème" topic shows English translation when viewing in EN
- [ ] "Welcome to Open Social" shows "Bienvenue" when viewing in FR
- [ ] "Welcome to Open Social" shows "Bienvenido" when viewing in ES
- [ ] Activity stream items render in the viewing user's preferred language
- [ ] james_okafor (sitemanager) can access Content → Translate tab
- [ ] Sprint Planning pinned at top of Portland stream
- [ ] Thunder 7.0 Roadmap appears in both Thunder + Portland streams
- [ ] Weekly Standup shows "Posted in" links to Portland + EMEA
- [ ] DrupalCamp Barcelona shows ⚙ cross-posted badge in Portland
- [ ] Admin profile shows ⚙ contribution stats
- [ ] ravi_patel profile shows ⚙ completeness < 100%
- [ ] `/upcoming-events/ical` returns valid iCal
- [ ] `/group/{emea}/events/ical` returns group-scoped iCal
- [ ] `/group/{emea}/stream/feed` returns valid RSS XML with content items
- [ ] Legacy Infrastructure blocks new topic creation
- [ ] Core Committers shows elena_garcia as pending
- [ ] Leadership Council hidden from non-members on `/all-groups`
- [ ] elena_garcia notification settings show followed content
- [ ] Tags aggregation view shows tagged content
- [ ] Camp Barcelona Recap has PDF file attachment visible
- [ ] Users with photos show headshot on profile; alex_novak shows default avatar
