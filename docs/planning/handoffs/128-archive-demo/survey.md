# #128 SD-3 Archive Demonstrator Seeds — Survey

**Story:** SD-3 (part of Epic #118, self-describing demo).
**Definitive plan (from the issue, no in-issue decisions to make):** redefine the
Legacy Infrastructure seed so it is **PUBLISHED + Archive-typed** — restoring the
runbook semantic `Archived = published + read-only + badge` (distinct from
`Unpublished = hidden`). Add one publicly visible pinned post so the pin badge
renders for anonymous. Fix (don't work around) any test assertions that encode
the old semantic.

## Reuse & Analogous-Feature Map

**Extend/refactor default — every requirement of #128 is satisfied by extending
already-shipped mechanisms.** No new module or component is warranted.

| Requirement                          | Existing mechanism (reuse)                                             | Change kind |
|--------------------------------------|------------------------------------------------------------------------|-------------|
| Archived = published + read-only     | `field_group_type` term "Archive" tagged by `step_720_group_types.php` (already tags Legacy Infrastructure line 101) | REUSE as-is |
| Read-only enforcement                | `DoGroupExtrasHooks::nodeAccess()` denies node `create` when the group's `field_group_type` == "Archive" (line 99-118 of `do_group_extras/src/Hook/DoGroupExtrasHooks.php`) | REUSE as-is |
| Archive CSS chip + tooltip badge     | `DoGroupExtrasHooks::preprocessGroup()` sets `group--archived` class + attaches library; `do_chrome/ArchivePinHooks::preprocessGroup()` renders the real `<span class="group__archived-badge" data-do-tooltip>` (shipped in #92) | REUSE as-is |
| Restore action (Archive → live)      | #143 shipped `/group/{group}/restore` + `RestoreGroupForm` + `RestoreGroupAccess` (in `do_group_extras`) | REUSE as-is |
| Publicly visible pinned post + tooltip | `pin_in_group` flag + `DoGroupPinHooks::preprocessNode()` renders `<span class="pin-badge">Pinned</span>` (do_group_pin); `ArchivePinHooks::preprocessNode()` decorates it with the tooltip (do_chrome) | REUSE as-is |
| Seeded pin                            | `step_700_demo_data.php` line 345-353 already flags "Sprint Planning: Portland 2026" with `pin_in_group` (owner=uid1) | REUSE — the group ("DrupalCon Portland 2026") uses the default `open` visibility → publicly reachable |

**Extend (single-line semantic correction, sanctioned by the issue as "the ONE
non-append seed change"):**

- `docs/groups/scripts/step_700_demo_data.php` lines 397–400: remove `$g->set("status", 0); $g->save();`.
  Legacy Infrastructure remains status=1 (default in the create block, line 83).
  The Archive term applied by `step_720` continues to trigger the badge + read-only.

**Extend (test corrections — enumerated for the PR body):**

- `tests/e2e/group-restore.spec.ts`
  - `findLegacyInfrastructureGid()` (lines 44–69): the T-green comment explains the
    lookup was moved to `/admin/group` because the archive-simulation set status=0
    and hid the group from `/all-groups`. With #128 that reason is gone; simplify
    back to `/all-groups` (anonymous surface — no login needed either, but keep
    the admin login for step-4 edit-form path). Both surfaces still work; prefer
    the anonymous surface because it matches #128's AC-1 assertion path exactly.
  - Lines 86–92 comment block: after #128, node-create denial IS the enforcement
    the story requires. Revisit whether to add a positive assertion that anonymous
    (or a non-Organizer authenticated user) hits 403 attempting content creation.
    Decision to be finalized in T-RED — likely add a positive AC-8 assertion since
    #128 explicitly calls it out ("cannot create content — enforcement, not just
    chrome").
- `tests/e2e/directory-cards.spec.ts`
  - Line 15–16 doc comment "8 groups, one archived, so at least the 7 published
    groups render" → update to "8 groups, one Archive-typed; all 8 published →
    all 8 cards render". The assertion itself (`cardCount > 0`) is soft and
    unchanged.

**New (only where truly analogous doesn't exist):**

- `tests/e2e/demonstrator-seeds.spec.ts` (NEW) — a single anonymous-persona spec
  exercising the two AC bullets: `/all-groups` shows Legacy Infrastructure with
  the Archive badge (+ tooltip presence), clicking in shows the Archived state,
  attempting a content-create route is denied; a stream page shows a pin badge
  (+ tooltip presence). This is the anonymous-visitor demonstrator that #128
  redefines, so it needs its own spec (group-restore.spec.ts is admin-authenticated
  round-trip; directory-cards.spec.ts is generic card rendering).

## Forward-Compat Check

Downstream stories touching same surfaces:

- **#134 SC-7 (Security Team private group, in flight)** — appends a NEW group to
  `step_700_demo_data.php`. Non-adjacent to line 397-400 (the archive block); no
  merge collision.
- **#121 (already merged)** — appended visibility+pending-requests at line 432+
  (Step 790). Non-adjacent.
- **#133 (final honesty sweep, not started)** — the ONLY story allowed to edit
  copy anywhere. #128 does not edit copy (only removes a redundant `set('status', 0)`
  line — no HelpText/tooltip changes).

**No forward-compat conflict.** #128 is the semantic correction that #143's
mechanism was built to demonstrate.

## Key Findings from Reading the Code

1. Legacy Infrastructure is ALREADY:
   - Tagged Archive-typed (`step_720_group_types.php` line 101).
   - Rendering the `group--archived` class + `<span class="group__archived-badge">`
     with tooltip (proven by the group-restore.spec.ts asserting it at line 81).
   - Enforcing node-create denial via `DoGroupExtrasHooks::nodeAccess()`.
   - Being hidden from `/all-groups` **solely because** `step_700` line 400 sets
     `status = 0`. Remove that line and Legacy Infrastructure appears on
     `/all-groups` immediately (the view filters `status = 1`, not
     `field_group_type != Archive` — see `views.view.all_groups.yml` referenced
     in group-restore.spec.ts:49-53).
2. Sprint Planning is ALREADY pinned in DrupalCon Portland 2026 (default `open`
   visibility → publicly visible). AC-2 may already be satisfied by the existing
   seed; T-RED will assert this and — if the pin badge does not render on the
   anonymous-reachable page — the seed needs an additional flag or a stream link
   surfaced to anonymous. Judgment deferred to T-RED which will verify empirically.
3. No config/sync changes needed. No HelpText edits needed (copy already shipped
   in #92 via `HelpText::get('archive.badge')` and `HelpText::get('pin.badge')`).
4. Group creation in `step_700` line 80-92 already writes `status => 1`, and
   `DoGroupExtrasHooks::entityPresave()` short-circuits for `administer group`
   permission (uid 1 owns the seed → keeps status=1). So the sole reason Legacy
   Infrastructure is unpublished is the redundant explicit `set("status", 0)` at
   line 400.

## Environment / Mechanics

- Worktree: `~/Projects/_worktrees/groups-archive-demo` on branch `128-archive-demo`.
- Assemble before verifying: `bash scripts/ci/assemble-config.sh`.
- Kernel & Functional (BrowserTestBase self-installs, no seed): unaffected by
  #128 (no test asserts Legacy Infrastructure unpublished — Kernel `GroupExtrasBehaviorTest`
  tests non-admin group *creation* defaulting to unpublished, which is a
  different code path and stays).
- E2E (runs against seeded site): 2 existing specs touched (see above), 1 new spec.
- Namespaced containers: `gm128-*`.

## Risk / Follow-ups

- **Zero risk of breaking #143** — the restore mechanism reads the Archive term,
  not the `status` field. Round-trip semantics are preserved.
- **Zero risk of copy drift** — no HelpText edits.
- Non-blocking follow-up (already noted in #143 handoffs, not for this PR): the
  site-wide "add content on archived group" enforcement runs through
  `_group_relationship_create_any_entity_access`, which does not call
  `hook_node_access` — so DoGroupExtrasHooks::nodeAccess() protects the node/add
  route but not necessarily the group/{group}/content/create/{plugin} route.
  T-RED will pick the enforcement path that IS active and assert against it.
