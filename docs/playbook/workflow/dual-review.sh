#!/usr/bin/env bash
# workflow/dual-review.sh — canonical, project-agnostic dual-review gate.
#
# Independent second-opinion review via the OpenAI Responses API (default model: o4-mini).
# NOTE (2026-06-16): migrated o3 -> o4-mini. OpenAI removes o3 from the API on 2026-12-11
#   (ChatGPT 2026-08-26). o4-mini is the available o4-series reasoning model (no plain "o4"
#   on the account); verified callable via /v1/responses with reasoning.effort=high.
#   Comparable reasoning to o3 at ~half the (current) price. Override with DUAL_REVIEW_MODEL.
# Used by the coding pipeline's review-rigor dial (second-opinion / panel): the brief gate
# (after Phase 1) and the diff gate (after Phase 6, T-green). See
# workflow/workflow-coding-pipeline.md (§Review-rigor) for where it sits in the pipeline.
#
# This script ships in playbook and is distributed into host projects at
# docs/playbook/workflow/dual-review.sh via the subtree mount. Run it directly,
# or wrap it with a thin project script that fills in default paths.
#
# Usage:
#   dual-review.sh --mode brief --brief <path> --out <path> [--round 2 --response <path>]
#   dual-review.sh --mode diff  --brief <path> --out <path> \
#                  [--handoff <path>] [--base <gitref>] [--diff <gitref-range>] \
#                  [--round 2 --response <path>]
#
# Flags:
#   --mode      brief | diff                (required)
#   --brief     path to brief.md            (required)
#   --out       path to write the review    (required)
#   --handoff   path to feature handoff     (diff mode, optional)
#   --base      base git ref for the diff   (diff mode; default: origin/main then main)
#   --diff      explicit "A..B" diff range  (diff mode; overrides --base autodetect)
#   --response  path to O's response doc     (round 2, required)
#   --round     1 | 2                        (default: 1)
#   --project   project name for prompt context (optional)
#
# Environment:
#   DUAL_REVIEW=1            enable (default: off — the gate is opt-in per story)
#   OPENAI_API_KEY           required
#   DUAL_REVIEW_MODEL        model (default: o4-mini; "o*" models get reasoning.effort=high)
#
# Gate behaviour (enforced by the Orchestrator, not this script):
#   BLOCK findings → hard gate; story pauses until resolved or the operator rules.
#   WARN / NIT     → recorded, not blocking.
#
# Discussion protocol (Round 2):
#   Round 1: reviewer raises findings → O writes a response doc addressing each.
#   Round 2: reviewer re-evaluates each finding → ACCEPTED or MAINTAINED.
#   Still MAINTAINED after round 2 → escalate to the operator.

set -euo pipefail

# --- Load .env (if present) BEFORE the switch ---------------------------------
# so DUAL_REVIEW / OPENAI_API_KEY / DUAL_REVIEW_MODEL set in .env take effect.
# shellcheck disable=SC1091
[[ -f ".env" ]] && set -a && source .env && set +a

# --- Global switch -----------------------------------------------------------
if [[ "${DUAL_REVIEW:-0}" != "1" ]]; then
  echo "# Dual Review — SKIPPED (DUAL_REVIEW not set to 1)" >&2
  exit 0
fi

# --- Argument parsing --------------------------------------------------------
MODE="" BRIEF_FILE="" OUT_FILE="" HANDOFF_FILE="" RESPONSE_FILE=""
BASE_REF="" DIFF_RANGE="" ROUND=1 PROJECT="" PROMPT_FILE="" DUMP_ONLY=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode)        MODE="${2:-}";          shift 2 ;;
    --brief)       BRIEF_FILE="${2:-}";    shift 2 ;;
    --out)         OUT_FILE="${2:-}";      shift 2 ;;
    --handoff)     HANDOFF_FILE="${2:-}";  shift 2 ;;
    --base)        BASE_REF="${2:-}";      shift 2 ;;
    --diff)        DIFF_RANGE="${2:-}";    shift 2 ;;
    --response)    RESPONSE_FILE="${2:-}"; shift 2 ;;
    --round)       ROUND="${2:-1}";        shift 2 ;;
    --project)     PROJECT="${2:-}";       shift 2 ;;
    # --- tri-review parity flags ---------------------------------------------
    # --dump-only   : assemble the prompt, write the sidecar, and EXIT without
    #                 calling the API (no OPENAI_API_KEY needed). Use this once
    #                 per gate to capture the canonical prompt bytes.
    # --prompt-file : skip all assembly and send these EXACT bytes as the prompt.
    #                 Feed the o4-mini arm the file produced by --dump-only so both
    #                 the o4-mini and the Opus reviewer receive byte-identical input.
    --prompt-file) PROMPT_FILE="${2:-}";   shift 2 ;;
    --dump-only)   DUMP_ONLY=1;            shift 1 ;;
    *) echo "Unknown flag: $1" >&2; exit 1 ;;
  esac
done

if [[ -n "$PROMPT_FILE" ]]; then
  [[ -n "$OUT_FILE" ]] || { echo "Error: --out is required" >&2; exit 1; }
else
  [[ "$MODE" == "brief" || "$MODE" == "diff" ]] || { echo "Error: --mode must be 'brief' or 'diff'" >&2; exit 1; }
  [[ -n "$BRIEF_FILE" ]] || { echo "Error: --brief is required" >&2; exit 1; }
  [[ -n "$OUT_FILE"   ]] || { echo "Error: --out is required" >&2; exit 1; }
fi

if [[ "$DUMP_ONLY" != "1" ]]; then
  command -v jq   &>/dev/null || { echo "Error: jq is required" >&2; exit 1; }
  command -v curl &>/dev/null || { echo "Error: curl is required" >&2; exit 1; }
  [[ -n "${OPENAI_API_KEY:-}" ]] || { echo "Error: OPENAI_API_KEY is not set" >&2; exit 1; }
fi

MODEL="${DUAL_REVIEW_MODEL:-o4-mini}"
PROJECT_CTX="${PROJECT:+ for the ${PROJECT} project}"

mkdir -p "$(dirname "$OUT_FILE")"

DISCIPLINE="Your discipline:
- Any claim about runtime behavior — execution ordering, library defaults, middleware sequencing, plugin lifecycle, external state — is a hypothesis until verified from source (library code or official docs). Treat unverified runtime claims as BLOCK findings.
- Be adversarial to the framing you are given. Your job is to find problems, not validate decisions."

# --- Build reviewer prompt ---------------------------------------------------
if [[ -n "$PROMPT_FILE" ]]; then
  # Replay an exact, pre-assembled prompt (tri-review parity). No assembly.
  [[ -f "$PROMPT_FILE" ]] || { echo "Error: --prompt-file not found at ${PROMPT_FILE}" >&2; exit 1; }
  PROMPT="$(cat "$PROMPT_FILE")"
elif [[ "$MODE" == "brief" && "$ROUND" == "1" ]]; then
  [[ -f "$BRIEF_FILE" ]] || { echo "Error: brief not found at ${BRIEF_FILE}" >&2; exit 1; }
  PROMPT="You are an independent architecture reviewer${PROJECT_CTX}. You have no loyalty to the brief's framing — your job is to find problems before they are built.

${DISCIPLINE}

Also check:
- Acceptance criteria are complete and testable.
- Scope is appropriately bounded.
- Missing error paths or edge cases.

Output format (use exactly this structure):

## Brief Review (Round 1)

### BLOCK findings
Each as: **[B-N]** description — why it blocks — what must be clarified or fixed.
If none: \"None.\"

### WARN findings
Each as: **[W-N]** description — recommendation.
If none: \"None.\"

### NIT findings
Each as: **[NIT-N]** description.
If none: \"None.\"

### Verdict
PASS — no BLOCK findings; implementation may proceed.
OR
BLOCK — N blocking finding(s); must resolve before implementation starts.

---

## Brief

$(cat "$BRIEF_FILE")"

elif [[ "$MODE" == "brief" && "$ROUND" == "2" ]]; then
  [[ -f "${RESPONSE_FILE:-}" ]] || { echo "Error: round 2 requires --response <path>" >&2; exit 1; }
  PROMPT="You are an independent architecture reviewer${PROJECT_CTX}. This is Round 2 — you previously raised BLOCK findings on the brief, and the Orchestrator has responded. Evaluate each response.

Output format:

## Brief Review (Round 2)

### BLOCK finding responses
For each: **[B-N] ACCEPTED** — reason. OR **[B-N] MAINTAINED** — what is still missing.

### Verdict
PASS — all BLOCK findings accepted; implementation may proceed.
OR
BLOCK — N finding(s) still unresolved; escalate to the operator.

---

## Original Brief

$(cat "$BRIEF_FILE")

---

## Orchestrator's Response

$(cat "$RESPONSE_FILE")"

elif [[ "$MODE" == "diff" && "$ROUND" == "1" ]]; then
  [[ -f "$BRIEF_FILE" ]] || { echo "Error: brief not found at ${BRIEF_FILE}" >&2; exit 1; }
  HANDOFF_CONTENT=""
  [[ -n "$HANDOFF_FILE" && -f "$HANDOFF_FILE" ]] && HANDOFF_CONTENT="$(cat "$HANDOFF_FILE")"

  if [[ -n "$DIFF_RANGE" ]]; then
    DIFF=$(git diff "$DIFF_RANGE" 2>/dev/null || echo "(diff unavailable for range ${DIFF_RANGE})")
  else
    BASE="${BASE_REF:-}"
    if [[ -z "$BASE" ]]; then
      git rev-parse --verify -q origin/main >/dev/null && BASE="origin/main" || BASE="main"
    fi
    DIFF=$(git diff "${BASE}...HEAD" 2>/dev/null || git diff "${BASE}..HEAD" 2>/dev/null || echo "(diff unavailable against ${BASE})")
  fi

  PROMPT="You are an independent architecture reviewer${PROJECT_CTX}. You have no loyalty to the implementation's framing — your job is to find problems before they are tested and merged.

${DISCIPLINE}

Also check:
- Implementation matches the brief's acceptance criteria.
- Correctness bugs, security issues, spec drift.
- Error paths are handled.

Output format (use exactly this structure):

## Implementation Review (Round 1)

### BLOCK findings
Each as: **[B-N]** file:line — description — why it blocks — remediation.
If none: \"None.\"

### WARN findings
Each as: **[W-N]** file:line — description — recommendation.
If none: \"None.\"

### NIT findings
Each as: **[NIT-N]** file:line — description.
If none: \"None.\"

### Verdict
PASS — no BLOCK findings; testing may proceed.
OR
BLOCK — N blocking finding(s); must resolve before testing starts.

---

## Brief

$(cat "$BRIEF_FILE")

---

## Feature Handoff

${HANDOFF_CONTENT:-(no handoff file)}

---

## Diff

\`\`\`diff
${DIFF}
\`\`\`"

else
  # diff round 2
  [[ -f "${RESPONSE_FILE:-}" ]] || { echo "Error: round 2 requires --response <path>" >&2; exit 1; }
  PROMPT="You are an independent architecture reviewer${PROJECT_CTX}. This is Round 2 — you previously raised BLOCK findings on the implementation, and the Orchestrator has responded. Evaluate each response.

Output format:

## Implementation Review (Round 2)

### BLOCK finding responses
For each: **[B-N] ACCEPTED** — reason. OR **[B-N] MAINTAINED** — what is still missing.

### Verdict
PASS — all BLOCK findings accepted; testing may proceed.
OR
BLOCK — N finding(s) still unresolved; escalate to the operator.

---

## Orchestrator's Response

$(cat "$RESPONSE_FILE")"
fi

# --- Persist the exact assembled prompt (sidecar) for tri-review parity ------
# The Opus arm of a tri-review is fed THIS file verbatim, guaranteeing it and
# the o4-mini arm review byte-identical input. Always written, even on a normal run.
PROMPT_SIDECAR="${OUT_FILE}.prompt.txt"
mkdir -p "$(dirname "$OUT_FILE")"
printf '%s' "$PROMPT" > "$PROMPT_SIDECAR"
echo "→ Assembled prompt written to ${PROMPT_SIDECAR}" >&2

if [[ "$DUMP_ONLY" == "1" ]]; then
  echo "→ --dump-only set; prompt captured, skipping API call." >&2
  exit 0
fi

# --- Call OpenAI Responses API ----------------------------------------------
echo "→ Running dual review (mode: ${MODE}, round: ${ROUND}, model: ${MODEL})..." >&2

PROMPT_TMPFILE=$(mktemp "${TMPDIR:-/tmp}/dual-review-prompt.XXXXXX")
PAYLOAD_TMPFILE=$(mktemp "${TMPDIR:-/tmp}/dual-review-payload.XXXXXX")
trap 'rm -f "$PROMPT_TMPFILE" "$PAYLOAD_TMPFILE"' EXIT
printf '%s' "$PROMPT" > "$PROMPT_TMPFILE"

if [[ "$MODEL" == o* ]]; then
  jq -Rs --arg model "$MODEL" '{"model": $model, "input": ., "reasoning": {"effort": "high"}}' \
    "$PROMPT_TMPFILE" > "$PAYLOAD_TMPFILE"
else
  jq -Rs --arg model "$MODEL" '{"model": $model, "input": .}' \
    "$PROMPT_TMPFILE" > "$PAYLOAD_TMPFILE"
fi

RESPONSE=$(curl -s --max-time 600 --connect-timeout 30 \
  -X POST https://api.openai.com/v1/responses \
  -H "Authorization: Bearer ${OPENAI_API_KEY}" \
  -H "Content-Type: application/json" \
  --data-binary "@${PAYLOAD_TMPFILE}")

OUTPUT=$(echo "$RESPONSE" | jq -r '.output[] | select(.type == "message") | .content[] | select(.type == "output_text") | .text' 2>/dev/null)

if [[ -z "$OUTPUT" ]]; then
  echo "Error: no output from API. Response:" >&2
  echo "$RESPONSE" | jq . >&2
  exit 1
fi

echo "$OUTPUT" > "$OUT_FILE"
echo "→ Review written to ${OUT_FILE}" >&2
echo "$OUTPUT"
