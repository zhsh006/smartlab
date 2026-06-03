import { chromium } from 'playwright';

const baseUrl = process.env.SMARTLAB_BASE_URL || 'https://127.0.0.1:3159';
const email = process.env.SMARTLAB_EMAIL || 'smartlab.sysadmin@local.test';
const password = process.env.SMARTLAB_PASSWORD || 'p8wi4PscFqhj5drxOBkobEdw';
const pages = [
  'overview',
  'records',
  'upload',
  'recognition',
  'breakpoint',
  'jump',
  'templates',
  'algorithms',
];

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: 1440, height: 1000 },
});
const page = await context.newPage();

const out = [];

try {
  await page.goto(`${baseUrl}/login.php`, { waitUntil: 'networkidle' });
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.locator('form#login button[name="Submit"]').click(),
  ]);

  const afterLogin = page.url();
  out.push(`login url=${afterLogin}`);
  if (/login\.php|logout\.php/.test(afterLogin)) {
    const bodyText = await page.locator('body').innerText();
    throw new Error(`login failed: ${bodyText.slice(0, 500).replace(/\s+/g, ' ')}`);
  }

  for (const name of pages) {
    const target = `${baseUrl}/smartlab-${name}.php`;
    const response = await page.goto(target, { waitUntil: 'networkidle' });
    const status = response?.status() ?? 0;
    const hasShell = await page.locator('.smartlab-demo').count();
    const hasBrand = await page.getByText('SmartLab V3').count();
    const title = (await page.title()).replace(/\s+/g, ' ').trim();
    await page.screenshot({ path: `/tmp/smartlab-${name}.png`, fullPage: true });
    out.push(`${name} status=${status} shell=${hasShell} brand=${hasBrand} title=${title}`);
    if (status !== 200 || hasShell < 1 || hasBrand < 1) {
      throw new Error(`render check failed for ${name}`);
    }
  }

  console.log(out.join('\n'));
} finally {
  await browser.close();
}
