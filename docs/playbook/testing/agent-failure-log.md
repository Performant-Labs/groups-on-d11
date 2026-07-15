
# Agent Failure Log — CTRFHub Coordinator

**Started:** 2026-05-02
**Provider:** DeepSeek V4 Pro → Anthropic (default, model TBD)

## Failure #1–5: CTRF-004 Wave 2 Test Writer

| # | Agent ID | Specialist | Provider | Turn 1 | Outcome |
|---|----------|-----------|----------|--------|---------|
| 1 | agent-7d9d73be | test-writer | DeepSeek V4 | ? | Wrote files; lost to worktree collision |
| 2 | agent-b4f0ae5e | test-writer (Flash) | DeepSeek V4 Flash | 0 | Empty response, failed |
| 3 | agent-70306913 | test-writer (Pro) | DeepSeek V4 Pro | 7 | Failed mid-execution |
| 4 | agent-64fba282 | implementor | DeepSeek V4 Pro | 18 | Failed mid-execution, zero files |
| 5 | agent-cb6feeb3 | implementor | Anthropic | 23 | Read-heavy, timeout, zero files |

## Pattern

- All agents fail on turn 1 or before turn 2 completes
- DeepSeek: empty responses or crash mid-execution
- Anthropic: 23 tool calls (all reads), never reached turn 2
- Zero files committed across attempts 2–5

## Hypotheses

1. Turn 1 overload — too many tool calls causes timeout
2. Task scope too large — 24 tests requires too much context
3. Specialist config issue — behaviorPrompt vs specialist prompt conflict

## Next test

Stack: Write-first rule + split scope + developer specialist + max 3 tool calls/turn
