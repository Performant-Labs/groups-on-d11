# OVERNIGHT-CI-FAIL — #140 MC-1 Links & Resources

**PR:** https://github.com/Performant-Labs/groups-on-d11/pull/154
**Branch:** `140-links`
**Parked at:** 2026-07-23, after CI cycle 2
**Parked by:** overnight orchestrator (Opus), per coordinator directive

## Correction to prior version of this note

Prior version claimed the failing test was "pre-existing on main." That was **wrong** — coordinator verified `main`'s most recent run (`29981652784`, sha `9ca8813`, the #121 merge) is fully GREEN including `group-restore.spec.ts`. I misread T's Phase 6 note (T reported a local-DDEV-state failure that never reflected true `main` CI). Corrected diagnosis below.

## TL;DR for morning triage

**#140's own tests are GREEN in CI cycle 2** (Kernel + Functional pass; both `group-links.spec.ts` E2E tests pass). The single E2E failure is `tests/e2e/group-restore.spec.ts:103` (story #143 MC-5) and it is a **cross-story collision caused by #140's new Full display now surfacing the word "Archived" on Legacy Infrastructure's group page** — see root cause below. #143's page-wide `getByText(/Archived/i).toHaveCount(0)` locator is too loose to survive the new content this story adds to group pages. This is the wave-design gotcha the handoff §6.7 cautions about: "Fix the defect class."

## CI history

### Cycle 1 — commit `b207a09`
Run: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29986224400
- Kernel: PASS
- Functional: **FAIL** — schema error on `field_group_links` widget settings.
- E2E: **FAIL** — `group-links.spec.ts:65` (locator over-match) + `group-restore.spec.ts:103`.

### Cycle 2 — commit `a1a68f6` (schema + E2E-locator fix)
Run: https://github.com/Performant-Labs/groups-on-d11/actions/runs/29987963390
- Kernel: PASS
- Functional: **PASS** (schema fix worked)
- E2E: **1 failure** (63 passed, 1 failed) — `group-restore.spec.ts:72/103` only.

Verbatim assertion:
```
✓  4 [chromium] › tests/e2e/group-links.spec.ts:43:7 › Group Links & Resources (#140 MC-1) › anonymous sees a Links & Resources section with a known seeded link (773ms)
✓  5 [chromium] › tests/e2e/group-links.spec.ts:76:7 › Group Links & Resources (#140 MC-1) › every seeded external link carries rel="noopener" (382ms)
✘  6 [chromium] › tests/e2e/group-restore.spec.ts:72:7 › Group archiving RESTORE action (#143 MC-5) › archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group

Error: expect(locator).toHaveCount(expected) failed — unexpected value "1"
101 |     await expect(page.locator('.messages--status')).toBeVisible();
102 |     await expect(page.locator('.messages--status')).toContainText(/restored/i);
> 103 |     await expect(page.getByText(/Archived/i)).toHaveCount(0);
```

### Baseline: main's most recent CI is GREEN
Coordinator-verified run: `29981652784` (sha `9ca8813`, #121 merge) — all 3 checks (Kernel, Functional, E2E) green including `group-restore.spec.ts`. The #143 test WAS green on `main`; #140's diff broke it.

## Actual root cause

**#140's new Full display for `community_group` renders `field_group_description` with `label: hidden` at weight 0** — see `docs/groups/config/core.entity_view_display.group.community_group.default.yml` lines 25–31. Before #140, no Full display config file existed for `community_group`, so what rendered on `/group/{gid}` depended on Drupal's implicit defaults for the entity — a much smaller footprint of text on the page.

The seeded Legacy Infrastructure group (`step_700_demo_data.php` line 75) has its `field_group_description` = **"Archived: Drupal 7 module maintenance coordination. This group is no longer active."** — literal string starting with "Archived:".

After #140, this description renders on `/group/{gid}` for every community_group. On Legacy Infrastructure, it puts the word "Archived" on the page as body copy, unrelated to the archive/restore state.

`tests/e2e/group-restore.spec.ts:103` asserts:
```ts
await expect(page.getByText(/Archived/i)).toHaveCount(0);
```
This is a **page-wide, case-insensitive substring match** intended to prove the archive badge is gone after restore. It has no scope to the badge element itself, so it now catches "Archived:" inside the description body copy that #140 caused to render.

**Note re: coordinator's original theory.** The coordinator's diagnosis-message hypothesis was "Links seed adds items on Legacy Infrastructure." Verified via `docs/groups/scripts/step_700_demo_data.php` line 483–508: #140's seed sets `field_group_links` ONLY on DrupalCon Portland 2026, Core Committers, and Thunder Distribution — **not** on Legacy Infrastructure. So it's not the Links field per se; it's the Full display now rendering `field_group_description` (which #140 authored the display for the first time), and that description happens to start with the word "Archived". Same conclusion (cross-story collision from #140's diff), different exact mechanism (rendered description text, not a Links list item).

## Two options for morning triage (no preference expressed here)

### Option A — Tighten #143's locator (correct per handoff §6.7 "Fix the defect class")
Change `tests/e2e/group-restore.spec.ts:103` from the loose page-wide text match to a scoped assertion — or just delete it, since line 104 already does the badge-only check:
```ts
await expect(page.locator('span.group__archived-badge, .group--archived')).toHaveCount(0);
```
- **Pros:** the test then asserts what it means to assert (the badge is gone), not "no page content contains 'Archived'." Robust against any future story adding neutral text on group pages.
- **Cons:** it's a change to #143's spec file, made via #140's PR. Cross-story overreach — the kind of thing the overnight contract avoids.
- **Should not be made inside PR #154.** File as a separate small PR against `main`.

### Option B — Reshape #140's demo seed to sidestep the collision
Change Legacy Infrastructure's description in `docs/groups/scripts/step_700_demo_data.php:75` to remove the word "Archived:" from body copy — e.g. "Drupal 7 module maintenance coordination. This group is no longer active." (drop the leading label, since the archived-state is already visually conveyed by the badge + CSS class).
- **Pros:** scoped entirely to #140's PR. Small diff. Merges cleanly.
- **Cons:** workaround, not a fix. The next story that adds content mentioning "Archived" anywhere on group pages will trip #143's test again.

Handoff §6.7 argues (A) is the right answer. Merge posture argues (B) is the low-risk unblock. Human decides.

## What a human would need to check to unblock

- Read this note.
- Pick A or B (or "hold this PR until we do A on a separate PR").
- If A: open a tiny PR against `main` fixing `group-restore.spec.ts:103` (~1 line change), merge that, then re-run CI on #154 (or rebase #154 first). CI will then be fully green on #154.
- If B: one-line edit to `step_700_demo_data.php:75`, commit + push to `140-links`, third CI cycle should be green.

## What's already done for #140 (for context)

- Full pipeline complete: O → A(plan PASS) → T(RED valid) → F → T(GREEN 7/7 kernel + 2/2 E2E) → o4-mini diff-gate (2 BLOCKs rejected, 2 WARNs folded) → A-dup(PASS) → U(PASS) → S(PASS all 10 ACs).
- 13 commits on branch (12 code/handoff + this note), all source-only.
- CI cycle-2 result: Kernel 118/118, Functional 38/38, E2E 63/64 (the 1 failure is the cross-story collision above).

## DDEV cleanup

Left `gm140-groups-links` running for triage. Tear down manually if desired:
```
ddev stop gm140-groups-links
```
