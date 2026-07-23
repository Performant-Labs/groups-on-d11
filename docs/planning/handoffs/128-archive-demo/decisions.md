# #128 — Decision Journal

## Phase 1 (O): brief written, D skipped

- **Decided:** skip D. #128 is a seed semantic correction; the visual (Archive
  badge/tooltip + Pin badge/tooltip + Restore action) shipped in #92/#143 and is
  unchanged. Nothing new to design.
- **Decided:** skip brief-gate o4-mini review (POC lean, "none" rigor). #128 is
  spec-definitive — no plan choices to arbitrate.
- **Assumed:** Sprint Planning post's pin badge already renders on some anonymous-
  reachable page. T-RED will empirically verify and, if false, seed additional
  pin/surface. (Not blocking A up-front — A reviews the plan, T proves the
  premise.)
- **Assumed:** DoGroupExtrasHooks::nodeAccess() is the effective enforcement path
  for at least one anonymous- or non-Organizer-reachable content-create URL on an
  Archive-typed group. T-RED will pick whichever route holds; if none is
  reachable, will document with #143's non-blocking follow-up and re-scope AC-1c
  to "badge visibility as sole observable" (matching #143's PR-time posture).
- **Evidence:** survey.md sections "Key Findings" + "Reuse & Analogous-Feature
  Map"; issue #128 body; step_720:101; step_700:397–400.

## Phase 3 (A): up-front plan review — PASS

- **Decided:** PASS. Plan is a strict extension of shipped mechanisms; no
  parallel-path or new-object smell. Reuse map is sound across all six
  requirements. Test plan covers all six ACs. Idempotency preserved by the
  existing `loadByProperties` guard at step_700:78–79. #134 non-adjacent to the
  edit region. No runtime forward-compat consumer of the `status=0` on Legacy
  Infrastructure — only two doc references in RUNBOOK.md.
- **Assumed:** RUNBOOK.md:2638 and :2800 (which still describe Archive as
  "set to unpublished / status=0") are documentation drift that belongs to #133
  (final honesty sweep) or a spin-off follow-up, not to #128 — the brief's
  non-goals explicitly forbid copy edits in this story. Flagged as `warn`
  finding, not `block`.
- **Assumed:** AC-1c enforcement-path fallback (badge-visibility as sole
  observable if neither create route is anonymously-denied) is pre-authorized by
  the Phase 1 decision above; A does not need to re-litigate at T-RED time.
- **Evidence:** brief.md; survey.md; step_700_demo_data.php:78–92 and 397–400;
  step_720_group_types.php:101; DoGroupExtrasHooks.php:64, 80–94, 99–118;
  views.view.all_groups.yml:83–91; grep for `Legacy Infrastructure` +
  `set("status", 0)` across the tree (only step_700:400 references the
  status mutation; only RUNBOOK.md docs echo the stale semantic).

## Phase 4 (T): author tests (RED) — valid RED confirmed

- **Decided:** AC-1c enforcement path is
  `/group/{gid}/content/create/group_node%3Aforum`, tested with an
  AUTHENTICATED NON-ORGANIZER persona (`elena_garcia` / seeded
  `demo_password_2026`), not anonymous. Empirically probed against a live
  copy of gid=8 (Legacy Infrastructure) with `status` temporarily flipped to
  1 (simulating F's fix): `/node/add/forum?group={gid}` returns 403 for
  ANONYMOUS on every group tested regardless of archive state (a truism, not
  enforcement — survey.md's flagged concern). The `content/create` route ALSO
  returns 403 for anonymous on every group (anonymous lacks the site-wide
  "create group content" permission needed to even attempt it) — but for
  elena_garcia (seeded member of Core Committers, NOT a member of Legacy
  Infrastructure), the SAME route returns 403 on gid=8 (Archive-typed) and
  200 on gid=3/Core Committers (non-archive, elena is a member). This is the
  archive-driven differential signal AC-1c requires. Contrary to survey.md's
  flagged concern (this route bypasses `hook_node_access` via
  `_group_relationship_create_any_entity_access`), in THIS build the route
  IS gated by the archive state — no fallback to badge-only observability
  was needed.
- **Decided:** AC-1a's directory-card assertion targets the actual rendered
  markup — a plain `.gc-directory-card__type` "Archive" taxonomy-label badge
  (no tooltip) — NOT `span.group__archived-badge` (which only renders on the
  group's own canonical page via `hook_preprocess_group`, which the
  `all_groups` View's Views-fields row render never invokes). This is a
  real, pre-existing gap in the `.gc-directory-card` component (outside
  #128's Files In Scope — no theme/template changes permitted per the
  brief's non-goals), documented in the spec header and here, not a test
  authorship error. AC-1b (the group page itself) carries the full
  assertion (`span.group__archived-badge` + `data-do-tooltip`).
- **Decided:** AC-2 is a REGRESSION GUARD, not a RED-driver. Probed
  anonymously against the current (pre-#128) seed: `/node/1` ("Sprint
  Planning: Portland 2026") already renders
  `<span class="pin-badge" data-do-tooltip="...">Pinned</span>` for
  anonymous visitors. `/group/1` and `/group/1/stream` do NOT render the
  badge (Views/teaser row rendering skips `title_suffix`). AC-2 passes today
  and must keep passing after #128's seed change — no seed addition needed.
- **Evidence:** live probes against a seeded `gm128-archive-demo` DDEV site
  (assembled via `scripts/ci/assemble-config.sh`, `site:install standard`,
  `config:import`, full `step_700`/`step_720`/`step_780`/`step_790` seed —
  mirroring `.github/workflows/test.yml`). Confirmed RED against the CURRENT
  seed (Legacy Infrastructure `status=0`): `demonstrator-seeds.spec.ts`
  AC-1a/1b/1c fail (element/route not found — group absent/403 due to
  unpublish, the correct-reason failure); `group-restore.spec.ts`'s
  simplified `/all-groups` lookup helper also correctly fails for the same
  reason (expected: the helper's fix is a no-op only once F's seed change
  ships). Confirmed GREEN when `status` is temporarily flipped to 1
  (simulating F's fix, then reverted): all 8 tests across
  `demonstrator-seeds.spec.ts` + `group-restore.spec.ts` +
  `directory-cards.spec.ts` pass. `directory-cards.spec.ts`'s 3 tests are
  unaffected either way (doc-comment-only edit, assertion unchanged).
