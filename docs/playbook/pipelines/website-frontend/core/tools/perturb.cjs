// perturb.cjs — Tier C blast-radius / active-vs-dormant confirmation (Website Front-End (build) Pipeline — per-change blast-radius gate).
// PLATFORM-AGNOSTIC engine. Heaviest tier — budgeted (only perturbs cascade-map suspects).
//
// For each boundary-escape suspect: snapshot the escaped elements' computed styles for the
// rule's declared props → disable the rule in the CSSOM → re-snapshot → diff.
//   - a changed value on an escaped element  => the rule ACTIVELY wins there  => CRITICAL (live)
//   - no change (a more-specific rule masks it) => DORMANT over-reach        => WARNING (bites later)
//
// Usage:
//   node perturb.cjs <URL> <cascade-map.json> [--max-targets N] [--budget-seconds S]
//                    [--waivers <path>] [--config <path>] > perturb.json
//
// Budget: processes suspects in DESCENDING score order; stops when --max-targets or
// --budget-seconds is hit; everything not reached is listed as `deferred` (ranked) so coverage
// is explicit — never a silent cap. Waived suspects (matched in --waivers by selector+scopedTo)
// are routed to `waived`, not dropped.

const { chromium } = require('playwright');
const fs = require('fs');

function arg(flag, def) { const i = process.argv.indexOf(flag); return i > -1 ? process.argv[i + 1] : def; }

(async () => {
  const url = process.argv[2];
  const mapPath = process.argv[3];
  if (!url || !mapPath || url.startsWith('--')) {
    console.error('Usage: node perturb.cjs <URL> <cascade-map.json> [--max-targets N] [--budget-seconds S] [--waivers p] [--config p]');
    process.exit(1);
  }
  const maxTargets = parseInt(arg('--max-targets', '25'), 10);
  const budgetSeconds = parseFloat(arg('--budget-seconds', '0')) || 0; // 0 = no wall-clock cap
  const cfg = arg('--config') ? JSON.parse(fs.readFileSync(arg('--config'), 'utf8')) : {};
  const compAttr = cfg.componentAttr || 'data-component-id';
  const waivers = arg('--waivers') && fs.existsSync(arg('--waivers'))
    ? JSON.parse(fs.readFileSync(arg('--waivers'), 'utf8')).waivers || [] : [];
  const isWaived = (f) => waivers.find((w) => w.selector === f.selector && w.scopedTo === f.scopedTo);

  const map = JSON.parse(fs.readFileSync(mapPath, 'utf8'));
  const byKey = new Map();
  for (const vp of Object.values(map.byViewport)) {
    for (const f of vp.boundaryEscapes || []) {
      const k = f.scopedTo + '|' + f.selector;
      if (!byKey.has(k) || f.score > byKey.get(k).score) byKey.set(k, f);
    }
  }
  const ranked = Array.from(byKey.values()).sort((a, b) => b.score - a.score);

  // partition: waived (out), and the budgeted slice to actually perturb
  const waived = [], active = [];
  for (const f of ranked) { const w = isWaived(f); if (w) waived.push({ ...f, waiver: w }); else active.push(f); }
  const toPerturb = active.slice(0, maxTargets);
  const deferredByCount = active.slice(maxTargets);

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  await page.goto(url, { waitUntil: 'networkidle' });

  const start = Date.now();
  const results = [];
  let deferredByBudget = [];
  for (let i = 0; i < toPerturb.length; i++) {
    if (budgetSeconds && (Date.now() - start) / 1000 > budgetSeconds) { deferredByBudget = toPerturb.slice(i); break; }
    const t = toPerturb[i];
    const r = await page.evaluate(({ t, compAttr }) => {
      const nameOf = (v) => (v && v.includes(':') ? v.split(':').pop() : v);
      const inSubtree = (el, comp) => {
        for (let n = el; n; n = n.parentElement) {
          if (n.getAttribute && n.hasAttribute(compAttr) && nameOf(n.getAttribute(compAttr)) === comp) return true;
        }
        return false;
      };
      const findRules = (sel) => {
        const out = [];
        const walk = (rules) => { for (const r of rules) {
          if (r.type === 1 && r.selectorText && r.selectorText.split(',').map((s) => s.trim()).includes(sel)) out.push(r);
          else if ((r.type === 4 || r.type === 12) && r.cssRules) { try { walk(r.cssRules); } catch (e) {} }
        } };
        for (const sheet of Array.from(document.styleSheets)) { let rules; try { rules = sheet.cssRules; } catch (e) { continue; } if (rules) walk(rules); }
        return out;
      };
      const escaped = Array.from(document.querySelectorAll(t.selector)).filter((el) => !inSubtree(el, t.scopedTo));
      if (!escaped.length) return { verdict: 'gone', note: 'no escaped element at perturb time' };
      const props = (t.declaredProps && t.declaredProps.length) ? t.declaredProps : ['display','width','height','margin','padding','color'];
      const before = escaped.map((el) => props.map((p) => getComputedStyle(el).getPropertyValue(p)));
      const rules = findRules(t.selector); const saved = rules.map((r) => r.style.cssText);
      rules.forEach((r) => { r.style.cssText = ''; }); void document.body.offsetHeight;
      const after = escaped.map((el) => props.map((p) => getComputedStyle(el).getPropertyValue(p)));
      rules.forEach((r, i) => { r.style.cssText = saved[i]; });
      const changes = [];
      before.forEach((row, ei) => row.forEach((v, pi) => { if (v !== after[ei][pi]) changes.push({ el: ei, prop: props[pi], from: v, to: after[ei][pi] }); }));
      const live = changes.length > 0;
      return {
        escapedCount: escaped.length, rulesFound: rules.length,
        verdict: live ? 'ACTIVE (live over-reach)' : 'DORMANT (masked — bites later)',
        severity: live ? ((t.highRiskProps && t.highRiskProps.length) ? 'CRITICAL' : 'WARNING') : 'WARNING',
        changes: changes.slice(0, 6),
      };
    }, { t, compAttr });
    results.push({ selector: t.selector, scopedTo: t.scopedTo, escapeComponents: t.escapeComponents, highRiskProps: t.highRiskProps || [], ...r });
  }
  await browser.close();

  const deferred = [...deferredByBudget, ...deferredByCount]
    .map((f) => ({ selector: f.selector, scopedTo: f.scopedTo, score: f.score, escapeComponents: f.escapeComponents }));
  const coverage = {
    total_suspects: ranked.length, waived: waived.length, perturbed: results.length,
    deferred: deferred.length, elapsed_seconds: Math.round((Date.now() - start) / 100) / 10,
    budget_seconds: budgetSeconds || null, max_targets: maxTargets,
    complete: deferred.length === 0,
  };
  console.log(JSON.stringify({
    url, tool: 'perturb.cjs', coverage, results,
    waived: waived.map((f) => ({ selector: f.selector, scopedTo: f.scopedTo, reason: f.waiver.reason, authority: f.waiver.authority })),
    deferred,
  }, null, 2));
})();
