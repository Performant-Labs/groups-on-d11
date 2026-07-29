<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lists the groups the current user belongs to ("My Groups").
 *
 * The main-menu "My Groups" link used to point at `/user` — the visitor's own
 * profile, which shows a group COUNT (via do_profile_stats' contribution
 * stats block) but never lists the groups themselves. A link labelled "My
 * Groups" that lands on a number rather than a list is the kind of copy/
 * destination mismatch #133's honesty sweep exists to catch, so this
 * controller supplies the destination the label always implied.
 *
 * Deliberately a controller rather than a view: the "groups this account is a
 * member of" relationship is a `group_relationship` traversal, which
 * `GroupMembershipLoader` already resolves correctly (including membership
 * status), whereas expressing it in Views config would mean hand-authoring a
 * relationship + filter chain that duplicates that same logic. Rendering
 * reuses the group entity's own `teaser`-style view builder, so cards stay
 * visually consistent with the rest of the site without this controller
 * owning any markup of its own.
 *
 * Two per-card additions on top of that base listing:
 *  - a role badge (`buildRoleBadge()`) naming the CURRENT USER'S actual group
 *    role in THAT group — derived from the membership's own `getMember()`/
 *    `getRoles()` roles, never from the group's owner field. A member
 *    promoted to Organizer after joining shows Organizer; a founder later
 *    demoted no longer does. Text-based (Organizer/Member), so the cue is
 *    never color-alone (WCAG).
 *  - a `?created=1` no-JS GET-param filter (`resolveCreatedOnly()`) narrowing
 *    the list to groups the current user OWNS (`Group::getOwnerId()`).
 *    Deliberately ownership-based, unlike the role badge above — a group's
 *    owner never changes after creation the way a membership role can, so
 *    "groups I created" and "groups where I'm Organizer" are genuinely
 *    different sets (e.g. an admin-created group later handed to another
 *    Organizer still shows under "I created" for the original owner only).
 */
final class MyGroupsController extends ControllerBase {

  /**
   * The GET parameter toggling the "only groups I created" filter.
   */
  private const CREATED_ONLY_QUERY_KEY = 'created';

  /**
   * The group role id whose presence renders the "Organizer" badge.
   *
   * Every membership also carries `community_group-insider_view` (an
   * internal view-access role every member gets automatically) — that role
   * is deliberately NOT checked here, so it never causes a false-positive
   * "Organizer" badge for a plain member.
   */
  private const ORGANIZER_ROLE_ID = 'community_group-organizer';

  public function __construct(
    private readonly Connection $database,
    private readonly Request $request,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  /**
   * Builds the "My Groups" listing.
   *
   * @return array
   *   A render array: the visitor's groups (each with a role badge), or a
   *   truthful empty state naming the concrete next step rather than a bare
   *   "no results". The empty state's copy differs depending on whether the
   *   visitor has zero memberships at all, or the `?created=1` filter simply
   *   matched none of their (non-zero) memberships.
   */
  public function page(): array {
    $created_only = $this->resolveCreatedOnly();
    $account_id = (int) $this->currentUser()->id();

    $groups = $this->loadMyGroups();
    $has_any_membership = $groups !== [];

    if ($created_only) {
      $groups = array_filter($groups, static fn (GroupInterface $group): bool => (int) $group->getOwnerId() === $account_id);
    }

    // Cache per user (the list IS the user's membership) and invalidate
    // whenever any membership changes — joining or leaving a group must
    // change this page immediately. Also varies by the filter's own query
    // argument, so Dynamic Page Cache never serves an unfiltered response
    // for a `?created=1` request (or vice versa).
    $cache = [
      'contexts' => ['user', 'url.query_args:' . self::CREATED_ONLY_QUERY_KEY],
      'tags' => ['group_relationship_list'],
    ];

    if ($groups === []) {
      return [
        'empty' => $this->buildEmptyState($has_any_membership, $created_only),
        '#cache' => $cache,
        '#attached' => ['library' => ['do_group_membership/my_groups']],
      ];
    }

    $view_builder = $this->entityTypeManager()->getViewBuilder('group');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['do-my-groups']],
      '#cache' => $cache,
      '#attached' => ['library' => ['do_group_membership/my_groups']],
      'filter' => $this->buildCreatedOnlyFilter($created_only),
    ];

    foreach ($groups as $group) {
      $build[$group->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['do-my-groups__card']],
        'role_badge' => $this->buildRoleBadge($group, $account_id),
        'group' => $view_builder->view($group, 'teaser'),
      ];
    }

    return $build;
  }

  /**
   * Builds the truthful empty state for the current combination of inputs.
   *
   * @param bool $has_any_membership
   *   Whether the visitor belongs to at least one group, regardless of the
   *   `?created=1` filter.
   * @param bool $created_only
   *   Whether the `?created=1` filter is currently active.
   *
   * @return array
   *   A `.gc-empty` render array with copy matching which of the two empty
   *   cases applies.
   */
  private function buildEmptyState(bool $has_any_membership, bool $created_only): array {
    if ($created_only && $has_any_membership) {
      // The visitor DOES belong to groups, just none they own — a filter
      // "matched nothing" empty state, not a "you have no groups" one (the
      // same distinction af6cce8 fixed for the all_groups directory).
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['gc-empty']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['gc-empty__title']],
          '#value' => $this->t("You haven't created any groups yet"),
        ],
        'text' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['gc-empty__text']],
          '#value' => $this->t('None of the groups you belong to are ones you created. Clear the filter to see all your groups, or start a new one.'),
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Show all my groups'),
          '#url' => Url::fromRoute('do_group_membership.my_groups'),
          '#attributes' => ['class' => ['gc-button', 'gc-button--primary']],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['gc-empty']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['gc-empty__title']],
        '#value' => $this->t('You have not joined any groups yet'),
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['gc-empty__text']],
        '#value' => $this->t('Browse the directory to find a community to join.'),
      ],
      'cta' => [
        '#type' => 'link',
        '#title' => $this->t('Browse all groups'),
        '#url' => Url::fromRoute('view.all_groups.page_1'),
        '#attributes' => ['class' => ['gc-button', 'gc-button--primary']],
      ],
    ];
  }

  /**
   * Whether the `?created=1` "only groups I created" filter is active.
   *
   * A plain GET-param read (`?created=1`) rather than a form submission, so
   * the filter is a real link the browser can follow with no JavaScript —
   * matching `do_showcase`'s `?variant=<id>` switcher precedent
   * (VariantSwitcher::build()'s no-JS fallback links).
   *
   * @return bool
   *   TRUE if the current request's `created` query argument is `1`.
   */
  private function resolveCreatedOnly(): bool {
    return $this->request->query->get(self::CREATED_ONLY_QUERY_KEY) === '1';
  }

  /**
   * Builds the "Only groups I created" no-JS filter control.
   *
   * A single link that toggles the `?created=1` query argument — no form,
   * no JavaScript required, matching this project's no-JS-safe-control rule.
   * Renders as a checkbox-styled control (a `<span>` glyph, never color
   * alone) so it reads as a togglable filter rather than a plain text link,
   * while remaining ordinary `<a href>` navigation underneath.
   *
   * @param bool $created_only
   *   Whether the filter is currently active — the visible check glyph and
   *   the link's `aria-pressed` state derive from this.
   *
   * @return array
   *   A render array for the filter control.
   */
  private function buildCreatedOnlyFilter(bool $created_only): array {
    $href_query = $created_only ? [] : [self::CREATED_ONLY_QUERY_KEY => '1'];

    // Non-color selection cue: a leading checkbox-style glyph that differs
    // by TEXT, not just a class-driven color, mirroring VariantSwitcher's
    // own "●" leading-glyph convention for its selected option.
    $glyph = $created_only ? '☑' : '☐';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['do-my-groups__filter']],
      'link' => [
        '#type' => 'link',
        '#title' => $glyph . ' ' . $this->t('Only groups I created'),
        '#url' => Url::fromRoute('do_group_membership.my_groups', [], ['query' => $href_query]),
        '#attributes' => [
          'class' => array_filter([
            'do-my-groups__filter-toggle',
            $created_only ? 'do-my-groups__filter-toggle--active' : NULL,
          ]),
          'role' => 'checkbox',
          'aria-checked' => $created_only ? 'true' : 'false',
        ],
      ],
    ];
  }

  /**
   * Builds the role badge naming the current user's actual role in $group.
   *
   * Reads the LIVE membership role via `Group::getMember()` (verified
   * against Group 4.x with `drush php:eval`: the method exists and returns
   * FALSE for a non-member, or a `GroupMembership` whose `getRoles()` lists
   * the account's current group roles) — never the group's owner field.
   * A member promoted to Organizer after joining shows Organizer; a founder
   * later demoted from Organizer no longer does, because both read the same
   * live membership state rather than a point-in-time "who created this"
   * fact.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to badge.
   * @param int $account_id
   *   The current user's uid.
   *
   * @return array
   *   A `.gc-badge` render array reading "Organizer" or "Member" — text
   *   carries the meaning, not the badge's color modifier alone (WCAG). An
   *   empty array (no badge) only in the defensive case where the listed
   *   group's membership row cannot be resolved as live membership (should
   *   not occur given loadMyGroups() only lists groups with a membership
   *   row for this account).
   */
  private function buildRoleBadge(GroupInterface $group, int $account_id): array {
    $member = $group->getMember($this->currentUser());
    if ($member === FALSE) {
      return [];
    }

    $is_organizer = FALSE;
    foreach ($member->getRoles() as $role) {
      if ($role->id() === self::ORGANIZER_ROLE_ID) {
        $is_organizer = TRUE;
        break;
      }
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $is_organizer ? $this->t('Organizer') : $this->t('Member'),
      '#attributes' => [
        'class' => [
          'gc-badge',
          $is_organizer ? 'gc-badge--primary' : 'gc-badge--info',
          'do-my-groups__role-badge',
        ],
      ],
    ];
  }

  /**
   * Loads the groups the current user is a member of.
   *
   * Reads the relationship data table directly, mirroring
   * `do_profile_stats`' `ContributionStatsBlock::countGroups()` — Group 4.x
   * ships no `group.membership_loader` service (verified: the container has
   * no such service), so that block's query IS this codebase's established
   * way to resolve "the groups this account belongs to".
   *
   * Filtering is on `gr.entity_id` — the account the membership relationship
   * REFERENCES — not `gr.uid`, which in Group 4.x is the membership record's
   * AUTHOR. Filtering `uid` would list groups whose membership rows the user
   * happened to author rather than the groups they belong to, returning
   * nothing for a member an admin added (the same trap issue #63 fixed in
   * the stats block).
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups, keyed by group id.
   */
  private function loadMyGroups(): array {
    $gids = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['gid'])
      ->condition('gr.entity_id', (int) $this->currentUser()->id())
      ->condition('gr.type', '%group_membership', 'LIKE')
      ->distinct()
      ->execute()
      ->fetchCol();

    if ($gids === []) {
      return [];
    }

    $groups = [];
    foreach ($this->entityTypeManager()->getStorage('group')->loadMultiple($gids) as $group) {
      // Respect access: a membership row does not by itself entitle the
      // viewer to see the group (e.g. it was archived/unpublished after they
      // joined), so each is access-checked before it is listed.
      if ($group instanceof GroupInterface && $group->access('view')) {
        $groups[$group->id()] = $group;
      }
    }
    return $groups;
  }

}
