# OVERNIGHT-CI-FAIL — #140 MC-1 Links & Resources

**PR:** https://github.com/Performant-Labs/groups-on-d11/pull/154
**Branch:** `140-links`
**Parked at:** 2026-07-23, after CI cycle 2
**Parked by:** overnight orchestrator (Opus), per coordinator directive after two CI cycles

## TL;DR for morning triage

**All #140 tests are GREEN in CI cycle 2.** The one remaining E2E failure is `tests/e2e/group-restore.spec.ts` — story **#143 MC-5**'s test, not #140's. #140's own changes cannot cause that assertion to fail. Recommended action: **verify the #143 failure is pre-existing on `main` and unrelated to #140**, then either merge #140 or block until #143's test is fixed on its own PR.

## CI history

### Cycle 1 — commit `b207a09` (pre-fix)
Run: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29986224400
- Kernel: PASS
- Functional: **FAIL** — `GroupAddFormFieldsTest::testAddFormRendersCreationFields` — schema error on `field_group_links` widget settings (`placeholder_url`/`placeholder_title` missing schema). Job: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29986224400/job/89138558014
- E2E: **FAIL** — 2 failures:
  - `group-links.spec.ts:65` (#140) — locator over-matched Olivero footer.
  - `group-restore.spec.ts:72` (#143) — pre-existing, unrelated. Job: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29986224400/job/89138557948

### Cycle 2 — commit `a1a68f6` (fix push)
Run: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29987963390
- Kernel: PASS
- Functional: **PASS** — schema fix worked.
- E2E: **1 failure** (63 passed, 1 failed) — `group-restore.spec.ts:72` (#143), NOT #140. Job: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29987963390/job/89143979611

Verbatim from cycle-2 log:
```
✓  4 [chromium] › tests/e2e/group-links.spec.ts:43:7 › Group Links & Resources (#140 MC-1) › anonymous sees a Links & Resources section with a known seeded link (773ms)
✓  5 [chromium] › tests/e2e/group-links.spec.ts:76:7 › Group Links & Resources (#140 MC-1) › every seeded external link carries rel="noopener" (382ms)
✘  6 [chromium] › tests/e2e/group-restore.spec.ts:72:7 › Group archiving RESTORE action (#143 MC-5) › archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group (12.8s)
...
Error: expect(locator).toHaveCount(expected) failed
       - unexpected value "1"
101 |     await expect(page.locator('.messages--status')).toBeVisible();
102 |     await expect(page.locator('.messages--status')).toContainText(/restored/i);
> 103 |     await expect(page.getByText(/Archived/i)).toHaveCount(0);
```

## What cycle-2 fix (commit a1a68f6) did

1. **Form display YAML** (`docs/groups/config/core.entity_form_display.group.community_group.default.yml`): replaced `settings: { placeholder_url: '', placeholder_title: '' }` with `settings: {  }` for the `field_group_links` `link_default` widget. `LinkWidget::defaultSettings()` already defaults both keys to `''`, so behavior is identical; the strict-schema mode (enabled by BrowserTestBase, not KernelTestBase — which is why T's Phase 6 kernel green never caught it) no longer rejects the config on save. → Functional test PASSED in cycle 2.
2. **E2E locator hardening** (`tests/e2e/group-links.spec.ts`): replaced `.field--name-field-group-links a[href^="http"]` CSS-descendant scope with a role+name lookup over `SEEDED_LINK_TITLES`. Cannot over-match. → #140 E2E tests PASSED in cycle 2.

## Why this was parked (contract literal reading)

Coordinator instruction: "The E2E fix (locator rescope) did not clear the failure … same test fails a second time after fix attempt → PARK."

I complied to preserve the safety guarantee — no `--admin` merge, no force push, no third fix attempt, no re-run. The park is intentionally conservative. Actual state is documented above.

## Why the coordinator's read may have been off

The E2E job in cycle 2 emitted a single failure summary line, which listed `group-restore.spec.ts:72`, not any `group-links.spec.ts` line. The failing test is #143's own archive-restore round-trip. #140 did not touch:
- `tests/e2e/group-restore.spec.ts`
- The RestoreGroupForm, DoGroupExtrasHooks::preprocessGroup archived-branch, `.group--archived*` CSS, or any node/group access logic.
- The `Archived` badge/text render path.

The assertion `await expect(page.getByText(/Archived/i)).toHaveCount(0)` finds one "Archived" text on the group page after restore. #140's diff added nothing that would render "Archived" text on a group page and nothing that would prevent it from clearing on restore.

T's Phase 6 handoff (`handoff-T-green.md`) explicitly recorded this same failure in cycle 0 (local Playwright vs seeded DDEV) as "1 pre-existing unrelated failure in `group-restore.spec.ts` (#143's own story; confirmed via `git diff --stat` that neither that spec nor its production code were touched by #140)." It has been failing before and after #140's diff.

## Recommended morning-triage actions

1. **Confirm #143's test is failing on `main` too** — run the same E2E job against `main` HEAD to establish it's a pre-existing failure, not something #140 introduced. If yes → this PR's own CI is effectively green.
2. **If #143 is pre-existing:** merge #154 with the existing `--admin` / manual-merge posture (or wait for #143 to be fixed on its own PR if the branch protection blocks non-green CI). A human decision — not one the overnight contract wants me to make.
3. **If #143 was NOT failing on `main`:** treat as a #140 regression I could not see, and re-investigate what in the Links & Resources diff could cause the archive-restore round-trip to leave an "Archived" text on the page (I do not have a plausible theory — the render paths are disjoint).

## What a human would need to check to unblock

- **One command:** `gh run list --repo Performant-Labs/groups-on-d11 --branch main --workflow test.yml --limit 3` — is `group-restore.spec.ts:72` red on recent main runs? If yes, this failure predates #140 and is #143's to fix.
- Alternately: `git log --oneline --all -- tests/e2e/group-restore.spec.ts | head -5` — see when the failing test was authored and whether it has ever passed in CI.

## What's already done for #140 (for context)

- Full pipeline complete: O → A(plan PASS) → T(RED valid) → F → T(GREEN 7/7 kernel + 2/2 E2E) → o4-mini diff-gate (2 BLOCKs rejected w/ evidence, 2 WARNs folded) → A-dup(PASS 7/7) → U(PASS all 8 required + both optional) → S(PASS all 10 ACs).
- 12 commits on branch, all source-only, all `Co-Authored-By: Claude Opus 4.7`.
- Kernel: 7/7 story-specific, 118/118 full 11-module suite, zero regressions.
- Functional (post cycle-2 fix): 38/38 including the previously-red `GroupAddFormFieldsTest`.
- E2E (post cycle-2 fix): 2/2 for #140 tests, 63/64 overall (the 1 failure is #143's).

## DDEV cleanup

Optional. `gm140-groups-links` project can be torn down to free resources; worktree stays for triage:
```
ddev stop gm140-groups-links
```
(Not doing this automatically — leaving state as-is so morning-triage can rerun locally if needed.)
