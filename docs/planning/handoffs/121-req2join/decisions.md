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
