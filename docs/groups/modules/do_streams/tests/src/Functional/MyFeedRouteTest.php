<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Functional;

use Drupal\Core\Config\FileStorage;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\RoleInterface;

/**
 * Behavioral Functional test for the new `/my-feed` route (issue #110, ST-1).
 *
 * Brief acceptance criteria under test (`docs/planning/handoffs/110-stream-110/brief.md`):
 *  - AC-1: anonymous `GET /my-feed` -> 403 or a redirect to the login form.
 *  - AC-2: authenticated `GET /my-feed` -> 200, rendering
 *    `<div class="shell do-streams-shell" data-testid="do-streams-shell">`
 *    (the shared shell theme hook from #109, `DoStreamsHooks::theme()` /
 *    `templates/do-streams-shell.html.twig`).
 *  - AC-3: the shell's `my_feed` scope tab renders `is-active` + `aria-current="true"`.
 *  - AC-4: the shell's `recent` ranking pill renders `is-active`.
 *  - AC-5: a user in exactly 1 group with 1 published node, vs. a SEPARATE
 *    non-member group with its own published node, sees ONLY the member
 *    group's node rendered in the results region.
 *  - AC-6: a user in 0 groups sees the empty state
 *    (`data-testid="do-streams-shell-empty"`) AND the new `empty_cta` slot
 *    (`data-testid="do-streams-shell-empty-cta"`, linking to `/all-groups`).
 *  - Cache: the response varies by viewing user (a Vary/X-Drupal-Cache-Contexts
 *    check spot-checking the `user` cache context bubbles to the outer
 *    response, per handoff-A.md Finding #4).
 *
 * PHASE 6 REPAIR NOTE (T): `testMembershipScopeResultsExcludeNonMemberGroupContent()`,
 * `testResponseVariesByViewingUser()`, and (its first, empty-state-visibility
 * assertion) `testZeroGroupUserSeesEmptyStateWithCta()` originally failed
 * because `views.view.my_feed.yml` is a genuinely SITE-LEVEL config artifact
 * (lives in `docs/groups/config/`, parallel to `activity_stream.yml`/
 * `all_groups.yml` — neither of which is module-shipped either), and
 * `BrowserTestBase`'s fresh per-run install never performs a `config:import`.
 * Without the view existing, `Views::getView('my_feed')` returns NULL and
 * `MyFeedController` gracefully renders the empty shell for every request,
 * masking the real membership-scope/cache assertions under test. This is the
 * SAME documented convention gap `DirectoryFiltersTest`'s own class docblock
 * describes for `all_groups.yml` — fixed the same way: `setUp()` below
 * installs the view from a MODULE-LOCAL fixture copy
 * (`tests/fixtures/config/views.view.my_feed.yml`, a byte-copy of the shipped
 * `docs/groups/config/views.view.my_feed.yml`), mirroring
 * `DirectoryFiltersTest`'s and `StreamsScopeTest`'s own established
 * `FileStorage` + `getStorage('view')->create()->save()` pattern.
 *
 * NONE of `do_streams.routing.yml`, `MyFeedController`, or
 * `views.view.my_feed.yml` exist yet (this story's own survey.md / brief.md
 * name them as NEW files under this story). Every test method below is
 * intended to fail with a route-not-found (404, since Drupal has no matching
 * path) or equivalent error until F wires the route + controller + view —
 * this is the deliberate RED this suite pins.
 *
 * Layer choice: Functional (BrowserTestBase), not Kernel — the story's own
 * spec instructs "if kernel testing controller output is awkward, use
 * BrowserTestBase — it's what the CI job runs anyway" and a real HTTP
 * request/response is the only way to assert the route's access-control
 * behavior (AC-1) end-to-end, mirroring
 * `do_group_membership/tests/src/Functional/ManageMembersRouteAccessTest.php`'s
 * enforcement-on-the-wire pattern.
 *
 * @group do_streams
 * @group do_tests
 */
class MyFeedRouteTest extends BrowserTestBase {

  use GroupTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'views',
    'flag',
    'do_group_pin',
    'do_discovery',
    'do_streams',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The community_group-shaped group type used across every test method.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal\node\Entity\NodeType::load('page')) {
      $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);
    }

    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
      'creator_membership' => TRUE,
    ]);

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rt_storage */
    $rt_storage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
    $rt_storage->save($rt_storage->createFromPlugin(
      $this->groupType,
      'group_node:page',
      ['entity_cardinality' => 0],
    ));

    // Outsider + insider scope roles granting view access on group_node:page,
    // mirroring StreamsScopeTest's setUp() — needed so Group's own access
    // layer does not incidentally mask the membership-scope filter's own
    // exclusion/inclusion behavior (AC-5).
    $permissions = ['view group_node:page relationship', 'view group_node:page entity'];
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
    $this->createGroupRole([
      'group_type' => $this->groupType->id(),
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);

    // views.view.my_feed.yml is a site-level config artifact (parallel to
    // activity_stream.yml/all_groups.yml — never module-shipped), so
    // BrowserTestBase's fresh per-run install never picks it up via
    // config:import. Install it here from a module-local fixture copy,
    // mirroring DirectoryFiltersTest's / StreamsScopeTest's own established
    // pattern for this exact situation.
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $viewData = $fixtures->read('views.view.my_feed');
    $this->assertNotFalse($viewData, 'The views.view.my_feed fixture exists and is readable.');
    \Drupal::entityTypeManager()->getStorage('view')->create($viewData)->save();
  }

  /**
   * Adds a published node to a group via the group_node relationship.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved, published node.
   */
  protected function addPublishedNode($group, string $title) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'page',
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:page');
    return $node;
  }

  /**
   * AC-1: anonymous `GET /my-feed` is denied (403 or login redirect).
   */
  public function testAnonymousGetsDeniedOrRedirectedToLogin(): void {
    $this->drupalGet('/my-feed');
    $status = $this->getSession()->getStatusCode();
    $currentUrl = $this->getSession()->getCurrentUrl();

    $isDenied = $status === 403;
    $isLoginRedirect = $status === 200 && str_contains($currentUrl, '/user/login');

    $this->assertTrue(
      $isDenied || $isLoginRedirect,
      sprintf(
        'Anonymous GET /my-feed must be denied (403) or redirected to the login form; got status %d at %s.',
        $status,
        $currentUrl,
      ),
    );
  }

  /**
   * AC-2, AC-3, AC-4: an authenticated user gets 200 + the shell chrome,
   * with the my_feed tab and recent ranking pill both active.
   */
  public function testAuthenticatedUserSeesShellWithMyFeedAndRecentActive(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/my-feed');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage()->getContent();

    $this->assertStringContainsString(
      'do-streams-shell',
      $page,
      'AC-2: the response HTML contains the shell chrome (data-testid="do-streams-shell").',
    );
    $this->assertMatchesRegularExpression(
      '/data-testid="do-streams-shell"/',
      $page,
      'AC-2: the shell root element carries the data-testid attribute.',
    );

    // AC-3: my_feed tab is active with aria-current="true".
    $this->assertMatchesRegularExpression(
      '/data-scope-id="my_feed"[^>]*aria-current="true"/',
      $page,
      'AC-3: the my_feed scope tab carries aria-current="true".',
    );
    $this->assertMatchesRegularExpression(
      '/class="shell-tabs__item is-active"[^>]*data-testid="do-streams-shell-tab"[^>]*data-scope-id="my_feed"/',
      $page,
      'AC-3: the my_feed scope tab carries the is-active class.',
    );

    // AC-4: recent ranking pill is active.
    $this->assertMatchesRegularExpression(
      '/class="shell-ranking__btn is-active"[^>]*data-testid="do-streams-shell-ranking-pill"[^>]*data-ranking-id="recent"/',
      $page,
      'AC-4: the recent ranking pill carries the is-active class.',
    );
  }

  /**
   * AC-5: a user sees ONLY content from groups they are a member of.
   *
   * The current user is a member of exactly one group (with one published
   * node); a SEPARATE group (with its own published node) exists that the
   * user does NOT belong to. Only the member group's node title may appear
   * in the results region.
   */
  public function testMembershipScopeResultsExcludeNonMemberGroupContent(): void {
    $account = $this->drupalCreateUser();

    $memberGroup = $this->createGroup(['type' => 'community_group', 'label' => 'My Group']);
    $memberGroup->addMember($account);
    $this->addPublishedNode($memberGroup, 'In My Feed Only');

    $otherGroup = $this->createGroup(['type' => 'community_group', 'label' => 'Other Group']);
    $this->addPublishedNode($otherGroup, 'Not In My Feed');

    $this->drupalLogin($account);
    $this->drupalGet('/my-feed');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString(
      'In My Feed Only',
      $page,
      'AC-5: the member group\'s node title appears in the results.',
    );
    $this->assertStringNotContainsString(
      'Not In My Feed',
      $page,
      'AC-5: a non-member group\'s node title must NOT appear in the results.',
    );
  }

  /**
   * AC-6: a user in 0 groups sees the empty state + the new empty_cta slot.
   */
  public function testZeroGroupUserSeesEmptyStateWithCta(): void {
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);

    $this->drupalGet('/my-feed');
    $this->assertSession()->statusCodeEquals(200);

    $page = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString(
      'data-testid="do-streams-shell-empty"',
      $page,
      'AC-6: the empty-state block renders for a user in 0 groups.',
    );

    // PHASE 6 REPAIR NOTE (T): this assertion originally used a single
    // ordered regex requiring data-testid to precede href
    // (`/data-testid="..."[^>]*href="..."/`) . Drupal core's
    // LinkGenerator::generate() (web/core/lib/Drupal/Core/Utility/LinkGenerator.php
    // ~line 154-155) explicitly does `$attributes = ['href' => ''] +
    // $options['attributes'];` with the inline comment "Make sure the href
    // comes first for testing purposes" — core's own #type => link element
    // ALWAYS emits href first, by design. Switched to Mink's
    // elementAttributeContains(), which asserts the attribute's VALUE
    // directly and does not care about source order.
    $this->assertSession()->elementAttributeContains(
      'css',
      '[data-testid="do-streams-shell-empty-cta"]',
      'href',
      '/all-groups',
    );
  }

  /**
   * Cache correctness (handoff-A.md Finding #4): the response bubbles a
   * per-user cache context so two different viewing users are never served
   * each other's cached /my-feed render.
   *
   * Spot-checked via the X-Drupal-Cache-Contexts response header (present
   * when Dynamic Page Cache / internal page cache debugging headers are
   * enabled under BrowserTestBase's default settings) OR, as a fallback,
   * by asserting the SAME URL renders DIFFERENT content for two different
   * users (proving no user-blind cache poisoning even if the debug header
   * is unavailable in this environment).
   */
  public function testResponseVariesByViewingUser(): void {
    $memberAccount = $this->drupalCreateUser();
    $group = $this->createGroup(['type' => 'community_group', 'label' => 'Cache Test Group']);
    $group->addMember($memberAccount);
    $this->addPublishedNode($group, 'Cache Scoped Content');

    $zeroGroupAccount = $this->drupalCreateUser();

    $this->drupalLogin($memberAccount);
    $this->drupalGet('/my-feed');
    $this->assertSession()->statusCodeEquals(200);
    $memberPage = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('Cache Scoped Content', $memberPage);

    $this->drupalLogin($zeroGroupAccount);
    $this->drupalGet('/my-feed');
    $this->assertSession()->statusCodeEquals(200);
    $zeroGroupPage = $this->getSession()->getPage()->getContent();

    $this->assertStringNotContainsString(
      'Cache Scoped Content',
      $zeroGroupPage,
      'A different viewing user (0 groups) must NOT see the first user\'s cached member-scoped content — the render must vary per user (user cache context).',
    );
  }

}
