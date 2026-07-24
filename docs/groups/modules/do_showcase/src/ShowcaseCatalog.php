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
 * #133 (SD-6 honesty sweep): `membership-models`, `group-type-homepages`,
 * and `private-group-reveal` all flip `coming` -> `live` — the underlying
 * features shipped (#121 request-to-join + invite-only gate; #122 group-type
 * homepages; #134 private-group reveal). Request-to-join stayed bespoke in
 * #121 (`drupal/grequest` is incompatible with group 4.0.x-dev, per #136),
 * but the comparison itself is live and demonstrable, not aspirational.
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
        'decision_sentence' => $this->t('Compares list vs. card layouts for the group directory — the decision: information density vs. visual scannability.'),
        // #124 SC-5: the compact/cards toggle ships on /all-groups (the
        // VariantSwitcher build()'d in DoShowcaseHooks::viewsPreRender()),
        // not on this /showcase page itself — the route points AT the live
        // feature, matching how every other `live` entry here links to
        // where its comparison actually renders (handoff-A-plan.md
        // advisory #4: 'view.all_groups.page_1' confirmed as the canonical
        // Views auto-generated route id for /all-groups, cross-checked
        // against PageHelp.php:72 + PageHelpRouteMapTest.php:46).
        'status' => 'live',
        'route' => 'view.all_groups.page_1',
      ],
      [
        'id' => 'membership-models',
        'title' => $this->t('Membership models'),
        // #133 (SD-6 honesty sweep): rewritten to name BOTH axes — join
        // policy (open / request-to-join / invite-only) and privacy
        // (public / unlisted / private, #134) — rather than only the
        // single join-policy axis the original sentence described.
        'decision_sentence' => $this->t('Compares the JOIN-POLICY axis (open / request-to-join / invite-only) alongside the PRIVACY axis (public / unlisted / private) — the decision: how much friction gates membership, and who can see the group at all.'),
        // #121 shipped request-to-join (Moderated) + the invite-only
        // create-access gate — both live and enforced. Visitors can browse
        // and try joining a Moderated or Invite Only group from the
        // /all-groups directory.
        'status' => 'live',
        'route' => 'view.all_groups.page_1',
      ],
      [
        'id' => 'group-type-homepages',
        'title' => $this->t('Group-type homepages'),
        'decision_sentence' => $this->t('Compares a generic group page vs. a type-tailored homepage — the decision: general-purpose UI vs. per-type customization.'),
        // #133 (SD-6 honesty sweep): #122 (SC-3) shipped the type-adapted
        // group lead section — flip live. Routes to the directory, where a
        // visitor can pick a group of any type and see its adapted homepage.
        'status' => 'live',
        'route' => 'view.all_groups.page_1',
      ],
      [
        'id' => 'stream-model',
        'title' => $this->t('Stream model'),
        // ST-8 (#130) / brief.md Amendment 1: flips coming -> live. The
        // OLD decision_sentence ("single combined activity stream vs.
        // per-content-type streams") described a comparison this story
        // does not build — corrected to D's approved copy
        // (handoff-D.md), naming the ACTUAL comparison: the SC-F1
        // switcher + Activity view (live) vs. the Content view (#129,
        // not yet built).
        'decision_sentence' => $this->t('Compares a node-content model vs. an activity-log model for /stream — the decision: a lean feed of raw posts vs. a richer feed that also surfaces comments, flags, pins, and membership events as their own rows.'),
        'status' => 'live',
        // The canonical Views auto-generated route id for /stream, same
        // pattern as 'view.all_groups.page_1' above.
        'route' => 'view.activity_stream.page_1',
      ],
      [
        'id' => 'private-group-reveal',
        'title' => $this->t('Private-group reveal'),
        'decision_sentence' => $this->t('Compares always-visible groups vs. private groups that reveal membership only after joining — the decision: open discovery vs. member-only privacy. (#134)'),
        // #133 (SD-6 honesty sweep): #134 shipped the private-group
        // view-access gate + directory hide — flip live. Persona-switching
        // from the directory reveals the private-group difference.
        'status' => 'live',
        'route' => 'view.all_groups.page_1',
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
   * Extended in #120 (SC-1) with two fields per persona: `uname` (NULL for
   * anonymous; the seeded account name for the other three) and
   * `tooltip_key` (the `\Drupal\do_chrome\HelpText` key this option's native
   * `title=` attribute and the switcher's wrapper-level ⓘ tooltip both read
   * from). Brief-amendments.md Amendment 2 (A blocker #2, resolved): a
   * separate `PersonaRegistry` class was rejected as a parallel path — this
   * method is the single source of truth for the persona list, consumed by
   * `\Drupal\do_showcase\Persona\PersonaSwitcher`,
   * `\Drupal\do_showcase\Access\PersonaAccessCheck`, and
   * `\Drupal\do_showcase\Controller\PersonaSwitchController` via
   * `personaSpec()`.
   *
   * Phase 5-fix (#120 production defect): added a fifth field, `label` — the
   * exact user-visible DISPLAY STRING for this persona ("Anonymous",
   * "Elena Garcia — Member", "Maria Chen — Organizer", "Groups-Moderate").
   * This is deliberately a SEPARATE field from `name` (the persona's plain
   * name/title). Before this fix, `\Drupal\do_showcase\Persona\
   * PersonaSwitcher::optionLabel()` independently `match`-hardcoded this same
   * display string for the `<select>` options, while
   * `DoShowcaseHooks::personaBanner()` read `$persona['name']` directly for
   * the banner copy — two divergent sources for what is supposed to be ONE
   * visible label, which is exactly how the Moderator persona's banner
   * regressed to "You're browsing as Moderator — switch back" instead of the
   * wireframe/AC-locked "You're browsing as Groups-Moderate — switch back"
   * (caught by `tests/e2e/persona-switcher.spec.ts`). `label` is now the
   * SINGLE source of truth both `PersonaSwitcher::optionLabel()` and
   * `DoShowcaseHooks::personaBanner()` read from — data-driven, matching this
   * method's existing `name`/`description` per-field pattern, rather than
   * adding a second method that duplicates the same `match` a second place
   * could silently diverge from again.
   *
   * #133 (SD-6 honesty sweep, work-list #12): `name` for the fourth persona
   * now ALSO reads "Groups-Moderate" (matching `label`) — the stale
   * "Moderator" name/title is gone everywhere it was user-visible, including
   * the `/showcase` tour listing (`ShowcaseController::build()`'s
   * `@name — @description` line), per brief.md scope item 3 ("personas are
   * Anonymous/Member/Organizer/Groups-Moderate").
   *
   * @return array<int, array{id: string, name: string, label: string, description: \Drupal\Core\StringTranslation\TranslatableMarkup, uname: string|null, tooltip_key: string}>
   *   The persona list, in display order.
   */
  public function personas(): array {
    return [
      [
        'id' => 'anonymous',
        'name' => 'Anonymous',
        'label' => (string) $this->t('Anonymous'),
        'description' => $this->t("The logged-out visitor's view (default)."),
        'uname' => NULL,
        'tooltip_key' => 'persona.anonymous',
      ],
      [
        'id' => 'elena-garcia',
        'name' => 'Elena Garcia',
        'label' => (string) $this->t('Elena Garcia — Member'),
        'description' => $this->t('An active member across several groups.'),
        'uname' => 'elena_garcia',
        'tooltip_key' => 'persona.elena',
      ],
      [
        'id' => 'maria-chen',
        'name' => 'Maria Chen',
        'label' => (string) $this->t('Maria Chen — Organizer'),
        'description' => $this->t('A group Organizer.'),
        'uname' => 'maria_chen',
        'tooltip_key' => 'persona.maria',
      ],
      [
        'id' => 'moderator',
        'name' => 'Groups-Moderate',
        'label' => (string) $this->t('Groups-Moderate'),
        'description' => $this->t('A site-wide moderation role.'),
        'uname' => 'groups_moderate_demo',
        'tooltip_key' => 'persona.moderator',
      ],
    ];
  }

  /**
   * Returns a single persona entry by id, or NULL if unrecognized.
   *
   * Single-lookup helper (Amendment 2) so `PersonaSwitcher`,
   * `PersonaAccessCheck`, and `PersonaSwitchController` share one lookup
   * site rather than each re-implementing an `array_column()`/
   * `array_filter()` scan. Returns NULL (never throws, never a partial
   * array) for an unrecognized id — every caller branches on NULL to
   * deny/404.
   *
   * @param string $id
   *   The persona id (e.g. 'maria-chen').
   *
   * @return array{id: string, name: string, label: string, description: \Drupal\Core\StringTranslation\TranslatableMarkup, uname: string|null, tooltip_key: string}|null
   *   The matching persona entry, or NULL if $id is not one of the 4
   *   personas().
   */
  public function personaSpec(string $id): ?array {
    foreach ($this->personas() as $persona) {
      if ($persona['id'] === $id) {
        return $persona;
      }
    }
    return NULL;
  }

}
