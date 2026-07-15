# Getting Started — Running the Website Front-End Pipeline on a Project

> ⚠️ **WEBSITE FRONT-END PIPELINE.** This is the implementation guide for standing the
> pipeline up on a website project and running cycles. It is distinct from the coding
> pipelines in `workflow/`. Overview: [`README.md`](README.md). Authoring a new platform
> plugin: [`adapters/WRITING-AN-ADAPTER.md`](adapters/WRITING-AN-ADAPTER.md).

## Mental model (30 seconds)

A running agent is **composed of three layers**:

```
core role (generic)  +  platform adapter (the "how")  +  project profile (this site)
```

- **Core** never changes per project — the roles, principles, verification tiers, gates, tool engines.
- **Adapter** is the platform plugin — Drupal/Canvas/SDC, or one you write for another stack.
- **Profile** is this site's config — URLs, paths, theme name, stateful-surface inventory.

You instantiate the pipeline once per project, then drive cycles through O.

---

## Step 1 — Pick or write the adapter

Does an adapter exist for your stack under [`adapters/`](adapters/)?

- **Yes** (e.g. Drupal+Canvas+SDC → [`adapters/drupal-canvas-sdc.md`](adapters/drupal-canvas-sdc.md)) — use it.
- **No** — write one from [`adapters/_adapter-template.md`](adapters/_adapter-template.md). See
  [`adapters/WRITING-AN-ADAPTER.md`](adapters/WRITING-AN-ADAPTER.md). Do this before continuing.

## Step 2 — Write the project profile

Copy [`profile.example.md`](profile.example.md) into your **project repo** (not this library),
e.g. `docs/<project>/frontend-pipeline-profile.md`, and fill in every field: adapter name,
local site URL, theme/app identifier, theme-chain paths, token files, component roots,
handoff dir, runbook path, the verification config (stateful-surface inventory, axe base URL),
and the repo posture (push/PR or local-only).

## Step 3 — Instantiate the agents (the composition)

In Claude Code, each agent is a file in `~/.claude/agents/`. The **recommended** instantiation
is a *thin* agent that points at the core role + adapter + profile and reads them at runtime
(F/A/T/W/S all have a Read tool and run inside the project repo):

```markdown
---
name: <project>-feature-implementor
description: F in the Website Front-End Pipeline for <project>
model: sonnet
tools: Read, Write, Grep, Glob, Bash, Git
permissionMode: bypassPermissions
---
You are F in the Website Front-End Pipeline. Your role contract is:
  ~/Projects/playbook/pipelines/website-frontend/core/roles/feature-implementor.md

Read it first. Then read, for THIS project:
  - Platform adapter: ~/Projects/playbook/pipelines/website-frontend/adapters/<platform>.md
  - Project profile:  docs/<project>/frontend-pipeline-profile.md

Follow the core role. Use the adapter for platform mechanics (layers, components,
commands) and the profile for paths/URLs/names.
```

Repeat for `architecture-reviewer`, `tester` (model `sonnet`) and `orchestrator` (model
`sonnet`). **S uses model `opus`** (vision required). (The W auditor lives in the separate
Website Audit Pipeline.)

> **Why thin pointers, not copies?** When core or the adapter improves, every project picks
> it up with no regeneration. If your environment can't read across repos at runtime, the
> fallback is to physically concatenate `core role + adapter + profile` into each agent file
> at setup time — but then you must re-concatenate when core/adapter change.

## Step 4 — Build the stateful-surface inventory

Copy [`core/tools/state-invariants.config.example.json`](core/tools/state-invariants.config.example.json)
into your project (e.g. `scripts/state-invariants.config.json`), and write a short
`stateful-surfaces.md` listing every surface that holds state (filters, pagination, active
language, search, nav toggles) with its persistence expectation. Entries ship
`"enabled": false` until T confirms selectors against the running site. This is what T's
Tier 2.5 runs against.

## Step 5 — Install the tool deps (once, in the project)

```bash
npm install --no-save playwright @axe-core/playwright @playwright/test
npx playwright install chromium
```

## Step 6 — Run an implementation cycle (O → D → A → T → F → T → A → U → S)

O drives it:

1. **Open a phase** — identify the next runbook item; do the **mandatory component inventory**
   (list adapter reuse-source components relevant to the phase); the brief names a reuse
   candidate or justifies a new component. Create the issue/branch.
2. **Brief gate** (if enabled — see [`core/gates.md`](core/gates.md)).
3. **F → A → (diff gate) → T → U → S**, each spawned via the Agent tool, each handoff read
   before the next. A must PASS before T. **U** (live-browser walkthrough) runs whenever the
   diff touches a UI / client-behavior surface, across viewport × theme-variant — see
   [`core/gates.md`](core/gates.md) (O records "U: N/A" when none is touched). S runs per the
   Tier-3 gate rule in [`core/verification-tiers.md`](core/verification-tiers.md).
4. **Decide** — PASS: stage by explicit path, commit per the profile's posture, check off the
   runbook. REWORK: new issue, re-spawn F.

## Auditing an existing front end

Auditing is a **separate pipeline** — the Website Audit Pipeline
([`../website-audit/`](https://github.com/Performant-Labs/website-audit), O-W-O). It produces findings + fix issues
that feed back into this build pipeline. The two are kept distinct and do not reference each
other. Run audits there.

## Verification quickstart

```bash
# T2 accessibility
AXE_BASE_URL=<site-url> node .../core/tools/axe-check.cjs / /listing
# T2.5 interaction (subset)
STATE_INVARIANTS_CONFIG=scripts/state-invariants.config.json \
  STATE_INVARIANTS_ONLY=url-filter npx playwright test .../core/tools/state-invariants.spec.js
```

## Worked reference

The `performantlabs.com` homepage overhaul (`pl2`) is the live reference instantiation:
Drupal-Canvas-SDC adapter + a local-only profile, agents in `~/.claude/agents/*`, runbook and
handoffs in the project repo. Use it as a concrete example of every step above.

## Common gotchas

- **A new stateful surface with no inventory entry is a blocking T finding** — O adds it before
  the cycle closes.
- **Don't skip S** for a visual change to save time — the gate rule allows skipping only with a
  computed-value equivalence proof.
