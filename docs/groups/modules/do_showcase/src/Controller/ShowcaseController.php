<?php

declare(strict_types=1);

namespace Drupal\do_showcase\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\do_showcase\ShowcaseCatalog;
use Drupal\do_showcase\VariantSwitcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

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
 */
class ShowcaseController extends ControllerBase {

  public function __construct(
    private readonly ShowcaseCatalog $catalog,
    private readonly VariantSwitcher $switcher,
    private readonly Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      new ShowcaseCatalog(),
      $container->get('do_showcase.variant_switcher'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * Renders the `/showcase` tour page.
   *
   * @return array
   *   A render array: page title/intro, the stub switcher instance, and one
   *   block per catalog entry (title, decision sentence, `[ live ]`/
   *   `[ coming ]` status badge, and a deep-link only where `live`).
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

    $build['#attached']['library'][] = 'do_chrome/tooltips';
    $build['#attached']['library'][] = 'do_showcase/switcher';

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('This page lists every side-by-side comparison in this demo, the decision each one represents, and whether it is live yet.'),
    ];

    // The one guaranteed wired stub switcher instance (wireframe.md's own
    // example: Compact list / Cards / Map, Map unavailable).
    $variant = (string) ($this->request->query->get('variant') ?? 'cards');
    $build['switcher'] = $this->switcher->build('directory.layout', [
      ['id' => 'compact', 'label' => (string) $this->t('Compact list')],
      ['id' => 'cards', 'label' => (string) $this->t('Cards')],
      ['id' => 'map', 'label' => (string) $this->t('Map'), 'available' => FALSE],
    ], $variant);

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

      // Only live entries get a deep-link — coming entries have no dead
      // link (truthful-copy rule, wireframe.md).
      if ($entry['status'] === 'live' && $entry['route'] !== NULL) {
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

    return $build;
  }

}
