<?php

declare(strict_types=1);

namespace Drupal\do_streams\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\do_streams\Hook\DoStreamsHooks;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the `/my-feed/events` route (issue #112, ST-3).
 *
 * Mirrors {@see \Drupal\do_streams\Controller\MyFeedController} one-for-one
 * (survey.md's Reuse map row 1: "EXTEND: mirror MyFeedController shape into
 * MyEventsController (own file, tiny; the two controllers share NO base
 * class today and adding one across a PR boundary is premature)"), but
 * composes TWO Views displays (Upcoming + My RSVPs) into ONE shell
 * invocation rather than one.
 *
 * handoff-A.md Finding #1 (binding resolution): the shared
 * `do_streams_shell` theme hook has a SINGLE `results`/`empty` slot, so this
 * controller pre-composes both sections into ONE `#results` render array
 * (each with its own `<h2>`, its own per-section empty state, and distinct
 * testids — `upcoming-events-results`/`-empty`, `my-rsvps-results`/`-empty`)
 * and always passes `empty: FALSE` on the outer shell invocation, so the
 * shell's own scope-copy empty branch never fires on this route. The shell
 * theme hook itself is NOT extended to accept a second results/empty slot —
 * that is a cross-story shell-contract change out of this issue's scope, per
 * A's own explicit guidance.
 *
 * handoff-A.md Finding #3: `?scope=global` is a REQUEST-TIME filter override
 * on the Upcoming display only (`ViewExecutable::getDisplay()->overrideOption
 * ('filters', ...)`, core Views API — {@see
 * \Drupal\views\Plugin\views\display\DisplayPluginBase::overrideOption()}),
 * never a new scope filter plugin (brief.md's own Non-goal) and never an
 * edit to the SHIPPED `views.view.my_events.yml` (that file keeps
 * `do_streams_membership_scope` in place; the override only ever affects the
 * in-memory, per-request ViewExecutable).
 *
 * Deviation from the brief's literal wording (recorded in handoff-F, not a
 * design change): `views_embed_view()` is DEPRECATED as of Drupal 11.4 (the
 * version actually installed here), so this controller uses the SAME
 * `Views::getView() -> setDisplay() -> execute() -> #type => view` shape
 * MyFeedController's own docblock explains and justifies — see that class
 * for the full rationale (single-execution, non-deprecated equivalent of
 * `views_embed_view()`, same access-check defense-in-depth).
 */
class MyEventsController extends ControllerBase {

  /**
   * The Views display id for the Upcoming events section.
   */
  protected const UPCOMING_DISPLAY_ID = 'default';

  /**
   * The Views display id for the My RSVPs section.
   */
  protected const MY_RSVPS_DISPLAY_ID = 'my_rsvps';

  /**
   * The shipped `my_events` view's machine name.
   */
  protected const VIEW_ID = 'my_events';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Builds the `/my-feed/events` page: shell + Upcoming + My RSVPs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (read for `?scope=global`, per handoff-A.md
   *   Finding #3 — the OBSERVABLE outcome is what T's Functional/E2E tests
   *   pin, never the override mechanism itself).
   *
   * @return array
   *   A `#theme => do_streams_shell` render array.
   */
  public function build(Request $request): array {
    $is_global_scope = $request->query->get('scope') === 'global';

    $upcoming_section = $this->buildUpcomingSection($is_global_scope);
    $my_rsvps_section = $this->buildMyRsvpsSection();

    $results = [
      'upcoming' => $upcoming_section,
      'my_rsvps' => $my_rsvps_section,
    ];

    return $this->buildShell($results, $is_global_scope);
  }

  /**
   * Builds the Upcoming events section (view `default` display).
   *
   * @param bool $is_global_scope
   *   Whether `?scope=global` widens the display beyond the viewer's own
   *   memberships (handoff-A.md Finding #3).
   *
   * @return array
   *   A render array: a `<h2>` heading + either the executed view's results
   *   or this section's own empty-state markup, all wrapped with the
   *   `upcoming-events-results`/`upcoming-events-empty` testid.
   */
  protected function buildUpcomingSection(bool $is_global_scope): array {
    $view = Views::getView(self::VIEW_ID);

    if ($view === NULL) {
      // The view config genuinely does not exist (e.g. config not
      // imported) — degrade to the empty variant rather than fatally
      // erroring, matching MyFeedController's own graceful-degradation
      // contract.
      return $this->buildSection(
        'upcoming-events',
        $this->t('Upcoming events'),
        NULL,
        [],
      );
    }

    $view->setDisplay(self::UPCOMING_DISPLAY_ID);

    if ($is_global_scope) {
      // handoff-A.md Finding #3: drop the do_streams_membership_scope
      // filter for this request only — the SHIPPED view config (and thus
      // every OTHER request) keeps it. Never a new filter plugin.
      $display = $view->getDisplay();
      $filters = $display->options['filters'] ?? [];
      unset($filters['do_streams_membership_scope']);
      $display->overrideOption('filters', $filters);
    }

    $view->execute();
    $is_empty = empty($view->result);

    $results_build = $is_empty ? [] : [
      '#type' => 'view',
      '#name' => self::VIEW_ID,
      '#display_id' => self::UPCOMING_DISPLAY_ID,
      '#embed' => TRUE,
      '#view' => $view,
    ];

    $count = $is_empty ? 0 : count($view->result);

    return $this->buildSection(
      'upcoming-events',
      $this->t('Upcoming events'),
      $is_empty ? NULL : $results_build,
      [
        'title' => $this->t('No upcoming events yet'),
        'text' => $this->t("You haven't joined any groups yet. Join a group to see its upcoming events here, or switch to Global to browse every event on the site."),
        'cta_label' => $this->t('→ Browse all groups'),
        'cta_url' => '/all-groups',
      ],
      $this->formatItemCount($count, $is_global_scope),
    );
  }

  /**
   * Builds the My RSVPs section (view `my_rsvps` display).
   *
   * @return array
   *   A render array: a `<h2>` heading + either the executed view's results
   *   or this section's own empty-state markup, all wrapped with the
   *   `my-rsvps-results`/`my-rsvps-empty` testid.
   */
  protected function buildMyRsvpsSection(): array {
    $view = Views::getView(self::VIEW_ID);

    if ($view === NULL) {
      return $this->buildSection(
        'my-rsvps',
        $this->t('My RSVPs'),
        NULL,
        [],
      );
    }

    $view->setDisplay(self::MY_RSVPS_DISPLAY_ID);
    $view->execute();
    $is_empty = empty($view->result);

    $results_build = $is_empty ? [] : [
      '#type' => 'view',
      '#name' => self::VIEW_ID,
      '#display_id' => self::MY_RSVPS_DISPLAY_ID,
      '#embed' => TRUE,
      '#view' => $view,
    ];

    $count = $is_empty ? 0 : count($view->result);

    return $this->buildSection(
      'my-rsvps',
      $this->t('My RSVPs'),
      $is_empty ? NULL : $results_build,
      [
        // handoff-D.md (binding): My RSVPs' empty copy points at the OTHER
        // section on the SAME page rather than repeating the join-a-group
        // CTA — RSVPing needs an event to exist, not a group to join per
        // se — and deliberately carries NO cta_url (a second button
        // pointing at the same place would be redundant chrome).
        'title' => $this->t("You haven't RSVP'd to anything yet"),
        'text' => $this->t('When you RSVP to an event above (or under Global), it will show up here.'),
        'cta_label' => NULL,
        'cta_url' => NULL,
      ],
      $this->formatRsvpCount($count),
    );
  }

  /**
   * Assembles one section's `<h2>` + results-or-empty render array.
   *
   * @param string $testid_prefix
   *   The section's testid prefix (`upcoming-events` or `my-rsvps`) — the
   *   results/empty wrapper carries `<prefix>-results` / `<prefix>-empty`.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $heading
   *   The section's `<h2>` text.
   * @param array|null $results_build
   *   The executed view's `#type => view` render array, or NULL when this
   *   section has zero results (renders the empty variant instead).
   * @param array $empty_copy
   *   Empty-state copy: `title`, `text`, and optionally `cta_label` +
   *   `cta_url` (both NULL when no CTA is warranted, per handoff-D.md).
   *   Ignored when $results_build is non-NULL.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup $count_label
   *   The section-head count annotation (e.g. "3 events · from your
   *   groups").
   *
   * @return array
   *   A render array: `container` wrapping a `section-head` + either the
   *   results or empty markup.
   */
  protected function buildSection(
    string $testid_prefix,
    $heading,
    ?array $results_build,
    array $empty_copy,
    $count_label = '',
  ): array {
    $section = [
      '#type' => 'container',
      'head' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['section-head']],
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $heading,
        ],
        'count' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => $count_label,
          '#attributes' => ['class' => ['section-head__count']],
        ],
      ],
    ];

    if ($results_build !== NULL) {
      $section['body'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['shell-results'],
          'data-testid' => $testid_prefix . '-results',
        ],
        'view' => $results_build,
      ];
      return $section;
    }

    $empty_body = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['gc-empty'],
        'data-testid' => $testid_prefix . '-empty',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $empty_copy['title'] ?? '',
        '#attributes' => ['class' => ['gc-empty__title']],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $empty_copy['text'] ?? '',
        '#attributes' => ['class' => ['gc-empty__text']],
      ],
    ];

    if (!empty($empty_copy['cta_url']) && !empty($empty_copy['cta_label'])) {
      $empty_body['cta'] = [
        '#type' => 'link',
        '#title' => $empty_copy['cta_label'],
        '#url' => Url::fromUserInput($empty_copy['cta_url']),
        '#attributes' => [
          'class' => ['gc-empty__cta-link'],
          'data-testid' => $testid_prefix . '-empty-cta',
        ],
      ];
    }

    $section['body'] = $empty_body;
    return $section;
  }

  /**
   * Formats the Upcoming section's item-count annotation.
   */
  protected function formatItemCount(int $count, bool $is_global_scope): string {
    if ($count === 0) {
      return (string) $this->t('0 events');
    }
    return $is_global_scope
      ? (string) $this->formatPlural($count, '1 event', '@count events')
      : (string) $this->t('@count · from your groups', ['@count' => $this->formatPlural($count, '1 event', '@count events')]);
  }

  /**
   * Formats the My RSVPs section's item-count annotation.
   */
  protected function formatRsvpCount(int $count): string {
    if ($count === 0) {
      return (string) $this->t('0 RSVPs');
    }
    return (string) $this->formatPlural($count, '1 event you\'re attending', '@count events you\'re attending');
  }

  /**
   * Assembles the `#theme => do_streams_shell` render array.
   *
   * @param array $results
   *   The two pre-composed sections (`upcoming`, `my_rsvps`), each already
   *   containing its own `<h2>` + results-or-empty markup (handoff-A.md
   *   Finding #1).
   * @param bool $is_global_scope
   *   Whether the Global scope tab is currently active (drives the
   *   two-tab Global/My Groups toggle's `is-active`/`aria-current`, mirrors
   *   the shell's OWN `scope_tabs` idiom but scoped to just these two ids
   *   per the wireframe — brief.md's Non-goal: no new scope plugin, just
   *   this page's own two-tab markup reusing the SAME `?scope=` query-param
   *   convention).
   *
   * @return array
   *   The shell render array, with `#results` never empty (Finding #1: the
   *   shell's own `empty`/`empty_copy` branch is deliberately unused on this
   *   route) and the page-head (title + iCal links) + two-tab toggle
   *   prepended ahead of the two sections.
   */
  protected function buildShell(array $results, bool $is_global_scope): array {
    $uid = $this->currentUser()->id();

    $build = [
      '#theme' => 'do_streams_shell',
      // Finding #1: this route never uses the shell's own 4-tab scope_tabs
      // set — 'my_feed' here is simply a harmless default (the shell's
      // preprocessDoStreamsShell() always overwrites scope_tabs itself);
      // the page's REAL two-tab Global/My-Groups toggle is built below, in
      // #results, ahead of the two sections.
      '#active_scope' => 'my_feed',
      '#active_ranking' => 'recent',
      '#results' => [
        'page_head' => $this->buildPageHead($uid),
        'scope_toggle' => $this->buildScopeToggle($is_global_scope),
        'upcoming' => $results['upcoming'],
        'my_rsvps' => $results['my_rsvps'],
      ],
      // Finding #1 (binding): ALWAYS FALSE — both sections carry their OWN
      // independent empty state (upcoming-events-empty / my-rsvps-empty),
      // so the shell's single all-or-nothing empty branch must never fire
      // on this route.
      '#empty_cta' => [],
      '#attached' => [
        'library' => ['do_streams/events'],
      ],
      // Mirrors MyFeedController's own per-viewing-user cache contract
      // (handoff-A.md Finding #4b's established pattern, extended here):
      // the Upcoming display's membership scope AND the ?scope= toggle
      // itself are both per-request/per-viewing-user, so `user` +
      // `user.roles:authenticated` bubble on the outer render array,
      // plus the `url.query_args` context so the SAME route with
      // ?scope=global is never served the My-Groups-scoped cached render
      // (and vice versa).
      '#cache' => [
        'contexts' => ['user', 'user.roles:authenticated', 'url.query_args:scope'],
        'tags' => [DoStreamsHooks::userStreamCacheTag($uid)],
      ],
    ];

    return $build;
  }

  /**
   * Builds the page-head render array (title + iCal links).
   *
   * REUSE-only, per brief.md's Non-goal "Do NOT reimplement iCal
   * generation" — links to the EXISTING `do_discovery.ical_site` /
   * `.ical_user` routes ({@see
   * \Drupal\do_discovery\Controller\IcalController}), never a new
   * endpoint. `ical_user`'s `{user}` parameter is built from the CURRENT
   * viewing user's own uid, never hardcoded.
   *
   * @param int|string $uid
   *   The current viewing user's id.
   *
   * @return array
   *   A `container` render array with the page title (`<h1>`) and the two
   *   iCal `<a>` links.
   */
  protected function buildPageHead($uid): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['page-head']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $this->t('My Feed — Events'),
        '#attributes' => ['class' => ['page-head__title']],
      ],
      'ical_links' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ical-links']],
        'site' => [
          '#type' => 'link',
          '#title' => [
            '#markup' => '📅 ' . $this->t('Subscribe: all events (iCal)'),
          ],
          '#url' => Url::fromRoute('do_discovery.ical_site'),
          '#attributes' => ['data-testid' => 'ical-link-site'],
        ],
        'user' => [
          '#type' => 'link',
          '#title' => [
            '#markup' => '📅 ' . $this->t('Subscribe: my RSVPs (iCal)'),
          ],
          '#url' => Url::fromRoute('do_discovery.ical_user', ['user' => $uid]),
          '#attributes' => ['data-testid' => 'ical-link-user'],
        ],
      ],
    ];
  }

  /**
   * Builds the two-tab Global/My-Groups scope toggle.
   *
   * Reuses the SAME `do-streams-shell-tab` markup/testid/`data-url-or-param`
   * contract the shared shell's own `scope_tabs` establishes (#109/#110),
   * per handoff-D.md: "only the tab SET differs (Global/My-groups here vs.
   * the full four-scope set) — same `?scope=` query-param convention, no
   * new toggle mechanism." Reusing the identical markup here (rather than
   * extending the shell template itself to accept a caller-supplied tab
   * SET) keeps this a two-line, page-owned fragment instead of a
   * cross-story shell-contract change, matching Finding #1's guidance.
   *
   * @param bool $is_global_scope
   *   Whether the Global tab is currently active.
   *
   * @return array
   *   A `nav` render array with the two scope tabs.
   */
  protected function buildScopeToggle(bool $is_global_scope): array {
    $tabs = [
      'global' => $this->t('Global'),
      'my_feed' => $this->t('My Groups'),
    ];

    $items = [];
    foreach ($tabs as $id => $label) {
      $is_active = ($id === 'global') === $is_global_scope;
      $attributes = [
        'class' => array_filter(['shell-tabs__item', $is_active ? 'is-active' : NULL]),
        'data-testid' => 'do-streams-shell-tab',
        'data-scope-id' => $id,
        'data-url-or-param' => '?scope=' . $id,
      ];
      if ($is_active) {
        $attributes['aria-current'] = 'true';
      }

      $items[$id] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('do_streams.my_events', [], ['query' => $id === 'global' ? ['scope' => 'global'] : []]),
        '#attributes' => $attributes,
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['shell-tabs'],
        'aria-label' => (string) $this->t('Events scope'),
        'data-testid' => 'do-streams-shell-tabs',
      ],
      'tabs' => $items,
    ];
  }

}
