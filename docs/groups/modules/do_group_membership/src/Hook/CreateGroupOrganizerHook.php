<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupRelationshipInterface;

/**
 * The create-group flow: creator auto-Organizer grant + preview redirect.
 *
 * #144 MC-6. ONE class holding TWO `#[Hook]` methods, per handoff-A.md Q2's
 * confirmation: both serve the single "create-group flow" concern and
 * neither is independently reusable, so they are NOT split into two classes,
 * and NOT folded into {@see \Drupal\do_group_membership\Hook\GroupAccessHook}
 * (that class is scoped to create-ACCESS gating —
 * `group_relationship_create_access` — a different hook and a different
 * concern).
 *
 * 1. {@see self::groupRelationshipInsert()} — modeled directly on the
 *    already-shipped
 *    `Drupal\do_notifications\Hook\DoNotificationsHooks::groupRelationshipInsert()`
 *    (`do_notifications/src/Hook/DoNotificationsHooks.php:165-198`). Fires
 *    AFTER Group 4.x's own form-save has created the creator's membership
 *    (with `community_group-admin` already applied via the group type's
 *    `creator_roles` setting — see `CreateFormEnhancer::enhanceGroupForm()`,
 *    which sets `group_roles` as an initial field value BEFORE the entity is
 *    ever saved, so by the time this POST-SAVE insert hook runs, Admin is
 *    already present). Grants Organizer additively via
 *    {@see \Drupal\do_group_membership\GroupMembershipManager::ensureRole()}.
 *
 * 2. {@see self::formAlter()} — appends a submit handler to the
 *    community_group add-form that redirects to the new guided-preview
 *    route (`do_group_membership.group_created_preview`) instead of the
 *    group's default canonical page, after the form's own submit handlers
 *    (including Group's own save) have run.
 *
 * Does NOT fork or duplicate #36's creator-membership mechanism (Group
 * 4.x's form-only `creator_membership` + `creator_roles`) — the insert hook
 * runs strictly after it and only ADDS a role to the membership Group's own
 * form-save already created; the form_alter only changes the post-submit
 * redirect destination, not what gets saved.
 */
class CreateGroupOrganizerHook {

  /**
   * The `community_group` add-form's form_id.
   *
   * Confirmed empirically (F, #144) against the assembled `community_group`
   * group type: `creator_wizard: true`
   * (`group.type.community_group.yml:10`) does NOT produce multiple
   * distinct wizard-STEP form_ids on this add-route — Group 4.x's
   * `CreateFormEnhancer` renders the wizard's remaining steps as
   * details/fieldset sections WITHIN the single
   * `group_community_group_add_form` request (a details-based single-page
   * form, not multi-URL), so one form_id filter is sufficient. See
   * handoff-F.md for the empirical verification method.
   */
  protected const COMMUNITY_GROUP_ADD_FORM_ID = 'group_community_group_add_form';

  public function __construct(
    private readonly GroupMembershipManager $manager,
  ) {}

  /**
   * Grants the creator's own membership the Organizer role, additively.
   *
   * Filters:
   *   1. `$relationship->getPluginId() === 'group_membership'` — skip
   *      `group_node:*` and any other relation plugin's insert.
   *   2. AC-8 guard: the relationship's member-user-id equals
   *      `$relationship->getGroup()->getOwnerId()` — only fires for the
   *      CREATOR's own membership, never for other members added later or
   *      in the same request (e.g. a batch-added member or demo-data
   *      seeding), per handoff-A.md finding #2.
   *
   * @param \Drupal\group\Entity\GroupRelationshipInterface $relationship
   *   The just-inserted `group_membership` relationship.
   */
  #[Hook('group_relationship_insert')]
  public function groupRelationshipInsert(GroupRelationshipInterface $relationship): void {
    if ($relationship->getPluginId() !== 'group_membership') {
      return;
    }

    $group = $relationship->getGroup();
    $member = $relationship->getEntity();
    if ($member === NULL || (string) $member->id() !== (string) $group->getOwnerId()) {
      return;
    }

    $this->manager->ensureRole($relationship, GroupMembershipManager::ORGANIZER_ROLE_ID);
  }

  /**
   * Redirects the community_group add-form's submit to the guided preview.
   *
   * Appends (does not replace) a submit handler on the community_group
   * add-form so it runs after the form's own submit handlers, including
   * Group's own save — plain appending is sufficient here because the
   * redirect handler does not depend on the membership row existing, only
   * on the saved group id from `$form_state->getFormObject()->getEntity()`
   * (per handoff-A.md Q3's best-effort read, empirically confirmed by F —
   * see handoff-F.md: Group's own submit handler does not call
   * `setRedirect()` on this form, so no `OrderAfter` reordering fallback is
   * needed).
   *
   * @param array $form
   *   The form render array, by reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form id.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id !== self::COMMUNITY_GROUP_ADD_FORM_ID) {
      return;
    }
    if (empty($form['actions']['submit']['#submit'])) {
      return;
    }

    $form['actions']['submit']['#submit'][] = [static::class, 'redirectToPreview'];
  }

  /**
   * Submit handler: redirects to the guided-preview page after group save.
   *
   * Static so it can be serialised into form state, matching the existing
   * `DoMultigroupHooks::nodeFormSubmit()` static-submit-handler convention.
   *
   * Also sets a status message (handoff-A.md finding #3, F's discretion)
   * matching Drupal's normal post-save convention, so the confirmation
   * persists for a user who navigates away from the preview page and back.
   *
   * @param array $form
   *   The form render array, by reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function redirectToPreview(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->getFormObject()->getEntity();

    \Drupal::messenger()->addStatus(t('Your group %label was created. You are the Organizer.', [
      '%label' => $group->label(),
    ]));

    $form_state->setRedirect('do_group_membership.group_created_preview', ['group' => $group->id()]);
  }

}
