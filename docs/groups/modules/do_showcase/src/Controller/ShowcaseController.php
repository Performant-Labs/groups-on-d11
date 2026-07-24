<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\do_chrome\HelpText;
use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\do_showcase\VariantSwitcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Controller for the `/showcase` tour page (SC-F1, #119).
 *
 * Follows the `do_notifications`/`do_discovery` `ControllerBase` +
 * `.routing.yml` pattern: a `_controller` method returning a render array,
 * DI via `create(ContainerInterface)`, `_permission: 'access content'`
 * (public — the page is itself a POC artifact meant to be seen by anonymous
 * visitors, matching `do_discovery`'s public-page precedent).
 *
 * Lists every planned comparison (including not-yet-built ones marked
 * "coming") with its one-sentence decision framing, and the persona
 * switcher (#120) naming all four public personas. Also hosts the one
 * guaranteed wired stub `VariantSwitcher` instance (`directory.layout`, per
 * wireframe.md's own example) so brief.md Acceptance #1 ("at least one wired
 * demo instance") is satisfied.
 *
 * #132 SD-5: extends the per-entry loop with an optional `[data-do-tooltip]`
 * ⓘ help trigger (`$item['help']`), rendered only when
 * `HelpText::get('showcase_help.<id>')` is non-empty (survey.md failure-mode
 * note: never render an empty `data-do-tooltip=""`, which would produce an
 * empty tippy popover on hover) — placed alongside the entry's
 * title/badge/decision metadata, before the `link` conditional. Also adds a
 * single `$build['switcher_map_help']` ⓘ trigger adjacent to the stub
 * switcher (the brief's approved alternative to touching
 * `VariantSwitcher::build()` for a per-option ⓘ, which is out-of-scope
 * framework surgery), guarded identically on non-empty
 * `showcase_help.map` copy.
 *
 * #124 SC-5 (A-advisory #7): the stub switcher's three-option list
 * (compact/cards/map) now reads from
 * `VariantSwitcher::directoryLayoutOptions()` — the SAME shared,
 * already-translated source `DoShowcaseHooks::viewsPreRender()` uses for
 * the `/all-groups` instance — rather than a hand-written literal, so #125
 * (SC-6) flips `map`'s `available` flag in exactly ONE place instead of two
 * call sites silently drifting apart.
 *
 * #123 SC-4 (Discovery three ways, handoff-A-plan.md): adds a SECOND,
 * independent `discovery.ranking` `VariantSwitcher` instance (Recent / Hot /
 * Promoted), deep-linkable via `?discovery=<id>` — deliberately a DISTINCT
 * query key from the stub switcher's `?variant=` (wireframe.md "Two
 * switchers, two distinct query keys": both can be open simultaneously on
 * one page load, so a shared key would let either instance's link silently
 * override the other's selection). Each option routes to the CORRESPONDING
 * EXISTING view (`activity_stream` / `hot_content` / `promoted_content`) via
 * `views_embed_view()` — handoff-A-plan.md Risk 3 resolution: embedding the
 * real views directly is the only shape that honestly satisfies "do NOT fork
 * ranking" (no `views.view.discovery_compare.yml` is created). The embed is
 * guarded (`embedDiscoveryView()`) the same way the `directory-presentation`
 * catalog entry's deep-link is guarded by `routeExists()` above: a missing
 * view/display degrades to an empty region rather than a 500, so an isolated
 * BrowserTestBase install that does not enable `views` (or has not imported
 * one of the three source views) never crashes rendering this page.
 * `embedDiscoveryView()` reads module availability via the inherited
 * `ControllerBase::moduleHandler()` lazy accessor rather than a
 * constructor-injected `ModuleHandlerInterface` — `ControllerBase` already
 * declares its OWN non-readonly `$moduleHandler` property backing that
 * method, so a constructor-promoted `private readonly` property of the same
 * name fatals ("Cannot redeclare non-readonly property ... as readonly").
 */
class ShowcaseController extends ControllerBase {

  /**
   * The discovery.ranking switcher's three options, id mapped to view id.
   *
   * #123 SC-4: in display order — Recent is FIRST so
   * `VariantSwitcher::resolveSelection()`'s existing "unknown/unavailable
   * $current falls back to the first available option" rule makes an
   * unrecognized `?discovery=` value land on Recent (brief.md acceptance:
   * never blank/broken) with no special-cased fallback logic needed here.
   * Maps each option id directly to the EXISTING view/display it embeds —
   * the single source of truth this controller's `page()` method reads for
   * both the switcher's option list AND which view `embedDiscoveryView()`
   * calls, so the two can never drift apart.
   */
  private const DISCOVERY_OPTIONS = [
    ['id' => 'recent', 'view' => 'activity_stream'],
    ['id' => 'hot', 'view' => 'hot_content'],
    ['id' => 'promoted', 'view' => 'promoted_content'],
  ];

  public function __construct(
    private readonly ShowcaseCatalog $catalog,
    private readonly VariantSwitcher $switcher,
    private readonly Request $request,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      new ShowcaseCatalog(),
      $container->get('do_showcase.variant_switcher'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('router.route_provider'),
    );
  }

  /**
   * Whether a route name is currently registered.
   *
   * #124 SC-5: the `directory-presentation` catalog entry references
   * `view.all_groups.page_1` — a Views-auto-generated route whose existence
   * depends on the `views` module being installed AND the `all_groups` view
   * config being present. On a full site install both hold; on an isolated
   * BrowserTestBase install with only `['do_showcase', 'node']` (see
   * `ShowcaseControllerHelpTest`) neither does, and letting
   * `Url::fromRoute()` render for a missing route throws
   * `RouteNotFoundException` → 500. This guard degrades gracefully by
   * omitting only the deep-link on installs where its target is absent,
   * leaving the entry's title/badge/decision/help ⓘ metadata rendered.
   *
   * @param string $route_name
   *   The route name (e.g. 'view.all_groups.page_1').
   *
   * @return bool
   *   TRUE if the route is registered, FALSE otherwise.
   */
  private function routeExists(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * Embeds one of the three existing discovery views, guarded gracefully.
   *
   * #123 SC-4 (handoff-A-plan.md Risk 3): calls `views_embed_view($view_id,
   * 'default')` — the SAME real config every other consumer of that view
   * uses (e.g. `hot_content`'s own `/hot` page), never a forked/duplicated
   * ranking pipeline. `views_embed_view()` itself already returns NULL (no
   * view render, no exception) when the named view does not exist or its
   * `default` display denies access — this method normalizes that NULL into
   * an empty render array (`[]`), matching this controller's existing
   * `routeExists()` guard's philosophy: a minimal/isolated test install that
   * has not imported one of the three source views (or does not enable
   * `views`) gets an empty embed region, never a 500, so
   * `ShowcaseControllerHelpTest` (which installs `['do_showcase', 'node']`
   * only, no `views`) keeps passing unaffected by this story.
   *
   * @param string $view_id
   *   The existing view id to embed ('activity_stream', 'hot_content', or
   *   'promoted_content').
   *
   * @return array
   *   The view's render array (via views_embed_view()), or an empty array if
   *   the view/display is unavailable.
   */
  private function embedDiscoveryView(string $view_id): array {
    if (!$this->moduleHandler()->moduleExists('views')) {
      return [];
    }
    // views_embed_view() is a core procedural function (deprecated in
    // drupal:11.4.0 in favor of the '#type' => 'view' render element per
    // https://www.drupal.org/node/3572594, but not yet removed) — used here
    // per handoff-A-plan.md's explicit directive to embed the three EXISTING
    // views directly rather than introduce a new render path this story does
    // not need.
    $embed = views_embed_view($view_id, 'default');
    return $embed ?? [];
  }

  /**
   * Renders the `/showcase` tour page.
   *
   * @return array
   *   A render array: page title/intro, the stub switcher instance, one
   *   block per catalog entry (title, decision sentence, `[ live ]`/
   *   `[ coming ]` status badge, an optional ⓘ help trigger, and a deep-link
   *   only where `live`), the "Discovery ranking" comparison section (#123
   *   SC-4: a second switcher instance + the corresponding embedded view),
   *   and a map-orientation ⓘ adjacent to the stub switcher.
   */
  public function page(): array {
    $build = [];

    // The switcher instance embedded below resolves its selected option
    // from the `variant` query argument (see below), so the page's cached
    // render output must vary by that argument too — otherwise Drupal's
    // Dynamic Page Cache serves whichever variant was first cached for
    // `/showcase` back to every subsequent request regardless of its own
    // `?variant=` value (#119 fix-loop round 3 blocker).
    $build['#cache']['contexts'][] = 'url.query_args:variant';
    // #123 SC-4: the discovery.ranking switcher below independently resolves
    // its own selection from the `discovery` query argument — this page
    // render now varies by TWO independent query args, so BOTH contexts
    // must be present (wireframe.md "Cache context callout": omitting
    // either means Dynamic Page Cache serves a stale variant/discovery
    // combination to a later request).
    $build['#cache']['contexts'][] = 'url.query_args:discovery';

    $build['#attached']['library'][] = 'do_chrome/tooltips';
    $build['#attached']['library'][] = 'do_showcase/switcher';

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This page lists every side-by-side comparison in this demo, the decision each one represents, and whether it is live yet.'),
    ];

    // The one guaranteed wired stub switcher instance (wireframe.md's own
    // example: Compact list / Cards / Map). Options are
    // shared with DoShowcaseHooks::viewsPreRender() (#124 SC-5,
    // A-advisory #7) via VariantSwitcher::directoryLayoutOptions(), which
    // already returns them translated.
    $variant = (string) ($this->request->query->get('variant') ?? 'cards');
    $build['switcher'] = $this->switcher->build(
      'directory.layout',
      $this->switcher->directoryLayoutOptions(),
      $variant,
    );

    // #132 SD-5: map-view orientation ⓘ, adjacent to the switcher. Guarded
    // on non-empty copy (same guard the per-entry help below uses) so an
    // empty showcase_help.map key would never render an empty tooltip.
    $map_copy = HelpText::get('showcase_help.map');
    if ($map_copy !== '') {
      $build['switcher_map_help'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => 'ⓘ Map view',
        '#attributes' => [
          'class' => ['do-showcase-info', 'do-showcase-map-help'],
          'tabindex' => '0',
          'role' => 'note',
          'aria-label' => $map_copy,
          'data-do-tooltip' => $map_copy,
        ],
      ];
    }

    $items = [];
    foreach ($this->catalog->entries() as $entry) {
      $badge = $entry['status'] === 'live' ? $this->t('[ live ]') : $this->t('[ coming ]');

      $item = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['do-showcase-catalog-entry'],
          'data-do-showcase-entry' => $entry['id'],
        ],
      ];
      $item['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $entry['title'],
      ];
      $item['badge'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $badge,
        '#attributes' => ['class' => ['do-showcase-status-badge']],
      ];
      $item['decision'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $entry['decision_sentence'],
      ];

      // #132 SD-5: per-entry ⓘ help trigger, rendered only when
      // showcase_help.<id> resolves to non-empty copy (survey.md
      // failure-mode note) — placed with the entry's metadata, before the
      // `link` conditional below.
      $help_copy = HelpText::get('showcase_help.' . $entry['id']);
      if ($help_copy !== '') {
        $item['help'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => 'ⓘ',
          '#attributes' => [
            'class' => ['do-showcase-info', 'do-showcase-entry-help'],
            'tabindex' => '0',
            'role' => 'note',
            'aria-label' => $help_copy,
            'data-do-tooltip' => $help_copy,
          ],
        ];
      }

      // Only live entries get a deep-link — coming entries have no dead
      // link (truthful-copy rule, wireframe.md). Additionally guarded on
      // route EXISTENCE at render time: `view.all_groups.page_1` (the
      // #124 SC-5 route target for `directory-presentation`) is a
      // Views-auto-generated route that only exists once `views` is
      // installed AND the `all_groups` view config is imported. A minimal
      // test install (BrowserTestBase with `['do_showcase', 'node']` and
      // no config import) has neither — rendering `Url::fromRoute()` on
      // that missing route would throw RouteNotFoundException → 500 (which
      // ShowcaseControllerHelpTest caught on this branch after the entry
      // flipped from `status: coming, route: NULL` to `status: live,
      // route: 'view.all_groups.page_1'`). Guarding here keeps the entry's
      // metadata + help ⓘ rendering intact while just omitting the deep-
      // link on installs where its target is not registered.
      if ($entry['status'] === 'live' && $entry['route'] !== NULL && $this->routeExists($entry['route'])) {
        $item['link'] = [
          '#type' => 'link',
          '#title' => $this->t('View this comparison'),
          '#url' => Url::fromRoute($entry['route']),
        ];
      }

      // The persona-switcher entry also lists all four public personas.
      if ($entry['id'] === 'persona-switcher') {
        $persona_items = [];
        foreach ($this->catalog->personas() as $persona) {
          $persona_items[] = $this->t('@name — @description', [
            '@name' => $persona['name'],
            '@description' => $persona['description'],
          ]);
        }
        $item['personas'] = [
          '#theme' => 'item_list',
          '#items' => $persona_items,
        ];
      }

      $items[$entry['id']] = $item;
    }

    $build['entries'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['do-showcase-catalog']],
      '#empty' => $this->t('Nothing to show yet — check back soon.'),
    ] + $items;

    // #123 SC-4: the "Discovery ranking" comparison section — a SECOND,
    // independent VariantSwitcher instance (`discovery.ranking`) plus the
    // embedded view its selection resolves to. See class docblock for the
    // full Risk 3 rationale (embed the three EXISTING views, do not fork
    // ranking).
    $requested_discovery = (string) ($this->request->query->get('discovery') ?? 'recent');
    $discovery_options = [
      ['id' => 'recent', 'label' => (string) $this->t('Recent')],
      ['id' => 'hot', 'label' => (string) $this->t('Hot')],
      ['id' => 'promoted', 'label' => (string) $this->t('Promoted')],
    ];
    $discovery_switcher = $this->switcher->build(
      'discovery.ranking',
      $discovery_options,
      $requested_discovery,
      'discovery',
    );

    // The switcher's own selection-resolution rule (first available option
    // when $current is unknown/unavailable) decides which id to actually
    // embed too — reading it back via resolveCurrent() keeps this single
    // source of truth rather than re-deriving a second fallback rule here
    // (the same pattern DoShowcaseHooks::viewsPreRender() already
    // establishes for the directory.layout instance).
    $resolved_discovery = $this->switcher->resolveCurrent($discovery_options, $requested_discovery);
    $discovery_view_id = 'activity_stream';
    foreach (self::DISCOVERY_OPTIONS as $option) {
      if ($option['id'] === $resolved_discovery) {
        $discovery_view_id = $option['view'];
        break;
      }
    }

    $build['discovery_ranking'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['do-showcase-discovery-ranking'],
        'data-do-discovery-ranking' => 'true',
      ],
      // CSS-only library (docs/groups/modules/do_showcase/css/
      // discovery-compare.css), scoped to THIS render subtree only — never
      // site-wide — mirroring the #124 SC-5 directory-compact library's own
      // attach-at-the-render-point precedent.
      '#attached' => [
        'library' => ['do_showcase/discovery-compare'],
      ],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Discovery ranking: Recent / Hot / Promoted'),
      ],
      'decision' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Same groups, three orderings. Recent is chronological, Hot is engagement-ranked (comments), Promoted is editorially curated. Switch tabs to feel the difference.'),
      ],
      'switcher' => $discovery_switcher,
      'embed' => $this->embedDiscoveryView($discovery_view_id),
    ];

    return $build;
  }

}
