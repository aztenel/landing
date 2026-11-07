const { chromium } = require('playwright');
const scale = Number(process.argv[2] || 1);
(async () => {
  const b = await chromium.launch();
  for (const k of ['tradein', 'novinka', 'bu']) for (const [fmt, w, h] of [['wide', 1672, 941], ['square', 1080, 1080]]) {
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: scale });
    await p.goto(`http://127.0.0.1:8765/pb-${k}-${fmt}.html`, { waitUntil: 'networkidle' });
    await p.waitForFunction(() => window.READY === true); await p.waitForTimeout(300);
    await p.screenshot({ path: `${__dirname}/pb-${k}-${fmt}${scale > 1 ? '@2x' : ''}.png` });
    await p.close();
  }
  await b.close();
})();
