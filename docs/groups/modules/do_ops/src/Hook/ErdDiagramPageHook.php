<?php

declare(strict_types=1);

namespace Drupal\do_ops\Hook;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\State\StateInterface;
use Drupal\do_ops\Erd\ErdGenerator;
use Drupal\node\NodeInterface;

/**
 * Injects the live ER diagram into the /er-diagram page's full view.
 *
 * Follows the do_chrome `PermissionMatrixPanel` precedent: block.block.*
 * placements are excluded from the assembled config/sync (see
 * scripts/ci/assemble-config.sh), so a block plugin placed via the UI would
 * not render on a clean self-seed — an `entity_view` injection renders
 * identically in the deployed image, CI's config-import E2E path, and any
 * fresh `ddev bootstrap`.
 *
 * The target node is identified by its id, stored in State (NOT config —
 * the node id is environment-specific derived data, assigned by
 * docs/groups/scripts/step_800_er_diagram_page.php the first time it runs on
 * a given database) rather than by matching on title or path alias on every
 * render.
 *
 * The diagram itself is generated fresh on every render via a `#lazy_builder`
 * (not a plain nested render array with `#cache max-age: 0`) — the entity
 * view builder wraps a node's built render array in its OWN per-entity
 * render cache (keyed on entity + view mode + langcode), so a plain child
 * array's max-age is not enough to guarantee re-execution: `#lazy_builder`
 * is Drupal's actual mechanism for an always-fresh island inside content
 * that may otherwise be cached around it, so the page can never go stale the
 * way a hand-pasted copy of docs/architecture/groups-erd.md would.
 */
class ErdDiagramPageHook implements TrustedCallbackInterface {

  public function __construct(
    private readonly ErdGenerator $erdGenerator,
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildDiagram'];
  }

  /**
   * Injects a lazy-built placeholder for the diagram into the page's view.
   */
  #[Hook('entity_view')]
  public function entityView(
    array &$build,
    EntityInterface $entity,
    EntityViewDisplayInterface $display,
    string $view_mode,
  ): void {
    if (!$entity instanceof NodeInterface || $view_mode !== 'full') {
      return;
    }

    $nid = $this->state->get('do_ops.erd_diagram_nid');
    if ($nid === NULL || (int) $entity->id() !== (int) $nid) {
      return;
    }

    $build['do_ops_erd_diagram'] = [
      '#weight' => 50,
      '#lazy_builder' => ['do_ops.erd_diagram_page_hook:buildDiagram', []],
      '#create_placeholder' => TRUE,
    ];
  }

  /**
   * `#lazy_builder` callback: builds the live diagram markup + library.
   *
   * Runs fresh on every request regardless of any render cache wrapping the
   * page around it — see the class docblock for why a lazy builder, not a
   * plain `#cache max-age: 0` child array, is required here.
   *
   * @return array
   *   A renderable array.
   */
  public function buildDiagram(): array {
    $diagram = $this->erdGenerator->generate('group');

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'do-ops-erd-diagram'],
      '#attached' => ['library' => ['do_ops/erd_diagram']],
      'diagram' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#attributes' => ['class' => ['mermaid']],
        // html_tag's #value is treated as pre-rendered markup, so the
        // generated Mermaid source (plain text) must be escaped explicitly.
        '#value' => Html::escape($diagram),
      ],
    ];
  }

}
