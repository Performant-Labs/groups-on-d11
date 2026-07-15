# Test-Case Schema (the interface)

A **semantic test case** is the durable source of truth. The Authoring pipeline writes it;
the Execution pipeline compiles and runs it. It is intentionally **not** a Playwright spec —
it describes behavior at the level of *intent and oracles*, so it survives UI refactors and
can be re-compiled / re-healed without losing the "why."

Stored as YAML, one case per file: `examples/<id>.case.yaml`.

## Why semantic, not a spec

- **Steps are intent-level, not selectors** — `select language es-MX`, not
  `page.click('#lang-3')`. A refactor that renames the selector doesn't invalidate the case.
- **Oracles are typed and explicit** — the assertion's *kind* is declared, so the compiler
  emits the right check and the Author can't get away with a weak "page loaded" oracle.
- **The case carries its own preconditions** (seed) and its own **falsifiability proof**
  (negative control), so a reviewer/Verifier can trust it without rerunning discovery.

## Fields

| Field | Req | Meaning |
|---|---|---|
| `id` | ✓ | Stable unique id, e.g. `LB-BULKAUDIO-001`. Never reused. |
| `title` | ✓ | One-line human summary. |
| `feature` | ✓ | Feature name. |
| `surface.route` | ✓ | Primary route under test. |
| `surface.reach` | ✓ | `spa` (HTMX/SPA swap — the real nav path) or `hard` (full load). |
| `risk` | ✓ | `low` / `med` / `high`. Drives attention + run priority. High = auth, data mutation, money, destructive, external effects. |
| `intent` | ✓ | The **user goal** this case protects, in plain language. The case must prove the user can achieve it. |
| `trace.source` | ✓ | Where this came from — a U finding (`docs/handoffs/…-U.md`), a spec section, an issue. Closes the discovery→regression loop. |
| `seed.requires` | ✓ | The data precondition, named. e.g. "es-MX deck with MIXED audio coverage". |
| `seed.note` |  | Why the default seed is insufficient (if it is). |
| `setup.serve` | ✓ | How to bring the app up (command + any gate env). |
| `setup.login` | ✓ | Identity to drive as. |
| `steps[]` | ✓ | Ordered intent-level actions (see Steps). |
| `oracles[]` | ✓ | Typed assertions (see Oracle types). May be inline on a step or case-global. |
| `viewports` | ✓ | e.g. `[1280, 360]`. |
| `negative_control[]` | ✓ | One or more "break X → which oracle must FAIL". The Verifier uses these to prove the case is falsifiable. |
| `status` | ✓ | `draft` → `verified` (passed Verifier) → `compiled` (spec emitted). |

## Steps (intent verbs)

Selector-free, compiler-resolved. Canonical verbs:

- `nav_spa: <route>` / `nav_hard: <route>` — reach a page (SPA swap vs full load)
- `select: { control: <name>, value: <v> }`
- `fill: { control: <name>, value: <v> }`
- `click: <control>`
- `set_mode: <value>` — domain toggle (project-defined)
- `read: { as: <var>, from: <description> }` — capture a value for a later oracle
- `wait_for: <condition>` — a real condition, never a fixed timeout

## Oracle types (the value layer)

Every oracle declares a `kind`. This is what makes the suite trustworthy.

| `kind` | Asserts | Example |
|---|---|---|
| `structural` | The machinery is alive on the path | Alpine `_x_dataStack` populated; console clean; **no blocking overlay** |
| `content` | The screen says the right thing | "M would be generated" is shown before any job starts |
| `data` | The UI's claim matches the **backend truth** (query the seeded DB) | `M == SELECT count(*) … WHERE audio_url IS NULL` |
| `relational` | A **metamorphic** invariant across states/inputs holds | `regenerate_all_count >= fill_missing_count` |

`structural` oracles are implicit defaults on every step. `data` and `relational` are the
two most valuable and the least common in off-the-shelf tools — they're the point.

## Negative control (falsifiability)

A test that can never fail is decorative. Each case lists how to break it and which oracle
must then fail. The Verifier applies one and confirms the case turns red — otherwise the
case is rejected, never promoted to `verified`.

## Worked shape

```yaml
id: <PREFIX>-NNN
title: …
feature: …
surface: { route: /…, reach: spa }
risk: high
intent: >
  <what the user must be able to do>
trace: { source: docs/handoffs/<feature>-U.md }
seed:
  requires: "<named data precondition>"
  note: "<why default seed is insufficient>"
setup:
  serve: "<command + gate env>"
  login: admin
steps:
  - nav_spa: /…
  - select: { control: …, value: … }
  - click: preview
  - read: { as: count_a, from: "the affected-count in #…" }
oracles:                                    # each oracle's kind carries the fields the runner needs
  - { kind: structural, on: every_step }    # implicit default: Alpine/console/overlay
  - { kind: content, expectText: "<text that must be present before any mutation>" }
  - { kind: data, var: count_a }            # runner: assertEquals(captured.count_a, scalar(adapter.sql('count_a', captured)))
  - { kind: relational, lhs: count_b, op: ">=", rhs: count_a }
# a `note:` may be added to any oracle for human context; the structured fields drive execution.
viewports: [1280, 360]
negative_control:
  - break: "<mutation>"  fails: relational
status: draft
```

See `examples/` for a real, filled-in case.
