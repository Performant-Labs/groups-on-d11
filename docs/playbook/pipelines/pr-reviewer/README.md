# Setting Up a PR Reviewer

Canonical documentation for the Universal Configurable PR Reviewer pipeline.
Lives in `playbook/pipelines/pr-reviewer/`.

---

## 1. Overview

The PR reviewer system gives every Performant Labs project two complementary review
paths that together cover automated CI gating and deep local analysis.

**CI path (PR-Agent):** Fires automatically on GitHub Actions when a PR is opened,
pushed to, or marked ready-for-review. It uses Qodo PR-Agent with DeepSeek V4 Pro
to post a structured Spec-enforcer review, applies a score-bracket label, injects a
score banner into the PR description, and sets a commit status check — all driven by
feature flags read from `.agents/pr-review.yml` at job start.

**Local path (`claude -p`):** Runs on-demand from a developer's MacBook. Invoked
with `.agents/scripts/pr-review.sh <PR-number> [--post]`, it fetches the diff via
`gh`, passes it to `claude -p` acting as Spec-enforcer, and optionally posts the
result back to GitHub as a PR comment. This path cannot run in CI because GitHub
Actions has no access to the macOS Keychain where Claude OAuth tokens are stored.

### Feature matrix

| Feature | Config key | Default |
|---|---|---|
| PR-Agent CI review | `pr_agent.enabled` | `true` |
| DeepSeek V4 Pro model | `pr_agent.model` | `deepseek/deepseek-v4-pro` |
| Score-bracket labels (`score-90+` etc.) | `pr_agent.score_labels` | `true` |
| Score banner in PR description | `pr_agent.score_banner` | `true` |
| Commit status check (`pr-agent/review`) | `pr_agent.status_check` | `true` |
| Skip docs-only PRs (paths-ignore) | `pr_agent.skip_docs_only` | `false` |
| High-stakes label name | `pr_agent.high_stakes_label` | `"high-stakes"` |
| Cancel in-flight on new push | `pr_agent.cancel_in_flight` | `true` |
| Local claude review | `local_review.enabled` | `true` |
| Local review model | `local_review.default_model` | `claude-sonnet-4-6` |
| Shell alias for local review | `local_review.shell_alias` | `""` (none) |
| O3/O4-mini dual review | `dual_review.enabled` | `false` |
| Dual review model | `dual_review.model` | `"o3"` |
| Dual review on brief phase | `dual_review.on_brief` | `true` |
| Dual review on impl phase | `dual_review.on_impl` | `true` |
| DCO sign-off enforcement | `dco.enabled` | `true` |
| PR template | `pr_template.enabled` | `true` |
| T1 headless tests | `test_tiers.t1_headless` | `true` |
| T2 Playwright ARIA tests | `test_tiers.t2_aria` | `false` |
| T2.5 authenticated Playwright tests | `test_tiers.t2_5_authenticated` | `false` |
| T3 visual regression tests | `test_tiers.t3_visual` | `false` |

---

## 2. Prerequisites

| Prerequisite | Required by | How to verify |
|---|---|---|
| `DEEPSEEK_API_KEY` repo secret | PR-Agent CI | GitHub → repo → Settings → Secrets → Actions |
| `GITHUB_TOKEN` (auto-provided) | PR-Agent CI, status check | Always available in Actions |
| `DISPLAY_TZ` repo variable (optional) | Score banner timestamps | GitHub → repo → Settings → Variables → Actions |
| `claude` CLI installed and authenticated | Local review, dual review | `claude --version` and `claude auth status` |
| `gh` CLI installed and authenticated | Local review, readiness check | `gh auth status` |
| `OPENAI_API_KEY` environment variable | Dual review (when enabled) | `echo $OPENAI_API_KEY` |
| `AI_GUIDANCE_DIR` environment variable | `dual-review.sh` script | `echo $AI_GUIDANCE_DIR` (defaults to `~/Projects/playbook`) |
| `jq` installed | Score banner step in CI | `command -v jq` |
| `yq` installed (optional) | CI config reader — falls back to grep/sed without it | `command -v yq` |

The `claude` CLI must be run from the repo root because `pr-review.sh` checks for `CLAUDE.md`
in the current directory and `claude -p` auto-reads it as project context.

---

## 3. First-time setup

Start from zero in a project that has no PR review system yet.

```bash
# 1. Set the AI_GUIDANCE path variable if you haven't already
#    (add to your shell profile to persist across sessions)
export AI_GUIDANCE="$HOME/Projects/playbook"

# 2. Copy the canonical scripts into the project
mkdir -p .agents/scripts
cp "$AI_GUIDANCE/pipelines/pr-reviewer/scripts/"*.sh .agents/scripts/
chmod +x .agents/scripts/*.sh

# 3. Run the setup wizard (interactive — prompts for each feature flag)
.agents/scripts/setup-pr-review.sh
```

The wizard asks for:
- Project name (defaults to directory name)
- Timezone for the score banner (default: `America/Los_Angeles`)
- PR-Agent model (default: `deepseek/deepseek-v4-pro`)
- Local review model (default: `claude-sonnet-4-6`)
- Whether to skip docs-only PRs (`skip_docs_only`)
- Whether to enable dual review (O3/O4-mini)
- Whether to enforce DCO sign-off
- Whether to install the PR template
- Which test tiers are active (T1/T2/T2.5/T3)

After confirming, the wizard writes `.agents/pr-review.yml` and prints the manual
steps to complete. Those steps are:

```bash
# 4. Copy GitHub Actions workflow
mkdir -p .github/workflows
cp "$AI_GUIDANCE/pipelines/pr-reviewer/templates/pr-review.yml" \
   .github/workflows/pr-review.yml

# 5. If skip_docs_only is false, remove or empty the paths-ignore block
#    in .github/workflows/pr-review.yml (see §4 gotcha below).

# 6. If dco.enabled is true, copy the DCO workflow
cp "$AI_GUIDANCE/pipelines/pr-reviewer/templates/dco.yml" \
   .github/workflows/dco.yml

# 7. Copy and customise the PR-Agent config
cp "$AI_GUIDANCE/pipelines/pr-reviewer/templates/pr_agent.toml" \
   .pr_agent.toml
# Edit the extra_instructions section to reflect this project's
# skills/, forbidden patterns, and conventions.

# 8. If pr_template.enabled is true, copy the PR template
mkdir -p .github
cp "$AI_GUIDANCE/pipelines/pr-reviewer/templates/pull_request_template.md" \
   .github/pull_request_template.md
# Fill in the "Apply high-stakes label if this PR touches" section.

# 9. If local_review.shell_alias is set, source the aliases file
#    in your shell profile (zsh only — colon names like pr:review are
#    a zsh-specific syntax, not valid bash):
echo "source $(pwd)/.agents/scripts/shell-aliases.sh" >> ~/.zshrc

# 10. Add the DEEPSEEK_API_KEY secret in GitHub repo settings.

# 11. Verify the setup
.agents/scripts/check-pr-review-readiness.sh
```

---

## 4. Config reference

Full annotated `.agents/pr-review.yml`. The wizard generates this file; edit it
directly or re-run with `--update` to regenerate.

```yaml
# .agents/pr-review.yml
# Generated by setup-pr-review.sh — YYYY-MM-DD.
# Re-run .agents/scripts/setup-pr-review.sh --update to change settings.

project:
  name: "my-project"
  # Human-readable project name. Shown in local review footers.
  # Type: string. Default: directory name at wizard run time.

  timezone: "America/Los_Angeles"
  # IANA timezone for display timestamps in score banners.
  # Type: string. Default: "America/Los_Angeles".
  # Governs the DISPLAY_TZ repo variable; set the same value in
  # GitHub → repo → Settings → Variables → Actions for CI banners.

pr_agent:
  enabled: true
  # Master switch for the CI PR-Agent job.
  # Type: bool. Default: true.
  # Setting this to false does not remove the workflow file — it is a
  # documentation marker only. The workflow always runs when present.

  model: "deepseek/deepseek-v4-pro"
  # LiteLLM-style model string passed to PR-Agent via CONFIG.MODEL.
  # Type: string. Default: "deepseek/deepseek-v4-pro".
  # The CI workflow reads this via the "Read pr-review config" step and
  # exports it as steps.cfg.outputs.model for the PR-Agent env block.

  max_tokens: 131072
  # Context window size sent as CONFIG.CUSTOM_MODEL_MAX_TOKENS.
  # Type: int. Default: 131072.
  # Informational only — not read by the workflow at runtime (the
  # workflow hardcodes "131072"). Update both places when changing.

  output_tokens: 64000
  # Max output tokens sent as CONFIG.MAX_MODEL_TOKENS.
  # Type: int. Default: 64000.
  # Same caveat as max_tokens — hardcoded in the workflow template.

  high_stakes_label: "high-stakes"
  # GitHub label name that triggers the high-stakes review path.
  # Type: string. Default: "high-stakes".
  # When this label is applied to a PR, the workflow routes to the
  # "PR-Agent review (high-stakes)" step instead of the default step.
  # Both steps use the same action SHA and env — the distinction exists
  # to allow per-project customisation in project-level workflow forks.

  skip_docs_only: false
  # When true, the workflow's paths-ignore block is active, skipping CI
  # for PRs that touch only documentation paths.
  # Type: bool. Default: false.
  # GOTCHA: paths-ignore is evaluated before the job runs and cannot be
  # made dynamic at runtime. This is a copy-time decision — if you set
  # skip_docs_only: true, you must keep the paths-ignore block in the
  # workflow file. If you set it to false, remove or empty that block.
  # Manual /review via issue_comment always works regardless.

  score_labels: true
  # When true, applies a score-bracket label (score-90+, score-80-89,
  # score-70-79, score-below-70) to the PR after each review.
  # Type: bool. Default: true.
  # Labels are created if they don't exist; previous score labels are
  # removed before the new one is applied.

  score_banner: true
  # When true, injects a summary banner at the top of the PR description
  # after each review. The banner shows score, model, security/major/
  # minor indicators, trigger info, and timestamp.
  # Type: bool. Default: true.
  # The banner is bounded by HTML comments (argos-status:start/end) so
  # re-runs replace the previous banner rather than appending.

  status_check: true
  # When true, posts a commit status (pr-agent/review) on the head SHA.
  # Type: bool. Default: true.
  # Green = score parsed successfully. Red = PR-Agent produced no score
  # (crash or empty response). A red status also posts a PR comment
  # telling the author to re-run with /review.

  cancel_in_flight: true
  # When true, concurrency.cancel-in-progress in the workflow is true,
  # cancelling older in-flight reviews when a new commit lands.
  # Type: bool. Default: true.
  # Informational only — the workflow template hardcodes cancel-in-progress:
  # true. To disable, edit the workflow file directly.

local_review:
  enabled: true
  # Documents that local review is configured for this project.
  # Type: bool. Default: true.
  # Informational only — the scripts are always present once copied.

  default_model: "claude-sonnet-4-6"
  # Claude model for pr-review.sh when no --model flag is passed.
  # Type: string. Default: "claude-sonnet-4-6".
  # Can be overridden at call time with --model <model> or the
  # REVIEWER_MODEL environment variable.

  shell_alias: ""
  # Optional zsh alias to install for the local review command.
  # Type: string. Default: "" (no alias installed).
  # Example: "pr:review" generates a zsh function `pr:review` in
  # .agents/scripts/shell-aliases.sh that calls pr-review.sh from
  # anywhere on your machine. Colon-names are zsh-only syntax.

dual_review:
  enabled: false
  # When true, dual-review.sh calls the canonical playbook dual-review
  # script (workflow/dual-review.sh), which submits the brief or diff to
  # O3/O4-mini for a second opinion.
  # Type: bool. Default: false.
  # Requires: OPENAI_API_KEY env var + the implement-loop workflow
  # (.agents/workflows/implementstory.md) which drives the two review
  # phases (brief and diff). See §5 Dual review.

  model: "o3"
  # OpenAI model for the second-opinion reviewer.
  # Type: string. Default: "o3".
  # Note: o3 API was EOL'd 2026-12-11; use "o4-mini" for new projects.
  # The playbook repo migrated from o3 to o4-mini in commit a0bf7a7.

  on_brief: true
  # Run dual review at Phase 4 (brief gate) of the implement-loop.
  # Type: bool. Default: true.

  on_impl: true
  # Run dual review at Phase 7 (diff gate) of the implement-loop.
  # Type: bool. Default: true.

dco:
  enabled: true
  # When true, .github/workflows/dco.yml should be present.
  # Type: bool. Default: true.
  # Informational only — the workflow file is what actually enforces DCO,
  # not this flag. Use this flag as the source of truth when deciding
  # whether to copy dco.yml during setup.

pr_template:
  enabled: true
  # When true, .github/pull_request_template.md should be present.
  # Type: bool. Default: true.
  # Informational only — same caveat as dco.enabled.

test_tiers:
  t1_headless: true
  # T1 headless tests (curl / fastify.inject() / cheerio, 1-5 s).
  # Type: bool. Default: true.
  # All projects are expected to have T1 coverage.

  t2_aria: false
  # T2 structural ARIA tests (Playwright accessibility tree, 5-10 s).
  # Type: bool. Default: false.
  # Enable when the project has UI routes that warrant ARIA verification.

  t2_5_authenticated: false
  # T2.5 authenticated-session tests (Playwright with auth state, 5-10 s).
  # Type: bool. Default: false.
  # Enable when the project has routes gated behind authentication.

  t3_visual: false
  # T3 visual regression tests (Playwright screenshots, 60-90 s).
  # Type: bool. Default: false.
  # T3 must never be enabled before T2 is green — see test tier hierarchy.
```

---

## 5. Feature guide

### 5.1 PR-Agent CI (`pr_agent.*`)

PR-Agent (Qodo) runs as a GitHub Actions job on every `pull_request` event
(opened, reopened, ready-for-review, synchronize) and on `issue_comment` events
matching the four slash commands: `/review`, `/describe`, `/improve`, `/ask`.

The job reads `.agents/pr-review.yml` via a "Read pr-review config" step that
exports feature flags as `GITHUB_OUTPUT`. If the config file is absent the job
falls back to safe defaults (all features on, `deepseek/deepseek-v4-pro`).

The action is pinned to a commit SHA (`qodo-ai/pr-agent@31d7dd02...`), not `@main`.
To upgrade: fetch the latest SHA with
`gh api repos/qodo-ai/pr-agent/commits/main -q '.sha'`, update both occurrences in
the workflow file, then open a PR so PR-Agent reviews itself with the new SHA.

**Verify it is working:**
1. Open a non-draft PR — the `pr-agent` job should appear in the Checks tab.
2. Check the PR description for the score banner after the job completes.
3. Check that a `score-*` label was applied.
4. Confirm the `pr-agent/review` commit status is green.
5. Post `/review` as a PR comment — the job should re-trigger within 30 s.

### 5.2 Local review (`local_review.*`)

The local reviewer runs `claude -p` in Spec-enforcer mode against a fetched diff.

```bash
# Print review to stdout (dry run)
.agents/scripts/pr-review.sh <PR-number>

# Print and post as a GitHub PR review comment
.agents/scripts/pr-review.sh <PR-number> --post

# Override the model for this run
.agents/scripts/pr-review.sh <PR-number> --model claude-opus-4-7

# Or override via environment variable
REVIEWER_MODEL=claude-opus-4-7 .agents/scripts/pr-review.sh <PR-number>
```

Must be run from the repo root (`CLAUDE.md` must be present in `cwd`). The script
verifies this and exits with a clear error if not.

If `local_review.shell_alias` is set (e.g., `"pr:review"`), source the generated
aliases file from your zsh profile and call it from anywhere:

```bash
pr:review <PR-number>
pr:review <PR-number> --post
```

**Security note:** The script uses a quoted heredoc (`<<'ENDOFPROMPT'`) for the
static prompt template, then appends untrusted PR content (title, body, diff) with
`printf '%s'` rather than unquoted heredoc variable expansion. This prevents shell
metacharacters in PR content from being interpreted.

**Verify it is working:**
```bash
claude auth status          # Claude CLI authenticated
gh auth status              # GitHub CLI authenticated
.agents/scripts/pr-review.sh <any-open-PR-number>
```

### 5.3 O3/O4-mini dual review (`dual_review.*`)

Dual review submits the implementation brief (Phase 4) and the completed diff
(Phase 7) to an OpenAI reasoning model for a second-opinion gate. It requires the
implement-loop workflow (`.agents/workflows/implementstory.md`) to call
`.agents/scripts/dual-review.sh` at those phases.

`dual-review.sh` reads `dual_review.enabled` from `.agents/pr-review.yml` and exits
immediately (SKIPPED) if the flag is false. When enabled it calls the OpenAI Responses
API directly (using `OPENAI_API_KEY`) and writes the review to
`.argos/stories/<taskId>/dual-{brief|impl}-review.md`.

**To enable:**
1. Set `dual_review.enabled: true` in `.agents/pr-review.yml`.
2. Set `dual_review.model` to `"o4-mini"` (o3 API is EOL 2026-12-11).
3. Export `OPENAI_API_KEY` in your shell profile.
4. Ensure `.agents/workflows/implementstory.md` calls `dual-review.sh` at Phase 4
   and Phase 7.

**Verify it is working:**
```bash
OPENAI_API_KEY=sk-... .agents/scripts/dual-review.sh --mode brief \
  --brief .argos/<taskId>/brief.md --out /tmp/dual-review-brief.md
```
If `dual_review.enabled` is false the output is `# Dual Review — SKIPPED`.

### 5.4 DCO enforcement

DCO (Developer Certificate of Origin) enforcement requires every non-merge commit in
a PR to carry a `Signed-off-by: Name <email>` trailer. The check runs via
`.github/workflows/dco.yml`.

**Developers add the sign-off with:**
```bash
git commit -s -m "your message"
# or
git commit --signoff -m "your message"
```

**To retrofit sign-off on the last N commits:**
```bash
git rebase HEAD~N --signoff
```

**Verify it is working:** Open a PR with an unsigned commit — the `dco` check should
fail with an error naming the offending SHA.

Note: the canonical `dco.yml` uses a shell-based check rather than the `dcoapp/dco`
GitHub App action. The App action was found to be unavailable / non-functional during
the token-tracker rollout and was replaced with a pure-shell `git log` check.

### 5.5 PR template

The PR template (`.github/pull_request_template.md`) pre-populates new PRs with:
- Summary section
- Linked issue(s) fields
- Acceptance criteria checklist
- Standard items: no secrets, tests present, forbidden patterns clean, spec not contradicted
- Decisions-that-deviate section
- High-stakes label guidance

Copy the template, then fill in the project-specific rows under "Checklist" and
"Apply `high-stakes` label if this PR touches" based on the project's `CLAUDE.md`.

### 5.6 Test tier hierarchy (`test_tiers.*`)

The four test tiers are ordered by cost and coverage. Higher tiers must not run
before lower tiers are green.

| Tier | Key | Tool | Time | Requirement |
|---|---|---|---|---|
| T1 | `t1_headless` | `curl` / `fastify.inject()` / cheerio | 1–5 s | Always required |
| T2 | `t2_aria` | Playwright accessibility tree | 5–10 s | T1 must be green |
| T2.5 | `t2_5_authenticated` | Playwright with auth state | 5–10 s | T2 must be green |
| T3 | `t3_visual` | Playwright screenshots | 60–90 s | T2 must be green |

T3 visual assertions must never be added before the corresponding T2 ARIA assertions
exist. This is a blocking pattern in the PR reviewer.

The test tier flags in `.agents/pr-review.yml` are documentation that records which
tiers are active for this project. They are read by the readiness checker and by
PR-Agent's `extra_instructions` (via `.pr_agent.toml`) to verify that PR diffs
include coverage at the declared tiers.

---

## 6. Updating an existing project

### Pulling in script improvements

Scripts in `.agents/scripts/` are not auto-synced from `playbook`. When the
canonical scripts are updated, manually copy the new versions:

```bash
export AI_GUIDANCE="$HOME/Projects/playbook"

cp "$AI_GUIDANCE/pipelines/pr-reviewer/scripts/pr-review.sh"               .agents/scripts/
cp "$AI_GUIDANCE/pipelines/pr-reviewer/scripts/dual-review.sh"             .agents/scripts/
cp "$AI_GUIDANCE/pipelines/pr-reviewer/scripts/check-pr-review-readiness.sh" .agents/scripts/
cp "$AI_GUIDANCE/pipelines/pr-reviewer/scripts/setup-pr-review.sh"         .agents/scripts/
chmod +x .agents/scripts/*.sh
```

Do not copy `shell-aliases.sh` this way — it contains the project-specific alias
name from `local_review.shell_alias`. Regenerate it by re-running the wizard.

### Re-running the wizard

```bash
.agents/scripts/setup-pr-review.sh --update
```

The `--update` flag lets the wizard run even when `.agents/pr-review.yml` already
exists. It re-prompts for all settings and overwrites the file.

### Updating the GH Actions workflow template

The workflow file (`.github/workflows/pr-review.yml`) is not auto-synced either.
When the canonical template is updated (e.g., new PR-Agent SHA, new banner fields),
copy the template and re-apply any project-specific adjustments:

```bash
cp "$AI_GUIDANCE/pipelines/pr-reviewer/templates/pr-review.yml" \
   .github/workflows/pr-review.yml
# Then: re-apply skip_docs_only decision (paths-ignore block)
```

### Readiness check

Run the readiness checker at any time to verify the setup is intact:

```bash
.agents/scripts/check-pr-review-readiness.sh
```

The checker reports PASS/WARN/FAIL for:
- Config file presence
- `CLAUDE.md` in cwd
- `claude` CLI installed
- `gh` CLI installed and authenticated
- `.github/workflows/pr-review.yml` present
- `.github/workflows/dco.yml` present (WARN if absent, not FAIL)
- `.github/pull_request_template.md` present (WARN if absent)
- `.pr_agent.toml` present (WARN if absent)
- `OPENAI_API_KEY` set when `dual_review.enabled` is true

Exit code 0 when no FAIL items. Exit code 1 when any FAIL items exist — suitable
for CI pre-flight gates.

---

## 7. Lessons learned from rollout

These findings come from the actual token-tracker (#66) and ctrfhub (#239) rollouts.

### Stale bespoke status-check step with undefined variables (`$NOW_TEAM`/`$NOW_UTC`)

During the ctrfhub rollout (PR #239), the canonical `pr-review.yml` workflow
conflicted with ctrfhub's existing workflow on the `main` branch. The existing
workflow had a hand-written status-check step that referenced `$NOW_TEAM` and
`$NOW_UTC` — variables that were defined in a different step that the bespoke
version had already dropped. The variables were undefined at runtime, producing
empty strings in the posted comment footer rather than failing loudly. The
resolution was to take the canonical version wholesale and discard the stale
bespoke code. Lesson: when rolling out the canonical template over an existing
bespoke workflow, do a full diff and do not try to merge the two incrementally —
take canonical, then re-apply only the paths-ignore decision.

### Shell injection risk in bespoke heredoc prompt construction

CTRFHub's pre-canonical `pr-review.sh` built the prompt in a single unquoted
heredoc (`<<ENDOFPROMPT`) and interpolated `${PR_META}` and `${PR_DIFF}` directly
inside it. An attacker who controls a PR title, description, or any line of the
diff could inject shell metacharacters that would be expanded during the heredoc
evaluation. The canonical version uses a quoted heredoc (`<<'ENDOFPROMPT'`) for
all static template text (so the shell performs no expansion), and then appends
untrusted content with `printf '%s'` (argument, not format string). The PR number
— the only variable interpolated directly — is a script-controlled integer, safe to
expand.

### Issue comment trigger fires on all slash commands, not just PR-Agent ones

The original ctrfhub workflow used `startsWith(github.event.comment.body, '/')` to
filter `issue_comment` events. This is too broad: `/close`, `/assign`, `/lgtm`, and
any other bot slash command would trigger a PR-Agent run. Combined with
`cancel-in-progress: true`, a Tugboat "preview ready" comment (posted by the
`aangelinsf` GitHub App identity, which GitHub surfaces as User type, not Bot)
arrived 60–120 s after the PR-Agent run kicked off, fired a second workflow run via
the `issue_comment` trigger, and `cancel-in-progress` then aborted the first
in-flight review. Because the label-applier and banner-injector steps gate on
`outcome == 'success'`, neither ran for that PR. The fix — already in the canonical
template — is to match only the four named PR-Agent commands:
`startsWith(comment.body, '/review') || startsWith(comment.body, '/describe') ||
startsWith(comment.body, '/improve') || startsWith(comment.body, '/ask')`.

Command-narrowing alone is **not sufficient**, because GitHub evaluates workflow-level
`concurrency` (and `cancel-in-progress`) when the run is *created*, before any job-level
`if`. So a comment that ultimately skips all jobs — an ordinary reply, or a `/review`
posted as the last line of a longer comment — still spawns a run in the
`pr-agent-<PR#>` group and, with `cancel-in-progress: true`, aborts a still-running
review. The robust fix is to make cancellation **event-scoped** in the thin caller so
comments never cancel a review:

```yaml
concurrency:
  group: pr-agent-${{ github.event.pull_request.number || github.event.issue.number }}
  cancel-in-progress: ${{ github.event_name != 'issue_comment' }}
```

A push (`synchronize`) still cancels a superseded in-flight review (the new commit makes
it stale); an `issue_comment` run never cancels — it queues behind the active review and
runs after. Note `/review` is matched with `startsWith`, so the command must be the **first
characters** of the comment, not buried mid-body.

### The paths-ignore block is a copy-time decision, not a runtime config value

GitHub Actions evaluates `paths-ignore` before the job runs, during event
filtering. It cannot read `.agents/pr-review.yml` at that point. This means
`pr_agent.skip_docs_only` in the config file is documentation only — the actual
behavior is determined by whether the `paths-ignore` block is present in the
copied workflow file. If you set `skip_docs_only: false`, you must remove or empty
the `paths-ignore` block from `.github/workflows/pr-review.yml` when you copy it.
If you leave the block in and set the flag to false, CI silently skips docs-only PRs
anyway.

### GitHub Actions YAML parser rejects unindented heredoc content in `run:` blocks

After the token-tracker rollout (#66), a follow-up commit (`39a7efa`) was needed to
fix a YAML parse error in the score-banner step. The heredoc body contained lines
starting at column 0 (the `<!-- argos-status:start -->` comment and the `> ...`
blockquote lines). GitHub Actions' YAML parser treats these as structural YAML rather
than shell script content, which silently dropped all `pull_request` triggers — the
workflow only fired on `issue_comment` events. The fix is to indent the entire
heredoc body at 10 spaces (matching the `run:` block's indentation level); the
shell strips the leading whitespace before executing. The canonical template has this
indentation already but be alert to it when editing score-banner content by hand.

### DCO `dcoapp/dco` GitHub App action does not exist

The initial token-tracker DCO implementation (pre-canonical) used
`dcoapp/dco@v1` as a GitHub Action. This action does not exist in the GitHub
Marketplace — the DCO bot operates as a GitHub App installed on the repository,
not as an Actions step. The job silently succeeded without checking anything.
The canonical `dco.yml` uses a pure-shell `git log` loop to check every non-merge
commit for a `Signed-off-by:` trailer. This approach has no external dependencies
and works on any runner with git available.

---

## 8. Projects using this pipeline

| Project | PR | Notes |
|---|---|---|
| token-tracker | [#66](https://github.com/Performant-Labs/token-tracker/pull/66) | Wave 1 — reference rollout |
| ctrfhub | [#239](https://github.com/Performant-Labs/ctrfhub/pull/239) | Wave 1 — dual_review enabled, pr:review alias |
| missile-money | [#46](https://github.com/Performant-Labs/missile-money/pull/46) | Wave 2 — dual_review enabled (o3), skip_docs_only |
| AlmondTTS | [#20](https://github.com/Performant-Labs/AlmondTTS/pull/20) | Wave 2 — replaces pr-agent-review.yml + slash-command.yml |
| language-buddy | [#236](https://github.com/Performant-Labs/language-buddy/pull/236) | Wave 2 — pr:review alias, skip_docs_only |
| performantlabs.com | [#248](https://github.com/Performant-Labs/performantlabs.com/pull/248) | Wave 2 — Drupal/Playwright, no paths-ignore; needs DEEPSEEK_API_KEY secret |
| personal-dashboard | [#37](https://github.com/Performant-Labs/personal-dashboard/pull/37) | Wave 2 — skip_docs_only, model upgraded from deepseek-chat |

---

*Part of the Universal Configurable PR Reviewer — `playbook/pipelines/pr-reviewer/`.*
*Milestone: Universal Configurable PR Reviewer (#97).*
