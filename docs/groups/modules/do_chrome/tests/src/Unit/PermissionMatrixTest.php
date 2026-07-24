<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\PermissionMatrix;
use Drupal\Tests\UnitTestCase;

/**
 * Unit coverage for the #91 (CH-B4) permission matrix definition.
 *
 * Pins the matrix to the ENFORCED community_group roles (verified against
 * docs/groups/config/group.role.community_group-{anon,outsider,insider}_view.yml
 * and .community_group-admin.yml after CH-F4 #95 + #100). If a role config
 * changes the enforced grants, these assertions should be updated in lockstep —
 * that is the point: the matrix must not drift from what is actually enforced.
 *
 * @coversDefaultClass \Drupal\do_chrome\PermissionMatrix
 */
final class PermissionMatrixTest extends UnitTestCase {

  /**
   * The matrix under test.
   */
  private PermissionMatrix $matrix;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // PermissionMatrix uses StringTranslationTrait ($this->t()); UnitTestCase
    // provides a stub translation service that returns the raw string.
    $this->matrix = new PermissionMatrix();
    $this->matrix->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * The four actor columns are present, in least→most-privileged order.
   *
   * @covers ::actors
   */
  public function testActorsColumnsAndOrder(): void {
    $ids = array_column($this->matrix->actors(), 'id');
    $this->assertSame(['anonymous', 'outsider', 'member', 'admin'], $ids);
  }

  /**
   * #133 (SD-6 capstone, honesty sweep — work-list #7): the admin actor's
   * column label must read "Organizer" — the MVP-correct, user-visible role
   * name (brief.md scope item 3: "personas are
   * Anonymous/Member/Organizer/Groups-Moderate"), not the stale "Group
   * admin" label PermissionMatrix::actors() originally shipped.
   *
   * RED reason: `actors()` currently returns `$this->t('Group admin')` for
   * the 'admin' column — this assertion fails until F flips it to
   * `$this->t('Organizer')` (13-item work-list #7).
   *
   * @covers ::actors
   */
  public function testAdminColumnLabelReadsOrganizer(): void {
    $actors = $this->matrix->actors();
    $admin = current(array_filter($actors, static fn (array $a): bool => $a['id'] === 'admin'));
    $this->assertNotFalse($admin, 'The admin actor column must exist.');
    $this->assertSame('Organizer', (string) $admin['label'], 'The admin actor column label must read "Organizer", not "Group admin" (#133 honesty sweep).');
  }

  /**
   * Every row has exactly one cell per actor, with a known state.
   *
   * @covers ::rows
   */
  public function testEveryRowIsFullyPopulated(): void {
    $actor_ids = array_column($this->matrix->actors(), 'id');
    $valid = [
      PermissionMatrix::STATE_YES,
      PermissionMatrix::STATE_NO,
      PermissionMatrix::STATE_NA,
    ];
    $rows = $this->matrix->rows();
    $this->assertNotEmpty($rows);
    foreach ($rows as $row) {
      $this->assertSame($actor_ids, array_keys($row['states']), 'Each row must have one cell per actor.');
      foreach ($row['states'] as $state) {
        $this->assertContains($state, $valid, 'Every cell must carry a known state.');
      }
    }
  }

  /**
   * The enforced cells match the deployed roles (the core anti-drift check).
   *
   * @covers ::rows
   */
  public function testEnforcedCellsMatchDeployedRoles(): void {
    $states = [];
    foreach ($this->matrix->rows() as $row) {
      $states[(string) $row['label']] = $row['states'];
    }

    $y = PermissionMatrix::STATE_YES;
    $n = PermissionMatrix::STATE_NO;
    $na = PermissionMatrix::STATE_NA;

    // Read + view content: everyone (public read-only demo).
    $this->assertSame(
      ['anonymous' => $y, 'outsider' => $y, 'member' => $y, 'admin' => $y],
      $states['View the group']
    );
    $this->assertSame(
      ['anonymous' => $y, 'outsider' => $y, 'member' => $y, 'admin' => $y],
      $states['View group content']
    );

    // Join: only the authenticated non-member (outsider_view has `join group`);
    // anonymous cannot, a member is already in (N/A), admin bypasses.
    $this->assertSame(
      ['anonymous' => $n, 'outsider' => $y, 'member' => $na, 'admin' => $y],
      $states['Join the group']
    );

    // Leave: insider_view grant (members) + admin bypass; N/A for non-members.
    $this->assertSame(
      ['anonymous' => $na, 'outsider' => $na, 'member' => $y, 'admin' => $y],
      $states['Leave the group']
    );

    // Post content: create group_node:* is an insider_view grant only —
    // verified FALSE for outsiders in #95.
    $this->assertSame(
      ['anonymous' => $n, 'outsider' => $n, 'member' => $y, 'admin' => $y],
      $states['Post content']
    );

    // Edit/remove own content: insider_view update/delete-own; admin any.
    $this->assertSame(
      ['anonymous' => $n, 'outsider' => $n, 'member' => $y, 'admin' => $y],
      $states['Edit or remove own content']
    );

    // Manage members: admin bypass only (no `administer members` on any _view
    // role) — members explicitly cannot.
    $this->assertSame(
      ['anonymous' => $n, 'outsider' => $n, 'member' => $n, 'admin' => $y],
      $states['Invite & manage members']
    );
  }

  /**
   * State labels are accessible, human strings.
   *
   * @covers ::stateLabel
   */
  public function testStateLabels(): void {
    $this->assertSame('Yes', (string) $this->matrix->stateLabel(PermissionMatrix::STATE_YES));
    $this->assertSame('No', (string) $this->matrix->stateLabel(PermissionMatrix::STATE_NO));
    $this->assertSame('Not applicable', (string) $this->matrix->stateLabel(PermissionMatrix::STATE_NA));
  }

}
