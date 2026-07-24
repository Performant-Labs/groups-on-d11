<?php

declare(strict_types=1);

namespace Drupal\do_streams\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\do_streams\Hook\DoStreamsHooks;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the `/my-feed` route (issue #110, ST-1 My Feed).
 *
 * Wraps the `my_feed` view's DEFAULT display (never a page display — this
 * route owns navigation, per the brief's own instruction) in the shared
 * `do_streams_shell` theme hook from #109
 * ({@see \Drupal\do_streams\Hook\DoStreamsHooks::theme()}), the SAME
 * shell-wrapping shape the shell's own docblock names as the intended caller
 * for ST-1/2/4/6.
 *
 * Deviation from the brief's literal wording (recorded in handoff-F, not a
 * design change): `views_embed_view()` is DEPRECATED as of Drupal 11.4 (the
 * version actually installed here — confirmed via composer.lock) and emits
 * an E_USER_DEPRECATED notice on every call, which risks turning an
 * otherwise-passing Functional test RED under strict deprecation handling.
 * Drupal core's OWN `ViewExecutable::preview()` docblock says outright: "To
 * render the view normally with access checks, use '#type' => 'view' render
 * elements instead." — so this controller loads + executes the view directly
 * (so the empty-state check below can inspect `$view->result` synchronously)
 * and hands the SAME already-executed `ViewExecutable` object to a
 * `#type => 'view'` render element via `#view`
 * ({@see \Drupal\views\Element\View::preRenderViewElement()}, which reuses a
 * pre-set `#view` rather than re-loading it). `ViewExecutable::execute()`
 * guards against a double query run (`if (!empty($this->executed)) return
 * TRUE;`), so this is a single-execution, non-deprecated equivalent of
 * `views_embed_view('my_feed', 'default')` — functionally identical output,
 * same access-check behavior (the render element still calls `$view->access()`
 * itself, providing the same defense-in-depth the view's own `role:
 * authenticated` access plugin adds on top of the route's
 * `_user_is_logged_in` requirement — handoff-A.md Finding #3).
 */
class MyFeedController extends ControllerBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static();
  }

  /**
   * Builds the `/my-feed` page: the shared shell wrapping the `my_feed` view.
   *
   * @return array
   *   A `#theme => do_streams_shell` render array.
   */
  public function build(): array {
    $view = Views::getView('my_feed');

    if ($view === NULL) {
      // The view config genuinely does not exist (e.g. config not imported)
      // — render the shell in its empty state rather than fatally erroring,
      // matching the shell's own "no hardcoded routes" / graceful-degradation
      // contract. No results, no cache tag to add (nothing was queried).
      return $this->buildShell([], TRUE);
    }

    $view->setDisplay('default');
    // Executing here (rather than deferring entirely to the render
    // element's own #pre_render callback) is what lets this controller
    // inspect $view->result synchronously for the shell's `empty` state —
    // see this class's docblock for why this does not double-run the query.
    $view->execute();

    $is_empty = empty($view->result);

    $results_build = [
      '#type' => 'view',
      '#name' => 'my_feed',
      '#display_id' => 'default',
      '#embed' => TRUE,
      '#view' => $view,
    ];

    return $this->buildShell($is_empty ? [] : $results_build, $is_empty);
  }

  /**
   * Assembles the `#theme => do_streams_shell` render array.
   *
   * @param array $results
   *   The pre-built results render array (empty array when there are no
   *   results — the shell's own preprocess treats an empty `results` as the
   *   signal to render the empty state, {@see
   *   \Drupal\do_streams\Hook\DoStreamsHooks::preprocessDoStreamsShell()}).
   * @param bool $is_empty
   *   Whether the empty state should render (drives the `empty_cta` slot,
   *   which only ever appears alongside the empty state).
   *
   * @return array
   *   The shell render array.
   */
  protected function buildShell(array $results, bool $is_empty): array {
    $build = [
      '#theme' => 'do_streams_shell',
      '#active_scope' => 'my_feed',
      '#active_ranking' => 'recent',
      '#results' => $results,
      // handoff-A.md Finding #5: the empty_cta render array is built HERE
      // (the caller), never hardcoded inside the shell's preprocess/template
      // — the shell stays route-agnostic. Only non-empty (and thus only
      // rendered) when the empty state itself renders.
      '#empty_cta' => $is_empty ? $this->buildEmptyCta() : [],
      // handoff-A.md Finding #7: this story's own (minimal) empty-CTA button
      // CSS — see css/my-feed.css's docblock for the scope note on the
      // inherited-from-#109 shell-chrome CSS gap this does NOT attempt to
      // backfill.
      '#attached' => [
        'library' => ['do_streams/my_feed'],
      ],
      // handoff-A.md Finding #4b: the shell theme hook itself declares no
      // cache metadata, so the per-viewing-user membership scope must
      // bubble a `user` cache context explicitly on this outer render array
      // (mirrors the exact `'#cache' => ['contexts' => ['user']]` idiom
      // already established by do_showcase's personaBanner() hook).
      // `user.roles:authenticated` is added too: the anonymous/authenticated
      // split itself (route-gated) is a further axis this render could vary
      // on if ever reached by more than one role.
      '#cache' => [
        'contexts' => ['user', 'user.roles:authenticated'],
        // handoff-A.md Finding #4a: rather than widening
        // DoStreamsHooks::viewsPostRender()'s DEMO_VIEW_ID-only allowlist
        // (which would require every OTHER caller of that view id to also
        // want the tag — a broader, riskier change), the per-viewing-user
        // stream cache tag is merged directly here, scoped to exactly this
        // route's render. testResponseVariesByViewingUser pins the
        // OBSERVABLE outcome this achieves regardless of mechanism.
        'tags' => [DoStreamsHooks::userStreamCacheTag($this->currentUser()->id())],
      ],
    ];

    return $build;
  }

  /**
   * Builds the empty-state CTA link render array ("-> Browse all groups").
   *
   * @return array
   *   A `#type => link` render array, styled as a button via
   *   `gc-empty__cta-link` (the approved wireframe's class name,
   *   `docs/planning/handoffs/110-stream-110/wireframe.html` State 2).
   */
  protected function buildEmptyCta(): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('→ Browse all groups'),
      '#url' => Url::fromUserInput('/all-groups'),
      '#attributes' => [
        'class' => ['gc-empty__cta-link'],
        'data-testid' => 'do-streams-shell-empty-cta',
      ],
    ];
  }

}
