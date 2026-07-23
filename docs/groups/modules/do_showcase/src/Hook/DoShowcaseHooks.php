<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\do_showcase\Persona\PersonaSwitcher;
use Drupal\do_showcase\ShowcaseCatalog;

/**
 * Hook implementations for do_showcase (SC-F1, #119; #120 SC-1).
 *
 * Ships the site-wide "POC demo" ribbon via `hook_page_top` — the same
 * "single global attach point" shape as `DoChromeHooks::pageAttachments()`
 * (site-wide chrome injected once, not re-attached per page). `page_top` is
 * used rather than `page_attachments` because the ribbon is VISIBLE markup
 * (a real `<button>` + `<a>`), not just a library/settings attachment.
 *
 * The ribbon shows identically for anonymous and authenticated users (no
 * session-dependent branching) and does not reshuffle nav DOM — it is a
 * fixed-position element rendered before the page region, never inserted
 * into the nav block itself (keeps `tests/e2e/nav.spec.ts` green).
 *
 * Dismissal is CLIENT-SIDE (sessionStorage (per-session), `do_showcase/ribbon`
 * library) — Brief-gate B-2: a server session write would bust the
 * anonymous page cache. sessionStorage (not localStorage) matches the
 * brief/issue's "dismissible per session" contract exactly. The ribbon
 * markup always renders server-side; the ribbon JS hides it on load if the
 * client-side dismiss flag is set, and removes it on a real button click.
 *
 * #120 adds TWO more independent `#[Hook('page_top')]` methods, each its own
 * disjoint `$page_top` key:
 *  - `personaSwitcherWidget()` — the "Browse as" dropdown itself. Rendered
 *    via `page_top` (same guaranteed-everywhere attach point as the ribbon)
 *    rather than relying SOLELY on `PersonaSwitcherBlock` placement config,
 *    because a placed-block region requires a `block.block.*` config entity
 *    that a fresh/isolated site install never has (this repo ships no
 *    module that self-installs block placement via `config/install/`) — the
 *    hook path is what guarantees the widget is visible on every page
 *    without depending on Block Layout placement
 *    (`PersonaSwitcherDropdownTest`, a `BrowserTestBase` test with
 *    `$modules = ['do_showcase']` and no config import, pins exactly this:
 *    the widget must render on `<front>` from module-enable alone).
 *    `PersonaSwitcherBlock` still exists as an OPTIONAL, explicitly-
 *    placeable alternative for a theme/site that wants the widget in a
 *    specific Block Layout region instead — both consumers share the ONE
 *    `do_showcase.persona_switcher` service, so there is exactly one
 *    render-array producer.
 *  - `personaBanner()` (brief-amendments.md Amendment 5) — SESSION-DEPENDENT
 *    (only renders, and with different copy, when the current session is an
 *    active persona), kept separate from the stateless ribbon so their cache
 *    metadata stays isolated.
 *
 * All three hooks assign disjoint keys into the same `$page_top` array
 * (`do_showcase_ribbon` / `do_showcase_persona_switcher` /
 * `do_showcase_persona_banner`), which `page_top` natively supports.
 */
class DoShowcaseHooks {

  public function __construct(
    private readonly PersonaSwitcher $personaSwitcher,
  ) {}

  /**
   * Registers the variant-switcher Twig template.
   *
   * @see \Drupal\do_showcase\VariantSwitcher::build()
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return [
      'do_showcase_variant_switcher' => [
        'variables' => [
          'instance_id' => '',
          'wrapper_attributes' => [],
          'options' => [],
          'tooltip' => '',
          'tooltip_attributes' => [],
        ],
        'template' => 'do-showcase-variant-switcher',
      ],
    ];
  }

  /**
   * Injects the site-wide POC demo ribbon.
   *
   * Identical copy/markup for anonymous and authenticated visitors. Links to
   * `/showcase`; the dismiss control is a real
   * `<button aria-label="Dismiss demo banner">`, keyboard-operable,
   * independently reachable from the `/showcase` link.
   *
   * The ribbon does NOT carry a ⓘ tooltip trigger (wireframe.md Surface 3
   * depicts only the POC text + "See what it compares →" link + dismiss ✕ —
   * no ⓘ). The issue's "carries a do_chrome tooltip" requirement is on the
   * SWITCHER (Surface 1), which already attaches `do_chrome/tooltips` via
   * `VariantSwitcher::build()`; the ribbon needs neither the library attach
   * nor a HelpText lookup.
   */
  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    $page_top['do_showcase_ribbon'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'do-showcase-ribbon',
        'class' => ['do-showcase-ribbon'],
        'data-do-showcase-ribbon' => 'true',
      ],
      '#attached' => [
        'library' => [
          'do_showcase/ribbon',
        ],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => (string) t('This is a proof-of-concept demo.'),
      ],
      'link' => [
        '#type' => 'link',
        '#title' => t('See what it compares →'),
        '#url' => Url::fromRoute('do_showcase.showcase'),
      ],
      'dismiss' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => '✕',
        '#attributes' => [
          'type' => 'button',
          'aria-label' => (string) t('Dismiss demo banner'),
          'class' => ['do-showcase-ribbon-dismiss'],
          'data-do-showcase-dismiss' => 'true',
        ],
      ],
    ];
  }

  /**
   * Injects the "Browse as" persona-switcher widget (#120 SC-1).
   *
   * Delegates entirely to `PersonaSwitcher::build()` (the
   * `do_showcase.persona_switcher` service) — this hook's only job is
   * attaching that render array at the guaranteed-everywhere `page_top`
   * point (see class docblock: this is what makes the widget visible
   * without depending on Block Layout placement config).
   *
   * Visible to ALL visitors, anonymous and authenticated-as-persona alike
   * (wireframe.md §1: "the dropdown always shows the switch control").
   */
  #[Hook('page_top')]
  public function personaSwitcherWidget(array &$page_top): void {
    $page_top['do_showcase_persona_switcher'] = $this->personaSwitcher->build();
  }

  /**
   * Injects the persistent "You're browsing as X — switch back" banner.
   *
   * #120 SC-1 (brief-amendments.md Amendment 5): a SIBLING `page_top` hook,
   * independent of `pageTop()` above — never folds into the ribbon.
   *
   * Renders `$page_top['do_showcase_persona_banner']` ONLY when the current
   * session is authenticated AND its account name matches one of the 4
   * persona `uname` values (wireframe.md §2: "no banner markup renders at
   * all — not hidden via CSS — truly absent from the DOM" when Anonymous).
   *
   * Copy is the exact issue phrasing, instantiated per persona — Elena and
   * Maria get a role suffix ("— Member" / "— Organizer"); the Moderator
   * persona's display name already reads as a role ("Groups-Moderate"), so
   * no separate role suffix is appended for it (matches
   * `tests/e2e/persona-switcher.spec.ts`'s pinned copy: "You're browsing as
   * Groups-Moderate — switch back", no additional suffix). The visible
   * "switch back" text is carried by the real `<a>` link itself (not baked
   * into the preceding text span), so the banner's full text-content reads
   * as one continuous phrase with no duplicated "switch back" — matching
   * `PersonaBannerTest`'s `assertStringContainsString()` on the concatenated
   * banner text.
   *
   * The wrapper is a real `<aside role="status">` (wireframe.md §2 /
   * `PersonaBannerTest`'s `aside[role="status"].do-showcase-persona-banner`
   * selector) — `#type => 'container'` always themes to a hardcoded `<div>`
   * (`container.html.twig`), so the children are pre-rendered to a string
   * via `renderInIsolation()` and embedded inside a hand-assembled
   * `<aside>...</aside>` string wrapped in {@see Markup::create()} — every
   * piece (the pre-rendered children, the `Attribute`-escaped attribute
   * string) is already safe, matching the same "manual tag + Markup::create"
   * technique `RestoreGroupForm::preRenderAsButtonTag()` and
   * `PersonaSwitcher::build()` both use for their own non-standard tag
   * names.
   *
   * `#cache['contexts'] => ['user']` (Amendment 6) — this render fragment
   * must never be served from a cached page to a session it doesn't belong
   * to.
   */
  #[Hook('page_top')]
  public function personaBanner(array &$page_top): void {
    $current_user = \Drupal::currentUser();

    if ($current_user->isAnonymous()) {
      // Truthful-empty-state rule: nothing to announce, so nothing renders
      // (wireframe.md §2: "not hidden via CSS — truly absent from the
      // DOM"). Still declare the cache context on a lightweight
      // placeholder so an anonymous page's cached render correctly busts
      // the moment the SAME url is later requested by an authenticated
      // persona session.
      $page_top['do_showcase_persona_banner'] = [
        '#cache' => ['contexts' => ['user']],
      ];
      return;
    }

    $catalog = new ShowcaseCatalog();
    $account_name = $current_user->getAccountName();

    $active_persona = NULL;
    foreach ($catalog->personas() as $persona) {
      if ($persona['uname'] !== NULL && $persona['uname'] === $account_name) {
        $active_persona = $persona;
        break;
      }
    }

    if ($active_persona === NULL) {
      // Authenticated, but not one of the 3 persona accounts (e.g. uid 1,
      // or any other real site account) — no persona banner for a session
      // this story does not consider "browsing as" anyone.
      $page_top['do_showcase_persona_banner'] = [
        '#cache' => ['contexts' => ['user']],
      ];
      return;
    }

    $role_suffix = match ($active_persona['id']) {
      'elena-garcia' => ' — ' . (string) t('Member'),
      'maria-chen' => ' — ' . (string) t('Organizer'),
      default => '',
    };

    // "You're browsing as {name}{role_suffix} — " immediately precedes the
    // real <a>"switch back"</a> link, so the banner's rendered text
    // concatenates to the exact issue phrasing with no duplicated phrase:
    // "You're browsing as Elena Garcia — Member — switch back".
    $lead_text = (string) t("You're browsing as @name@role_suffix — ", [
      '@name' => $active_persona['name'],
      '@role_suffix' => $role_suffix,
    ]);

    $children = [
      'glyph' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '▶',
        '#attributes' => ['aria-hidden' => 'true'],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $lead_text,
      ],
      'switch_back' => [
        '#type' => 'link',
        '#title' => t('switch back'),
        '#url' => Url::fromRoute('do_showcase.persona_switch', ['persona' => 'anonymous']),
        '#attributes' => ['class' => ['do-showcase-persona-banner-switch-back']],
      ],
    ];
    $children_html = (string) \Drupal::service('renderer')->renderInIsolation($children);

    $aside_attributes = (string) new Attribute([
      'role' => 'status',
      'aria-label' => (string) t('Active persona'),
      'class' => ['do-showcase-persona-banner'],
    ]);

    $page_top['do_showcase_persona_banner'] = [
      '#markup' => Markup::create('<aside' . $aside_attributes . '>' . $children_html . '</aside>'),
      '#attached' => [
        'library' => ['do_showcase/persona-switcher'],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

}
