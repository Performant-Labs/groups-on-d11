<?php

declare(strict_types=1);

namespace Drupal\do_streams\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_showcase\VariantSwitcher;
use Drupal\views\ViewExecutable;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ST-8 (#130): mounts the SC-F1 `VariantSwitcher` over `/stream`.
 *
 * TWO cooperating methods mount the switcher over `activity_stream:page_1`
 * — a new sibling class to `DoShowcaseHooks` (#124 SC-5), not a refactor of
 * it (handoff-A.md advisory #2: the two-hook shape is a shallow structural
 * duplication per instance, not a semantic one; `VariantSwitcher` itself,
 * the one object that MUST be single-sourced, is reused verbatim). This
 * class lives in do_streams (not do_showcase) because the CALLER — the
 * hook set that mounts the switcher onto a page — belongs with the module
 * that owns that page; do_streams owns `/stream` via the `activity_stream`
 * view and its own `DoStreamsHooks` ranking/shell hooks (handoff-A.md
 * advisory #1). The switcher SERVICE, template, and JS stay in do_showcase
 * as the reusable framework.
 *
 * `viewsPreRender()` IS a real `#[Hook('views_pre_render')]` — do_streams
 * had no prior `views_pre_render` implementation, so this is a normal
 * addition. `preprocessViewsView()` is deliberately NOT `#[Hook]`-attributed
 * here (integration correction, discovered running
 * `StreamModelTogglePreRenderTest`, not anticipated by the brief/A-review,
 * both of which assumed #124's DoShowcaseHooks shape would transplant
 * verbatim): Drupal's `ModuleHandler::invoke()` throws `LogicException`
 * ("Module do_streams should not implement preprocess_views_view more than
 * once") if a SINGLE module registers a second `preprocess_views_view`
 * listener — unlike #124, where do_showcase had no pre-existing
 * `preprocess_views_view` implementation to collide with, do_streams
 * ALREADY has one (`DoStreamsHooks::preprocessViewsView()`, ST-2 #111, the
 * `/following` library attachment). This class's `preprocessViewsView()`
 * therefore stays a plain public method holding this story's full
 * model-toggle logic, and `DoStreamsHooks::preprocessViewsView()` (the
 * module's one legal `#[Hook('preprocess_views_view')]` slot) delegates to
 * it via a constructor-injected `ModelToggleHooks` instance — see that
 * method's own docblock. This is the minimum-blast-radius fix: it touches
 * one existing method (adds a one-line delegation call) rather than
 * inventing a second, illegal hook registration or merging two unrelated
 * view-scoped concerns (following-feed library attach; stream-model
 * switcher mount) into a single hand-written method body.
 *
 * The two methods:
 *  - `viewsPreRender()` sets the `data-do-stream-model` wrapper attribute,
 *    the `url.query_args:variant` cache context, and the attached
 *    libraries — all on `$view->element`, which
 *    `DisplayPluginBase::buildRenderable()` returns directly, so these
 *    survive the render pipeline unchanged (see `DoShowcaseHooks`'s class
 *    docblock for the confirmed live-DOM trace: the attribute lands on the
 *    OUTER `.views-element-container` wrapper
 *    `\Drupal\views\Element\View::preRenderViewElement()` adds via
 *    `#theme_wrappers => ['container']`, not on the INNER `.view…` div,
 *    which gets its own, separate `attributes` Twig variable from
 *    `template_preprocess_views_view()`).
 *  - `preprocessViewsView()` injects the switcher render array into
 *    `$variables['header']`. This CANNOT be done in `viewsPreRender()` —
 *    `DisplayPluginBase::elementPreRender()` (a queued `#pre_render`
 *    callback) unconditionally OVERWRITES `$element['#header']` with
 *    `renderArea('header', …)` strictly AFTER `hook_views_pre_render` but
 *    strictly BEFORE `hook_preprocess_views_view()` fires, so writing to
 *    `#header` inside `viewsPreRender()` would be silently discarded by
 *    render time (identical reasoning to `DoShowcaseHooks`'s own class
 *    docblock, verified again here for `activity_stream:page_1`).
 *
 * Both methods share the identical view-id/display-id guard
 * (`isStreamModelView()`) and the same `requestedVariant()` helper — one
 * source of truth for "which view/display this story targets" and "what
 * variant the current request asked for", per `DoShowcaseHooks`'s own
 * shape.
 *
 * The target is currently a single `{view_id => display_id}` pair
 * (`activity_stream` => `page_1`). `/my-feed` is deferred (brief.md "Out
 * of scope": `views.view.my_feed.yml` does not exist in main, blocked on
 * open PR #110) — when #110 merges, mounting the same `stream.model`
 * instance there is intended to be a one-line addition to
 * `isStreamModelView()`'s guard, reusing the identical instance id,
 * options, and tooltip copy.
 *
 * `$switcher` is NULLABLE (`?VariantSwitcher`, wired via `@?do_showcase
 * .variant_switcher` in do_streams.services.yml — the standard Drupal
 * optional-service-reference syntax, e.g. core.services.yml's own
 * `'@?router.request_context'`). do_streams.info.yml declares a hard
 * `do_showcase:do_showcase` dependency, so on any REAL site both modules
 * are always enabled together and `$switcher` is never actually NULL in
 * production. The nullability exists ONLY because several PRE-EXISTING
 * do_streams Kernel tests (`FollowingFeedTest`, `RankingTest`,
 * `MembershipScopeTest`, `FollowingScopeTest`, `StreamShellTest` — none
 * authored or touched by this story) use an explicit `protected static
 * $modules` allowlist that lists `do_streams` WITHOUT `do_showcase` — a
 * Kernel-test-harness artifact (explicit allowlists bypass `.info.yml`
 * dependency-driven auto-enable a real `drush en`/site-install would
 * perform) that made the do_streams container fail to compile at all
 * (`ServiceNotFoundException: ... has a dependency on a non-existent
 * service "do_showcase.variant_switcher"`) once `ModelToggleHooks` became
 * a hard, non-optional constructor dependency of `DoStreamsHooks`. Making
 * the reference optional — and guarding both methods below on `$switcher
 * === NULL` — restores those tests to green without editing any test file
 * (F does not edit tests) and without weakening the real, enforced
 * `do_showcase:do_showcase` module dependency.
 *
 * `$requestStack` stays a plain, non-nullable dependency — it is a core
 * Symfony service (`request_stack`), always present regardless of which
 * custom modules a Kernel test enables.
 */
class ModelToggleHooks {

  /**
   * The view id this story's stream-model switcher mounts on.
   */
  private const STREAM_VIEW_ID = 'activity_stream';

  /**
   * The display id this story's stream-model switcher mounts on.
   */
  private const STREAM_DISPLAY_ID = 'page_1';

  public function __construct(
    private readonly ?VariantSwitcher $switcher,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Sets the stream-model wrapper attribute, cache context, libraries.
   *
   * ST-8 (#130). Scoped ONLY to view id `activity_stream`, display
   * `page_1` (brief.md scope — this story touches no other view). Every
   * other view/display returns immediately with NO side effects
   * (`StreamModelTogglePreRenderTest::testHookDoesNotFireForADifferentViewId`
   * pins this negative case). Also returns immediately (class docblock) if
   * `$this->switcher` is NULL — do_showcase not being enabled in the
   * current container (Kernel-test-harness-only; do_streams.info.yml
   * always enables it on a real site).
   *
   * Three responsibilities, all on `$view->element`:
   *  1. `#cache['contexts']` — `url.query_args:variant` set DIRECTLY on the
   *     view's own render array (mirrors `DoShowcaseHooks::viewsPreRender()`'s
   *     A-advisory #1 reasoning: do not rely solely on
   *     `VariantSwitcher::build()`'s own child `#cache` metadata bubbling
   *     up, since the WRAPPER-ATTRIBUTE decision below is made BEFORE
   *     `build()` even runs and must independently carry the varying
   *     context).
   *  2. `#attributes['data-do-stream-model']` — the resolved variant id
   *     (wireframe.md Surface 2 "Contract"), computed via
   *     `VariantSwitcher::resolveCurrent()` using the EXACT SAME
   *     first-available-fallback rule `build()` applies internally (no
   *     query -> 'activity' page default; unavailable/unknown -> first
   *     available, i.e. 'activity'; never a hand-duplicated fallback rule
   *     that could silently drift from `build()`'s own). Lands on
   *     `.views-element-container` on a live page (same mirror selector
   *     #124 proved in live DOM — survey.md's "DO NOT invent a different
   *     selector" guardrail).
   *  3. `do_showcase/switcher` (the client-side click/keyboard toggle +
   *     sessionStorage persistence, SC-F1, reused verbatim — no second JS
   *     toggle) and `do_streams/model-toggle` (this story's new CSS-only
   *     library) are attached.
   */
  #[Hook('views_pre_render')]
  public function viewsPreRender(ViewExecutable $view): void {
    if ($this->switcher === NULL || !$this->isStreamModelView($view)) {
      return;
    }

    $requested_variant = $this->requestedVariant();
    $option_specs = $this->switcher->streamModelOptions();
    $resolved_variant = $this->switcher->resolveCurrent($option_specs, $requested_variant);

    $view->element['#cache']['contexts'][] = 'url.query_args:variant';
    $view->element['#attributes']['data-do-stream-model'] = $resolved_variant;
    $view->element['#attached']['library'][] = 'do_showcase/switcher';
    $view->element['#attached']['library'][] = 'do_streams/model-toggle';
  }

  /**
   * Injects the stream-model switcher into the view's header region.
   *
   * ST-8 (#130). Scoped identically to `viewsPreRender()` above (same view
   * id/display id guard, same NULL-switcher guard). This is the seam that
   * actually survives into the rendered `<header>` — see class docblock
   * for why `viewsPreRender()` alone cannot do this.
   *
   * NOT `#[Hook]`-attributed (see class docblock: do_streams already has
   * ONE legal `preprocess_views_view` listener, `DoStreamsHooks
   * ::preprocessViewsView()`, which delegates to this method) — called
   * directly, with the SAME `array &$variables` reference
   * `hook_preprocess_views_view()` itself receives, so this method's own
   * behavior (and its Kernel test coverage, which calls it only indirectly
   * through the full render pipeline) is identical to if it WERE the hook
   * implementation.
   *
   * `$variables['header']` is Views' OWN keyed array of per-area render
   * arrays (empty here, since `views.view.activity_stream.yml` declares no
   * `header:` area handlers) — this adds a `switcher` key alongside
   * whatever (today, nothing) core's own area handlers already populated,
   * rather than replacing the whole array, so a FUTURE header area added
   * to the view's own config would not be silently clobbered by this
   * story.
   */
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'] ?? NULL;
    if ($this->switcher === NULL || !$view instanceof ViewExecutable || !$this->isStreamModelView($view)) {
      return;
    }

    $requested_variant = $this->requestedVariant();
    $option_specs = $this->switcher->streamModelOptions();

    $switcher = $this->switcher->build(
      'stream.model',
      $option_specs,
      $requested_variant,
    );

    // Wrapper-mirror wiring (mirrors DoShowcaseHooks::preprocessViewsView()'s
    // O decision #1 / A-advisory #2): the generic, data-driven callback in
    // do_showcase.switcher.js reads these two attributes off the
    // radiogroup wrapper — set here, not hard-coded in the shared JS file,
    // so the switcher stays agnostic to what its selection means to this
    // particular caller. `.views-element-container` is the SAME element
    // `viewsPreRender()`'s `#attributes` write lands on (see class
    // docblock) — the proven selector, not a new one.
    $switcher['#attributes']['data-do-showcase-mirror-attribute'] = 'data-do-stream-model';
    $switcher['#attributes']['data-do-showcase-mirror-selector'] = '.views-element-container';
    $switcher['#wrapper_attributes']['data-do-showcase-mirror-attribute'] = 'data-do-stream-model';
    $switcher['#wrapper_attributes']['data-do-showcase-mirror-selector'] = '.views-element-container';

    $variables['header']['switcher'] = $switcher;
  }

  /**
   * Whether the given view/display is this story's stream-model target.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view to check.
   *
   * @return bool
   *   TRUE for view id `activity_stream`, display `page_1`; FALSE
   *   otherwise.
   */
  private function isStreamModelView(ViewExecutable $view): bool {
    return $view->id() === self::STREAM_VIEW_ID
      && $view->current_display === self::STREAM_DISPLAY_ID;
  }

  /**
   * Reads the `?variant=` query argument off the current request.
   *
   * Defaults to `activity` (this story's page default — the only
   * available option today) when absent — NOT `cards`, which is
   * `DoShowcaseHooks`'s own default for its unrelated `directory.layout`
   * instance.
   *
   * @return string
   *   The raw requested variant id (not yet resolved against the option
   *   list's availability).
   */
  private function requestedVariant(): string {
    $request = $this->requestStack->getCurrentRequest();
    return (string) ($request?->query->get('variant') ?? 'activity');
  }

}
