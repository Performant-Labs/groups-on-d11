# Test-Case Pipelines

Two standalone, on-demand pipelines that turn **behavior a human/agent already discovered**
into **durable, fast, repeatable regression tests**.

```
discovery (U, or the spec)  ──►  AUTHORING  ──►  test-case artifact  ──►  EXECUTION  ──►  pass/fail
   "what's the behavior?"        codify it        (the interface)         replay it fast
```

- **[Authoring](authoring/)** — takes a *known* behavior (a confirmed U finding, or an
  acceptance criterion) and codifies it into a **semantic test case**: intent-level steps
  + typed oracles + seed requirements + a negative control. It does **not** explore — that
  is U's job. It crystallizes.
- **[Execution](execution/)** — compiles each semantic case to a deterministic Playwright
  spec, runs it on the self-hosted runners, and triages failures (real regression vs UI
  drift). Cheap and repeatable; no LLM in the inner loop.

The contract between them is **[test-case-schema.md](test-case-schema.md)** — the
"test-step data." Authoring writes it; Execution consumes it.

## NOT Playwright Agents

This mechanism is **independent of the Playwright Agents install** on this system. PW Agents
serves a different terminal purpose there (it *customizes predefined PW scripts* during
another product's install). These pipelines have a different ending — they *codify newly
discovered behavior into repeatable steps that did not exist before*. The two are kept
fully separate by design: nothing here reads, writes, or depends on `.claude/agents/
playwright-test-*`, `specs/*.plan.md`, or any PW Agents artifact. Do not add a switch to
make one pipeline do both jobs.

## Status

On-demand only. Not wired into the coding pipeline or any existing pipeline. Invoked deliberately,
typically right after a feature lands or a U walkthrough surfaces something worth locking in.

## Layout

| Path | What |
|---|---|
| `test-case-schema.md` | The semantic test-case format (the interface) |
| `authoring/` | Authoring pipeline — roles + flow |
| `execution/` | Execution pipeline — compile, run, triage |
| `lib/` | Shared helpers (data-oracle, runner) — no PW Agents deps |
| `examples/` | Real cases (e.g. the bulk-audio preview-count gap) |
