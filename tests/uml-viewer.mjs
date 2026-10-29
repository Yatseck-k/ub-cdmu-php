import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
const { chromium } = await import(process.env.CDMU_PLAYWRIGHT || 'playwright');
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const errors = [], network = [];
page.on('pageerror', error => errors.push(error.message));
page.on('request', request => { if (/^https?:/.test(request.url())) network.push(request.url()); });
await context.route(/^https?:/, route => route.abort());
const unescapeXml = text => text.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
// The server-rendered SVG inside the file is what uml.svg contains; the page replaces it with its own layout on load.
const phpLayout = file => {
  const text = readFileSync(file, 'utf8'), start = text.indexOf('<div class="canvas" id="canvas">'), svg = text.slice(start, text.indexOf('</svg>', start));
  return {
    cards: [...svg.matchAll(/<g class="class-card" data-id="([^"]*)" data-x="(-?\d+)" data-y="(-?\d+)" data-layer="(\d+)" data-index="(\d+)"/g)].map(m => [unescapeXml(m[1]), m[2], m[3], m[4], m[5]].join('|')).sort(),
    edges: [...svg.matchAll(/<g class="edge edge-(\w+)" data-from="([^"]*)" data-to="([^"]*)" data-kinds="([^"]*)"><path d="([^"]*)"/g)].map(m => [m[1], unescapeXml(m[2]), unescapeXml(m[3]), m[4], m[5]].join('|')).sort(),
  };
};
const domLayout = () => page.evaluate(() => ({
  cards: [...document.querySelectorAll('.class-card')].map(c => [c.dataset.id, c.dataset.x, c.dataset.y, c.dataset.layer, c.dataset.index].join('|')).sort(),
  edges: [...document.querySelectorAll('.edge')].map(e => [e.classList[1].slice(5), e.dataset.from, e.dataset.to, e.dataset.kinds, e.querySelector('path').getAttribute('d')].join('|')).sort(),
}));
const sameLayout = async (file, label) => {
  const expected = phpLayout(file);
  assert.ok(expected.cards.length > 0, label + ': server-side SVG must contain cards');
  assert.deepEqual(await domLayout(), expected, label + ': the page layout must equal the PHP layout');
};
try {
  const normal = resolve(process.argv[2] || 'fixtures/uml/.metrics/uml.html');
  await page.goto(pathToFileURL(normal).href);
  await page.waitForSelector('.class-card');
  await sameLayout(normal, 'default view');
  assert.ok(await page.locator('.edge').count() > 0, 'actual relations must be visible');
  const classes = await page.locator('.class-card').count();
  assert.equal(await page.locator('.class-card[data-id="DateTimeImmutable"]').count(), 0, 'external types are hidden by default');
  await page.locator('#show-external').check();
  assert.ok(await page.locator('.class-card').count() > classes && await page.locator('.class-card[data-id="DateTimeImmutable"]').count() === 1, 'external types can be shown');
  assert.ok(await page.locator('.edge-inherit').count() > 0 && await page.locator('.edge-type').count() > 0, 'relation groups are drawn by default');
  // Parallel relations draw as one line in the strongest enabled group, so hiding type hints turns new/static calls visible.
  await page.locator('#show-type').uncheck();
  assert.equal(await page.locator('.edge-type').count(), 0);
  assert.ok(await page.locator('.edge-calls').count() > 0 && await page.locator('.edge-inherit').count() > 0);
  await page.locator('#show-calls').uncheck();
  assert.equal(await page.locator('.edge-calls').count(), 0);
  await page.locator('#show-inherit').uncheck();
  assert.equal(await page.locator('.edge').count(), 0, 'relation groups can be hidden');
  for (const box of ['#show-calls', '#show-inherit', '#show-type']) await page.locator(box).check();
  await page.locator('#show-external').uncheck();
  await sameLayout(normal, 'after toggling relation groups');
  await page.locator('#search').fill('Architecture\\Application\\Service');
  assert.equal(await page.locator('.class-card').count(), 1);
  await page.locator('.class-card').click();
  assert.equal(await page.locator('#detail-title').textContent(), 'Architecture\\Application\\Service');
  assert.match(await page.locator('#details').textContent(), /Architecture\.php/);
  await page.locator('#search').fill('');
  await page.locator('#namespace').selectOption('Architecture\\Domain');
  assert.ok(await page.locator('.class-card').count() >= 5);
  assert.equal(await page.locator('.class-card[data-id="DateTimeImmutable"]').count(), 0);
  await page.locator('.class-card').first().focus();
  await page.keyboard.press('Enter');
  assert.ok(await page.locator('#detail-title').textContent() !== 'Select a class');
  await page.locator('#zoom-in').click();
  assert.equal(await page.locator('#zoom-value').textContent(), '120%');
  await page.locator('#reset').click();
  assert.equal(await page.locator('#zoom-value').textContent(), '100%');
  await page.locator('.class-card').first().focus();
  await page.keyboard.press('ArrowRight');
  assert.match(await page.locator('#canvas svg > g').getAttribute('transform'), /translate\(-40,0\)/);
  await page.keyboard.press('0');
  assert.match(await page.locator('#canvas svg > g').getAttribute('transform'), /translate\(0,0\)/);
  const bounds = await page.locator('#canvas').boundingBox();
  await page.mouse.move(bounds.x + 10, bounds.y + 10);
  await page.mouse.down(); await page.mouse.move(bounds.x + 60, bounds.y + 60); await page.mouse.up();
  assert.doesNotMatch(await page.locator('#canvas svg > g').getAttribute('transform'), /translate\(0,0\)/);
  if (process.env.CDMU_SCREENSHOT) await page.screenshot({ path: process.env.CDMU_SCREENSHOT, fullPage: true });
  if (process.argv[3]) {
    await page.goto(pathToFileURL(resolve(process.argv[3])).href);
    await page.waitForSelector('.class-card');
    await sameLayout(resolve(process.argv[3]), 'large view');
    assert.equal(await page.locator('.class-card').count(), 200);
    assert.match(await page.locator('#count').textContent(), /5 hidden/);
    const last = await page.evaluate(() => JSON.parse(document.getElementById('graph-data').textContent).nodes.at(-1).id);
    await page.locator('#search').fill(last);
    assert.equal(await page.locator('.class-card').count(), 1, 'search must reach a node beyond the initial limit');
    await page.goto(pathToFileURL(resolve(process.argv[3] + '.hostile.html')).href);
    await page.waitForSelector('.class-card');
    await sameLayout(resolve(process.argv[3] + '.hostile.html'), 'hostile labels');
    assert.equal(await page.evaluate(() => globalThis.injected), undefined, 'source labels must never execute');
    await page.goto(pathToFileURL(resolve(process.argv[3] + '.ambiguous.html')).href);
    await page.waitForSelector('.class-card');
    await sameLayout(resolve(process.argv[3] + '.ambiguous.html'), 'ambiguous definitions');
    await page.locator('.class-card').first().click();
    assert.match(await page.locator('#details').textContent(), /Multiple definitions/);
    assert.match(await page.locator('#details').textContent(), /Example\.php/);
    assert.doesNotMatch(await page.locator('#details').textContent(), /External type/);
  }
  assert.deepEqual(errors, [], 'no browser errors');
  assert.deepEqual(network, [], 'viewer must not request a network');
  console.log('Offline UML browser smoke passed: graph, filters, details, keyboard, zoom, pan, limit and source-label escaping');
} finally { await browser.close(); }
