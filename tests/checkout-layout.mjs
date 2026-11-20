#!/usr/bin/env node
/**
 * Browser regression for the real, server-rendered checkout.
 * Requires a PHP 8.1+ CLI with PDO SQLite, playwright-core, and Chromium.
 * Example: PHP_BIN=php CHROME_EXECUTABLE=/path/to/chromium node tests/checkout-layout.mjs
 * If playwright-core is installed elsewhere, set PLAYWRIGHT_MODULE to its path.
 */
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';

const root = path.resolve(import.meta.dirname, '..');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const work = mkdtempSync(path.join(tmpdir(), 'tg-layout-'));
const php = process.env.PHP_BIN || 'php';
const db = path.join(work, 'layout.sqlite');
const fixtureEnv = { DB_DRIVER: 'sqlite', DB_SQLITE_PATH: db, APP_ENV: 'test', SESSION_SECURE: 'false', APP_URL: 'http://localhost', APP_ENCRYPTION_KEY: '0'.repeat(64) };
const env = { ...process.env, ...fixtureEnv };
let browser, server;
try {
  const seed = path.join(work, 'seed.php');
  writeFileSync(seed, `<?php
require '${root.replaceAll("'", "\\'")}/includes/db.php';
$p=db();
$p->exec(file_get_contents('${root.replaceAll("'", "\\'")}/database/schema.sqlite.sql'));
$p->exec("INSERT INTO merchants(name,email,password,api_key) VALUES('Layout Merchant with a Long Business Name','layout@example.com','unused','tg_LAYOUT')");
$p->exec("INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,linked_email,google_app_password) VALUES(1,'Main','merchant.long.upi.identifier@bank','layout@example.com','unused')");
$p->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at) VALUES('tg_3DCD1B6CB69CD28C179CFCF73D2C',1,1,1,1,'pending',?)")->execute([db_now(3600)]);
`);
  execFileSync(php, [seed], { cwd: root, env, stdio: 'pipe' });
  mkdirSync(path.join(work, 'sessions'));
  const spec = path.join(work, 'request.json');
  const output = path.join(work, 'response.json');
  writeFileSync(spec, JSON.stringify({ env: fixtureEnv, session_dir: path.join(work, 'sessions'), session_id: 'layouttest', output, method: 'GET', query: { order_id: 'tg_3DCD1B6CB69CD28C179CFCF73D2C' }, script: path.join(root, 'pay.php') }));
  execFileSync(php, [path.join(root, 'tests/request.php'), spec], { cwd: root, env, stdio: 'pipe' });
  const response = JSON.parse(readFileSync(output, 'utf8'));
  if (response.status !== 200 || !response.body.includes('id="payment-options"')) throw new Error('Pending checkout fixture did not render');
  server = createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/pay.php') { res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); return res.end(response.body); }
    if (url.pathname === '/api/checkout-status.php') { res.writeHead(200, {'Content-Type':'application/json'}); return res.end('{"status":"pending"}'); }
    if (!url.pathname.startsWith('/assets/')) { res.writeHead(404); return res.end(); }
    const file = path.resolve(root, '.' + url.pathname);
    if (!file.startsWith(path.join(root, 'assets') + path.sep)) { res.writeHead(403); return res.end(); }
    try {
      const ext = path.extname(file);
      const type = {'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.png':'image/png'}[ext] || 'application/octet-stream';
      res.writeHead(200, {'Content-Type':type}); res.end(readFileSync(file));
    } catch { res.writeHead(404); res.end(); }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  browser = await chromium.launch({ executablePath: process.env.CHROME_EXECUTABLE || undefined, headless: true, args: ['--no-sandbox'] });
  for (const width of [320, 375, 440, 441, 576, 720, 721, 768, 850, 851, 1024, 1280, 1568]) {
    const page = await browser.newPage({ viewport: {width, height: 1000}, reducedMotion: 'reduce' });
    await page.goto(`http://127.0.0.1:${server.address().port}/pay.php?order_id=tg_3DCD1B6CB69CD28C179CFCF73D2C`, { waitUntil:'load' });
    const layout = await page.evaluate(() => {
      const box = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return { x:r.x, y:r.y, right:r.right, bottom:r.bottom, width:r.width, height:r.height }; };
      return { viewport:innerWidth, scroll:document.documentElement.scrollWidth, card:box('.checkout'), summary:box('.checkout-summary'), content:box('.checkout-content'), scan:box('.checkout-scan'), apps:box('.payment-apps'), qr:box('#qr'), qrFrame:box('.qr-frame'), brand:box('.checkout-top .brand'), tag:box('.checkout-tag'), buttons:[...document.querySelectorAll('.upi-actions a')].map(el => { const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height,scroll:el.scrollWidth,client:el.clientWidth}; }), qrSvg:!!document.querySelector('#qr svg'), columns:getComputedStyle(document.querySelector('.checkout-layout')).gridTemplateColumns, optionsColumns:getComputedStyle(document.querySelector('#payment-options')).gridTemplateColumns };
    });
    const fail = message => { throw new Error(`${width}px: ${message} ${JSON.stringify(layout)}`); };
    if (layout.scroll > width + 1 || layout.card.x < -1 || layout.card.right > width + 1) fail('page overflows horizontally');
    if (width >= 1024 && (layout.card.width < 900 || layout.content.width < 500)) fail('desktop checkout is constrained');
    if (width > 850 && layout.content.width < 300) fail('payment column is too narrow');
    if (width <= 850 && layout.content.y < layout.summary.bottom - 1) fail('summary and payment sections should stack');
    if (width <= 440 && layout.apps.y < layout.scan.bottom - 1) fail('scan and app sections should stack');
    if (width > 440 && layout.apps.x < layout.scan.right - 1) fail('scan and app sections overlap');
    if (!layout.qrSvg || layout.qr.width < 100 || layout.qr.height < 100) fail('QR is missing or too small');
    if (Math.abs((layout.qrFrame.x - layout.scan.x) - (layout.scan.right - layout.qrFrame.right)) > 2) fail('QR is not centred in the scan card');
    if (layout.brand.right > layout.tag.x + 1) fail('checkout header overlaps');
    for (const b of layout.buttons) if (b.width < 45 || b.height < 40 || b.scroll > b.client + 1) fail('UPI button is too small or overflows');
    for (let i=0; i<layout.buttons.length; i++) for (let j=i+1; j<layout.buttons.length; j++) {
      const a=layout.buttons[i], b=layout.buttons[j];
      if (Math.min(a.right,b.right)-Math.max(a.x,b.x)>1 && Math.min(a.bottom,b.bottom)-Math.max(a.y,b.y)>1) fail('UPI buttons overlap');
    }
    await page.locator('#claim-section summary').click();
    const claim = await page.evaluate(() => {
      const field = document.querySelector('#claim-utr').getBoundingClientRect();
      const button = document.querySelector('.claim-form button').getBoundingClientRect();
      const card = document.querySelector('.checkout').getBoundingClientRect();
      return {field:{x:field.x,right:field.right,y:field.y,bottom:field.bottom},button:{x:button.x,right:button.right,y:button.y,bottom:button.bottom},card:{x:card.x,right:card.right}};
    });
    if (claim.field.x < claim.card.x || claim.field.right > claim.card.right || claim.button.x < claim.card.x || claim.button.right > claim.card.right || (Math.min(claim.field.right, claim.button.right) - Math.max(claim.field.x, claim.button.x) > 1 && Math.min(claim.field.bottom, claim.button.bottom) - Math.max(claim.field.y, claim.button.y) > 1)) fail(`UTR form overflows or overlaps ${JSON.stringify(claim)}`);
    await page.locator('#claim-section summary').click();
    if (process.env.TG_SCREENSHOT_DIR) await page.screenshot({ path: path.join(process.env.TG_SCREENSHOT_DIR, `checkout-${width}.png`), fullPage:true });
    console.log(`✓ ${width}px checkout: card ${Math.round(layout.card.width)}px, payment column ${Math.round(layout.content.width)}px`);
    await page.close();
  }
} finally {
  if (browser) await browser.close();
  if (server) await new Promise(resolve => server.close(resolve));
  rmSync(work, {recursive:true,force:true});
}
