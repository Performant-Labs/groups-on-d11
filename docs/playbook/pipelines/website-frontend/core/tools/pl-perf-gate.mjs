#!/usr/bin/env node
// pl-perf-gate.mjs — Performance budget GATE for the Website Front-End (build) Pipeline.
// PLATFORM-AGNOSTIC. Reference implementation of the @pl-audit gate-tool pattern
// (see pl-audit-gates.md; #279 Option B; G2 / issue #282).
//
// Consumes the shared @pl-audit detection libraries from a PINNED CHECKOUT — it does NOT
// reference the Website Audit *pipeline*, only its libraries (the two pipelines stay decoupled).
//
// Usage:  PL_AUDIT_DIR=/path/to/website-audit node pl-perf-gate.mjs <url> [url ...]
// Env:
//   PL_AUDIT_DIR    required — a checkout of Performant-Labs/website-audit at PL_AUDIT_REF
//                   (provides lib/*; browser deps resolve from its node_modules).
//   PERF_MIN_SCORE  optional — minimum AUTHORED perf score to pass (default 70). From profile.
// Deps: playwright — resolved transitively from $PL_AUDIT_DIR/node_modules via render-harness.
//
// PROVENANCE-SCOPED (the point): findings are classified with @pl-audit/contract
// findingProvenance() (#42). Only `authored` findings are gated; `environment` (e.g. dev-mode
// CSS aggregation off → 100+ <link>s), `inherited` (parent/vendor), and `waived` findings are
// REPORTED but never failed against the subtheme. Run against a PRODUCTION-SHAPED build
// (aggregation ON) for a meaningful number — see core/nfr-checklist.md (G2).
//
// Exit: 0 = pass, 1 = authored perf score below budget, 2 = misconfiguration.

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const PL = process.env.PL_AUDIT_DIR;
if (!PL) {
  console.error('pl-perf-gate: PL_AUDIT_DIR is required (a checkout of Performant-Labs/website-audit).');
  process.exit(2);
}
const urls = process.argv.slice(2);
if (!urls.length) {
  console.error('usage: PL_AUDIT_DIR=… node pl-perf-gate.mjs <url> [url ...]');
  process.exit(2);
}
const MIN = Number(process.env.PERF_MIN_SCORE || 70);

const imp = (rel) => import(pathToFileURL(path.join(PL, rel)).href);
const { render } = await imp('lib/render-harness/index.js');
const perf = await imp('lib/perf/index.js');
const { findingProvenance, provenanceCounts } = await imp('lib/contract/index.js');

let worst = 100;
let failed = false;
for (const url of urls) {
  const ctx = await render(url, { timeoutMs: 30000 });
  try {
    const res = await perf.perfDimension.run(ctx);
    const findings = res.findings || [];
    const authored = findings.filter((f) => findingProvenance(f) === 'authored');
    const authoredScore = perf.scoreFromFindings(authored);
    worst = Math.min(worst, authoredScore);
    const c = provenanceCounts(findings);

    console.log(`\n${url}`);
    console.log(`  perf score (all findings): ${res.score}  |  AUTHORED score: ${authoredScore}  (budget ${MIN})`);
    console.log(`  provenance: authored=${c.authored} inherited=${c.inherited} environment=${c.environment} waived=${c.waived}`);
    for (const f of authored) {
      console.log(`   - [authored]    ${f.severity.padEnd(8)} ${f.title}`);
    }
    for (const f of findings.filter((x) => findingProvenance(x) !== 'authored')) {
      console.log(`   · [${findingProvenance(f).padEnd(11)}] ${f.severity.padEnd(8)} ${f.title}  (reported, not gated)`);
    }
    if (authoredScore < MIN) failed = true;
  } finally {
    await ctx.close();
  }
}

console.log(`\n${failed ? 'FAIL' : 'PASS'} — worst authored perf score ${worst} (budget ${MIN})`);
process.exit(failed ? 1 : 0);
