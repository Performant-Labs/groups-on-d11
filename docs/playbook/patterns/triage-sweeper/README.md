# Pattern: triage-sweeper — evidence-cited automated issue triage

> A scheduled bot that reviews open issues against current `main`, **labels** them with a
> structured verdict/blast/risk taxonomy, and posts an **evidence-cited** comment — but
> **never closes**. Closing stays a human decision; the bot's job is to make that decision
> cheap and auditable. Modeled on NousResearch/hermes-agent's `hermes-sweeper` (which has
> adjudicated ~3,800 issues), adapted to the Performant Labs stack and made safe-by-default.

**Pilot repo:** `Performant-Labs/language-buddy` (most active issue tracker; where pipeline
tooling is being operationalized first). The bundle is repo-agnostic — installing elsewhere
is the same three steps with a different `GH_REPO`.

---

## Why this shape

The hermes-sweeper insight worth stealing is not "an LLM closes issues" — it's the
**contract** around the LLM that makes automated triage trustworthy:

1. **Labels are the bot's structured output**, across three orthogonal dimensions a
   maintainer actually sorts by:
   - **verdict** — why it's (not) closable: `implemented-on-main`, `cannot-reproduce`,
     `not-planned`, `incoherent`, `actionable`.
   - **blast radius** — triage priority: `massive → broad → moderate → contained`.
   - **risk surface** — review attention: `security-boundary`, `compatibility`,
     `data-loss`, `automation`.
2. **Every close-type verdict must cite real `path:line` + a commit sha.** The engine
   downgrades any `implemented-on-main`/`cannot-reproduce`/`not-planned` that arrives
   without citations to `actionable`. A verdict you can't audit is not a verdict.
3. **Idempotency via HTML-comment markers.** Each comment embeds
   `<!-- triage-sweeper:swept-sha=<HEAD> item=<n> -->`. Re-runs find their prior verdict,
   update in place instead of stacking, and skip issues already swept at the current HEAD.
   The comment body doubles as the bot's state store — no external DB.
4. **Structured issue forms** (`bug_report.yml`) give the engine machine-parseable slots.
   A bug missing repro/version is cleanly `incoherent` instead of a guessing game.
5. **Human close-gate.** We deliberately ship *without* auto-close. A wrong label costs a
   filter click; a wrong close costs trust and a reopen. `sweeper:actionable` issues get a
   human anyway, so the bot never needs the close button to add value.

## What's in the bundle (`install/`)

```
install/
  .github/
    labels.yml                     # the taxonomy (synced idempotently)
    ISSUE_TEMPLATE/bug_report.yml  # structured form → clean machine input
    workflows/triage-sweeper.yml   # scheduled + manual Action (comment/label only)
  scripts/
    sweeper.py                     # engine: drives `claude -p`, labels, upserts comment
    prompt.md                      # the triage prompt + strict JSON output schema
    sync-labels.sh                 # one-shot label creation/update from labels.yml
  skills/github/                   # vendored MIT "hands" (see PROVENANCE.md) —
    github-issues/                 #   issue triage reference (taxonomy context)
    github-code-review/            #   diff/commit inspection → the path:line + sha evidence
    codebase-inspection/           #   pygount LOC/language pre-scan ("where to look")
    LICENSE  PROVENANCE.md         #   MIT © Nous Research, pinned commit
```

The engine runs Claude Code **headless** (`claude -p`) with read-only tools
(`Read,Grep,Glob,Bash(git log/show/diff:*),Bash(rg:*),Bash(pygount:*)`), so it verifies
claims against the actual checked-out tree and cites what it read — the same mechanism that
lets hermes-sweeper point at `agent/conversation_loop.py:3166-3201`. The **evidence-gathering
recipes are the vendored skills** (`github-code-review` for commit-level proof,
`codebase-inspection` for the pre-scan); `prompt.md` points the reviewer at them by path.

## Install (per target repo)

```bash
REPO=Performant-Labs/language-buddy
cd <clone-of-that-repo> && git checkout -b chore/triage-sweeper

# 1. Files: forms/workflow to .github, scripts to .github/scripts (prompt.md must sit
#    next to sweeper.py — the engine loads it via __file__), skills to .github/skills
#    (sweeper.py finds them at ../skills/github relative to itself).
P=~/Projects/playbook/patterns/triage-sweeper/install
mkdir -p .github/scripts .github/skills
cp -R "$P/.github/." .github/
cp "$P/scripts/"* .github/scripts/
cp -R "$P/skills/." .github/skills/
chmod +x .github/scripts/*.sh .github/scripts/*.py

# 2. Labels (needs gh auth + `pip install pyyaml`):
GH_REPO=$REPO .github/scripts/sync-labels.sh

# 3. Secret: repo needs ANTHROPIC_API_KEY for the Action.
gh secret set ANTHROPIC_API_KEY --repo $REPO   # paste key when prompted

git add .github && git commit -m "chore: add triage-sweeper (comment+label, human close-gate)"
```

**Dry-run first** (label/comment on nothing, just log): from the Actions tab run
`triage-sweeper` via *Run workflow* with `dry_run=true`, or locally:

```bash
GH_REPO=$REPO SWEEPER_DRY_RUN=true SWEEPER_MAX_ISSUES=5 \
  python3 .github/scripts/sweeper.py     # run from inside the target repo checkout
```

## Controls & cost

| Knob | Env / input | Default | Purpose |
|---|---|---|---|
| Batch cap | `SWEEPER_MAX_ISSUES` | 20 | issues per run — caps cost & blast |
| Dry run | `SWEEPER_DRY_RUN` | false | log verdicts, touch nothing |
| Model | `SWEEPER_MODEL` | `claude-opus-4-8` | drop to a cheaper tier for high volume |
| Extra filter | `SWEEPER_FILTER` | `is:open is:issue` | e.g. scope to `label:type/bug` |
| Human override | `sweeper:skip` label | — | bot never touches that issue again |

Oldest-updated issues are swept first (backlog drain). Cadence is a daily cron; raise
`SWEEPER_MAX_ISSUES` or add an hourly trigger once you trust it. Cost ≈ one Opus
review per issue per `main`-change — the swept-sha marker means a static issue is reviewed
once, not every run.

## How it fits the pipelines

- This is **maintainer automation**, not the coding pipeline — but a `sweeper:actionable` +
  `blast-*` + `risk-*` triple is a ready-made brief header for
  [`workflow/workflow-coding-pipeline.md`](../../workflow/workflow-coding-pipeline.md):
  blast → review-rigor dial (massive/broad ⇒ `panel`; moderate ⇒ `second-opinion`), risk →
  the reviewer's attention list.
- Anything the sweeper labels `sweeper:risk-security-boundary` should not be auto-anything;
  it's a hard stop for human review, consistent with the playbook's standing rules.

## How the reference implementation works (observed, 2026-07-13)

We reverse-engineered NousResearch's `hermes-sweeper` from public GitHub data — it has no
public source, but its behavior and its tools are observable. What we found, and how it
shaped the choices above:

**Architecture: it's a Hermes Agent, not a bespoke script.** A global code search for the
`hermes-sweeper` marker returns zero real hits — the orchestration/prompt is private. But
the **hands are public**: it drives the MIT-licensed skills in
[`hermes-agent/skills/github/`](https://github.com/NousResearch/hermes-agent/tree/main/skills/github)
(`github-issues`, `github-code-review`, `codebase-inspection`) — the same three we vendor
here. The `github-issues` skill reads its token from `~/.hermes/.env` and runs gh-first /
curl-fallback: i.e. a scheduled Hermes instance posting as the maintainer's account.

**Its prompt is likely *evolved*, not hand-written.** NousResearch also ships
[`hermes-agent-self-evolution`](https://github.com/NousResearch/hermes-agent-self-evolution)
(DSPy + GEPA, ~$2–10/run) that auto-optimizes skills/prompts against eval traces with
constraint gates. That plausibly explains the discipline we *measured* below — worth a
phase-2 optimization pass on our own `prompt.md`.

**Measured behavior (600-comment sample + label census + issue timelines):**

| Signal | Observation | Our design response |
|---|---|---|
| Verdict labels | Only ever on **closed** issues → it **auto-closes** | We default to comment+label, **human close-gate** (see below) |
| Risk labels | On **hundreds of open** issues, **no comment** | Second mode: silent risk/blast labels on the live backlog |
| Comment corpus | **80%** of all repo comments; **100%** `confidence=high` | It only *comments* when high-confidence closing |
| Citations | `path:line` in **98%**, commit sha in **62%** | Engine **downgrades** any uncited close-verdict to `actionable` |
| Close mechanic | comment → close → label in **~3 s**, uniform `state_reason=not_planned` | — |
| Cadence | daily batch, **10:00–14:00 UTC**, ~2.5 comments/min | our default cron mirrors a daily batch |
| Reopen rate | **~0** — nothing successfully contested | high citation rate is what makes closes stick |

**Governance siblings** (context for bounding an autonomous bot like this):
[`hermes-telegram-business`](https://github.com/NousResearch/hermes-telegram-business)
ships the same *observe-with-approval* human-gate we default to; and
[`agent-governance-toolkit`](https://github.com/NousResearch/agent-governance-toolkit)
covers policy enforcement / sandboxing (OWASP Agentic Top 10).

**The one deliberate divergence: we do not auto-close by default.** hermes trusts its
high-confidence + cited closes at scale (with ~0 reopen friction). We keep the human gate
because a wrong close costs trust and a reopen while a wrong label costs a filter click, and
our bot hasn't yet earned a 98%-citation track record on *our* repos. Auto-close is available
as a gated extension below once labels-only mode proves calibrated.

## Extension ideas (not shipped)

- **Standing policy file** (`POLICY.md`) the engine reads, so `not-planned` cites a real
  documented decision rather than inferring one.
- **Duplicate detection** pass (embed + nearest-neighbor over open issues) → a
  `sweeper:possible-duplicate` label linking the twin.
- **Maintainer-gated auto-close** behind an allow-list + `confidence=high` + a 7-day
  grace comment — only after the labels-only mode has proven calibrated on real traffic.
