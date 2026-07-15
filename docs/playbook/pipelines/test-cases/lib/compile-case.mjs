// compile-case.mjs — K (Compiler): a verified semantic case → a deterministic,
// self-contained Playwright spec that drops into the project's e2e suite. No LLM at
// run time; runs in CI.
//
//   node compile-case.mjs <case.yaml> --adapter <adapter.mjs> --out <out.spec.ts>
//
// The spec follows the project's e2e conventions (auth via the project's storageState /
// globalSetup; viewports via the project's viewport "projects"). The adapter supplies the
// project-specific bits: selectors, data-oracle SQL (sqlText), the seed precondition, the
// count locator.

import { readFileSync, writeFileSync } from 'node:fs';

const argv = process.argv.slice(2);
const caseFile = argv[0];
const adapterPath = argv[argv.indexOf('--adapter') + 1];
const outPath = argv[argv.indexOf('--out') + 1];

const YAML = await import('yaml');
const tc = YAML.parse(readFileSync(caseFile, 'utf8'));
const a = (await import(adapterPath)).default;

const sel = (name) => a.controls[name].selector;
const modeVal = (v) => a.controls.mode.values?.[v] ?? v;
const L = [];
const p = (s = '') => L.push(s);

p(`// AUTO-GENERATED from ${tc.id} by test-cases/lib/compile-case.mjs — do not hand-edit; regenerate from the case.`);
p(`// Semantic source: ${caseFile.split('/').pop()}   |   trace: ${tc.trace?.source ?? ''}`);
p(`import { test, expect } from '@playwright/test';`);
p(`import Database from 'better-sqlite3';`);
p('');
p(`const DB = process.env.SQLITE_PATH || '${a.dbPath}';`);
p(`function dbScalar(sql: string): number {`);
p(`  const db = new Database(DB, { readonly: true });`);
p(`  try { return Number(Object.values(db.prepare(sql).get() as Record<string, unknown>)[0]); } finally { db.close(); }`);
p(`}`);
p(`async function readCount(page: import('@playwright/test').Page): Promise<number> {`);
p(`  const li = page.locator('${a.countLocator.container} li', { hasText: '${a.countLocator.hasText}' });`);
p(`  await li.first().waitFor();`);
p(`  return Number(((await li.locator('strong').first().textContent()) || '').trim());`);
p(`}`);
p('');
p(`// ${tc.title}  (risk: ${tc.risk})`);
p(`test.describe(${JSON.stringify(tc.id)}, () => {`);
p(`  // seed.requires: ${tc.seed.requires}`);
p(`  test.beforeAll(() => {`);
p(`    const db = new Database(DB);`);
p(`    try { db.prepare(${JSON.stringify(a.precondition)}).run(); } finally { db.close(); }`);
p(`    expect(dbScalar(${JSON.stringify(a.sqlText('fill_missing_count'))})).toBeGreaterThan(0); // mixed coverage established`);
p(`  });`);
p('');
p(`  test(${JSON.stringify(tc.title)}, async ({ page }) => {`);
p(`    // auth: project storageState (admin); viewport: project viewport-projects`);
p(`    await page.goto('/');`);
for (const step of tc.steps) {
  const [verb, arg] = Object.entries(step)[0];
  if (verb === 'nav_spa') {
    p(`    // reach the surface via SPA navigation (HTMX swap, not a reload)`);
    p(`    await page.evaluate(() => new Promise<void>((res) => { document.body.addEventListener('htmx:afterSettle', () => res(), { once: true }); (window as unknown as { htmx: { ajax: (m: string, u: string, o: object) => void } }).htmx.ajax('GET', '${arg}', { target: '#main-content', swap: 'innerHTML' }); }));`);
    p(`    await expect(page.locator('#backfill-scope-form')).toBeVisible(); // structural`);
  } else if (verb === 'select') {
    p(`    await page.selectOption('${sel(arg.control)}', '${arg.value}');`);
  } else if (verb === 'set_mode') {
    p(`    await page.click('label:has(${sel('mode')}[value="${modeVal(arg)}"])');`);
  } else if (verb === 'click') {
    p(`    await page.click('${sel(arg)}');`);
    p(`    await page.waitForFunction(() => !document.querySelector('.htmx-request'));`);
  } else if (verb === 'read') {
    p(`    const ${arg.as} = await readCount(page);`);
  }
}
for (const o of tc.oracles) {
  if (o.kind === 'content' && o.expectText)
    p(`    await expect(page.locator('${a.controls.resultRegion.selector}')).toContainText(${JSON.stringify(o.expectText)}); // content oracle`);
  else if (o.kind === 'data')
    p(`    expect(${o.var}).toBe(dbScalar(${JSON.stringify(a.sqlText(o.var))})); // data oracle: UI count == DB truth`);
  else if (o.kind === 'relational') {
    const fn = o.op === '>' ? 'toBeGreaterThan' : o.op === '>=' ? 'toBeGreaterThanOrEqual' : 'toBe';
    p(`    expect(${o.lhs}).${fn}(${o.rhs}); // relational/metamorphic oracle`);
  }
}
p(`  });`);
p(`});`);

writeFileSync(outPath, L.join('\n') + '\n');
console.log(`compiled ${tc.id} → ${outPath} (${L.length} lines)`);
