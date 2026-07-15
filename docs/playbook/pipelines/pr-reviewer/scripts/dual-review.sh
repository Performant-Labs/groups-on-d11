#!/usr/bin/env bash
# dual-review.sh — Independent architecture review via the OpenAI Responses API.
#
# Used by the Orchestrator at Phase 4 (brief review) and Phase 7 (impl review)
# of the implementstory workflow. Activated only when dual_review.enabled is true
# in .agents/pr-review.yml (or DUAL_REVIEW=1 env override).
#
# Usage:
#   .agents/scripts/dual-review.sh --mode brief --task-id <id> [--round 2]
#   .agents/scripts/dual-review.sh --mode impl  --task-id <id> [--round 2]
#
# Output written to:
#   .argos/stories/<taskId>/dual-{brief|impl}-review[-2].md
# and echoed to stdout.
#
# Discussion protocol (enforced by Orchestrator):
#   Round 1: reviewer raises findings → Orchestrator writes argos-{brief|impl}-response.md
#   Round 2: reviewer re-evaluates → ACCEPT or MAINTAIN BLOCK per finding
#   Still BLOCK after round 2 → escalate to human
#
# Gate behaviour (enforced by Orchestrator):
#   BLOCK findings → hard gate; story pauses until resolved.
#   WARN / NIT     → recorded, not blocking.
#
# Prerequisites:
#   - OPENAI_API_KEY set in .env or environment
#   - curl and jq available
#   - Run from the repo root (CLAUDE.md must be in cwd)

set -euo pipefail

export PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"

# ── Config reader (yq with grep fallback) ──────────────────────────────────
CONFIG_FILE=".agents/pr-review.yml"

cfg_get() {
  local key="$1" fallback="${2:-}"
  if [[ ! -f "$CONFIG_FILE" ]]; then echo "$fallback"; return; fi
  if command -v yq &>/dev/null; then
    local val
    val=$(yq e "${key} // \"\"" "$CONFIG_FILE" 2>/dev/null)
    echo "${val:-$fallback}"
  else
    local stripped="${key##*.}"
    local val
    val=$(grep -E "^\s*${stripped}:" "$CONFIG_FILE" 2>/dev/null | head -1 \
          | sed 's/.*: *//;s/"//g;s/\r//')
    echo "${val:-$fallback}"
  fi
}

# ── Global switch ────────────────────────────────────────────────────────────
# DUAL_REVIEW=1 env var overrides the config file (useful for one-off runs).
if [[ "${DUAL_REVIEW:-0}" != "1" ]]; then
  ENABLED="$(cfg_get '.dual_review.enabled' 'false')"
  if [[ "$ENABLED" != "true" ]]; then
    echo "# Dual Review — SKIPPED (dual_review.enabled is false; set DUAL_REVIEW=1 to force)" >&2
    exit 0
  fi
fi

# ── Argument parsing ─────────────────────────────────────────────────────────
MODE=""
TASK_ID=""
ROUND=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode)    MODE="${2:?--mode requires a value}";    shift 2 ;;
    --task-id) TASK_ID="${2:?--task-id requires a value}"; shift 2 ;;
    --round)   ROUND="${2:-1}";  shift 2 ;;
    *) echo "Unknown flag: $1" >&2; exit 1 ;;
  esac
done

if [[ -z "$MODE" || -z "$TASK_ID" ]]; then
  echo "Usage: $0 --mode <brief|impl> --task-id <id> [--round <1|2>]" >&2
  exit 1
fi

if [[ "$MODE" != "brief" && "$MODE" != "impl" ]]; then
  echo "Error: --mode must be 'brief' or 'impl'" >&2
  exit 1
fi

# ── Preflight checks ─────────────────────────────────────────────────────────
if ! command -v jq &>/dev/null; then
  echo "Error: jq is required (brew install jq)" >&2; exit 1
fi
if ! command -v curl &>/dev/null; then
  echo "Error: curl is required" >&2; exit 1
fi
if [[ ! -f "CLAUDE.md" ]]; then
  echo "Error: run from the repo root (CLAUDE.md not found)" >&2; exit 1
fi

# Source .env so keys defined there are available.
# shellcheck disable=SC1091
if [[ -f ".env" ]]; then set -a; source .env || true; set +a; fi

if [[ -z "${OPENAI_API_KEY:-}" ]]; then
  echo "Error: OPENAI_API_KEY is not set" >&2; exit 1
fi

# ── Paths ─────────────────────────────────────────────────────────────────────
PROJECT_NAME="$(cfg_get '.project.name' "$(basename "$PWD")")"
MODEL="${DUAL_REVIEW_MODEL:-$(cfg_get '.dual_review.model' 'o3')}"

# Support .argos/stories/<taskId>/ and legacy .argos/<taskId>/ layouts.
if [[ -d ".argos/stories/${TASK_ID}" ]]; then
  ARGOS_DIR=".argos/stories/${TASK_ID}"
elif [[ -d ".argos/${TASK_ID}" ]]; then
  ARGOS_DIR=".argos/${TASK_ID}"
else
  ARGOS_DIR=".argos/stories/${TASK_ID}"
fi
mkdir -p "$ARGOS_DIR"

BRIEF_FILE="${ARGOS_DIR}/brief.md"
HANDOFF_FILE="${ARGOS_DIR}/feature-handoff.md"
ARGOS_BRIEF_RESPONSE="${ARGOS_DIR}/argos-brief-response.md"
ARGOS_IMPL_RESPONSE="${ARGOS_DIR}/argos-impl-response.md"

if [[ "$MODE" == "brief" ]]; then
  [[ "$ROUND" == "2" ]] \
    && OUTPUT_FILE="${ARGOS_DIR}/dual-brief-review-2.md" \
    || OUTPUT_FILE="${ARGOS_DIR}/dual-brief-review.md"
else
  [[ "$ROUND" == "2" ]] \
    && OUTPUT_FILE="${ARGOS_DIR}/dual-impl-review-2.md" \
    || OUTPUT_FILE="${ARGOS_DIR}/dual-impl-review.md"
fi

# ── Build reviewer prompt (safe construction) ─────────────────────────────────
# Static sections use quoted heredocs (<<'EOF') — no variable or command
# expansion. Controlled values (PROJECT_NAME, TASK_ID) are inserted with
# printf. Untrusted file contents (brief, handoff, diff) are appended with
# `cat file >> tmp` — never expanded inside a double-quoted string.

PROMPT_TMP=$(mktemp /tmp/dual-review-prompt.XXXXXX)
PAYLOAD_TMP=$(mktemp /tmp/dual-review-payload.XXXXXX)
trap 'rm -f "$PROMPT_TMP" "$PAYLOAD_TMP"' EXIT

if [[ "$MODE" == "brief" && "$ROUND" == "1" ]]; then
  [[ -f "$BRIEF_FILE" ]] || { echo "Error: brief not found at ${BRIEF_FILE}" >&2; exit 1; }

  printf 'You are an independent architecture reviewer for the %s project. You have no loyalty to the brief'\''s framing — your job is to find problems before they are built.\n\n' \
    "$PROJECT_NAME" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'
Your discipline:
- Any claim about runtime behaviour — execution ordering, library defaults, middleware sequencing, plugin lifecycle, external state — is a hypothesis until verified from source (library code or official docs). Treat unverified runtime claims as BLOCK findings.
- Be adversarial to the framing you are given. Your job is to find problems, not validate decisions.

Also check:
- Acceptance criteria are complete and testable.
- Scope is appropriately bounded.
- Missing error paths or edge cases.

Output format (use exactly this structure):
STATIC
  printf '\n## Brief Review — %s (Round 1)\n' "$TASK_ID" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'

### BLOCK findings
Each as: **[B-N]** description — why it blocks — what must be clarified or fixed.
If none: "None."

### WARN findings
Each as: **[W-N]** description — recommendation.
If none: "None."

### NIT findings
Each as: **[NIT-N]** description.
If none: "None."

### Verdict
PASS — no BLOCK findings; implementation may proceed.
OR
BLOCK — N blocking finding(s); must resolve before implementation starts.

---

## Task Brief

STATIC
  cat "$BRIEF_FILE" >> "$PROMPT_TMP"

elif [[ "$MODE" == "brief" && "$ROUND" == "2" ]]; then
  [[ -f "$ARGOS_BRIEF_RESPONSE" ]] || { echo "Error: round 2 requires ${ARGOS_BRIEF_RESPONSE}" >&2; exit 1; }

  printf 'You are an independent architecture reviewer for the %s project. This is Round 2 — you previously raised BLOCK findings on the task brief, and the Orchestrator has responded. Evaluate each response.\n' \
    "$PROJECT_NAME" >> "$PROMPT_TMP"
  printf '\n## Brief Review — %s (Round 2)\n' "$TASK_ID" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'

### BLOCK finding responses
For each: **[B-N] ACCEPTED** — reason. OR **[B-N] MAINTAINED** — what is still missing.

### Verdict
PASS — all BLOCK findings accepted; implementation may proceed.
OR
BLOCK — N finding(s) still unresolved; escalate to human.

---

## Original Brief

STATIC
  cat "$BRIEF_FILE" >> "$PROMPT_TMP"
  printf '\n\n---\n\n## Orchestrator'\''s Response\n\n' >> "$PROMPT_TMP"
  cat "$ARGOS_BRIEF_RESPONSE" >> "$PROMPT_TMP"

elif [[ "$MODE" == "impl" && "$ROUND" == "1" ]]; then
  [[ -f "$BRIEF_FILE" ]] || { echo "Error: brief not found at ${BRIEF_FILE}" >&2; exit 1; }

  DIFF=$(git diff main..story/"${TASK_ID}" 2>/dev/null \
      || git diff origin/main..HEAD 2>/dev/null \
      || echo "(diff unavailable)")

  printf 'You are an independent architecture reviewer for the %s project. You have no loyalty to the implementation'\''s framing — your job is to find problems before they are tested and merged.\n\n' \
    "$PROJECT_NAME" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'
Your discipline:
- Any claim about runtime behaviour — execution ordering, library defaults, middleware sequencing, plugin lifecycle, external state — is a hypothesis until verified from source (library code or official docs). Treat unverified runtime claims as BLOCK findings.
- Be adversarial to the framing you are given. Your job is to find problems, not validate decisions.

Also check:
- Implementation matches the brief's acceptance criteria.
- Correctness bugs, security issues, spec drift.
- Error paths are handled.

Output format (use exactly this structure):
STATIC
  printf '\n## Implementation Review — %s (Round 1)\n' "$TASK_ID" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'

### BLOCK findings
Each as: **[B-N]** file:line — description — why it blocks — remediation.
If none: "None."

### WARN findings
Each as: **[W-N]** file:line — description — recommendation.
If none: "None."

### NIT findings
Each as: **[NIT-N]** file:line — description.
If none: "None."

### Verdict
PASS — no BLOCK findings; testing may proceed.
OR
BLOCK — N blocking finding(s); must resolve before testing starts.

---

## Task Brief

STATIC
  cat "$BRIEF_FILE" >> "$PROMPT_TMP"
  printf '\n\n---\n\n## Feature Handoff\n\n' >> "$PROMPT_TMP"
  if [[ -f "$HANDOFF_FILE" ]]; then
    cat "$HANDOFF_FILE" >> "$PROMPT_TMP"
  else
    printf '(no handoff file)\n' >> "$PROMPT_TMP"
  fi
  printf '\n\n---\n\n## Diff\n\n```diff\n' >> "$PROMPT_TMP"
  # Diff is from git — controlled source, but still written via printf '%s'
  # to avoid any printf format-string interpretation of diff content.
  printf '%s\n' "$DIFF" >> "$PROMPT_TMP"
  printf '```\n' >> "$PROMPT_TMP"

else
  # impl round 2
  [[ -f "$ARGOS_IMPL_RESPONSE" ]] || { echo "Error: round 2 requires ${ARGOS_IMPL_RESPONSE}" >&2; exit 1; }

  printf 'You are an independent architecture reviewer for the %s project. This is Round 2 — you previously raised BLOCK findings on the implementation, and the Orchestrator has responded. Evaluate each response.\n' \
    "$PROJECT_NAME" >> "$PROMPT_TMP"
  printf '\n## Implementation Review — %s (Round 2)\n' "$TASK_ID" >> "$PROMPT_TMP"
  cat >> "$PROMPT_TMP" <<'STATIC'

### BLOCK finding responses
For each: **[B-N] ACCEPTED** — reason. OR **[B-N] MAINTAINED** — what is still missing.

### Verdict
PASS — all BLOCK findings accepted; testing may proceed.
OR
BLOCK — N finding(s) still unresolved; escalate to human.

---

## Orchestrator's Response

STATIC
  cat "$ARGOS_IMPL_RESPONSE" >> "$PROMPT_TMP"
fi

# ── Call OpenAI Responses API ─────────────────────────────────────────────────
echo "→ Dual Review (mode: ${MODE}, round: ${ROUND}, task: ${TASK_ID}, model: ${MODEL})..." >&2

if [[ "$MODEL" == o* ]]; then
  jq -Rs --arg model "$MODEL" \
    '{"model": $model, "input": ., "reasoning": {"effort": "high"}}' \
    "$PROMPT_TMP" > "$PAYLOAD_TMP"
else
  jq -Rs --arg model "$MODEL" \
    '{"model": $model, "input": .}' \
    "$PROMPT_TMP" > "$PAYLOAD_TMP"
fi

RESPONSE=$(curl -s -X POST https://api.openai.com/v1/responses \
  -H "Authorization: Bearer ${OPENAI_API_KEY}" \
  -H "Content-Type: application/json" \
  --data-binary "@${PAYLOAD_TMP}")

OUTPUT=$(echo "$RESPONSE" \
  | jq -r '.output[] | select(.type=="message") | .content[] | select(.type=="output_text") | .text' \
  2>/dev/null)

if [[ -z "$OUTPUT" ]]; then
  echo "Error: no output from API. Full response:" >&2
  echo "$RESPONSE" | jq . >&2
  exit 1
fi

echo "$OUTPUT" > "$OUTPUT_FILE"
echo "→ Review written to ${OUTPUT_FILE}" >&2
echo "$OUTPUT"
