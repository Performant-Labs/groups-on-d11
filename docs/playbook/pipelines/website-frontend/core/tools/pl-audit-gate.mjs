#!/usr/bin/env node
// pl-audit-gate.mjs — GENERIC @pl-audit dimension gate for the Website Front-End (build) Pipeline.
// PLATFORM-AGNOSTIC. Generalizes the reference pl-perf-gate.mjs to every dimension
// (see pl-audit-gates.md; #279 Option B; #276 G1/G3–G6). Shares the @pl-audit *libraries*
// from a pinned checkout — never the audit *pipeline* (the two stay decoupled).
//
// Usage:  DIM=<dim> PL_AUDIT_DIR=/path/to/website-audit [MIN_SCORE=70] node pl-audit-gate.mjs <url> [url ...]
//   DIM ∈ a11y | css-arch | css-quality | perf | security | visual
// Env:
//   PL_AUDIT_DIR  required — checkout of Performant-Labs/website-audit at PL_AUDIT_REF (provides lib/*).
//   DIM           required — which dimension to gate.
//   MIN_SCORE     optional — minimum AUTHORED score to pass (default 70). From the project profile.
// Multiple URLs = e.g. one per THEME VARIANT (theme--dark, each color_scheme) for #285; the
// gate takes the worst authored score across them. Run a PRODUCTION-SHAPED build (aggregation ON).
// Deps: playwright (+ axe for a11y) resolve from $PL_AUDIT_DIR/node_modules via the engines.
//
// PROVENANCE-SCOPED (#42): only `authored` findings are gated. `environment` (e.g. dev-mode
// aggregation-off), `inherited` (parent/vendor layer), and `waived` findings are REPORTED but
// never failed against the subtheme. Authored score per dimension:
//   • perf      — recomputed via scoreFromFindings(authored) so env artifacts don't count.
//   • css-arch  — res.scoreOwn (#39 Your-layer = subtheme/authored).
//   • others    — res.score (these dimensions emit no inherited/environment findings; score
//                 already excludes waived).
//
// Exit: 0 = pass, 1 = authored score below budget, 2 = misconfiguration.

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const REGISTRY = {
  a11y:          { mod: 'lib/a11y/index.js',        export: 'a11yDimension' },
  'css-arch':    { mod: 'lib/css-arch/index.js',    export: 'cssArchDimension', useScoreOwn: true },
  'css-quality': { mod: 'lib/css-quality/index.js', export: 'cssQualityDimension' },
  perf:          { mod: 'lib/perf/index.js',        export: 'perfDimension', scoreFrom: 'scoreFromFindings' },
  security:      { mod: 'lib/security/index.js',     export: 'securityDimension' },
  visual:        { mod: 'lib/visual/index.js',       export: 'visualDimension' },
};

const PL = process.env.PL_AUDIT_DIR;
const DIM = process.env.DIM;
const reg = REGISTRY[DIM];
if (!PL) { console.error('pl-audit-gate: PL_AUDIT_DIR is required (checkout of website-audit).'); process.exit(2); }
if (!reg) { console.error(`pl-audit-gate: DIM must be one of ${Object.keys(REGISTRY).join(', ')} (got: ${DIM}).`); process.exit(2); }
const urls = process.argv.slice(2);
if (!urls.length) { console.error('usage: DIM=… PL_AUDIT_DIR=… node pl-audit-gate.mjs <url> [url ...]'); process.exit(2); }
const MIN = Number(process.env.MIN_SCORE || 70);

const imp = (rel) => import(pathToFileURL(path.join(PL, rel)).href);
const { render } = await imp('lib/render-harness/index.js');
const mod = await imp(reg.mod);
const dimension = mod[reg.export];
const { findingProvenance, provenanceCounts } = await imp('lib/contract/index.js');

let worst = 100;
let failed = false;
for (const url of urls) {
  const ctx = await render(url, { timeoutMs: 30000 });
  try {
    const res = await dimension.run(ctx);
    const findings = res.findings || [];
    const authored = findings.filter((f) => findingProvenance(f) === 'authored');

    let authoredScore;
    if (reg.scoreFrom && typeof mod[reg.scoreFrom] === 'function') authoredScore = mod[reg.scoreFrom](authored);
    else if (reg.useScoreOwn && typeof res.scoreOwn === 'number') authoredScore = res.scoreOwn;
    else authoredScore = res.score;
    worst = Math.min(worst, authoredScore);

    const c = provenanceCounts(findings);
    console.log(`\n[${DIM}] ${url}`);
    console.log(`  score (all): ${res.score}  |  AUTHORED score: ${authoredScore}  (budget ${MIN})`);
    console.log(`  provenance: authored=${c.authored} inherited=${c.inherited} environment=${c.environment} waived=${c.waived}`);
    for (const f of authored) console.log(`   - [authored] ${String(f.severity).padEnd(8)} ${f.title}`);
    for (const f of findings.filter((x) => findingProvenance(x) !== 'authored')) {
      console.log(`   · [${findingProvenance(f).padEnd(11)}] ${String(f.severity).padEnd(8)} ${f.title}  (reported, not gated)`);
    }
    if (authoredScore < MIN) failed = true;
  } finally {
    await ctx.close();
  }
}

console.log(`\n[${DIM}] ${failed ? 'FAIL' : 'PASS'} — worst authored score ${worst} (budget ${MIN})`);
process.exit(failed ? 1 : 0);
