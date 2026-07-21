<?php

/**
 * @file
 * Step 780 (CH-A1, issue #83): Community header navigation.
 *
 * Seeds the primary header navigation for the groups_chrome theme. The
 * `groups_chrome_main_menu` block (region: primary_menu) and the
 * `groups_chrome_account_menu` block (region: secondary_menu) are already
 * placed via config/sync; this step supplies the actual `main` menu links so
 * the primary nav renders the four community entry points:
 *
 *   Groups        -> /all-groups                     (all_groups view)
 *   Activity      -> /stream                          (activity_stream view)
 *   My Groups     -> /user                            (current user's page;
 *                                                       shows their group
 *                                                       memberships via
 *                                                       do_profile_stats)
 *   Create Group  -> /group/add/community_group       (group add form)
 *
 * The account menu (Log in / My account / Log out) is provided by core's
 * `account` menu and rendered by the placed account-menu block; no seeding is
 * required for it.
 *
 * Menu links are content entities (`menu_link_content`), not config, so they
 * live in the seed rather than config/sync. Each link is created idempotently
 * (keyed on a stable internal machine key) so a re-seed does not duplicate it,
 * and it remains fully editable in the UI at /admin/structure/menu/manage/main.
 *
 * No schema changes: uses only existing routes/paths and the core `main` menu.
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

echo "\n=== Step 780: Header navigation (main menu links) ===\n";

/**
 * Definition of the four primary-nav links, in display order.
 *
 * 'key' is a stable identifier stored in the link description so re-seeds are
 * idempotent; 'uri' uses the `internal:` scheme so links resolve against the
 * routing system (and stay valid if base paths change).
 */
$links = [
  [
    'key' => 'ch83-nav-groups',
    'title' => 'Groups',
    'uri' => 'internal:/all-groups',
    'weight' => 0,
  ],
  [
    'key' => 'ch83-nav-activity',
    'title' => 'Activity',
    'uri' => 'internal:/stream',
    'weight' => 1,
  ],
  [
    'key' => 'ch83-nav-my-groups',
    'title' => 'My Groups',
    'uri' => 'internal:/user',
    'weight' => 2,
  ],
  [
    'key' => 'ch83-nav-create-group',
    'title' => 'Create Group',
    'uri' => 'internal:/group/add/community_group',
    'weight' => 3,
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

foreach ($links as $link) {
  // Idempotency: skip if a link with this stable key already exists. The key
  // is stored in `description`, which is not otherwise used for these items.
  $existing = $storage->loadByProperties(['description' => $link['key']]);
  if ($existing) {
    echo "Exists: {$link['title']} ({$link['uri']})\n";
    continue;
  }

  MenuLinkContent::create([
    'title' => $link['title'],
    'link' => ['uri' => $link['uri']],
    'menu_name' => 'main',
    'weight' => $link['weight'],
    'expanded' => FALSE,
    'enabled' => TRUE,
    // Stable key for idempotent re-seeds; harmless if surfaced in the UI.
    'description' => $link['key'],
  ])->save();

  echo "Created: {$link['title']} -> {$link['uri']}\n";
}

echo "=== Step 780 complete ===\n";
