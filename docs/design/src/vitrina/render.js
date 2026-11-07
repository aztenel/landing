const { chromium } = require('playwright');
const s = Number(process.argv[2] || 1);
(async () => {
  const b = await chromium.launch();
  for (const f of ['v2-1-glavnaya', 'v2-2-tovar']) {
    const p = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: s });
    await p.goto('file://' + __dirname + '/' + f + '.html', { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(800);
    await p.screenshot({ path: `${__dirname}/${f}-first.png` });
    await p.evaluate(() => document.querySelectorAll('nav.sticky').forEach(n => n.style.position = 'static'));
    await p.screenshot({ path: `${__dirname}/${f}-full.png`, fullPage: true });
    await p.close();
  }
  await b.close();
})();
