# Brief — #120 SC-1 Persona switcher (Browse-as dropdown)

**Review rigor:** second-opinion (diff-gate only; brief-gate skipped per overnight authorization)
**Worktree:** `~/Projects/_worktrees/groups-persona-switcher` (branch `120-persona-switcher` off `origin/main`)
**Handoff dir:** `docs/planning/handoffs/120-persona-switcher/`
**Namespace containers:** `gm120-*` — NEVER `docker rm` a sibling story's container.

## Objective
Ship a "Browse as" dropdown in the site header (visible to anonymous visitors) that lets a POC
visitor switch among **4 fully public personas** (Anonymous / Elena Garcia — Member / Maria Chen —
Organizer / Groups-Moderate) with a persistent "switch back" banner while active. Powered by
`drupal/masquerade 2.2` (D11-compatible per composer.lock — recorded on #120 + PR body).

## Source of truth
`gh issue view 120 --repo Performant-Labs/groups-on-d11` — the issue **is** the spec. Re-read at
every phase (survey → design → arch → test-red → feature → test-green → diff-gate → walkthrough → audit).

## Survey summary
See `survey.md` in this dir for the Reuse & Analogous-Feature map. Highlights:
- `PersonaSwitcher` service is analogous to existing `VariantSwitcher` (same module,
  autowire=false, string_translation). Justified as **NEW** (different widget + session semantics).
- `PersonaRegistry` constant map is analogous to `ShowcaseCatalog`.
- Groups-Moderate role config already exists (empty perms) — this story **appends** its scoped perms.
- `HelpText` gets 4 **append-only** keys prefixed `persona.*` — never edit existing keys.
- All other work happens inside `do_showcase` module.
- Seed = **new** `step_790_persona_switcher.php` (append-only file, numbered after 780).

## Acceptance (from #120)
- [ ] D11 masquerade compatibility verified & recorded on GH issue + PR body.
- [ ] Anonymous visitor sees "Browse as" dropdown in header near account area; per-option
      `do_chrome` tooltips render.
- [ ] Switching to each persona: session becomes that user; banner shows "You're browsing as X —
      switch back"; switch-back returns to anonymous cleanly.
- [ ] uid 1 is unreachable via any masquerade path (test asserts at access-check level, not just UI).
- [ ] Maria (Organizer) can edit a group / manage members; plain Elena (Member) cannot (positive +
      negative Functional tests).
- [ ] Groups-Moderate persona can view pending queue, approve, archive/restore on a group it's not
      a member of; and CANNOT reach `/admin/config`, `/admin/people`, module pages, or general user
      admin (negative Functional test).
- [ ] Playwright spec `tests/e2e/persona-switcher.spec.ts`: full switch → verify → switch-back for
      at least 2 personas incl. Moderator, against the seeded demo.
- [ ] `HelpText` append-only 4 new `persona.*` keys.
- [ ] Seed `step_790_persona_switcher.php` (append-only new file): Groups-Moderate account,
      Maria's Organizer group role, one pending join request.
- [ ] WCAG 2.2 AA: label on `<select>`, keyboard-operable, visible focus, contrast, non-color
      status on banner.
- [ ] Existing Kernel/Functional/E2E stay green; CI passes.
- [ ] PR opened, CI green, MERGE (overnight authorization from operator — see agent prompt).

## Input documents
- `docs/workflow/PROJECT_CONTEXT.md`
- `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §4/§6/§7
- `docs/playbook/workflow/*` (pipeline, dual-review)
- Playbook: `~/Projects/playbook/frameworks/playwright/conventions.md`
- Foundation of trust: existing `do_showcase/src/VariantSwitcher.php`,
  `do_showcase/src/ShowcaseCatalog.php`, `do_chrome/src/HelpText.php`,
  `do_showcase/src/Hook/DoShowcaseHooks.php`.

## Downstream contract (forward-compat)
- Prefix `persona.*` for tooltip keys keeps us disjoint from #121 (`join.*`, `visibility.*`),
  #126–128 (help/tooltips).
- Seed file numbering `step_790_*` avoids collision with #121's future append to `step_700/step_780`.

## Overnight autonomy contract
- After each phase: commit source-only (explicit paths; `Co-Authored-By: Claude Opus 4.7
  <noreply@anthropic.com>`); ping main one-line via SendMessage.
- Skip A-dup and brief-gate (per operator's LEAN POC directive).
- After S PASS: open PR (assign aangelinsf, labels enhancement+showcase, disclose AI); drive CI
  green; **auto-merge with `--merge --delete-branch`**; cleanup worktree; DDEV container `gm120-*`
  pruned; final ping main `[120] MERGED at <sha>`.
- CI same test fails twice OR novel breakage → STOP + park with
  `docs/planning/handoffs/120-persona-switcher/OVERNIGHT-CI-FAIL.md` + ping main.
