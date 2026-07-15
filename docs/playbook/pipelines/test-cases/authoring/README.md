# Authoring Pipeline

**Input:** a *known* behavior — a confirmed U finding (`docs/handoffs/…-U.md`) or an
acceptance criterion.
**Output:** a `verified` semantic test case (`examples/<id>.case.yaml`) ready to compile.

Authoring **codifies**; it does not explore. Discovery is U's job (in the coding pipeline) — this pipeline
consumes what U found and turns it into something fast and repeatable. (If ever pointed at a
bare, un-explored surface, the optional Explorer step below gathers candidate behaviors
first — but it never changes the pipeline's ending, which is always a codified case.)

## Roles

| Handle | Role | Responsibility | Gate |
|---|---|---|---|
| **O** | Orchestrator | Scope the target, gather the source finding/criterion, sequence roles, own the corpus write | — |
| **(E)** | Explorer *(optional)* | Only when no finding exists yet: drive the live UI (via `u-drive.mjs`) to list candidate behaviors. Skipped when a U finding is the input. | — |
| **D** | Adapter *(once per surface)* | **Step 0 of authoring.** Produce the project adapter for the surface from the **real DOM/DB** — selectors, `read()`, data-oracle SQL, count locator, seed precondition. Reused by every case for that surface; skipped when the surface already has an adapter. Scaffold: `adapter.template.mjs`. | → A |
| **A** | Author | Write the semantic case per `../test-case-schema.md`: intent-level steps, typed oracles (incl. **data** + **relational**), seed requirement, negative control | — |
| **C** | Critic *(adversarial)* | Are the oracles real (not "page loaded")? Is it falsifiable? Intent-level (no selectors)? Duplicate of an existing case/spec? → PASS / REWORK | → A |
| **V** | Verifier | Run the case on the good build (must PASS) **and** apply one `negative_control` (must FAIL). A case that can't fail is rejected. Sets `status: verified` | → A |
| **K** | Compiler | Emit the deterministic Playwright spec from the verified case | — |

**Lean v1 (on-demand, given a U finding):** `O → [D] → A → C → V → K`. The Explorer is a
no-op when discovery already happened; **D runs only the first time a surface is authored**
(later cases for the same surface reuse its adapter).

## Flow

```
U finding / acceptance criterion
        │
        ▼
   O scopes ──► D adapts surface ──► A authors case.yaml ──► C critiques ──►(rework)──► A
                (once per surface;          │ pass
                 reused thereafter)         ▼
                                   V verifies (good=PASS, broken=FAIL)
                                            │ verified
                                            ▼
                                   K compiles → execution/
```

## Step 0 — the adapter (per surface)

The adapter is what turns "point at a page" into a real suite: it's the thin seam holding
the app's DOM/DB facts that the portable pipeline can't know generically (Playwright
provides selectors + interaction; it does **not** provide the SQL that proves a UI count is
*true*). Author it **once per surface** from the real DOM — never from a plan/spec, which
may overshoot what's actually built.

1. Copy `adapter.template.mjs` → `<project>/e2e/adapters/<surface-slug>.adapter.mjs`.
2. Fill the four blanks: **controls** (real selectors), **read()** (resolve a count's
   on-screen phrase), **data-oracle SQL** (the backend truth, incl. non-naive rules), and
   the **seed precondition** (hand the full state matrix to seed control).
3. Verify the SQL against a tiny known fixture DB before authoring any case on top of it.

Worked example (verified): `language-buddy/e2e/adapters/admin-audio-backfill.adapter.mjs`.

## The four things that make this worth a pipeline

1. **Typed oracles** — `structural / content / data / relational`, declared per assertion.
2. **Data oracle** — the case cross-checks the UI's claim against the seeded DB truth.
3. **Negative control** — every case proves it can fail before it's accepted (V's gate).
4. **Seed-as-precondition** — the exact data state is named (the gap that hid the bulk-audio
   count bug from the existing suite).

These are *authoring-quality* features — none of them are discovery. That separation is the
whole point.
