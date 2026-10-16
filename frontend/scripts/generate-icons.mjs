// PWA アイコン（PNG）を SVG から生成する。結果は public/icons にコミット済みなので、通常のビルドでは実行不要。
// 使い方: cd frontend && node scripts/generate-icons.mjs（Playwright の Chromium が必要）
// 元の SVG は courtq-logo/courtq-app-icon.svg（public/favicon.svg）と icon-maskable.svg（余白付き・全面塗り）。
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const publicDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'public');
const targets = [
  { svg: 'favicon.svg', out: 'icons/icon-192.png', size: 192 },
  { svg: 'favicon.svg', out: 'icons/icon-512.png', size: 512 },
  { svg: 'icons/icon-maskable.svg', out: 'icons/icon-maskable-512.png', size: 512 },
  // iOS は角丸を自前で付けるため、背景が全面塗りのバージョンを使う
  { svg: 'icons/icon-maskable.svg', out: 'icons/apple-touch-icon.png', size: 180 },
];

const browser = await chromium.launch();
const page = await browser.newPage();
for (const { svg, out, size } of targets) {
  const markup = readFileSync(join(publicDir, svg), 'utf8');
  await page.setViewportSize({ width: size, height: size });
  await page.setContent(
    `<style>html,body{margin:0;background:transparent}svg{display:block;width:${size}px;height:${size}px}</style>${markup}`,
  );
  await page.screenshot({ path: join(publicDir, out), omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
  console.log('generated', out);
}
await browser.close();
