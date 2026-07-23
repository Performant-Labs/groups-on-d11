<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;

/**
 * #120 SC-1 Persona Switcher — `do_showcase.persona_access` route-level
 * access-check service (brief-amendments.md Amendment 4, A's warn-4/finding-4:
 * "Do NOT rely on an in-controller `if` alone — one refactor slip and the
 * guard is gone").
 *
 * The service (`\Drupal\do_showcase\Access\PersonaAccessCheck`, tagged
 * `access_check`, `applies_to: [_persona_access]`) must deny ANY route
 * parameter that resolves to uid 1 — regardless of what allowlist id was
 * requested — deny an unknown/non-allowlisted persona id, and allow each of
 * the 4 real persona ids, with `anonymous` always allowed regardless of the
 * CURRENT session's own state (switch-back must work from every persona).
 *
 * RED reason: `do_showcase.persona_access` is not a registered service at
 * all yet (do_showcase.services.yml has no such key) and
 * `Drupal\do_showcase\Access\PersonaAccessCheck` does not exist — every test
 * fails on "Service ... not found" / "Class ... not found", not on a wrong
 * AccessResult.
 *
 * @group do_showcase
 */
final class PersonaAccessCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
  }

  /**
   * uid 1's own account name is ALWAYS denied as a switch target, no matter
   * what allowlist entry it happens to collide with — the uid-1 guard is
   * unconditional (brief AC: "uid 1 is unreachable via any masquerade path").
   */
  public function testUidOneTargetIsAlwaysDenied(): void {
    // uid 1 is created implicitly by KernelTestBase's user schema install in
    // some configurations; ensure it exists with a known uname here so the
    // test is not dependent on install order.
    $root = User::create(['uid' => 1, 'name' => 'root_admin', 'status' => 1]);
    $root->enforceIsNew();
    $root->save();

    /** @var \Drupal\Core\Access\AccessInterface $access_check */
    $access_check = \Drupal::service('do_showcase.persona_access');
    $result = $access_check->access(
      \Drupal::routeMatch(),
      \Drupal::currentUser(),
      // The route parameter is the uid-1 account's OWN uname — this is the
      // exact attack the access check must foreclose even if a future
      // allowlist edit accidentally added it.
      'root_admin'
    );

    $this->assertFalse($result->isAllowed(), 'A persona id resolving to uid 1 must NEVER be allowed.');
  }

  /**
   * An unrecognized persona id (not one of the 4 allowlisted ids, and not
   * "anonymous") is denied.
   */
  public function testUnknownPersonaIdIsDenied(): void {
    /** @var \Drupal\Core\Access\AccessInterface $access_check */
    $access_check = \Drupal::service('do_showcase.persona_access');
    $result = $access_check->access(\Drupal::routeMatch(), \Drupal::currentUser(), 'not-a-real-persona');

    $this->assertFalse($result->isAllowed(), 'An unknown persona id must be denied.');
  }

  /**
   * Each of the 4 allowlisted persona ids is allowed.
   *
   * @dataProvider allowlistedPersonaIdsProvider
   */
  public function testAllowlistedPersonaIdIsAllowed(string $persona_id): void {
    /** @var \Drupal\Core\Access\AccessInterface $access_check */
    $access_check = \Drupal::service('do_showcase.persona_access');
    $result = $access_check->access(\Drupal::routeMatch(), \Drupal::currentUser(), $persona_id);

    $this->assertTrue($result->isAllowed(), sprintf('Allowlisted persona id "%s" must be allowed.', $persona_id));
  }

  /**
   * Data provider: the 4 real persona ids.
   *
   * @return array<string, array{0: string}>
   */
  public static function allowlistedPersonaIdsProvider(): array {
    return [
      'anonymous' => ['anonymous'],
      'elena-garcia' => ['elena-garcia'],
      'maria-chen' => ['maria-chen'],
      'moderator' => ['moderator'],
    ];
  }

  /**
   * `anonymous` is always allowed as a switch-back target, regardless of the
   * CURRENT session — i.e. even when the caller is itself a fabricated
   * persona-adjacent authenticated user, switch-back must never be blocked.
   */
  public function testAnonymousIsAlwaysAllowedRegardlessOfCurrentSession(): void {
    $some_user = User::create(['name' => 'some_authenticated_user', 'status' => 1]);
    $some_user->save();
    $this->container->get('current_user')->setAccount($some_user);

    /** @var \Drupal\Core\Access\AccessInterface $access_check */
    $access_check = \Drupal::service('do_showcase.persona_access');
    $result = $access_check->access(\Drupal::routeMatch(), \Drupal::currentUser(), 'anonymous');

    $this->assertTrue($result->isAllowed(), 'The "anonymous" (switch-back) target must always be allowed regardless of current session.');
  }

}
