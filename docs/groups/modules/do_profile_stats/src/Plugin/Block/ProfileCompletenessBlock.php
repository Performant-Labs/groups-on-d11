<?php

namespace Drupal\do_profile_stats\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\user\UserInterface;

/**
 * Provides a 'Profile Completeness' block.
 *
 * @Block(
 *   id = "do_profile_completeness",
 *   admin_label = @Translation("Profile Completeness"),
 *   category = @Translation("Custom")
 * )
 */
class ProfileCompletenessBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $user = $this->getContextUser();
    if (!$user) {
      return [];
    }

    // Only show to the profile owner.
    $current_user = \Drupal::currentUser();
    if ($current_user->id() != $user->id()) {
      return [];
    }

    // Fields to check for completeness.
    $fields_to_check = [
      'field_first_name' => 'First name',
      'field_last_name' => 'Last name',
      'user_picture' => 'Profile image',
      'field_bio' => 'Bio',
      'field_country' => 'Country',
      'field_areas_of_expertise' => 'Areas of expertise',
      'field_industries_worked_in' => 'Industries',
      'field_drupal_contributions' => 'Drupal contributions',
    ];

    $filled = 0;
    $total = count($fields_to_check);
    $missing = [];

    foreach ($fields_to_check as $field_name => $label) {
      if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
        $filled++;
      }
      else {
        $missing[] = $label;
      }
    }

    $percentage = $total > 0 ? round(($filled / $total) * 100) : 0;

    return [
      '#theme' => 'do_profile_completeness',
      '#percentage' => $percentage,
      '#missing_fields' => $missing,
    ];
  }

  /**
   * Gets the user from route context.
   */
  protected function getContextUser(): ?UserInterface {
    $user = \Drupal::routeMatch()->getParameter('user');
    if ($user instanceof UserInterface) {
      return $user;
    }
    if (is_numeric($user)) {
      return \Drupal\user\Entity\User::load($user);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
