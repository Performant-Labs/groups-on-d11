# Decisions — #143 archive restore

Append-only.

## O — Phase 1 (survey + brief)

**Decided.**
- Archive mechanism confirmed = `field_group_type` taxonomy term ref, term "Archive". Not a boolean.
- RESTORE ships as a new form + route in `do_group_extras` (module already owns archive enforcement), analogous to `do_group_membership`'s form-per-action pattern.
- Confirmation form extends `ConfirmFormBase` — guarantees real `<button>` submit natively; simplest WCAG-compliant path.
- Target-type on restore: user picks via `<select>` prefilled `Working group`. No shadow field to remember prior type.

**Assumed (to verify in A).**
- The Organizer group role holds `administer group` permission on `community_group` (or an equivalent perm covering group settings). If not, A adjusts the access check.
- BrowserTestBase functional test can self-install `group_type` vocab + Archive term + community_group; verified path since step_720 does this at seed time via entity API (portable).

**Hedged.**
- Whether to add a HelpText tooltip key for the restore button. Default: skip (in-form label + description suffice for POC); reconsider if S flags it.

**Evidence.**
- `docs/groups/scripts/step_720_group_types.php` (term model + widget surfacing).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (Archive detection + enforcement).
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` (badge chrome — leave untouched; will re-render automatically).
- `docs/groups/modules/do_group_membership/{routing.yml,src/Controller/ManageMembersController.php}` (analogous access pattern).
- `docs/groups/config/group.role.community_group-groups_moderate.yml` (synchronized outsider role).
- #78 / #92 / #128 issue bodies read for context.

## D — Phase 2 (wireframe)

**Decided.**
- Wireframe covers exactly 4 surfaces: (1) "Restore group" local task tab, visible only when archived + access-permitted; (2) restore confirmation form at `/group/{gid}/restore` extending `ConfirmFormBase` (question/description/target-type select/confirm/cancel/success/race-error states); (3) WCAG 2.2 AA annotations (labels, `aria-describedby`, tab order, real `<button type="submit">`, empty-vocab guard); (4) round-trip note clarifying re-archive uses the existing group edit form, no new Archive action in this story.
- Tab weight set to `30` (after Members' `20`) so tab order reads View / Edit / Members / Restore group.
- Confirm form mirrors `RemoveMemberForm` exactly: `parent::buildForm()` first, then post-hoc `$form['actions']['submit']['#attributes']` additions (class for Remove, `aria-describedby` for Restore) — no custom markup invented.
- Race-condition state (group un-archived before submit completes) added as an explicit warning-message + no-op-redirect state, since AC-6/AC-9 imply server-side correctness beyond the happy path.
- Empty-vocabulary edge case (all-Archive vocab) specified as a hard refusal-to-render state per instructions, even though step_720 seeding makes it unreachable at runtime.

**Assumed.**
- `ConfirmFormBase`'s default submit action renders as `<button type="submit">` in this theme (matches `RemoveMemberForm` precedent) — flagged for A to double check against a live render rather than re-verified here.
- No custom Escape-key JS is needed (full-page form, not a modal) — listed as an open question rather than decided outright.

**Hedged.**
- Whether a JS Cancel-on-Escape affordance is wanted — left as an open question for human sign-off (Surface 3 / Open questions section).

**Evidence.**
- `docs/groups/modules/do_group_membership/do_group_membership.links.task.yml`, `.routing.yml`, `src/Form/RemoveMemberForm.php`, `src/Controller/ManageMembersController.php` (tab/route/form/access pattern mirrored).
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` (confirmed badge auto-disappears once `field_group_type` no longer resolves to "Archive"; no chrome changes needed).
- Wireframe: `docs/planning/handoffs/143-archive-restore/wireframe.md`.

## O — D-gate approval (2026-07-22)

**Decided.**
- Wireframe APPROVED by operator (via coordinator relay) 2026-07-22.
- Q1 (Escape-key JS): SKIP — matches `RemoveMemberForm` precedent; POC bar. No custom JS ships.
- Q2 (aria-describedby wiring point): flagged for A to validate against live render; not a design-gate concern.

**Evidence.**
- `docs/planning/handoffs/143-archive-restore/wireframe.md` (unmodified from D output).

## O — brief-gate round 1 (2026-07-22)

**Decided.** o4-mini raised 6 BLOCKs; adjudicated against reality (per handoff §12.5 warning about spurious BLOCKs). Result: 4 real gaps folded into the brief's Design outline; 2 rejected as spurious.

- **B-1 access** — real. Pinned exact perm strings (`administer group` group-scope + site-wide), full `AccessResult::allowedIf` shape with cacheability, both non-privileged + non-archived → 403 single denial path.
- **B-2 validation** — partial. Empty-vocab guard added; tampering rejected (Form API validates `#options`); missing-Archive-term out of scope (site-owned vocab); save exception folded into B-5 fix.
- **B-3 403 vs 404** — real. Pinned 403 per MMC convention (404 would leak existence).
- **B-4 AC-8 sequence** — real. Cross-referenced wireframe Surface 4 into AC-8 with explicit 5-step click path.
- **B-5 save failure** — real. try/catch pattern mirroring `RemoveMemberForm::submitForm` added.
- **B-6 DI spec** — spurious. `RemoveMemberForm` sets the DI convention; 1-service form doesn't warrant a separate DI-strategy section. Rejected.

WARN/NIT: W-1 covered by Form API; W-2 covered by Drupal local-task access filtering (verified in `do_group_membership.links.task.yml`); W-3 covered by Playwright per-test contexts; NIT-1 route naming matches project convention; NIT-2 `t()` automatic in `ConfirmFormBase` overrides; NIT-3 id uniqueness guaranteed by form id inclusion.

**Assumed.**
- Drupal Form API's server-side `#options` validation is authoritative against payload tampering (standard Drupal security posture; verified in core).
- The `group` route parameter converter is already registered (used by `entity.group.canonical`, `do_group_membership.*`, etc.).

**Evidence.**
- `docs/planning/handoffs/143-archive-restore/brief-review-r1.md` — o4-mini findings.
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php` (access pattern actual).
- `docs/groups/modules/do_group_membership/src/Form/RemoveMemberForm.php` (DI + submit try/catch pattern actual).

**No round 2.** All 6 BLOCKs adjudicated in this response — 4 folded into brief, 2 rejected with rationale. Advancing to A.

## A — Phase 3 (up-front plan review, 2026-07-22)

**Decided.** Verdict: **BLOCK** on 1 finding (BLOCK), 2 WARNs, 7 NITs. Handoff:
`docs/planning/handoffs/143-archive-restore/handoff-A-plan.md`.

- **BLOCK #1 (perm-string):** brief pins group-scope perm `'administer group'` for restore access.
  Config evidence (`group.role.community_group-organizer.yml`) shows Organizer grants
  `'edit group'` + `'administer members'` — NOT `'administer group'`. As written, AC-1 fails
  (Organizer 403s). Fix: swap group-scope perm to `'edit group'`; keep site-admin escape hatch
  `$account->hasPermission('administer group')`. Groups-Moderate (`admin: true`) covers AC-2
  either way. One-line brief amendment; re-review trivial.
- **WARN #2 (cacheability):** dropping `url.path` is correct for a single-URL surface; group's
  own cache tags (via `addCacheableDependency($group)`) auto-invalidate on `$group->save()` — no
  manual `Cache::invalidateTags()` in submitForm needed. Note to F.
- **WARN #3 (race guard):** double-check in `submitForm` is redundant-but-harmless; correct posture.
- **D-Q2 (aria-describedby wiring point):** GO — post-`parent::buildForm()` mirroring MMC's
  `RemoveMemberForm::buildForm()` line 97 pattern works because `ConfirmFormBase` populates
  `$form['actions']['submit']` before the return. F needs to also set the description paragraph's
  `id` (either via `<p id="…">` wrapper inside `getDescription()` return, or by overriding
  `$form['description']` post-parent). Spec'd in NIT #6.
- **`#type => 'submit'` renders `<button>`:** CONFIRMED for Drupal 10/11 core; theme-agnostic
  (`Submit` element extends `Button`). Survey.md gotcha was inaccurate (D7-era). No override
  needed. #138 GREEN evidence supports.
- **Local-task visibility:** access-callback-driven filtering suffices; no
  `hook_menu_local_tasks_alter` needed (MMC precedent).
- **Anti-duplication:** clean — no overlap with existing `do_group_extras` or `do_chrome` code.

**Assumed.** `admin: true` on group roles grants all group perms implicitly (Drupal `group`
module convention) — this is why Groups-Moderate and community_group-admin both pass regardless
of which specific perm string is chosen. If this assumption is wrong, both `'administer group'`
and `'edit group'` fail equally for those roles and a different mechanism is needed; F can
confirm at test time.

**Hedged.** None.

**Evidence.**
- `docs/groups/config/group.role.community_group-organizer.yml` — perm list (the BLOCK).
- `docs/groups/config/group.role.community_group-groups_moderate.yml` — `admin: true`.
- `docs/groups/config/group.role.community_group-admin.yml` — `admin: true`.
- `docs/groups/config/views.view.pending_groups.yml:68` — `'administer group'` as site-wide perm.
- `docs/groups/modules/do_group_membership/src/{Controller/ManageMembersController.php, Form/RemoveMemberForm.php}` — analogous pattern.
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — Archive-name check pattern.

**Return path.** O amends brief §Design outline (perm string swap + rationale). A re-reviews
(delta-only). No T/F respawn (no code exists yet).

## O — A round 1 adjudication (2026-07-22)

**Decided.** A r1 verdict BLOCK (1 block, 2 warns, 7 nits) — genuine catch: pinned perm string `'administer group'` on the group scope would have 403'd Organizer (AC-1) because `group.role.community_group-organizer.yml` grants `'edit group'` + `'administer members'` only. VERIFIED against config file.

**Fix folded into brief §Design outline / Access:** group-scope check → `$group->hasPermission('edit group', $account)`; site-admin fallback `$account->hasPermission('administer group')` unchanged.

All A r1 WARNs (#2 cacheability, #3 race guard) accepted as-is (both are "no change; note in brief"). All NITs (#4 task key rename to `do_group_extras.restore`, #5 direct EntityTypeManager injection acceptable, #6 aria-describedby id mechanism spec, #7 `#type=>submit` renders `<button>` in D10/11 confirmed, #8 local-task visibility handled by access filtering, #9 test locus correct, #10 anti-duplication clean) folded into brief for F's benefit.

D-Q2 (aria-describedby wiring point) → A r1 NIT-6 answered GO (post-`parent::buildForm()` pattern works); id mechanism specified.

**Assumed.** A r2 will verify the one-line perm swap and pass on the second look. If A r2 raises new BLOCKs, will amend and re-launch; escalation threshold = >2 blocks per handoff §12.

**Evidence.**
- `docs/planning/handoffs/143-archive-restore/handoff-A-plan.md` — full A r1 findings.
- `docs/groups/config/group.role.community_group-organizer.yml` (perm list — confirms BLOCK).
- Existing brief §Design outline (now revised).
