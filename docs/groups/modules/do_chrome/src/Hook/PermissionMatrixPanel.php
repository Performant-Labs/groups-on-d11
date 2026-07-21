<?php

declare(strict_types=1);

namespace Drupal\do_chrome\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_chrome\HelpText;
use Drupal\do_chrome\PermissionMatrix;
use Drupal\group\Entity\GroupInterface;

/**
 * #91 (CH-B4): the "Who can do what" permission-matrix panel.
 *
 * Renders a matrix of the four actors (anonymous / signed-in visitor / member /
 * group admin) against the actions they can take, reflecting the roles ACTUALLY
 * ENFORCED on the demo (see \Drupal\do_chrome\PermissionMatrix, derived from the
 * deploy-time community_group role config after CH-F4 #95 + #100).
 *
 * Owns disjoint surfaces — its own #[Hook] methods, its own theme hook
 * (`do_chrome_permission_matrix`), its own template + CSS class namespace, and
 * its own HelpText copy keys (`permissions.panel.*`) — so it is parallel-safe
 * with the other B-story surfaces (#88–#92) and does NOT edit the shared
 * DoChromeHooks. The tooltip library is attached globally by DoChromeHooks, so
 * the intro tooltip only needs to emit `data-do-tooltip`.
 *
 * Placement: injected into the group entity's FULL view via `entity_view`, so
 * the panel appears on every group canonical page (/group/{id}) without any
 * block-placement config. This matters because block.block.* placements are
 * excluded from the assembled config/sync (scripts/ci/assemble-config.sh), so a
 * block plugin would not render on a clean self-seed; an entity_view injection
 * renders in both the deployed image and CI's config-import E2E path.
 *
 * @see \Drupal\do_chrome\PermissionMatrix
 * @see \Drupal\do_chrome\Hook\DoChromeHooks::pageAttachments()
 */
class PermissionMatrixPanel {

  /**
   * Registers the permission-matrix Twig template.
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return [
      'do_chrome_permission_matrix' => [
        'variables' => [
          'intro' => '',
          'intro_tooltip' => '',
          'footnote' => '',
          'actors' => [],
          'rows' => [],
        ],
        'template' => 'do-chrome-permission-matrix',
      ],
    ];
  }

  /**
   * Injects the permission-matrix panel into the group's full view.
   *
   * Only the full view mode of a group entity gets the panel — not teasers,
   * cards, or non-group entities — so it appears once, on the group page, and
   * never in the directory listing.
   */
  #[Hook('entity_view')]
  public function entityView(
    array &$build,
    EntityInterface $entity,
    EntityViewDisplayInterface $display,
    string $view_mode,
  ): void {
    if (!$entity instanceof GroupInterface || $view_mode !== 'full') {
      return;
    }

    $matrix = new PermissionMatrix();

    $build['do_chrome_permission_matrix'] = [
      '#theme' => 'do_chrome_permission_matrix',
      '#intro' => HelpText::get('permissions.panel.intro'),
      '#intro_tooltip' => HelpText::get('permissions.panel.footnote'),
      '#footnote' => HelpText::get('permissions.panel.footnote'),
      '#actors' => $matrix->actors(),
      '#rows' => $this->buildRows($matrix),
      // Weighted after the group's own fields; the shared tooltip library powers
      // the intro's data-do-tooltip trigger.
      '#weight' => 50,
      '#attached' => ['library' => ['do_chrome/tooltips']],
      // Re-render if the enforcing roles change; the matrix is otherwise static.
      '#cache' => [
        'tags' => [
          'config:group.role.community_group-anon_view',
          'config:group.role.community_group-outsider_view',
          'config:group.role.community_group-insider_view',
          'config:group.role.community_group-admin',
        ],
      ],
    ];
  }

  /**
   * Flattens the matrix rows into template-ready cells.
   *
   * Each cell carries its raw state (for the CSS class + glyph) and an
   * accessible label (for aria/title), so the template stays presentation-only.
   *
   * @param \Drupal\do_chrome\PermissionMatrix $matrix
   *   The matrix definition.
   *
   * @return array<int, array{label: mixed, cells: array<int, array{state: string, label: mixed}>}>
   *   Rows, each with an action label and cells ordered to match the actors.
   */
  private function buildRows(PermissionMatrix $matrix): array {
    $actor_ids = array_column($matrix->actors(), 'id');
    $rows = [];
    foreach ($matrix->rows() as $row) {
      $cells = [];
      foreach ($actor_ids as $actor_id) {
        $state = $row['states'][$actor_id];
        $cells[] = [
          'state' => $state,
          'label' => $matrix->stateLabel($state),
        ];
      }
      $rows[] = [
        'label' => $row['label'],
        'cells' => $cells,
      ];
    }
    return $rows;
  }

}
