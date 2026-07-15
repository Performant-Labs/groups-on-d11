// run-case.mjs — interpret a semantic test case against the live app.
//
// Standalone (no PW Agents). Reuses our own u-drive.mjs for correct HTMX/SPA waits +
// the structural evidence bundle, and data-oracle.mjs for the data/relational oracles.
// All selectors and SQL live in a PROJECT ADAPTER, so cases stay portable.
//
//   node run-case.mjs <case.yaml> --adapter <project-adapter.mjs>
//
// Adapter contract (the only project-specific coupling):
//   export default {
//     base, login: { email, pass },
//     async serveCheck(),                         // optional: assert app is up
//     controls: { <name>: { selector, kind } },   // kind: select|radio|button|input
//     async read(page, fromDescription) -> value, // resolve a `read.from` to a value
//     dbPath,
//     async sql(name, captured) -> string,         // build the SQL for a `data` oracle
//   }

import { readFileSync } from 'node:fs';
import * as U from '../../../workflow/u-drive.mjs';
import { scalar, assertEquals, assertRelation } from './data-oracle.mjs';

const [caseFile, , adapterPath] = process.argv.slice(2);
if (!caseFile || !adapterPath) {
  console.error('usage: node run-case.mjs <case.yaml> --adapter <adapter.mjs>');
  process.exit(2);
}

const YAML = await import('yaml').catch(() => {
  console.error('missing dep: `npm i yaml` in the project running this case');
  process.exit(2);
});
const tc = YAML.parse(readFileSync(caseFile, 'utf8'));
const adapter = (await import(adapterPath)).default;

const captured = {};       // read vars
const results = [];        // oracle outcomes
const fail = (label, err) => { results.push({ label, ok: false, err: String(err) }); };
const pass = (r) => results.push(r);

const { browser, page } = await U.launch({ base: adapter.base });
try {
  await adapter.serveCheck?.();
  await U.login(page, adapter.login);

  for (const step of tc.steps) {
    const [verb, arg] = Object.entries(step)[0];
    if (verb === 'nav_spa')      await U.spaNav(page, arg);
    else if (verb === 'nav_hard') await page.goto(arg);
    else if (verb === 'select')   await page.selectOption(adapter.controls[arg.control].selector, String(arg.value));
    else if (verb === 'fill')     await page.fill(adapter.controls[arg.control].selector, String(arg.value));
    else if (verb === 'set_mode') { const v = adapter.controls.mode.values?.[arg] ?? arg; const sel = adapter.controls.mode.selector; await page.click(`label:has(${sel}[value="${v}"])`).catch(() => page.check(`${sel}[value="${v}"]`, { force: true })); }
    else if (verb === 'click')   { await page.click(adapter.controls[arg].selector); await page.waitForFunction(() => !document.querySelector('.htmx-request'), null, { timeout: 8000 }).catch(() => {}); }
    else if (verb === 'read')     captured[arg.as] = await adapter.read(page, arg.from);
    else if (verb === 'wait_for') await page.waitForSelector(arg);
    else throw new Error(`unknown step verb: ${verb}`);

    // structural oracle runs after every step (the implicit default)
    const ev = await U.collectEvidence(page, { label: `${tc.id}-${verb}` });
    if (ev.deadComponents.length || ev.consoleErrors.length || ev.blockingOverlays.length)
      fail(`structural@${verb}`, JSON.stringify({ dead: ev.deadComponents, console: ev.consoleErrors, overlays: ev.blockingOverlays }));
    else pass({ label: `structural@${verb}`, ok: true });
  }

  // typed oracles
  for (const o of tc.oracles) {
    try {
      if (o.kind === 'structural') continue;             // handled per-step above
      if (o.kind === 'content') {
        const ok = await page.locator(adapter.controls.resultRegion?.selector || 'body')
          .filter({ hasText: o.expectText || '' }).count();
        if (o.expectText && !ok) throw new Error(`content not present: "${o.expectText}"`);
        pass({ label: `content`, ok: true });
      } else if (o.kind === 'data') {
        const dbVal = await scalar(adapter.dbPath, await adapter.sql(o.var, captured));
        pass(assertEquals(`data:${o.var}`, captured[o.var], dbVal));
      } else if (o.kind === 'relational') {
        pass(assertRelation(`relational`, captured[o.lhs], o.op, captured[o.rhs]));
      }
    } catch (e) { fail(o.kind, e); }
  }
} finally {
  await U.collectEvidence(page, { label: `${tc.id}-final` }).catch(() => {});
  await browser.close();
}

const failed = results.filter(r => !r.ok);
console.log(JSON.stringify({ id: tc.id, verdict: failed.length ? 'FAIL' : 'PASS', results }, null, 2));
process.exit(failed.length ? 1 : 0);
