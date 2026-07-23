# Handoff-U: Phase 8 - #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer
**Issue:** #144

## Setup status: fully set up (with two empirical fixes required)

Fresh DDEV project built from this worktree (no prior gm144-auto-organizer project existed):

1. .ddev/config.yaml renamed pl-groups-on-d11 to gm144-auto-organizer (session-local, reverted
   after -- see Cleanup below). ddev start succeeded (vendor/ and web/core/ were already
   present in the worktree, gitignored per convention).
2. bash scripts/ci/assemble-config.sh -- copied 95 config files + 13 custom do_* modules.
3. drush site:install -y (plain, not --existing-config; the latter failed --
   sites/default/files/sync had no core.extension.yml).
4. Empirical fix #1: config_sync_directory was unset in web/sites/default/settings.php
   (only commented out), so the DDEV settings.ddev.php fallback pointed drush cim at
   sites/default/files/sync instead of the repo config/sync/ (per RUNBOOK.md:152-170, this
   line must be added explicitly before the ddev include). Added
   settings[config_sync_directory] = ../config/sync; to the gitignored settings.php --
   confirmed gitignored via git check-ignore, so no repo change results.
5. Empirical fix #2: first drush cim -y then failed on Site UUID in source storage does not
   match the target storage (expected when a fresh site:install generates its own UUID against
   a config/sync baked with a fixed one). Fixed via drush config:set system.site uuid <uuid> -y
   before re-running cim -- standard drush practice for this exact situation, not a code defect.
6. drush cim -y succeeded cleanly after both fixes.
7. Granted admin (uid 1) the administrator role and set a known password
   (drush user:password admin ...) -- site admin already satisfies create community_group group
   permission, no persona seed scripts were needed.
8. npm install plus confirmed Playwright bundled Chromium already available; drove the site with a
   throwaway Node script (chromium.launch plus real assertions), not the Playwright MCP tool
   (equivalent driving mechanics -- same real headless browser, same DOM/console/network
   inspection).

No blockers. Site reachable at https://gm144-auto-organizer.ddev.site (HTTP 200 on /user/login).

## Walkthrough results

### Walk-1 (AC-3)
- Navigated to /group/add/community_group -- renders as a single-page form, not a
  multi-step wizard (confirms F empirical finding: creator_wizard: true only enhances the
  single form, it does not add wizard steps/URLs).
- Filled Title. Description field required server-side even though the client-side required
  HTML attribute does not survive CKEditor DOM replacement of the textarea (confirmed via a
  direct submit-without-description probe: server returned Description field is required. twice
  in the error region) -- filled via .ck-editor__editable contenteditable, matching
  manage-members.spec.ts established convention. This confirms T-green flagged E2E-spec gap
  (missing description fill) is real and must be fixed in create-group.spec.ts before it will
  pass against a seeded site -- not a UI/production defect, a test-authoring gap.
- Submitted via Create Community Group and become a member button.
- Landed on /group/1/created (not canonical) -- AC-3 PASS.
- Screenshot: screenshots/walk1-after-submit.png.

### Walk-2 (AC-4, AC-5)
Scoped all heading/DOM-order checks to the main landmark (unscoped queries pick up admin-theme
toolbar/nav h2s and contextual-links uls -- expected admin-theme noise, not page content).

- Exactly one h1 in main: Your group "UI Walkthrough Group ..." is ready! -- PASS.
- Exactly one h2: What is next? -- PASS.
- Zero h3-plus elements in main -- PASS (no heading skip).
- DOM order in main: h1 then p (You are the Organizer of this group...) then h2 then ul>li>a x3,
  exactly matching the wireframe specified order -- PASS.
- Three CTA links, each repeating the group name plus descriptive action, none generic:
  - Edit "UI Walkthrough Group ..." details
  - Manage members of "UI Walkthrough Group ..."
  - View "UI Walkthrough Group ..."
  No click here or read more -- PASS.
- Tab order: first Tab lands on Skip to main content (the theme standard skip link) --
  correct/expected Drupal admin-theme behavior; satisfies focus lands sensibly per the
  wireframe own note that the h1 being the first content element (after standard landmarks)
  is sufficient, no JS focus-forcing needed -- PASS.
- Screenshot: screenshots/walk2-full-page.png. Mobile (360px): screenshots/walk2-mobile-360.png
  -- page content (h1/p/h2/CTAs) renders correctly at narrow width; the admin toolbar own
  hamburger menu was mid-transition/expanded in that particular screenshot due to the viewport
  resize event (a Drupal core admin-toolbar behavior, unrelated to this story own template/CSS,
  not a defect in the walked feature).

### Walk-3 (AC-7)
- Clicked Manage members CTA, landed on /group/1/members -- PASS.
- Member table shows admin with Role(s) column: community_group-admin, Organizer --
  confirms the additive grant (AC-1 backend concern) is visible end-to-end in the UI -- PASS.
- Screenshot: screenshots/walk3-members.png.

### Walk-4 (bonus, A-3-optional status message)
- On the initial landing (Walk-1 screenshot), a Drupal status message is present:
  Community Group [name] has been created. plus Your group [name] was created. You are the
  Organizer. -- the optional messenger call F included is working. Not a blocker either way per
  the brief; noted as present.

## Console / network

- Console errors: none observed across the full walk (login, create-group submit, preview
  page, manage-members page, direct re-navigation to /created).
- Network: re-fetched /group/1/created directly -- 200. No failed requests observed
  during the scripted walk.

## Issues found

None blocking. One non-blocking, already-flagged item confirmed real (not newly discovered):

- tests/e2e/create-group.spec.ts create-group step does not fill the CKEditor description
  field (it only checks for a plain textarea name field_group_description 0 value, which is
  invisible once CKEditor attaches) -- per T-green own flag, this spec will stall/fail on a real
  seeded site until it is updated to fill via .ck-editor__editable, mirroring
  manage-members.spec.ts pattern. This is a test-authoring gap, not a UI/production defect
  -- the actual production UI/behavior is correct, confirmed by this walkthrough driving the same
  real form successfully once the CKEditor fill was used.

## Verdict: PASS

AC-3, AC-4, AC-5, AC-7 (the UI-visible acceptance criteria in scope for U) all verified directly
against a real browser session on the real assembled config. No console errors, no network
failures, heading hierarchy and link-text conform to the wireframe exactly, and the end-to-end
Organizer-role visibility (AC-1 UI-facing proof) is confirmed on the Manage Members page.

Recommendation for downstream: before/during S or a future E2E run, create-group.spec.ts
should be updated to fill the CKEditor description field (as flagged by T-green and re-confirmed
here) -- this is a test-fix, not a rework request against F implementation.

## Cleanup performed

- ddev stop (project left registered, not deleted, matching F/T cleanup pattern).
- .ddev/config.yaml reverted to tracked name: pl-groups-on-d11.
- Throwaway walkthrough/inspection scripts (.u-walk.mjs, .u-inspect.mjs, .u-inspect2.mjs,
  .u-scoped-check.mjs, .u-report-gen.py, .u-append.py) deleted.
- config/sync/* and web/modules/custom/* changes from assemble-config.sh NOT staged/reverted
  by me (matches the constraint -- O handles staging/reversion).
- node_modules/ (from npm install) left in place as a gitignored build artifact (not staged).
