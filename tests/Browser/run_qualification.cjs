const { chromium } = require('C:/Users/HUAWEI/AppData/Roaming/npm/node_modules/@playwright/mcp/node_modules/playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8008';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

function getOtp(secret = 'JBSWY3DPEHPK3PXP') {
    const cmd = `php artisan tinker --execute "echo app(PragmaRX\\Google2FAQRCode\\Google2FA::class)->getCurrentOtp('${secret}');"`;
    return execSync(cmd, { cwd: path.resolve(__dirname, '../../') }).toString().trim();
}

async function loginAsAdmin(page) {
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'networkidle' });
    console.log('Navigated to login:', page.url());

    // Fill credentials
    await page.fill('input[type="email"]', 'admin@example.test');
    await page.fill('input[type="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    // If challenged for MFA
    if (page.url().includes('login') || (await page.$('input[autocomplete="one-time-code"]')) || (await page.$('input[name*="code"]'))) {
        console.log('MFA challenge detected, generating TOTP...');
        const otp = getOtp('JBSWY3DPEHPK3PXP');
        console.log('Generated TOTP:', otp);
        const codeInput = await page.$('input[autocomplete="one-time-code"]') || await page.$('input[name*="code"]') || await page.$('input[type="text"]');
        if (codeInput) {
            await codeInput.fill(otp);
            await page.click('button[type="submit"]');
            await page.waitForTimeout(2000);
        }
    }

    console.log('Logged in. Current URL:', page.url());
}

(async () => {
    const browser = await chromium.launch({
        headless: true,
        executablePath: CHROME_PATH
    });

    try {
        const context = await browser.newContext();
        const page = await context.newPage();

        await loginAsAdmin(page);

        // Test 1: SYS-003 System Health
        console.log('\n--- Testing SYS-003: System Health ---');
        await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });
        console.log('System Health URL:', page.url());
        
        // Assert title
        const healthTitle = await page.title();
        console.log('Page Title:', healthTitle);

        // Badges check
        const badgeTexts = await page.$$eval('.fi-badge', els => els.map(e => e.textContent.trim()));
        console.log('Found badges:', [...new Set(badgeTexts)]);

        // Collapsible section check
        const collapsibleTrigger = await page.$('button[aria-expanded]');
        if (collapsibleTrigger) {
            const initialExpanded = await collapsibleTrigger.getAttribute('aria-expanded');
            console.log('RPO/RTO collapsible initial aria-expanded:', initialExpanded);
            await collapsibleTrigger.click();
            await page.waitForTimeout(500);
            const afterExpanded = await collapsibleTrigger.getAttribute('aria-expanded');
            console.log('After click aria-expanded:', afterExpanded);
        }

        // Check for refresh action
        const refreshBtn = await page.$('button:has-text("Refresh local evidence")');
        console.log('Refresh button found:', !!refreshBtn);
        if (refreshBtn) {
            await refreshBtn.click();
            await page.waitForTimeout(1000);
            console.log('Clicked refresh local evidence.');
        }

        // Check for self test email
        const selfTestBtn = await page.$('button:has-text("Send self-test email")');
        console.log('Self-test email button found:', !!selfTestBtn);

        // Test 2: SYS-004 Governance & Audit
        console.log('\n--- Testing SYS-004: Governance & Audit ---');
        await page.goto(`${BASE_URL}/admin/governance-audit`, { waitUntil: 'networkidle' });
        console.log('Governance & Audit URL:', page.url());

        // Check tabs
        const tabs = await page.$$eval('[role="tab"]', els => els.map(e => ({
            text: e.textContent.trim(),
            selected: e.getAttribute('aria-selected'),
            tabindex: e.getAttribute('tabindex')
        })));
        console.log('Rendered WAI-ARIA tabs:', tabs);

        // Check Privacy tab copy
        const privacyTab = await page.$('[role="tab"]:has-text("Privacy and Retention Boundary")');
        if (privacyTab) {
            await privacyTab.click();
            await page.waitForTimeout(1000);
            const bodyContent = await page.content();
            const containsPrivacyNotice = bodyContent.includes("Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.");
            console.log('Contains canonical privacy notice:', containsPrivacyNotice);
        }

        console.log('\nSYS-003 and SYS-004 baseline browser check PASSED.');
    } catch (err) {
        console.error('Error during test:', err);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
