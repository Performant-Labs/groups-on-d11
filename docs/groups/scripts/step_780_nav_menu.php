<?php

/**
 * @file
 * Step 780 (CH-A1, issue #83): Community header navigation.
 *
 * Seeds the primary header navigation for the groups_chrome theme. The
 * `groups_chrome_main_menu` block (region: primary_menu) and the
 * `groups_chrome_account_menu` block (region: secondary_menu) are already
 * placed via config/sync; this step supplies the actual `main` menu links so
 * the primary nav renders the community entry points:
 *
 *   Groups        -> /all-groups                     (all_groups view)
 *   Activity      -> /stream                          (activity_stream view)
 *   My Feed       -> /my-feed                          (do_streams.my_feed
 *                                                       route, issue #110)
 *   My Groups     -> /user                             (current user's page;
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
 * Issue #110 (ST-1 My Feed) appends the "My Feed" link between Activity and
 * My Groups. handoff-A.md Finding #8 (docs/planning/handoffs/110-stream-110/
 * handoff-A.md): `menu_link_content.weight` is an INTEGER field in core — the
 * approved wireframe's own weight suggestion of `1.5` would be silently
 * coerced (a float is not a legal weight), so the two existing links after
 * Activity are surgically re-weighted (ONLY their `weight` value — no
 * title/uri/description change) to make room for My Feed at the now-free
 * integer weight 2: Groups(0) < Activity(1) < My Feed(2) < My Groups(3) <
 * Create Group(4). This keeps every link's relative order identical to the
 * wireframe's intent while staying within core's actual integer-only schema.
 *
 * The re-weight is applied on BOTH a fresh install (the link is created with
 * its final weight directly) AND an already-seeded environment (e.g. a
 * pre-#110 Coolify deploy that already ran this script once) — an existing
 * link whose stored weight differs from its now-desired weight has ONLY that
 * field updated below, so a real already-deployed site's nav re-orders
 * correctly the next time this idempotent script runs, not only a fresh
 * test install.
 *
 * No schema changes: uses only existing routes/paths and the core `main` menu.
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

echo "\n=== Step 780: Header navigation (main menu links) ===\n";

/**
 * Definition of the five primary-nav links, in display order.
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
    // Issue #110 (ST-1): the new authenticated-only "My Feed" link. Route
    // access (`_user_is_logged_in: 'TRUE'` on do_streams.my_feed) means
    // Drupal's own menu-link-tree access filter hides this link from
    // anonymous users automatically — no per-link visibility flag needed
    // here (handoff-A.md Finding #1).
    'key' => 'st1-nav-my-feed',
    'title' => 'My Feed',
    'uri' => 'internal:/my-feed',
    'weight' => 2,
  ],
  [
    'key' => 'ch83-nav-my-groups',
    'title' => 'My Groups',
    'uri' => 'internal:/user',
    // Surgically re-weighted from 2 -> 3 (handoff-A.md Finding #8) to make
    // room for My Feed at weight 2. Title/uri/description are unchanged.
    'weight' => 3,
  ],
  [
    'key' => 'ch83-nav-create-group',
    'title' => 'Create Group',
    'uri' => 'internal:/group/add/community_group',
    // Surgically re-weighted from 3 -> 4 (handoff-A.md Finding #8), same
    // rationale as My Groups above.
    'weight' => 4,
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

foreach ($links as $link) {
  // Idempotency: a link with this stable key may already exist (either from
  // an earlier run of THIS script in the same install, or — for the two
  // renumbered keys — from a pre-#110 seed that predates this story).
  $existing = $storage->loadByProperties(['description' => $link['key']]);
  if ($existing) {
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent $entity */
    $entity = reset($existing);
    if ((int) $entity->getWeight() !== $link['weight']) {
      // Surgical re-weight only: title/uri/description are untouched, per
      // handoff-A.md Finding #8's explicit "surgical" instruction.
      $entity->set('weight', $link['weight']);
      $entity->save();
      echo "Re-weighted: {$link['title']} ({$link['uri']}) -> weight {$link['weight']}\n";
    }
    else {
      echo "Exists: {$link['title']} ({$link['uri']})\n";
    }
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
