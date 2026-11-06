// Рендер макетов в PNG. Запуск из этой папки: node render.js [путь_к_chrome109]
// Без аргумента — обычный Chromium из Playwright. С путём — проверка в Chrome 109 (Windows 7).
const { chromium } = require('playwright');
const path = require('path');
const exe = process.argv[2];
const out = path.join(__dirname, '..', 'screens');
const jobs = [
  // [html, png, ширина, высота, вся страница]
  ['v4-1-glavnaya-accent', 'erp-1-statistika', 1440, 900, true],
  ['v4-2-kassa-accent', 'erp-2-prodazha', 1366, 768, false],
  ['v4-2-kassa', 'erp-2-prodazha-grafit', 1366, 768, false],
  ['v4-2-kassa-legacy', 'erp-2-prodazha-staryi-noutbuk', 1366, 768, false],
  ['v4-3-sklad-accent', 'erp-3-sklad', 1440, 900, true],
  ['v4-4-roli-accent', 'erp-4-sotrudniki-i-roli', 1440, 900, true],
  ['v4-2-kassa-dark', 'erp-2-prodazha-temnaya', 1440, 900, false],
  ['ms-1-pusto-accent', 'iphone-prodavec-1-pustoi-chek', 390, 844, false],
  ['ms-2-chek-accent', 'iphone-prodavec-2-chek', 390, 844, false],
  ['ms-2-chek', 'iphone-prodavec-2-chek-grafit', 390, 844, false],
  ['m-1-glavnaya-accent', 'iphone-vladelec-1-statistika', 440, 956, false],
  ['m-2-skaner-accent', 'iphone-vladelec-2-prihod-skaner', 440, 956, false],
];
(async () => {
  const b = await chromium.launch(exe ? { executablePath: exe, args: ['--no-sandbox'] } : {});
  for (const [html, png, w, h, full] of (exe ? jobs.filter(j => j[0].startsWith('v4')) : jobs)) {
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: exe ? 1 : 2 });
    await p.goto('file://' + path.join(__dirname, html + '.html'), { waitUntil: 'networkidle' });
    await p.waitForTimeout(1200);
    await p.screenshot({ path: path.join(out, (exe ? 'chrome109-' : '') + png + '.png'), fullPage: full });
    await p.close();
  }
  await b.close();
})();
