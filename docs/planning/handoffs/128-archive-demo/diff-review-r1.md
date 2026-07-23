## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** tests/e2e/demonstrator-seeds.spec.ts:65–70 — AC-1a test verifies only the visible “Archive” badge text on the directory card and omits any assertion about a tooltip attribute. The brief’s AC-1a explicitly requires “Archive badge (visible text + tooltip attribute).” As written, neither the test nor the UI under test actually satisfies the spec.  
Why it blocks: It diverges from the acceptance criteria.  
Remediation: Either update the directory-card templates to render the tooltip on the Archive badge, or (if the design has intentionally changed) revise AC-1a in the brief and the test to match the new, correct UI contract.

**[B-2]** tests/e2e/demonstrator-seeds.spec.ts:1–60 (file header) — The header asserts that Views-fields row renders never invoke `hook_preprocess_group` and therefore directory cards cannot have tooltip markup. This is an unverified claim about Drupal’s runtime behavior.  
Why it blocks: Any claim about execution order or hook invocation is a hypothesis until confirmed in source; relying on it may mask a misunderstanding of Views’ rendering pipeline.  
Remediation: Inspect the Views-row rendering code or official Drupal docs to confirm whether `hook_preprocess_group` is or is not invoked in that context, and adjust the test or implementation to align with the actual behavior.

### WARN findings

**[W-1]** docs/groups/RUNBOOK.md (around lines 2638–2800) still describes “Legacy Infrastructure archived” as setting `status=0`, which is now incorrect.  
Recommendation: Schedule a follow-up documentation sweep (e.g. in story #133) to update RUNBOOK.md to reflect that archiving is driven by `field_group_type = "Archive"` and not by publishing status.

### NIT findings

**[NIT-1]** tests/e2e/demonstrator-seeds.spec.ts — the enormous file-header commentary, while thorough, makes the test hard to scan. Consider trimming or externalizing extended rationale to improve readability of the core test logic.

### Verdict

BLOCK — 2 blocking finding(s); must resolve before testing starts.
