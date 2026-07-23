<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\do_chrome\HelpText;
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
 *
 * Phase 6.5 (diff-gate B-3 repair): `personaBanner()` used to `new
 * ShowcaseCatalog()` its own throwaway instance instead of using the shared
 * `do_showcase.showcase_catalog` service — bypassing the DI container and
 * risking divergence from every other consumer of that service (e.g.
 * `PersonaSwitcher`, which is constructor-injected with the SAME instance).
 * `ShowcaseCatalog` is now constructor-injected here too (as `$catalog`),
 * matching how `$personaSwitcher` is already injected, and
 * `do_showcase.services.yml`'s `do_showcase.hooks` entry now passes
 * `@do_showcase.showcase_catalog` as a second argument.
 */
class DoShowcaseHooks {

  public function __construct(
    private readonly PersonaSwitcher $personaSwitcher,
    private readonly ShowcaseCatalog $catalog,
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
   * Copy is the exact issue phrasing, instantiated per persona, reading the
   * persona's `label` field (`ShowcaseCatalog::personas()`) — the SAME
   * display-string source `\Drupal\do_showcase\Persona\
   * PersonaSwitcher::optionLabel()` reads for its `<select>` `<option>`
   * text. Elena and Maria's `label` already carries the role suffix
   * ("Elena Garcia — Member" / "Maria Chen — Organizer"); the Moderator
   * persona's `label` ("Groups-Moderate") already reads as a role, so no
   * separate role-suffix concatenation happens here.
   *
   * Phase 5-fix (#120 production defect repair): this method used to build
   * `$lead_text` from `$active_persona['name']` (the persona's plain name,
   * e.g. "Moderator") plus its OWN independent `match ($active_persona['id'])`
   * role-suffix table — a second, divergent copy of the exact logic
   * `PersonaSwitcher::optionLabel()` already encoded, which is how the
   * Groups-Moderate banner regressed to "You're browsing as Moderator —
   * switch back" instead of the wireframe/AC-locked "You're browsing as
   * Groups-Moderate — switch back" (caught by
   * `tests/e2e/persona-switcher.spec.ts`'s Groups-Moderate full-switch
   * test). Fixed by reading the persona's `label` field directly — there is
   * now exactly one source of truth for this visible copy
   * (`ShowcaseCatalog::personas()`), consumed identically by the switcher's
   * `<option>` text and this banner.
   *
   * Phase 6.5 (diff-gate B-3 repair): this method used to `new
   * ShowcaseCatalog()` its own instance rather than using the shared
   * `do_showcase.showcase_catalog` service already injected everywhere else
   * that reads persona data (`PersonaSwitcher`). Now reads
   * `$this->catalog->personas()` — the constructor-injected instance — so
   * there is exactly one `ShowcaseCatalog` instance in play across the
   * request, matching how `PersonaSwitcher` is already injected.
   *
   * The visible "switch back" text is carried by the real `<a>` link itself
   * (not baked into the preceding text span), so the banner's rendered text
   * concatenates to the exact issue phrasing with no duplicated phrase:
   * "You're browsing as Elena Garcia — Member — switch back" /
   * "You're browsing as Groups-Moderate — switch back" — matching
   * `PersonaBannerTest`'s `assertStringContainsString()` on the concatenated
   * banner text and `persona-switcher.spec.ts`'s `toContainText()`
   * assertions for every persona.
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
   *
   * #132 SD-5 (brief-amendments.md Amendment 1 / Amendment 2): appends a
   * fourth `$children` node, `'help'`, AFTER `'switch_back'` — the corrected
   * DOM order `glyph, text, switch_back, help` (the brief's own worked
   * example placed it before `glyph`, which A's review corrected: the ⓘ
   * trails the switch-back link so it does not visually crowd the leading
   * `▶`). The trigger reuses the verbatim `<span class="do-showcase-info"
   * tabindex="0" role="note" aria-label data-do-tooltip>ⓘ</span>` pattern
   * already used by `PersonaSwitcher::build()` and
   * `GroupTypeContentHelp::infoTrigger()`, reading its copy from
   * `HelpText::get('showcase_help.persona_banner')`. `do_chrome/tooltips` is
   * now attached explicitly in this hook's own `#attached['library']`
   * (Amendment 2) rather than relying on the transitive attach via
   * `do_showcase/persona-switcher`.
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

    $account_name = $current_user->getAccountName();

    $active_persona = NULL;
    foreach ($this->catalog->personas() as $persona) {
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

    // "You're browsing as {label} — " immediately precedes the real
    // <a>"switch back"</a> link, so the banner's rendered text concatenates
    // to the exact issue phrasing with no duplicated phrase. `label` is the
    // single source of truth shared with PersonaSwitcher::optionLabel() (see
    // this method's docblock) — it already carries the role suffix for
    // Elena/Maria and reads correctly as-is for Moderator/Anonymous, so no
    // separate role-suffix table is built here.
    $lead_text = (string) t("You're browsing as @label — ", [
      '@label' => $active_persona['label'],
    ]);

    $help_copy = HelpText::get('showcase_help.persona_banner');

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
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => 'ⓘ',
        '#attributes' => [
          'class' => ['do-showcase-info'],
          'tabindex' => '0',
          'role' => 'note',
          'aria-label' => $help_copy,
          'data-do-tooltip' => $help_copy,
        ],
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
        'library' => [
          'do_showcase/persona-switcher',
          'do_chrome/tooltips',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

}
