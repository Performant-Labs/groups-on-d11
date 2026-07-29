<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Keeps the curated primary navigation free of profile-default links.
 *
 * Drupal's `standard` install profile ships a plugin-derived "Home" link
 * (plugin id `standard.front_page`) in the `main` menu. It is NOT a
 * `menu_link_content` entity, so the idempotent seed in
 * `docs/groups/scripts/step_780_nav_menu.php` — which owns the five curated
 * community links — cannot manage it the way it manages the others. That seed
 * therefore disables the link imperatively, via
 * `MenuLinkManager::updateDefinition()`.
 *
 * That imperative disable is not durable: `updateDefinition()` writes an
 * override that a full menu-link rebuild can discard, after which "Home"
 * silently reappears as a sixth primary-nav item. It had in fact reappeared,
 * which is why this hook exists — the seed's intent ("no Home link") is
 * re-asserted here at discovery time, so it holds across every rebuild without
 * anyone having to re-run the seed.
 *
 * Rationale for not showing it at all: Olivero's site-branding block already
 * renders the logo AND the site name as links to the front page, immediately
 * to the left of the primary nav. A third "Home" link is pure duplication, and
 * it costs 84px of a header that (see the "Desktop nav fit" rules in
 * `groups_chrome/css/chrome.css`) does not have 84px to spare for a logged-in
 * visitor: with "Home" present the authenticated primary nav does not fit on
 * one line until ~1254px, which is inside the desktop-nav range and therefore
 * produces the collapse-to-hamburger flicker this removal is part of fixing.
 */
class PrimaryNavLinks {

  /**
   * The `standard` profile's front-page link plugin id.
   */
  private const FRONT_PAGE_LINK = 'standard.front_page';

  /**
   * Disables the profile-default "Home" link in the curated primary nav.
   *
   * Runs at link-discovery time, so it survives cache rebuilds and menu-link
   * rebuilds — unlike the seed's `updateDefinition()` call, which it replaces
   * as the durable source of truth.
   *
   * @param array $links
   *   Discovered menu link plugin definitions, keyed by plugin id.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    if (isset($links[self::FRONT_PAGE_LINK])) {
      $links[self::FRONT_PAGE_LINK]['enabled'] = FALSE;
    }
  }

}
