<?php

declare(strict_types=1);

namespace Drupal\do_chrome;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The "Who can do what" permission matrix for the community_group type (#91).
 *
 * Single source of truth for the matrix rendered by the CH-B4 panel. The cells
 * are DERIVED FROM THE ENFORCED ROLES on the demo, not from the (stale) #81
 * deck — re-verified against the deploy-time role config after CH-F4 (#95) and
 * #100 landed:
 *
 *   docs/groups/config/group.role.community_group-anon_view.yml     (Anonymous)
 *   docs/groups/config/group.role.community_group-outsider_view.yml (Outsider)
 *   docs/groups/config/group.role.community_group-insider_view.yml  (Member)
 *   docs/groups/config/group.role.community_group-admin.yml         (Admin)
 *
 * Enforced grants (verified):
 *  - Anonymous  → view group + view all 5 group_node content types. Nothing else.
 *  - Outsider   → the above + `join group`. NO content create (an insider grant).
 *  - Member     → view + `leave group` + create / update-own / delete-own for
 *                 all 5 group_node types. NO `administer members`.
 *  - Admin      → `admin: true` ⇒ implicit ALL group permissions (bypass),
 *                 including member management. The only mutating-everything actor.
 *
 * Cell states are intentionally coarse (yes / no / n-a) so the panel reflects
 * what is TRUE today without over-claiming. This class holds NO Drupal service
 * dependencies — it is a pure data definition, unit-testable in isolation.
 */
final class PermissionMatrix {

  use StringTranslationTrait;

  /**
   * Cell state: the actor CAN do this action (enforced grant).
   */
  public const STATE_YES = 'yes';

  /**
   * Cell state: the actor CANNOT do this action (no grant enforces it).
   */
  public const STATE_NO = 'no';

  /**
   * Cell state: the action does not apply to this actor.
   *
   * Used for "Join" against a Member (already in) — not a denial, an N/A.
   */
  public const STATE_NA = 'n-a';

  /**
   * The actor columns, in display order (least → most privileged).
   *
   * @return array<int, array{id: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Each actor: a machine id and a human column label.
   */
  public function actors(): array {
    return [
      ['id' => 'anonymous', 'label' => $this->t('Anonymous')],
      ['id' => 'outsider', 'label' => $this->t('Signed-in visitor')],
      ['id' => 'member', 'label' => $this->t('Member')],
      ['id' => 'admin', 'label' => $this->t('Group admin')],
    ];
  }

  /**
   * The action rows with the enforced cell state per actor.
   *
   * The `states` map is keyed by actor id and every actor id from actors() is
   * present in every row (asserted in tests), so the template can render a full
   * grid without missing-key guards.
   *
   * @return array<int, array{label: \Drupal\Core\StringTranslation\TranslatableMarkup, states: array<string, string>}>
   *   Ordered action rows.
   */
  public function rows(): array {
    // Column order mirrors actors(): anonymous, outsider, member, admin.
    // Each row lists the four states in that same order for readability.
    $rows = [
      // Everyone can read the group entity and its content — fully public demo.
      [$this->t('View the group'),
        [self::STATE_YES, self::STATE_YES, self::STATE_YES, self::STATE_YES]],
      [$this->t('View group content'),
        [self::STATE_YES, self::STATE_YES, self::STATE_YES, self::STATE_YES]],
      // `join group` is granted only to outsider_view (authenticated non-member);
      // anonymous cannot join, a member is already in (N/A), admin bypasses.
      [$this->t('Join the group'),
        [self::STATE_NO, self::STATE_YES, self::STATE_NA, self::STATE_YES]],
      // `leave group` is an insider_view grant; admin bypasses. Anonymous /
      // outsider are not members, so leaving does not apply.
      [$this->t('Leave the group'),
        [self::STATE_NA, self::STATE_NA, self::STATE_YES, self::STATE_YES]],
      // create group_node:* — insider_view only (verified FALSE for outsiders).
      [$this->t('Post content'),
        [self::STATE_NO, self::STATE_NO, self::STATE_YES, self::STATE_YES]],
      // update/delete OWN group_node:* — insider_view; admin edits any.
      [$this->t('Edit or remove own content'),
        [self::STATE_NO, self::STATE_NO, self::STATE_YES, self::STATE_YES]],
      // `administer members` / role management — admin bypass only.
      [$this->t('Invite & manage members'),
        [self::STATE_NO, self::STATE_NO, self::STATE_NO, self::STATE_YES]],
    ];

    $actor_ids = array_column($this->actors(), 'id');
    $out = [];
    foreach ($rows as [$label, $states]) {
      $out[] = [
        'label' => $label,
        'states' => array_combine($actor_ids, $states),
      ];
    }
    return $out;
  }

  /**
   * Human, accessible text for a cell state (used as the cell's aria/title).
   *
   * @param string $state
   *   One of the STATE_* constants.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The accessible label for that state.
   */
  public function stateLabel(string $state): TranslatableMarkup {
    return match ($state) {
      self::STATE_YES => $this->t('Yes'),
      self::STATE_NA => $this->t('Not applicable'),
      default => $this->t('No'),
    };
  }

}
