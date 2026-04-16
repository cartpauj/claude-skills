#!/usr/bin/env node
// Render assets from preview.html into PNGs using 4× DSR + Catrom + unsharp.
//
// Expects (in cwd):
//   preview.html  — the source HTML with each asset in its own sized container
//   jobs.json     — array of { sel, out, w, h } (see below)
//
// Optional env:
//   H2I_VIEWPORT="WxH"   viewport size; default 2400x6000
//   H2I_UNSHARP="0.7"    unsharp strength; default 0.7
//
// jobs.json shape:
//   [
//     { "sel": "#banner",  "out": "banner-1544x500.png", "w": 1544, "h": 500 },
//     { "sel": "#icon256", "out": "icon-256x256.png",    "w": 256,  "h": 256 }
//   ]

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';

const cwd = process.cwd();
const previewPath = path.join(cwd, 'preview.html');
const jobsPath = path.join(cwd, 'jobs.json');

if (!existsSync(previewPath)) { console.error(`missing ${previewPath}`); process.exit(1); }
if (!existsSync(jobsPath))    { console.error(`missing ${jobsPath}`);    process.exit(1); }

const jobs = JSON.parse(readFileSync(jobsPath, 'utf8'));
const [vw, vh] = (process.env.H2I_VIEWPORT || '2400x6000').split('x').map(Number);
const unsharp = process.env.H2I_UNSHARP || '0.7';

const DSR = 4;
const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: { width: vw, height: vh },
  deviceScaleFactor: DSR,
});
const page = await ctx.newPage();
await page.goto('file://' + previewPath, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(200);

for (const { sel, out, w, h } of jobs) {
  const tmp = path.join(cwd, `_h2i-${out}`);
  const outPath = path.join(cwd, out);
  await page.locator(sel).screenshot({ path: tmp });
  execSync([
    'convert', `"${tmp}"`,
    '-colorspace', 'RGB',
    '-filter', 'Catrom',
    '-resize', `${w}x${h}!`,
    '-colorspace', 'sRGB',
    '-unsharp', `0x0.6+${unsharp}+0.01`,
    '-strip',
    '-define', 'png:compression-filter=5',
    '-define', 'png:compression-level=9',
    `"${outPath}"`,
  ].join(' '));
  console.log('rendered', outPath);
}

await browser.close();
