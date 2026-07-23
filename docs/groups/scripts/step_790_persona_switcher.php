<?php

/**
 * @file
 * Step 790 (#120 SC-1): persona-switcher seed data.
 *
 * Single source of truth shared by the container entrypoint and (once wired
 * in) the CI E2E job (.github/workflows/test.yml), matching the same
 * "shared script" convention step_700/step_720/step_780 already established.
 * Numbered after step_780 (nav menu) per O's Phase-1 decision (survey.md) —
 * append-only, avoids colliding with #121's future append to
 * step_700/step_780.
 *
 * Provisions the 3 concrete persona accounts this story's Kernel/Functional/
 * E2E suites all target by uname (see ShowcaseCatalog::personas()):
 *   - `elena_garcia` and `maria_chen` already exist (seeded in
 *     step_700_demo_data.php) — this script does NOT recreate them.
 *   - `groups_moderate_demo` is NEW — created here with the site-level
 *     `groups_moderate` role.
 *
 * Also grants Maria Chen the `community_group-organizer` group role on one
 * existing seeded group (idempotent — checks for an existing Organizer
 * membership before writing), and creates ONE pending group_relationship on
 * a seeded group via `GroupMembershipManager::STATUS_PENDING`
 * (brief-amendments.md Amendment 8 — the const, not the literal string
 * 'pending', so this stays a single edit-in-one-place if the value ever
 * changes; the pending relationship also doubles as a ready-made fixture for
 * #121's future request-to-join approve-path test).
 *
 * Fully idempotent — safe to re-run over a persistent volume or a fresh CI
 * install, matching every other step_7xx script's convention.
 */

use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

echo "\n=== Step 790: Persona switcher seed (#120 SC-1) ===\n";

$entity_type_manager = \Drupal::entityTypeManager();
$group_storage = $entity_type_manager->getStorage('group');

// ===== 1. groups_moderate site role (idempotent) =====
if (!Role::load('groups_moderate')) {
  Role::create(['id' => 'groups_moderate', 'label' => 'Groups Moderate'])->save();
  echo "Created role: groups_moderate\n";
}
else {
  echo "Exists: role groups_moderate\n";
}

// ===== 2. groups_moderate_demo user account (idempotent) =====
$moderator = user_load_by_name('groups_moderate_demo');
if (!$moderator) {
  $moderator = User::create([
    'name' => 'groups_moderate_demo',
    'mail' => 'groups.moderate.demo@example.com',
    'status' => 1,
    'pass' => 'demo_password_2026',
  ]);
  $moderator->save();
  echo 'Created uid=' . $moderator->id() . " groups_moderate_demo\n";
}
else {
  echo "Exists: groups_moderate_demo\n";
}
if (!$moderator->hasRole('groups_moderate')) {
  $moderator->addRole('groups_moderate');
  $moderator->save();
  echo "Granted groups_moderate role to groups_moderate_demo\n";
}

// ===== 3. Maria Chen's Organizer group role on one seeded group =====
// step_700_demo_data.php already seeds maria_chen as a member of several
// groups WITHOUT the Organizer group role (plain membership,
// $group->addMember($user) with no group_roles). This story needs at least
// one seeded group where Maria genuinely holds the Organizer role, so
// PersonaAccessPositiveTest / the E2E "Maria Chen (Organizer)" flow have a
// real group-edit / manage-members surface to exercise.
$maria = user_load_by_name('maria_chen');
if (!$maria) {
  echo "SKIP: maria_chen not found (expected to already exist from step_700_demo_data.php)\n";
}
else {
  // "DrupalCon Portland 2026" is the group step_700 seeds Maria into first —
  // reuse it rather than creating a new group, per the "extend, don't
  // duplicate" reuse rule.
  $groups = $group_storage->loadByProperties(['label' => 'DrupalCon Portland 2026']);
  $maria_group = reset($groups);
  if (!$maria_group) {
    echo "SKIP: seeded group \"DrupalCon Portland 2026\" not found\n";
  }
  else {
    // Group::getMember() returns a GroupMembershipInterface — a "shared
    // bundle class" that DECORATES the actual group_relationship entity
    // (Drupal\group\Entity\GroupMembership extends SharedBundleClassBase),
    // not a separate wrapper — it already responds to ->get()/->set()/
    // ->save() directly, matching how GroupMembershipManager::hasRole()
    // reads 'group_roles' off a relationship object elsewhere in this repo.
    $existing_membership = $maria_group->getMember($maria);
    if ($existing_membership) {
      $has_organizer_role = FALSE;
      if ($existing_membership->hasField('group_roles')) {
        foreach ($existing_membership->get('group_roles')->getValue() as $item) {
          if (($item['target_id'] ?? NULL) === GroupMembershipManager::ORGANIZER_ROLE_ID) {
            $has_organizer_role = TRUE;
            break;
          }
        }
      }
      if ($has_organizer_role) {
        echo "Exists: maria_chen already Organizer on \"DrupalCon Portland 2026\"\n";
      }
      else {
        $existing_membership->set('group_roles', [GroupMembershipManager::ORGANIZER_ROLE_ID]);
        if ($existing_membership->hasField('field_membership_status') && $existing_membership->get('field_membership_status')->isEmpty()) {
          $existing_membership->set('field_membership_status', [['value' => GroupMembershipManager::STATUS_ACTIVE]]);
        }
        $existing_membership->save();
        echo "Granted Organizer group role to maria_chen on \"DrupalCon Portland 2026\"\n";
      }
    }
    else {
      $maria_group->addMember($maria, [
        'group_roles' => [GroupMembershipManager::ORGANIZER_ROLE_ID],
        'field_membership_status' => [['value' => GroupMembershipManager::STATUS_ACTIVE]],
      ]);
      echo "Added maria_chen as Organizer on \"DrupalCon Portland 2026\"\n";
    }
  }
}

// ===== 4. One pending group_relationship =====
// (GroupMembershipManager::STATUS_PENDING) — Groups-Moderate's E2E flow
// verifies it can view a group's pending-join queue on a group it has never
// joined. Seed one pending relationship on a DIFFERENT seeded group (not
// Maria's Organizer group above) using an account not already a member
// there, so the pending row is unambiguous.
$ravi = user_load_by_name('ravi_patel');
if (!$ravi) {
  echo "SKIP: ravi_patel not found (expected to already exist from step_700_demo_data.php) — pending-relationship seed skipped\n";
}
else {
  $groups = $group_storage->loadByProperties(['label' => 'Core Committers']);
  $pending_group = reset($groups);
  if (!$pending_group) {
    echo "SKIP: seeded group \"Core Committers\" not found — pending-relationship seed skipped\n";
  }
  elseif ($pending_group->getMember($ravi)) {
    echo "Exists: ravi_patel already has a relationship on \"Core Committers\" (skipping — would not be a clean pending fixture)\n";
  }
  else {
    $pending_group->addMember($ravi, [
      'field_membership_status' => [['value' => GroupMembershipManager::STATUS_PENDING]],
    ]);
    echo "Created PENDING relationship: ravi_patel -> \"Core Committers\"\n";
  }
}

echo "=== Step 790 complete ===\n";
