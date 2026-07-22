<?php

declare(strict_types=1);

namespace Drupal\do_showcase;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The `/showcase` tour catalog: every planned comparison + the persona list.
 *
 * Brief-gate B-4 (ACCEPTED): the comparison list and persona list are typed
 * PHP-array CODE CONSTANTS (not config/content), each entry
 * `{id, title, decision_sentence, status: live|coming, route}`. All
 * user-facing strings are `t()`-wrapped (TranslatableMarkup) for
 * localization. This class holds no Drupal service dependencies — it is a
 * pure data definition, unit-testable in isolation, in the same shape as
 * `\Drupal\do_chrome\PermissionMatrix`.
 *
 * `coming` entries carry a NULL route (truthful-copy rule: never a dead link
 * to a page that does not exist yet). Only `live` entries carry a route.
 *
 * The `membership-models` entry stays `coming` — request-to-join is bespoke
 * in #121; `drupal/grequest` is incompatible with group 4.0.x-dev (per
 * #136). Do not imply it is live.
 */
final class ShowcaseCatalog {

  use StringTranslationTrait;

  /**
   * The seven required catalog entries (six comparisons + persona switcher).
   *
   * @return array<int, array{id: string, title: \Drupal\Core\StringTranslation\TranslatableMarkup, decision_sentence: \Drupal\Core\StringTranslation\TranslatableMarkup, status: string, route: string|null}>
   *   The catalog entries, in display order.
   */
  public function entries(): array {
    return [
      [
        'id' => 'discovery-ranking',
        'title' => $this->t('Discovery ranking'),
        'decision_sentence' => $this->t('Compares three ways to surface groups: Recent, Hot, Promoted — the decision: how much editorial curation vs. raw recency.'),
        'status' => 'live',
        'route' => 'do_showcase.showcase',
      ],
      [
        'id' => 'directory-presentation',
        'title' => $this->t('Directory presentation'),
        'decision_sentence' => $this->t('Compares list vs. card layouts for the group directory — the decision: information density vs. visual scannability. Not yet built — tracked in issue #124.'),
        'status' => 'coming',
        'route' => NULL,
      ],
      [
        'id' => 'membership-models',
        'title' => $this->t('Membership models'),
        'decision_sentence' => $this->t('Compares open-join vs. request-to-join vs. invite-only — the decision: how much friction gates group membership.'),
        'status' => 'coming',
        'route' => NULL,
      ],
      [
        'id' => 'group-type-homepages',
        'title' => $this->t('Group-type homepages'),
        'decision_sentence' => $this->t('Compares a generic group page vs. a type-tailored homepage — the decision: general-purpose UI vs. per-type customization.'),
        'status' => 'coming',
        'route' => NULL,
      ],
      [
        'id' => 'stream-model',
        'title' => $this->t('Stream model'),
        'decision_sentence' => $this->t('Compares a single combined activity stream vs. per-content-type streams — the decision: one feed to scan vs. filtered feeds.'),
        'status' => 'coming',
        'route' => NULL,
      ],
      [
        'id' => 'private-group-reveal',
        'title' => $this->t('Private-group reveal'),
        'decision_sentence' => $this->t('Compares always-visible groups vs. private groups that reveal membership only after joining — the decision: open discovery vs. member-only privacy. (#134)'),
        'status' => 'coming',
        'route' => NULL,
      ],
      [
        'id' => 'persona-switcher',
        'title' => $this->t('Persona switcher'),
        'decision_sentence' => $this->t('Switch between four public personas to see the demo from each point of view — the decision: one generic anonymous view vs. role-tailored experiences.'),
        'status' => 'live',
        'route' => 'do_showcase.showcase',
      ],
    ];
  }

  /**
   * The four public personas named on the persona-switcher catalog entry.
   *
   * @return array<int, array{id: string, name: string, description: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   The persona list, in display order.
   */
  public function personas(): array {
    return [
      [
        'id' => 'anonymous',
        'name' => 'Anonymous',
        'description' => $this->t("The logged-out visitor's view (default)."),
      ],
      [
        'id' => 'elena-garcia',
        'name' => 'Elena Garcia',
        'description' => $this->t('An active member across several groups.'),
      ],
      [
        'id' => 'maria-chen',
        'name' => 'Maria Chen',
        'description' => $this->t('A group admin/organizer.'),
      ],
      [
        'id' => 'moderator',
        'name' => 'Moderator',
        'description' => $this->t('A site-wide moderation role.'),
      ],
    ];
  }

}
