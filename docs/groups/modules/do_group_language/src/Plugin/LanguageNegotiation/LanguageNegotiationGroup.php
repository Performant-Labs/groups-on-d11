<?php

namespace Drupal\do_group_language\Plugin\LanguageNegotiation;

use Drupal\language\LanguageNegotiationMethodBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Group-level language negotiation plugin.
 *
 * Reads field_group_language from the group entity in the URL path.
 * Runs before URL/session negotiation but after browser language.
 *
 * @LanguageNegotiation(
 *   id = \Drupal\do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup::METHOD_ID,
 *   weight = 5,
 *   name = @Translation("Group language"),
 *   description = @Translation("Language based on the group's language setting.")
 * )
 */
class LanguageNegotiationGroup extends LanguageNegotiationMethodBase {

  const METHOD_ID = 'language-group';

  /**
   * {@inheritdoc}
   */
  public function getLangcode(?Request $request = NULL) {
    if (!$request) {
      return NULL;
    }

    $path = $request->getPathInfo();

    // Extract group ID from path: /group/{gid} or /group/{gid}/...
    if (!preg_match('#^/group/(\d+)#', $path, $matches)) {
      return NULL;
    }

    $gid = $matches[1];

    try {
      $group = \Drupal::entityTypeManager()
        ->getStorage('group')
        ->load($gid);

      if (!$group) {
        return NULL;
      }

      if (!$group->hasField('field_group_language') || $group->get('field_group_language')->isEmpty()) {
        return NULL;
      }

      $langcode = $group->get('field_group_language')->value;

      // Don't override if set to undefined or not applicable.
      if (in_array($langcode, ['und', 'zxx', ''])) {
        return NULL;
      }

      return $langcode;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
