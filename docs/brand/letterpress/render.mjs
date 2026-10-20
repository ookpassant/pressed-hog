// Render the letterpress wordpress.org banners from banner.html.
//
// Needs Node and Playwright (npm i -D playwright, or a global install).
// Run from the repo root: node docs/brand/letterpress/render.mjs
// Output: .wordpress-org/banner-1544x500.png and banner-772x250.png
import { chromium } from 'playwright';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..', '..', '..', '.wordpress-org');
const page = pathToFileURL(join(here, 'banner.html')).href;

const browser = await chromium.launch();
for (const [w, h, scale] of [[1544, 500, 1], [772, 250, 0.5]]) {
	const tab = await browser.newPage({ viewport: { width: w, height: h } });
	await tab.goto(`${page}?z=${scale}`);
	await tab.evaluate(() => document.fonts.ready);
	await tab.screenshot({ path: join(out, `banner-${w}x${h}.png`) });
}
await browser.close();
console.log('Rendered letterpress banners into .wordpress-org/');
