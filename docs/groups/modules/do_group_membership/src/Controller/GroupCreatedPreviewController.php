<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The guided-preview landing page after creating a group (#144 MC-6).
 *
 * Content-only route (`do_group_membership.group_created_preview`,
 * `/group/{group}/created`) — the module's first `_controller`-based route
 * (every other route in this module is `_form`, per
 * {@see \Drupal\do_group_membership\Controller\ManageMembersController}'s
 * class docblock). Reached once per group creation via
 * `CreateGroupOrganizerHook::redirectToPreview()`'s post-submit redirect
 * (AC-3).
 *
 * Renders the exact DOM order the approved wireframe specifies
 * (`docs/planning/handoffs/144-auto-organizer/wireframe.md`): h1 -> p -> h2
 * -> ul>li>a x3 — a confirmation heading naming the group, a factual
 * "you're the Organizer" statement, a "What's next?" subheading, and three
 * self-descriptive CTA links (edit group, manage members, view group).
 *
 * The h1 (AC-5's "exactly one h1, first content element") is emitted via
 * `_title_callback` ({@see self::title()}), NOT a `<h1>` in the content
 * render array — the active theme's page-title block already renders the
 * route title as the page's `<h1>` (confirmed empirically, F, #144: a
 * static/dynamic `_title` PLUS an in-content `<h1>` produces TWO `<h1>`
 * elements on the rendered page, violating "exactly one h1"). Routing this
 * through `_title_callback` keeps the group-name-quoting heading text the
 * wireframe specifies while staying to a single h1.
 *
 * DI/access-callback shape mirrors
 * {@see \Drupal\do_group_membership\Controller\ManageMembersController}: a
 * bare `ContainerInjectionInterface`-implementing class (not
 * `ControllerBase`, matching the rest of this module, which has no existing
 * `ControllerBase` precedent) plus a `_custom_access` callback.
 */
class GroupCreatedPreviewController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * Title callback: the page's sole h1 (AC-5), naming the group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The just-created group, upcast from the {group} route parameter.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The page title.
   */
  public function title(GroupInterface $group): TranslatableMarkup {
    return $this->t('Your group "%label" is ready!', ['%label' => $group->label()]);
  }

  /**
   * Renders the guided-preview page content.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The just-created group, upcast from the {group} route parameter.
   *
   * @return array
   *   A render array. The page's h1 comes from {@see self::title()} (the
   *   route's `_title_callback`, rendered by the theme's page-title block);
   *   this array supplies everything AFTER it, in wireframe DOM order:
   *   p, h2, ul>li>a x3.
   */
  public function view(GroupInterface $group): array {
    $label = $group->label();

    $build['organizer_statement'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t("You're the Organizer of this group, which means you can edit its details, manage who joins, and moderate its content."),
    ];

    $build['next_steps_heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t("What's next?"),
    ];

    $build['next_steps'] = [
      '#type' => 'html_tag',
      '#tag' => 'ul',
      '#attributes' => ['class' => ['do-group-membership--next-steps']],
      0 => [
        '#type' => 'html_tag',
        '#tag' => 'li',
        0 => [
          '#type' => 'link',
          '#title' => $this->t('Edit "%label" details', ['%label' => $label]),
          '#url' => Url::fromRoute('entity.group.edit_form', ['group' => $group->id()]),
        ],
      ],
      1 => [
        '#type' => 'html_tag',
        '#tag' => 'li',
        0 => [
          '#type' => 'link',
          '#title' => $this->t('Manage members of "%label"', ['%label' => $label]),
          '#url' => Url::fromRoute('do_group_membership.manage_members', ['group' => $group->id()]),
        ],
      ],
      2 => [
        '#type' => 'html_tag',
        '#tag' => 'li',
        0 => [
          '#type' => 'link',
          '#title' => $this->t('View "%label"', ['%label' => $label]),
          '#url' => Url::fromRoute('entity.group.canonical', ['group' => $group->id()]),
        ],
      ],
    ];

    $build['#attached']['library'][] = 'do_group_membership/group_created_preview';

    // Cache metadata: the rendered links/labels depend on the group entity
    // (its label, id — busts on rename) and on permissions (the same
    // account might lose administer-members access later), per-user (the
    // group-name quoting is identical for every viewer, but the access
    // callback's own cache contexts already vary per-user/permission — this
    // keeps the render array's own metadata consistent with the access
    // result's, mirroring ManageMembersController::access()'s cache-metadata
    // shape).
    $build['#cache'] = [
      'contexts' => ['user.permissions'],
      'tags' => $group->getCacheTags(),
    ];

    return $build;
  }

  /**
   * Access callback for `do_group_membership.group_created_preview`.
   *
   * Allowed for the group's owner (the creator, immediately after landing
   * here via the redirect), OR anyone holding `administer members` on this
   * group (an Organizer/Moderator), OR a site admin
   * (`administer group`) — mirrors
   * {@see \Drupal\do_group_membership\Controller\ManageMembersController}'s
   * `access()` cache-metadata shape.
   *
   * `(string)`-normalized comparison for the owner check: both
   * `AccountInterface::id()` and `EntityOwnerInterface::getOwnerId()` are
   * untyped in core (may return int or string depending on the storage
   * backend), so a strict `===` risks a false negative on a type mismatch —
   * matches the same defensive cast already used in
   * `CreateGroupOrganizerHook::groupRelationshipInsert()`'s owner-equality
   * filter.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group, upcast from the {group} route parameter.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    $access = (string) $account->id() === (string) $group->getOwnerId()
      || $group->hasPermission('administer members', $account)
      || $account->hasPermission('administer group');

    return AccessResult::allowedIf($access)
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
