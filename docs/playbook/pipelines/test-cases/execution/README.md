# Execution Pipeline

**Input:** verified semantic cases from authoring.
**Output:** pass/fail per case, with triaged failures.

The point of execution is to replay codified behavior **fast and repeatably**. So most of
it is **not an agent** — that's deliberate. An LLM in the inner loop would reintroduce the
cost and nondeterminism the whole two-tier design exists to avoid.

## Roles

| Handle | Agent? | Responsibility |
|---|---|---|
| **K** | no — mechanical | **Compile** the semantic case → a deterministic Playwright spec via a project **adapter** (maps intent verbs + control names → selectors, and oracle SQL). |
| **R** | no — mechanical | **Run** the compiled specs (or interpret the case directly via `../lib/run-case.mjs`) on the self-hosted `pl-runner` fleet. Emits pass/fail + traces/screenshots. Evaluates `data` oracles against the seeded DB (`../lib/data-oracle.mjs`). |
| **T** | yes (U-logic) | **Triage** a failure: real regression vs UI drift vs flaky/env. Verdict + evidence. Only fires on failure. |
| **H** | yes *(later)* | **Heal**, only on Triage's "drift" verdict: re-derive steps from the **semantic source case** (not the broken spec), update the spec, re-run. Never heals a real regression; every heal lands as a PR. |

**Lean v1:** `K → R → T`. Skip the Healer at first — on failure, Triage reports
"real regression vs drift" and a human decides.

## How a case runs (two modes)

- **Interpret** (`lib/run-case.mjs`) — read the `.case.yaml`, drive the live app through a
  project adapter, evaluate oracles live. Best for the first proof + low-volume on-demand.
- **Compile** (`K`) — emit a standalone `.spec.ts` for the project's e2e suite, so it runs
  in CI with zero LLM. Best for the durable regression corpus.

Both share the **adapter** (project-specific selector + SQL map) and `lib/`.

## The adapter (project-specific, the only coupling point)

A semantic case is portable; turning it into clicks is not. Each project supplies an adapter:

```
control name (intent)        →  selector
  language                   →  select[name="languageCode"]
  engine                     →  select[name="ttsEngine"]
  mode                       →  [name="mode"] radios
  preview (button)           →  button[data-action="preview"]
  "would be generated" count →  #backfill-result-container [data-count]
data oracle SQL              →  parameterized query against SQLITE_PATH
```

The adapter is the **only** place selectors live. A UI refactor touches the adapter, not the
cases — which is why the cases stay durable.
