# Decisions — #121 SC-2 Membership models enforced

Append-only. Every phase adds an entry (Decided / Assumed / Hedged / Evidence).
Orchestrator writes the closing Chain Summary at post-merge sweep.

## Phase 1 — Orchestrator: survey + brief (2026-07-22)

**Decided:**
- Slug `121-req2join`; worktree `~/Projects/_worktrees/groups-req2join`; branch `121-req2join` off `origin/main`.
- Namespace containers `gm121-*` if any spawned (E2E job.)
- HelpText edit lands first in this story (this story is the HelpText serialization leader per WAVE-EXECUTION-HANDOFF §3).
- Test personas for the join flow: `sophie_mueller`, `alex_novak` (NOT Elena — she is already a member of both Leadership Council and Core Committers per `step_700_demo_data.php` lines 99, 101).
- Request-to-join is **bespoke** on the customized `group_membership` relationship (status pending → active/deleted). No `grequest` (unusable on Group 4.0.x).
- Manager reuse: `GroupMembershipManager::approvePending()` / `denyPending()` / `addMember()` already exist in `do_group_membership`. New request flow will call a NEW `requestJoin()` method on the same manager (extend, not new class) that creates a `group_membership` relationship with `field_membership_status = pending` and no `group_roles`. Approval reuses `approvePending()`; denial reuses `denyPending()`.

**Assumed (to verify with A):**
- Two-axes reconciliation for THIS story: the existing single `field_group_visibility` field carries both axes for now — `open` = public+open-join, `moderated` = public+request-join, `invite_only` = visible+closed-to-join. Private/unlisted (visibility=private/unlisted axis) is deferred to #134. This preserves back-compat with #95, HelpText #88 copy shape, and the seeded fixtures. If A rejects this, escalate — a full two-field split is a scope-cap that should not land in #121.
- Seed edit: append-only `field_group_visibility` assignments for Leadership Council (`moderated`) and Core Committers (`invite_only`); one pending request seeded (sophie_mueller → Leadership Council). Other groups stay `open` (default_value already `open`).
- E2E persona flow: use existing seeded logins (sophie/alex) rather than fresh registration.

**Hedged:** Group access enforcement path for `invite_only` (no `join group` grant → the outsider_view role's `join group` permission is stripped/gated per-visibility). Two candidate paths: (a) split into two group roles + assign per visibility (heavy), or (b) hook_group_access alter on `group_membership.create` gated on the group's `field_group_visibility` (lighter). Recommend (b) — matches "extend not new" and does not touch the shared outsider_view role config. Flagging for A.

**Evidence:**
- `gh issue view 121` re-read at survey start.
- `docs/groups/modules/do_chrome/src/HelpText.php` lines 84–87 (current visibility copy).
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` lines 71–73 (assertions that turn RED).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (manager already carries approvePending/denyPending — reuse).
- `docs/groups/scripts/step_700_demo_data.php` lines 99 + 101 (Elena is member of both closed groups — cannot exercise flows).
- `docs/groups/config/group.role.community_group-outsider_view.yml` (holds `join group` grant — the surface to gate).

## Phase 1b — Orchestrator: brief-gate dual-review (2026-07-22)

**Decided:**
- Coordinator direction executed: composite `field_group_visibility` retained; axis-independence AC deferred to #134 (documented in PR body).
- Ran `dual-review.sh --mode brief` (o4-mini). Round 1 = BLOCK (5 findings).
- Amended brief via `brief-response.md` addendum §§1–6: hook mechanism named (`hook_group_relationship_create_access`), organizer defined (`community_group-organizer` + `administer members`), added AC-15/AC-16 (approve/deny access guards), AC-4 extended (approval assigns no roles), seed idempotency spelled out with existence guards.
- Round 2 = **PASS** — all 5 BLOCKs ACCEPTED by reviewer.

**Assumed (to verify with A):**
- `hook_group_relationship_create_access()` is the correct extension point on Group 4.0.x for gating create. A should validate against the module's actual hook list.

**Hedged:** none — addendum is authoritative and A operates on brief + brief-response together.

**Evidence:**
- `docs/planning/handoffs/121-req2join/dual-review-brief.md` (Round 1 — 5 BLOCK).
- `docs/planning/handoffs/121-req2join/brief-response.md` (adjudication + addendum).
- `docs/planning/handoffs/121-req2join/dual-review-brief-r2.md` (Round 2 — PASS).
- `docs/groups/config/group.role.community_group-organizer.yml` L16 (`administer members` grant confirms B-2 resolution).

## Phase 3 — Architecture Reviewer: plan review (2026-07-22)

**Decided:**
- Verdict: **BLOCK**. Three findings; the plan must be amended before T writes tests.
- A-1 (block): The "pending requests queue" the plan proposes as a NEW `/group/{group}/members/pending` route + local task + `ManageMembersController::pendingQueue()` + `PendingRequestActionForm` is a parallel path — `ManageMembersForm` already renders pending memberships with Approve/Deny row buttons wired to `approvePending`/`denyPending` (see `ManageMembersForm.php:70,134-136,214-234,296-329`). Fix: fold the queue back into the existing surface; re-scope AC-4/AC-15/AC-16 to `/group/{group}/members`.
- A-2 (block): Brief-response §2's claim that the new route uses `_group_permission: 'administer members'` is factually wrong about the "same gate" — every existing `do_group_membership.*` route uses `_custom_access` referencing `ManageMembersController::access` (with a site-admin escape hatch + explicit cache metadata). Fix: correct the wording; new routes reuse `_custom_access` idiom.
- A-3 (block): Manager surface `countPendingFor`/`getPendingFor` proposed in survey has no consumer once A-1 is applied (ManageMembersForm reads via `$group->getMembers()`). Fix: trim to `requestJoin` + `joinPolicyFor` only.
- WARNs recorded (not blocking): W1 factor a shared `createMembership` helper on the manager to prevent `requestJoin`/`addMember` divergence; W2 F must verify `hook_group_relationship_create_access` signature against `vendor/drupal/group` at implementation; W3 `visibility.field` copy must retain view-vs-join distinction; W4 source-only path guard rail confirmed.
- NIT: consider seeding a second pending relationship (alex_novak) so the queue demos >1 row.

**Assumed:**
- Group 4.0.x-dev exposes `hook_group_relationship_create_access` at the signature the addendum implies (accepted on R2 dual-review, but not verifiable from source-only worktree — flagged W2).
- No wireframe artifact exists yet; the plan is UI-simple enough that D-phase output can follow after the amendment. Not blocking.

**Hedged:**
- If the wireframe (once produced by D) genuinely requires a distinct "pending only" landing surface as an organizer shortcut, the correct extension is a query-string filter on the existing route (`/group/{group}/members?status=pending`) or a Views filter — NOT a second controller. That would be reviewed as a WARN, not a BLOCK, at that time.

**Evidence:**
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,134-136,214-234,296-329` (pending queue already live inside the existing form).
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` (all four routes use `_custom_access`, none use `_group_permission`).
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:38-45` (shared access callback + cache metadata contract).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:36-297` (manager cadence — state-transition verbs; `approvePending`/`denyPending` already exist and are already called from `ManageMembersForm`).
- `docs/groups/modules/do_chrome/src/HelpText.php:84-87` (visibility copy target — reuse map correct).
- `docs/groups/config/field.storage.group.field_group_visibility.yml:12-23` (composite values retained per coordinator direction).
- Full findings: `docs/planning/handoffs/121-req2join/handoff-A-plan.md`.

## Phase 3 — Architecture Reviewer + Orchestrator amendment (2026-07-22)

**A verdict:** BLOCK (3 findings) — see `handoff-A-plan.md`.

**Decided (O adjudication):**
- A-1 ACCEPTED: parallel pending-queue surface DELETED from plan. Organizer surface = existing `ManageMembersForm` on `/group/{group}/members` (already renders pending rows with Approve/Deny — verified `ManageMembersForm.php:70,80,214-234,296-329`).
- A-2 ACCEPTED: new request-join route uses `_custom_access` (peer `ManageMembersController::requestJoinAccess()`), matching the established idiom (`routing.yml` — all 4 existing routes use `_custom_access`). No `_group_permission` YAML anywhere.
- A-3 ACCEPTED: manager surface trimmed to `requestJoin` + `joinPolicyFor` only. `countPendingFor` / `getPendingFor` dropped (no consumer).
- A-W1 recorded: F factors a private `createMembership()` helper shared by `addMember` + `requestJoin`.
- A-W2 recorded: F verifies hook signature in `vendor/drupal/group/`; T asserts on behavior, not hook name.
- A-W3 recorded: `visibility.field` copy must retain view/join distinction; HelpTextTest asserts it.
- NIT accepted: seed a SECOND pending row (alex_novak → Leadership Council) for row-action isolation demo.

**Amendment authoritative:** `brief-response-v2.md` supersedes conflicting text in prior brief/survey/response docs. Corrected file-touch list is in v2 §"Files to touch after amendment".

**Assumed (to verify with A on re-review):**
- No further duplication surface remains once the pending-queue route is deleted. A re-review will confirm.

**Hedged:** none.

**Evidence:**
- `docs/planning/handoffs/121-req2join/handoff-A-plan.md` (A's full report).
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,80,214-234,296-329` (existing pending-row surface — the parallel path A-1 avoids).
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` (established `_custom_access` idiom).
