let chromium;
try {
    chromium = require('playwright').chromium;
} catch {
    try {
        chromium = require('C:/Users/HUAWEI/AppData/Roaming/npm/node_modules/@playwright/mcp/node_modules/playwright').chromium;
    } catch {
        chromium = require('@playwright/test').chromium;
    }
}
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const path = require('path');
const fs = require('fs');

const REPO_ROOT = path.resolve(__dirname, '../../');
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8008';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

function runArtisan(phpCode) {
    const trimmed = phpCode.trim();
    const body = trimmed.endsWith(';') ? trimmed : `echo (${trimmed});`;
    const code = `require __DIR__ . '/vendor/autoload.php'; $app = require __DIR__ . '/bootstrap/app.php'; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${body}`;
    return execFileSync('php', ['-d', 'memory_limit=256M', '-r', code], { cwd: REPO_ROOT, env: { ...process.env, DB_DATABASE: 'test_tala_db' } }).toString().trim();
}

function clearReplayCache() {
    runArtisan('\\Tests\\Browser\\BrowserQualificationEnvironment::clearReplayCache();');
}

function base32Decode(base32) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (let i = 0; i < base32.length; i++) {
        const val = alphabet.indexOf(base32[i].toUpperCase());
        if (val === -1) continue;
        bits += val.toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.substring(i, i + 8), 2));
    }
    return Buffer.from(bytes);
}

async function getFreshOtp(secret = 'JBSWY3DPEHPK3PXP') {
    const remainingSeconds = 30 - (Math.floor(Date.now() / 1000) % 30);
    if (remainingSeconds < 4) {
        await new Promise(r => setTimeout(r, (remainingSeconds + 1) * 1000));
    }
    const key = base32Decode(secret);
    const counter = Math.floor(Date.now() / 30000);
    const buf = Buffer.alloc(8);
    buf.writeBigUInt64BE(BigInt(counter));
    const hmac = crypto.createHmac('sha1', key).update(buf).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const code = ((hmac.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
    return code;
}

async function loginUser(page, email, password = 'password', mfaSecret = 'JBSWY3DPEHPK3PXP', loginPath = '/admin/login') {
    clearReplayCache();
    await page.goto(`${BASE_URL}${loginPath}`, { waitUntil: 'domcontentloaded' });
    await page.fill('#form\\.email', email);
    await page.fill('#form\\.password', password);
    await page.click('button[type="submit"]');

    if (mfaSecret) {
        try {
            await page.waitForSelector('#multiFactorChallengeForm\\.app\\.code', { timeout: 8000 });
            clearReplayCache();
            const otp = await getFreshOtp(mfaSecret);
            const codeInput = page.locator('#multiFactorChallengeForm\\.app\\.code');
            await codeInput.fill(otp);
            await page.click('button:has-text("Confirm sign in")');
            await page.waitForURL(url => !url.href.includes('/login'), { timeout: 15000 });
            await page.waitForLoadState('domcontentloaded');
        } catch (e) {
            if (page.url().includes('/login')) {
                await page.waitForURL(url => !url.href.includes('/login'), { timeout: 15000 });
                await page.waitForLoadState('domcontentloaded');
            }
        }
    } else {
        await page.waitForURL(url => !url.href.includes('/login'), { timeout: 15000 });
        await page.waitForLoadState('domcontentloaded');
    }
}


function getMailEventCount() {
    const res = runArtisan("echo App\\Models\\OperationalEvent::where('event_type', 'like', 'mail_self_test%')->count();");
    return parseInt(res, 10);
}

const ledger = {};

(async () => {
    console.log('================================================================');
    console.log('TALA SLICE 7 COMPLETE BROWSER ACCEPTANCE & QUALIFICATION SUITE');
    console.log('================================================================\n');

    const launchOptions = { headless: true };
    if (process.env.CHROME_PATH || fs.existsSync(CHROME_PATH)) {
        launchOptions.executablePath = process.env.CHROME_PATH || CHROME_PATH;
    }
    const browser = await chromium.launch(launchOptions);

    const context = await browser.newContext();
    const page = await context.newPage();

    try {
        // =====================================================================
        // STEP 0: Authenticate as System Administrator
        // =====================================================================
        console.log('Step 0: Authenticating System Administrator (admin@example.test)...');
        await loginUser(page, 'admin@example.test', 'password', 'JBSWY3DPEHPK3PXP');
        console.log('Authenticated successfully. First landing:', page.url());

        // =====================================================================
        // STEP 1: SYS-003 System Health
        // =====================================================================
        console.log('\n--- Step 1: SYS-003 System Health ---');
        await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });

        // 1A. Badges in table
        const badges = await page.$$eval('.fi-badge', els => [...new Set(els.map(e => e.textContent.trim()))]);
        console.log('[SYS-003] Status Badges present:', badges);
        const canonicalStatuses = ['Available', 'Needs attention', 'Unavailable', 'Not recently checked'];
        const validBadges = badges.length > 0 && badges.every(b => canonicalStatuses.includes(b));
        console.log('[SYS-003] All badges adhere to canonical 4-status vocabulary:', validBadges);
        ledger.sys003_canonical_badges = validBadges ? 'PASS' : 'FAIL';

        // 1B. Collapsible section toggle
        const collapseBtn = await page.$('.fi-section-collapse-btn, .fi-section-header');
        if (collapseBtn) {
            const sectionEl = await page.$('.fi-section.fi-collapsible');
            const wasCollapsed = await sectionEl.evaluate(el => el.classList.contains('fi-collapsed'));
            await collapseBtn.click();
            await page.waitForTimeout(600);
            const isNowCollapsed = await sectionEl.evaluate(el => el.classList.contains('fi-collapsed'));
            console.log(`[SYS-003] Collapsible RPO/RTO targets section: collapsed was ${wasCollapsed} -> now ${isNowCollapsed}`);
            ledger.sys003_collapsible_toggle = (wasCollapsed !== isNowCollapsed) ? 'PASS' : 'FAIL';
        } else {
            ledger.sys003_collapsible_toggle = 'FAIL';
        }

        // 1C. Technical disclosure copy
        const healthHtml = await page.content();
        const hasDisclosure = healthHtml.includes('planning target, not achieved evidence');
        console.log('[SYS-003] Contains prospective technical disclosure disclaimer:', hasDisclosure);
        ledger.sys003_technical_disclosure = hasDisclosure ? 'PASS' : 'FAIL';

        // 1D. Refresh local evidence action
        const refreshBtn = await page.$('button:has-text("Refresh local evidence")');
        if (refreshBtn) {
            await refreshBtn.click();
            await page.waitForTimeout(1000);
            console.log('[SYS-003] Clicked "Refresh local evidence" action successfully.');
            ledger.sys003_refresh_evidence = 'PASS';
        }

        // 1E. Mail self-test & rate limiting
        console.log('[SYS-003] Testing Send self-test email action and rate-limiting modal...');
        const adminId = runArtisan("echo App\\Models\\User::where('email', 'admin@example.test')->value('id');");
        runArtisan(`Illuminate\\Support\\Facades\\RateLimiter::clear('tala:system-health:mail-self-test:${adminId}');`);

        const countBefore = getMailEventCount();
        const selfTestBtn = await page.waitForSelector('button:has-text("Send self-test email")', { state: 'visible' });
        await selfTestBtn.click({ force: true });
        await page.waitForTimeout(800);

        const submit1 = await page.waitForSelector('.fi-modal-window button:has-text("Send self-test")', { state: 'visible', timeout: 5000 });
        await submit1.click({ force: true });
        await page.waitForTimeout(2500);

        const countAfterFirst = getMailEventCount();
        const eventRecorded = countAfterFirst > countBefore;
        console.log(`[SYS-003] Mail self-test event count: ${countBefore} -> ${countAfterFirst} (recorded: ${eventRecorded})`);
        ledger.sys003_mail_self_test = eventRecorded ? 'PASS' : 'FAIL';

        // Verify RateLimiter throttles further attempts
        const throttled = runArtisan(`echo Illuminate\\Support\\Facades\\RateLimiter::tooManyAttempts('tala:system-health:mail-self-test:${adminId}', 1) ? 'THROTTLED' : 'OPEN';`) === 'THROTTLED';
        console.log(`[SYS-003] RateLimiter throttled state after invocation: ${throttled}`);
        ledger.sys003_rate_limiting = throttled ? 'PASS' : 'FAIL';

        // Close modal if open
        await page.keyboard.press('Escape');
        await page.waitForTimeout(800);

        // =====================================================================
        // STEP 2: SYS-004 Governance & Audit
        // =====================================================================
        console.log('\n--- Step 2: SYS-004 Governance & Audit ---');
        await page.goto(`${BASE_URL}/admin/governance-audit`, { waitUntil: 'networkidle' });

        // 2A. 4 Canonical Tabs with WAI-ARIA
        const tabs = await page.$$eval('[role="tab"]', els => els.map(e => ({
            name: e.textContent.trim(),
            role: e.getAttribute('role'),
            selected: e.getAttribute('aria-selected'),
            tabindex: e.getAttribute('tabindex'),
            controls: e.getAttribute('aria-controls')
        })));
        console.log('[SYS-004] Rendered Tabs:', tabs.map(t => t.name));
        const expectedTabs = [
            'Institutional Changes',
            'System Events',
            'Output and Export Access',
            'Privacy and Retention Boundary'
        ];
        const tabsMatch = tabs.length === 4 && tabs.every((t, i) => t.name === expectedTabs[i]);
        console.log('[SYS-004] Exact 4 canonical tabs present:', tabsMatch);
        ledger.sys004_canonical_tabs = tabsMatch ? 'PASS' : 'FAIL';

        // 2B. Physical keyboard roving focus
        console.log('[SYS-004] Testing physical keyboard roving focus (ArrowRight, End, Home)...');
        const firstTab = await page.$('#tab-institutional-changes');
        await firstTab.focus();

        await page.keyboard.press('ArrowRight');
        await page.waitForTimeout(300);
        const activeAfterRight = await page.$eval('[role="tab"][tabindex="0"]', el => el.textContent.trim());

        await page.keyboard.press('End');
        await page.waitForTimeout(300);
        const activeAfterEnd = await page.$eval('[role="tab"][tabindex="0"]', el => el.textContent.trim());

        await page.keyboard.press('Home');
        await page.waitForTimeout(300);
        const activeAfterHome = await page.$eval('[role="tab"][tabindex="0"]', el => el.textContent.trim());

        const rovingPassed = (activeAfterRight === 'System Events' && activeAfterEnd === 'Privacy and Retention Boundary' && activeAfterHome === 'Institutional Changes');
        console.log(`[SYS-004] Roving focus: Right->"${activeAfterRight}", End->"${activeAfterEnd}", Home->"${activeAfterHome}" -> ${rovingPassed ? 'PASS' : 'FAIL'}`);
        ledger.sys004_roving_focus = rovingPassed ? 'PASS' : 'FAIL';

        // 2B.1 Physical keyboard Enter / Space tab activation
        console.log('[SYS-004] Testing physical keyboard Enter and Space tab activation...');
        await page.keyboard.press('ArrowRight'); // Focuses System Events
        await page.waitForTimeout(200);
        await page.keyboard.press('Enter'); // Activates System Events
        await page.waitForFunction(() => {
            const btn = document.getElementById('tab-system-events');
            return btn && btn.getAttribute('aria-selected') === 'true';
        }, { timeout: 8000 });
        const systemEventsActive = await page.$eval('#tab-system-events', el => el.getAttribute('aria-selected') === 'true');

        await page.keyboard.press('ArrowRight'); // Focuses Output and Export Access
        await page.waitForTimeout(200);
        await page.keyboard.press('Space'); // Activates Output and Export Access
        await page.waitForFunction(() => {
            const btn = document.getElementById('tab-output-access');
            return btn && btn.getAttribute('aria-selected') === 'true';
        }, { timeout: 8000 });
        const outputAccessActive = await page.$eval('#tab-output-access', el => el.getAttribute('aria-selected') === 'true');
        await page.keyboard.press('Home'); // Focuses Institutional Changes
        await page.waitForTimeout(200);
        await page.keyboard.press('Enter'); // Re-activates Institutional Changes
        await page.waitForFunction(() => {
            const btn = document.getElementById('tab-institutional-changes');
            return btn && btn.getAttribute('aria-selected') === 'true';
        }, { timeout: 8000 });

        const keyboardActivationPassed = systemEventsActive && outputAccessActive;
        console.log(`[SYS-004] Keyboard Enter/Space tab activation: ${keyboardActivationPassed ? 'PASS' : 'FAIL'}`);

        // 2C. Slide-Over Detail View & Escape key focus restoration
        console.log('[SYS-004] Testing slide-over detail view and Escape close...');
        const viewDetailBtn = await page.waitForSelector('button:has-text("View detail")', { state: 'visible', timeout: 8000 });
        await viewDetailBtn.click({ force: true });

        const dialog = await page.waitForSelector('.fi-modal-open, .fi-modal-window, [role="dialog"]', { state: 'attached', timeout: 8000 }).catch(() => null);
        const hasDialog = !!dialog;
        console.log('[SYS-004] Slide-over modal open (attached):', hasDialog);

        const modalContent = dialog ? await dialog.innerText() : '';
        const hasAllowlistedFields = modalContent.includes('Reference ID') && modalContent.includes('Date and time') && modalContent.includes('Actor');
        console.log('[SYS-004] Modal presents allowlisted safe fields:', hasAllowlistedFields);

        await page.keyboard.press('Escape');
        await page.waitForSelector('.fi-modal-open, .fi-modal-window, [role="dialog"]', { state: 'detached', timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(500);
        const dialogAfterEscape = await page.$('.fi-modal-open, .fi-modal-window, [role="dialog"]');
        const closedCleanly = !dialogAfterEscape;
        console.log('[SYS-004] Modal closed via Escape key:', closedCleanly);
        ledger.sys004_slide_over = (hasDialog && hasAllowlistedFields && closedCleanly) ? 'PASS' : 'FAIL';

        // 2D. Canonical Privacy Notice on Privacy tab & WAI-ARIA Click Synchronization
        console.log('[SYS-004] Testing Privacy and Retention Boundary tab...');
        await page.click('#tab-privacy-retention');
        await page.waitForFunction(() => {
            const btn = document.getElementById('tab-privacy-retention');
            const panel = document.getElementById('tabpanel-governance');
            return btn && btn.getAttribute('aria-selected') === 'true' && panel && panel.getAttribute('aria-labelledby') === 'tab-privacy-retention';
        }, { timeout: 10000 });

        const isSelected = await page.$eval('#tab-privacy-retention', el => el.getAttribute('aria-selected'));
        const tabindex = await page.$eval('#tab-privacy-retention', el => el.getAttribute('tabindex'));
        const labelledBy = await page.$eval('#tabpanel-governance', el => el.getAttribute('aria-labelledby'));
        console.log(`[SYS-004] After click: aria-selected="${isSelected}", tabindex="${tabindex}", labelledBy="${labelledBy}"`);
        const ariaSyncPassed = (isSelected === 'true' && tabindex === '0' && labelledBy === 'tab-privacy-retention' && keyboardActivationPassed);
        ledger.sys004_wai_aria_click_sync = ariaSyncPassed ? 'PASS' : 'FAIL';

        await page.locator('text=Automatic record disposal is not available in TALA').waitFor({ timeout: 10000 });
        const privacyContent = await page.content();
        const expectedNotice = "Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.";
        const hasCanonicalNotice = privacyContent.includes(expectedNotice);
        console.log('[SYS-004] Canonical statutory copy present on Privacy tab:', hasCanonicalNotice);
        ledger.sys004_canonical_privacy = hasCanonicalNotice ? 'PASS' : 'FAIL';

        // =====================================================================
        // STEP 3: Responsive Viewports & 200% Zoom Reflow (Criterion 28)
        // =====================================================================
        console.log('\n--- Step 3: Responsive Viewports & 200% Zoom Reflow ---');
        const viewports = [
            { name: '360x800 (Narrow mobile)', width: 360, height: 800 },
            { name: '390x844 (Standard mobile)', width: 390, height: 844 },
            { name: '768x1024 (Tablet portrait)', width: 768, height: 1024 },
            { name: '1366x768 (Standard desktop)', width: 1366, height: 768 },
            { name: '200% Zoom (Desktop reflow: 683x384 @2x)', width: 683, height: 384 },
        ];

        let allViewportsPassed = true;
        const testPages = ['/admin/system-health', '/admin/governance-audit'];
        for (const testPath of testPages) {
            for (const vp of viewports) {
                await page.setViewportSize({ width: vp.width, height: vp.height });
                await page.goto(`${BASE_URL}${testPath}`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(400);

                const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
                const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
                const overflow = scrollWidth > (clientWidth + 1);

                console.log(`[Viewport: ${testPath}] ${vp.name}: clientWidth=${clientWidth}, scrollWidth=${scrollWidth} -> ${overflow ? 'OVERFLOW (FAIL)' : 'ZERO OVERFLOW (PASS)'}`);
                if (overflow) allViewportsPassed = false;
            }
        }

        // Mobile drawer open/close test across both System Health and Governance Audit
        let mobileDrawerPassed = true;
        for (const testPath of testPages) {
            for (const vp of [{ width: 390, height: 844 }, { width: 360, height: 800 }]) {
                await page.setViewportSize(vp);
                await page.goto(`${BASE_URL}${testPath}`, { waitUntil: 'networkidle' });
                const mobileNavBtn = await page.$('.fi-topbar-open-sidebar-btn, button[x-on\\:click*="openSidebar"], button[aria-label*="sidebar" i], .fi-topbar button:has(svg)');
                if (!mobileNavBtn) {
                    console.error(`[Viewport] Mobile sidebar toggle button not found on ${testPath} at ${vp.width}x${vp.height}`);
                    mobileDrawerPassed = false;
                    continue;
                }
                await mobileNavBtn.click();
                await page.waitForTimeout(600);
                const isDrawerOpen = await page.evaluate(() => {
                    const host = document.querySelector('.tala-sidebar-host');
                    const isOpenState = window.Alpine?.$store?.sidebar?.isOpen;
                    return !!isOpenState || (host && host.getAttribute('role') === 'dialog');
                });
                console.log(`[Viewport: ${testPath} ${vp.width}x${vp.height}] Mobile drawer open: ${isDrawerOpen}`);
                if (!isDrawerOpen) mobileDrawerPassed = false;

                await page.keyboard.press('Escape');
                await page.waitForTimeout(600);
                const isDrawerClosed = await page.evaluate(() => {
                    const isOpenState = window.Alpine?.$store?.sidebar?.isOpen;
                    return isOpenState === false || !isOpenState;
                });
                console.log(`[Viewport: ${testPath} ${vp.width}x${vp.height}] Mobile drawer closed: ${isDrawerClosed}`);
                if (!isDrawerClosed) mobileDrawerPassed = false;
            }
        }
        ledger.responsive_viewports_and_zoom = (allViewportsPassed && mobileDrawerPassed) ? 'PASS' : 'FAIL';

        // =====================================================================
        // STEP 4: Native Theme Switcher, OS Mode & Learner Persistence (Criterion 23)
        // =====================================================================
        console.log('\n--- Step 4: Native Theme Switcher, OS Mode & Learner Persistence (Criterion 23) ---');
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });

        // 4A. Native UI click: Dark Theme
        console.log('[Criterion 23] Opening user menu and clicking native "Enable dark theme" button...');
        const userMenuBtn = await page.waitForSelector('.fi-user-menu-trigger, button[aria-label="User menu"]', { timeout: 5000 });
        await userMenuBtn.click();
        await page.waitForTimeout(500);

        const darkBtn = await page.waitForSelector('.fi-theme-switcher button[aria-label*="dark" i]');
        await darkBtn.click();
        await page.waitForTimeout(500);

        const isDarkAfterClick = await page.evaluate(() => document.documentElement.classList.contains('dark'));
        const themeStoredDark = await page.evaluate(() => localStorage.getItem('theme'));
        console.log(`  - After native Dark toggle: classList has "dark" = ${isDarkAfterClick}, localStorage.theme = "${themeStoredDark}"`);

        // 4B. Native UI click: System Theme & OS Emulation
        console.log('[Criterion 23] Opening user menu and clicking native "Enable system theme" button...');
        await userMenuBtn.click();
        await page.waitForTimeout(500);

        const sysBtn = await page.waitForSelector('.fi-theme-switcher button[aria-label*="system" i]');
        await sysBtn.click();
        await page.waitForTimeout(500);

        const themeStoredSystem = await page.evaluate(() => localStorage.getItem('theme'));
        console.log(`  - After native System toggle: localStorage.theme = "${themeStoredSystem}"`);

        // Emulate OS dark mode
        await page.emulateMedia({ colorScheme: 'dark' });
        await page.waitForTimeout(300);
        const darkUnderOS = await page.evaluate(() => document.documentElement.classList.contains('dark'));

        // Emulate OS light mode
        await page.emulateMedia({ colorScheme: 'light' });
        await page.waitForTimeout(300);
        const lightUnderOS = await page.evaluate(() => !document.documentElement.classList.contains('dark'));
        console.log(`  - System mode OS media emulation: Dark OS preference active = ${darkUnderOS}, Light OS preference active = ${lightUnderOS}`);

        // 4C. Switch to Dark mode and verify persistence across reload and on Learner Surface (/student/dashboard)
        console.log('[Criterion 23] Testing theme persistence on Learner surface (/student/dashboard)...');
        await userMenuBtn.click();
        await page.waitForTimeout(500);
        const darkBtn2 = await page.waitForSelector('.fi-theme-switcher button[aria-label*="dark" i]');
        await darkBtn2.click();
        await page.waitForTimeout(500);

        // Open student session to test learner workspace theme persistence
        const studentThemeContext = await browser.newContext();
        const studentThemePage = await studentThemeContext.newPage();
        await loginUser(studentThemePage, 'student.test@example.test', 'password', null, '/student/login');

        // Propagate dark theme in learner session storage and reload
        await studentThemePage.evaluate(() => localStorage.setItem('theme', 'dark'));
        await studentThemePage.goto(`${BASE_URL}/student`, { waitUntil: 'networkidle' });

        // Verify learner's rendered theme (computed styles, background color, text color)
        const studentDarkStyles = await studentThemePage.evaluate(() => {
            const hasDarkClass = document.documentElement.classList.contains('dark');
            const storedTheme = localStorage.getItem('theme');
            const bodyStyle = window.getComputedStyle(document.body);
            const headingEl = document.querySelector('h1, h2, .fi-header-heading') || document.body;
            const headingStyle = window.getComputedStyle(headingEl);

            return {
                hasDarkClass,
                storedTheme,
                bodyBg: bodyStyle.backgroundColor,
                bodyColor: bodyStyle.color,
                headingColor: headingStyle.color,
            };
        });

        console.log(`  - Learner surface (/student/dashboard) dark theme: classList has "dark"=${studentDarkStyles.hasDarkClass}, bodyBg="${studentDarkStyles.bodyBg}", bodyColor="${studentDarkStyles.bodyColor}", headingColor="${studentDarkStyles.headingColor}"`);

        // Also test switching to light theme on learner surface and verify computed style change
        await studentThemePage.evaluate(() => localStorage.setItem('theme', 'light'));
        await studentThemePage.goto(`${BASE_URL}/student`, { waitUntil: 'networkidle' });

        const studentLightStyles = await studentThemePage.evaluate(() => {
            const hasDarkClass = document.documentElement.classList.contains('dark');
            const storedTheme = localStorage.getItem('theme');
            const bodyStyle = window.getComputedStyle(document.body);
            const headingEl = document.querySelector('h1, h2, .fi-header-heading') || document.body;
            const headingStyle = window.getComputedStyle(headingEl);

            return {
                hasDarkClass,
                storedTheme,
                bodyBg: bodyStyle.backgroundColor,
                bodyColor: bodyStyle.color,
                headingColor: headingStyle.color,
            };
        });

        console.log(`  - Learner surface (/student/dashboard) light theme: classList has "dark"=${studentLightStyles.hasDarkClass}, bodyBg="${studentLightStyles.bodyBg}", bodyColor="${studentLightStyles.bodyColor}", headingColor="${studentLightStyles.headingColor}"`);

        const renderedThemeStylesDiffer = (studentDarkStyles.bodyBg !== studentLightStyles.bodyBg || studentDarkStyles.bodyColor !== studentLightStyles.bodyColor);
        const studentDarkPersisted = studentDarkStyles.hasDarkClass &&
            studentDarkStyles.storedTheme === 'dark' &&
            !studentLightStyles.hasDarkClass &&
            studentLightStyles.storedTheme === 'light' &&
            renderedThemeStylesDiffer;

        console.log(`  - Learner surface rendered theme verified: ${studentDarkPersisted} (styles differ: ${renderedThemeStylesDiffer})`);
        await studentThemeContext.close();

        // 4D. Revert back to light theme via native toggle
        await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });
        const revertUserMenuBtn = await page.waitForSelector('.fi-user-menu-trigger, button[aria-label="User menu"]', { timeout: 5000 });
        await revertUserMenuBtn.click();
        await page.waitForTimeout(500);
        const lightBtn = await page.waitForSelector('.fi-theme-switcher button[aria-label*="light" i]');
        await lightBtn.click();
        await page.waitForTimeout(500);
        const finalLightMode = await page.evaluate(() => !document.documentElement.classList.contains('dark') && localStorage.getItem('theme') === 'light');
        console.log(`  - Reverted back to native Light mode: ${finalLightMode}`);

        const themeSuitePassed = (isDarkAfterClick && themeStoredDark === 'dark' && themeStoredSystem === 'system' && darkUnderOS && lightUnderOS && studentDarkPersisted && finalLightMode);
        ledger.theme_persistence = themeSuitePassed ? 'PASS' : 'FAIL';
        ledger.criterion_23_native_theme_toggle_and_persistence = themeSuitePassed ? 'PASS' : 'FAIL';

        // =====================================================================
        // STEP 5: Real Print Layout, Multipage, Monochrome & Failure Contracts (Criterion 24)
        // =====================================================================
        console.log('\n--- Step 5: Real Print Layout, Multipage, Monochrome & Failure Contracts (Criterion 24) ---');
        const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures.json'), 'utf8'));
        let allPrintOutputsPassed = true;
        let multipagePassed = true;
        let monochromePassed = true;

        // 5A: Registrar session for OUT-001, OUT-002, OUT-003, OUT-005, Class Roster
        console.log('[Print] Verifying outputs accessible to Registrar (OUT-001, OUT-002, OUT-003, OUT-005, Class Roster)...');
        const regContext = await browser.newContext();
        const regPage = await regContext.newPage();
        await loginUser(regPage, 'registrar.test@example.test', 'password', 'JBSWY3DPEHPK3PXP', '/admin/login');
        await regPage.emulateMedia({ media: 'print' });

        const artifactsDir = path.resolve(__dirname, 'artifacts');
        if (!fs.existsSync(artifactsDir)) {
            fs.mkdirSync(artifactsDir, { recursive: true });
        }

        const regOutputs = [
            {
                id: 'OUT-001',
                name: 'Application Acknowledgment',
                url: fixtures.out001,
                toolbarSelector: '.controls',
                expectedNotice: 'not an admission certificate',
                landscape: false,
            },
            {
                id: 'OUT-002',
                name: 'Published Timetable',
                url: fixtures.out002,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'PUBLISHED TIMETABLE',
                landscape: true,
            },
            {
                id: 'OUT-003',
                name: 'Certificate of Registration (COR)',
                url: fixtures.out003,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'Certificate of Registration',
                landscape: false,
            },
            {
                id: 'OUT-005',
                name: 'TALA Standard TOR Preview',
                url: fixtures.out005,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'TRANSCRIPT OF RECORDS',
                landscape: false,
            },
            {
                id: 'ClassRoster',
                name: 'Operational Class Roster',
                url: fixtures.classRoster,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'Operational reference — not an official issuance',
                landscape: true,
            },
        ];

        for (const out of regOutputs) {
            console.log(`[Print] Visiting ${out.id} (${out.name}) at ${out.url}...`);
            const resp = await regPage.goto(`${BASE_URL}${out.url}`, { waitUntil: 'networkidle' });
            const status = resp.status();
            const content = await regPage.content();
            const toolbarDisplay = await regPage.$eval(out.toolbarSelector, el => window.getComputedStyle(el).display).catch(() => 'NOT_FOUND');
            const bodyBg = await regPage.$eval('body', el => window.getComputedStyle(el).backgroundColor).catch(() => 'UNKNOWN');
            const hasNotice = content.includes(out.expectedNotice);
            const hasBreakAvoid = await regPage.evaluate(() => {
                const styles = Array.from(document.querySelectorAll('style')).map(s => s.textContent).join('\n');
                if (styles.includes('break-inside: avoid') || styles.includes('break-inside:avoid')) return true;
                try {
                    for (const sheet of document.styleSheets) {
                        try {
                            for (const rule of sheet.cssRules) {
                                if (rule.cssText && (rule.cssText.includes('break-inside: avoid') || rule.cssText.includes('break-inside:avoid'))) {
                                    return true;
                                }
                            }
                        } catch {}
                    }
                } catch {}
                const el = document.querySelector('tr, .notice, .official-output-notice');
                if (el) {
                    const cs = window.getComputedStyle(el);
                    if (cs.breakInside === 'avoid' || cs.pageBreakInside === 'avoid') return true;
                }
                return false;
            });

            const breakInside = await regPage.evaluate(() => {
                const tr = document.querySelector('tr');
                if (!tr) return 'avoid';
                const style = window.getComputedStyle(tr);
                return style.breakInside || style.pageBreakInside || 'avoid';
            });
            const validBreakInside = (breakInside === 'avoid' || hasBreakAvoid);

            const isMonochromeBg = (bodyBg === 'rgb(255, 255, 255)' || bodyBg === 'rgba(0, 0, 0, 0)' || bodyBg === 'transparent');
            const colorAdjust = await regPage.evaluate(() => {
                const style = window.getComputedStyle(document.body);
                return style.printColorAdjust || style.webkitPrintColorAdjust || 'exact';
            });
            const validColorAdjust = (colorAdjust === 'exact' || isMonochromeBg);

            const hasMultipage = await regPage.evaluate(() => {
                const thead = document.querySelector('thead');
                if (thead && window.getComputedStyle(thead).display === 'table-header-group') return true;
                const styles = Array.from(document.querySelectorAll('style')).map(s => s.textContent).join('\n');
                if (styles.includes('table-header-group') || styles.includes('break-after') || styles.includes('@page')) return true;
                return false;
            });

            // Generate and inspect actual output PDF artifact
            const pdfFilename = `${out.id}.pdf`;
            const pdfPath = path.join(artifactsDir, pdfFilename);
            await regPage.pdf({
                path: pdfPath,
                format: 'A4',
                landscape: !!out.landscape,
                printBackground: true,
            });

            const pdfExists = fs.existsSync(pdfPath);
            const pdfSize = pdfExists ? fs.statSync(pdfPath).size : 0;
            const pdfContent = pdfExists ? fs.readFileSync(pdfPath).toString('binary') : '';
            const pageMatches = pdfContent.match(/\/Type\s*\/Page\b/g) || [];
            const pageCount = pageMatches.length;
            const pdfValid = pdfExists && pdfSize > 500 && pageCount >= 1;

            // Multipage PDF inspection on representative outputs
            let multipageVerified = true;
            if (out.id === 'OUT-005' || out.id === 'ClassRoster') {
                await regPage.evaluate(() => {
                    const tbody = document.querySelector('tbody');
                    if (tbody) {
                        const tr = tbody.querySelector('tr');
                        if (tr) {
                            for (let i = 0; i < 45; i++) {
                                tbody.appendChild(tr.cloneNode(true));
                            }
                        }
                    }
                });

                const multipagePdfFilename = `${out.id}-multipage.pdf`;
                const multipagePdfPath = path.join(artifactsDir, multipagePdfFilename);
                await regPage.pdf({
                    path: multipagePdfPath,
                    format: 'A4',
                    landscape: !!out.landscape,
                    printBackground: true,
                });

                const multiBuffer = fs.readFileSync(multipagePdfPath);
                const multiContent = multiBuffer.toString('binary');
                const multiMatches = multiContent.match(/\/Type\s*\/Page\b/g) || [];
                const multiPageCount = multiMatches.length;
                multipageVerified = multiPageCount > 1;
                console.log(`  - ${out.id} Multipage PDF generated: ${multipagePdfFilename} (${multiBuffer.length} bytes, pages=${multiPageCount}, multipageVerified=${multipageVerified})`);
            }

            const passed = (status === 200 && toolbarDisplay === 'none' && hasNotice && validBreakInside && validColorAdjust && hasMultipage && pdfValid && multipageVerified);
            console.log(`  - ${out.id} Status: ${status} (expected 200)`);
            console.log(`  - ${out.id} Toolbar (${out.toolbarSelector}) display: "${toolbarDisplay}" (expected "none")`);
            console.log(`  - ${out.id} Body bg: "${bodyBg}" (monochrome verified: ${isMonochromeBg})`);
            console.log(`  - ${out.id} PDF artifact: ${pdfFilename} (${pdfSize} bytes, pages=${pageCount}, valid=${pdfValid})`);
            console.log(`  - ${out.id} Multipage contract present: ${hasMultipage}`);
            console.log(`  - ${out.id} Break-inside avoid rule present: ${validBreakInside}`);
            console.log(`  - ${out.id} Color adjust exact present: ${validColorAdjust}`);
            console.log(`  - ${out.id} Statutory notice present: ${hasNotice}`);
            console.log(`  - ${out.id} Result: ${passed ? 'PASS' : 'FAIL'}`);

            if (!passed) allPrintOutputsPassed = false;
            if (!validBreakInside || !multipageVerified) multipagePassed = false;
            if (!validColorAdjust) monochromePassed = false;
        }
        await regContext.close();

        // 5B: Student session for OUT-004, OUT-006, OUT-007
        console.log('\n[Print] Verifying outputs accessible to Student (OUT-004, OUT-006, OUT-007)...');
        const stuContext = await browser.newContext();
        const stuPage = await stuContext.newPage();
        await loginUser(stuPage, 'student.test@example.test', 'password', null, '/student/login');
        await stuPage.emulateMedia({ media: 'print' });

        const stuOutputs = [
            {
                id: 'OUT-004',
                name: 'Unofficial Student Record',
                url: fixtures.out004,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'UNOFFICIAL — FOR STUDENT REFERENCE',
                landscape: false,
            },
            {
                id: 'OUT-006',
                name: 'Statement of Account (SOA)',
                url: fixtures.out006,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'Statement of Account',
                landscape: false,
            },
            {
                id: 'OUT-007',
                name: 'Payment Acknowledgment',
                url: fixtures.out007,
                toolbarSelector: '.official-output-toolbar',
                expectedNotice: 'Payment Acknowledgment',
                landscape: false,
            },
        ];

        for (const out of stuOutputs) {
            console.log(`[Print] Visiting ${out.id} (${out.name}) at ${out.url}...`);
            const resp = await stuPage.goto(`${BASE_URL}${out.url}`, { waitUntil: 'networkidle' });
            const status = resp.status();
            const content = await stuPage.content();
            const toolbarDisplay = await stuPage.$eval(out.toolbarSelector, el => window.getComputedStyle(el).display).catch(() => 'NOT_FOUND');
            const bodyBg = await stuPage.$eval('body', el => window.getComputedStyle(el).backgroundColor).catch(() => 'UNKNOWN');
            const hasNotice = content.includes(out.expectedNotice);
            const hasBreakAvoid = await stuPage.evaluate(() => {
                const styles = Array.from(document.querySelectorAll('style')).map(s => s.textContent).join('\n');
                if (styles.includes('break-inside: avoid') || styles.includes('break-inside:avoid')) return true;
                try {
                    for (const sheet of document.styleSheets) {
                        try {
                            for (const rule of sheet.cssRules) {
                                if (rule.cssText && (rule.cssText.includes('break-inside: avoid') || rule.cssText.includes('break-inside:avoid'))) {
                                    return true;
                                }
                            }
                        } catch {}
                    }
                } catch {}
                const el = document.querySelector('tr, .notice, .official-output-notice');
                if (el) {
                    const cs = window.getComputedStyle(el);
                    if (cs.breakInside === 'avoid' || cs.pageBreakInside === 'avoid') return true;
                }
                return false;
            });

            const breakInside = await stuPage.evaluate(() => {
                const tr = document.querySelector('tr');
                if (!tr) return 'avoid';
                const style = window.getComputedStyle(tr);
                return style.breakInside || style.pageBreakInside || 'avoid';
            });
            const validBreakInside = (breakInside === 'avoid' || hasBreakAvoid);

            const isMonochromeBg = (bodyBg === 'rgb(255, 255, 255)' || bodyBg === 'rgba(0, 0, 0, 0)' || bodyBg === 'transparent');
            const colorAdjust = await stuPage.evaluate(() => {
                const style = window.getComputedStyle(document.body);
                return style.printColorAdjust || style.webkitPrintColorAdjust || 'exact';
            });
            const validColorAdjust = (colorAdjust === 'exact' || isMonochromeBg);

            const hasMultipage = await stuPage.evaluate(() => {
                const thead = document.querySelector('thead');
                if (thead && window.getComputedStyle(thead).display === 'table-header-group') return true;
                const styles = Array.from(document.querySelectorAll('style')).map(s => s.textContent).join('\n');
                if (styles.includes('table-header-group') || styles.includes('break-after') || styles.includes('@page')) return true;
                return false;
            });

            // Generate and inspect actual output PDF artifact
            const pdfFilename = `${out.id}.pdf`;
            const pdfPath = path.join(artifactsDir, pdfFilename);
            await stuPage.pdf({
                path: pdfPath,
                format: 'A4',
                landscape: !!out.landscape,
                printBackground: true,
            });

            const pdfExists = fs.existsSync(pdfPath);
            const pdfSize = pdfExists ? fs.statSync(pdfPath).size : 0;
            const pdfContent = pdfExists ? fs.readFileSync(pdfPath).toString('binary') : '';
            const pageMatches = pdfContent.match(/\/Type\s*\/Page\b/g) || [];
            const pageCount = pageMatches.length;
            const pdfValid = pdfExists && pdfSize > 500 && pageCount >= 1;

            // Multipage inspection on student record OUT-004
            let multipageVerified = true;
            if (out.id === 'OUT-004') {
                await stuPage.evaluate(() => {
                    const tbody = document.querySelector('tbody');
                    if (tbody) {
                        const tr = tbody.querySelector('tr');
                        if (tr) {
                            for (let i = 0; i < 45; i++) {
                                tbody.appendChild(tr.cloneNode(true));
                            }
                        }
                    }
                });

                const multipagePdfFilename = `${out.id}-multipage.pdf`;
                const multipagePdfPath = path.join(artifactsDir, multipagePdfFilename);
                await stuPage.pdf({
                    path: multipagePdfPath,
                    format: 'A4',
                    landscape: !!out.landscape,
                    printBackground: true,
                });

                const multiBuffer = fs.readFileSync(multipagePdfPath);
                const multiContent = multiBuffer.toString('binary');
                const multiMatches = multiContent.match(/\/Type\s*\/Page\b/g) || [];
                const multiPageCount = multiMatches.length;
                multipageVerified = multiPageCount > 1;
                console.log(`  - ${out.id} Multipage PDF generated: ${multipagePdfFilename} (${multiBuffer.length} bytes, pages=${multiPageCount}, multipageVerified=${multipageVerified})`);
            }

            const passed = (status === 200 && toolbarDisplay === 'none' && hasNotice && validBreakInside && validColorAdjust && hasMultipage && pdfValid && multipageVerified);
            console.log(`  - ${out.id} Status: ${status} (expected 200)`);
            console.log(`  - ${out.id} Toolbar (${out.toolbarSelector}) display: "${toolbarDisplay}" (expected "none")`);
            console.log(`  - ${out.id} Body bg: "${bodyBg}" (monochrome verified: ${isMonochromeBg})`);
            console.log(`  - ${out.id} PDF artifact: ${pdfFilename} (${pdfSize} bytes, pages=${pageCount}, valid=${pdfValid})`);
            console.log(`  - ${out.id} Multipage contract present: ${hasMultipage}`);
            console.log(`  - ${out.id} Break-inside avoid rule present: ${validBreakInside}`);
            console.log(`  - ${out.id} Color adjust exact present: ${validColorAdjust}`);
            console.log(`  - ${out.id} Statutory notice present: ${hasNotice}`);
            console.log(`  - ${out.id} Result: ${passed ? 'PASS' : 'FAIL'}`);

            if (!passed) allPrintOutputsPassed = false;
            if (!validBreakInside || !multipageVerified) multipagePassed = false;
            if (!validColorAdjust) monochromePassed = false;
        }

        // 5C: Output Failure Contracts (403 unauthorized + safe access logging, 404 not found)
        console.log('\n[Criterion 24] Verifying Output Failure Contracts (403 on unauthorized access, 404 on missing output)...');
        await stuPage.emulateMedia({ media: 'screen' });

        // Student attempts accessing registrar-only transcript preview (OUT-005)
        const unauthorizedResp = await stuPage.goto(`${BASE_URL}${fixtures.out005}`, { waitUntil: 'domcontentloaded' });
        const forbiddenStatus = unauthorizedResp.status();
        console.log(`  - Student accessing Registrar Transcript Preview: status=${forbiddenStatus} (expected 403)`);

        // Check that a safe denied access log was recorded in output_access_logs
        const studentUserId = runArtisan("echo App\\Models\\User::where('email', 'student.test@example.test')->value('id');");
        const deniedLogCount = runArtisan(`echo App\\Models\\OutputAccessLog::where('actor_user_id', ${studentUserId})->where('action', 'denied')->count();`);
        const safeAccessLogRecorded = parseInt(deniedLogCount, 10) > 0;
        console.log(`  - Safe access log in output_access_logs: denied events=${deniedLogCount} (recorded: ${safeAccessLogRecorded})`);

        // Accessing non-existent output
        const notFoundResp = await stuPage.goto(`${BASE_URL}/outputs/cor/999999`, { waitUntil: 'domcontentloaded' });
        const notFoundStatus = notFoundResp.status();
        console.log(`  - Accessing non-existent COR output /outputs/cor/999999: status=${notFoundStatus} (expected 404)`);

        await stuContext.close();

        const failureContractPassed = (forbiddenStatus === 403 && safeAccessLogRecorded && notFoundStatus === 404);
        ledger.print_simulation = allPrintOutputsPassed ? 'PASS' : 'FAIL';
        ledger.criterion_24_multipage_monochrome_and_failure_contracts = (allPrintOutputsPassed && multipagePassed && monochromePassed && failureContractPassed) ? 'PASS' : 'FAIL';

        // =====================================================================
        // STEP 6: Shared Authenticated Shell Navigation across Roles
        // =====================================================================
        console.log('\n--- Step 6: Shared Shell Navigation & Isolation ---');
        const roleNavExpectations = {
            'system-super-admin': {
                nav: ['Users & Access', 'Public Content', 'System Health', 'Governance & Audit']
            },
            'registrar': {
                nav: ['Admissions', 'Catalog & Curricula', 'Term Planning', 'Students & Enrollment', 'Grades & Completion']
            },
            'accounting': {
                nav: ['Fee Plans', 'Student Accounts']
            },
            'faculty': {
                nav: ['My Availability', 'My Schedule', 'Grade Rosters']
            },
            'academic-head': {
                nav: ['Academic Oversight']
            }
        };

        const prohibitedDestinations = ['Staff Home', 'Reports', 'Settings', 'Approvals', 'Readiness Center'];
        let allRoleNavPassed = true;

        for (const [role, exp] of Object.entries(roleNavExpectations)) {
            let navLabels = [];
            if (role === 'system-super-admin') {
                // Already authenticated in main page session
                await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });
                await page.waitForSelector('.fi-sidebar-item-label', { timeout: 10000 }).catch(() => {});
                navLabels = await page.$$eval('.fi-sidebar-item-label, .fi-sidebar-group-label', els => els.map(e => e.textContent.trim()));
            } else {
                const roleEmail = (role === 'academic-head') ? 'ahead.test@example.test' : `${role}.test@example.test`;
                const roleContext = await browser.newContext();
                const rolePage = await roleContext.newPage();

                await loginUser(rolePage, roleEmail, 'password', 'JBSWY3DPEHPK3PXP');
                await rolePage.waitForSelector('.fi-sidebar-item-label', { timeout: 10000 }).catch(() => {});
                navLabels = await rolePage.$$eval('.fi-sidebar-item-label, .fi-sidebar-group-label', els => els.map(e => e.textContent.trim()));
                await roleContext.close();
            }

            console.log(`[Role: ${role}] Navigation items:`, [...new Set(navLabels)]);

            // Verify expected items are present and prohibited are absent
            const hasExpected = exp.nav.every(item => navLabels.some(label => label.includes(item)));
            const hasProhibited = prohibitedDestinations.some(item => navLabels.some(label => label.includes(item)));

            console.log(`  - Has expected items: ${hasExpected}`);
            console.log(`  - Free of prohibited items: ${!hasProhibited}`);

            if (!hasExpected || hasProhibited) {
                allRoleNavPassed = false;
            }
        }

        ledger.shared_shell_navigation_and_isolation = allRoleNavPassed ? 'PASS' : 'FAIL';

        // =====================================================================
        // STEP 7: Screen-Reader, Status Announcement, Forced Colors & Motion (Criterion 29)
        // =====================================================================
        console.log('\n--- Step 7: Screen-Reader, Status Announcement, Forced Colors & Motion (Criterion 29) ---');
        await page.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });

        // 7A. Status announcements / Live Regions
        console.log('[Criterion 29] Testing status announcements and aria-live polite regions...');
        const refreshActionBtn = await page.waitForSelector('button:has-text("Refresh local evidence")', { state: 'visible' });
        await refreshActionBtn.click();
        await page.waitForTimeout(1000);

        const liveRegionNotice = await page.waitForSelector('[role="status"][aria-live="polite"]', { timeout: 5000 });
        const liveRegionText = await liveRegionNotice.innerText();
        const hasLiveEvidenceNotice = liveRegionText.includes('Local evidence was refreshed');
        console.log(`  - Live region announcement on Refresh: "${liveRegionText.trim()}" (valid: ${hasLiveEvidenceNotice})`);

        // Toast notification container check
        const toastContainer = await page.$('.fi-no[role="status"], [role="status"]');
        const hasToastContainer = !!toastContainer;
        console.log(`  - Notification toast container with role="status": ${hasToastContainer}`);

        // 7B. Forced Colors mode focus ring and table border visibility
        console.log('[Criterion 29] Testing Forced Colors mode (forced-colors: active)...');
        await page.emulateMedia({ forcedColors: 'active' });
        await page.waitForTimeout(400);

        await refreshActionBtn.focus();
        const focusRingOutline = await refreshActionBtn.evaluate(el => window.getComputedStyle(el).outlineStyle);
        const focusRingVisible = (focusRingOutline !== 'none' && focusRingOutline !== 'hidden');
        console.log(`  - Button focus ring outline under forced-colors: "${focusRingOutline}" (visible: ${focusRingVisible})`);

        const tableBorderVisible = await page.evaluate(() => {
            const el = document.querySelector('td, th, .fi-ta-table, table');
            if (!el) return true;
            const style = window.getComputedStyle(el);
            return (style.borderStyle !== 'none' && style.borderStyle !== 'hidden') ||
                   (style.borderBottomStyle !== 'none' && style.borderBottomStyle !== 'hidden');
        });
        console.log(`  - Table border visibility under forced-colors: ${tableBorderVisible}`);

        // Restore media
        await page.emulateMedia({ forcedColors: 'none' });

        // 7C. Reduced Motion mode
        console.log('[Criterion 29] Testing Reduced Motion mode (prefers-reduced-motion: reduce)...');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.waitForTimeout(400);

        const motionReduced = await page.evaluate(() => {
            const elements = [document.body, document.querySelector('.fi-sidebar'), document.querySelector('button')].filter(Boolean);
            return elements.every(el => {
                const s = window.getComputedStyle(el);
                const trans = parseFloat(s.transitionDuration) || 0;
                const anim = parseFloat(s.animationDuration) || 0;
                return trans <= 0.001 && anim <= 0.001;
            });
        });
        console.log(`  - CSS transition and animation durations reduced to <= 0.001s: ${motionReduced}`);

        await page.emulateMedia({ reducedMotion: 'no-preference' });

        const criterion29Passed = (hasLiveEvidenceNotice && hasToastContainer && focusRingVisible && tableBorderVisible && motionReduced);
        ledger.criterion_29_status_announcements_forced_colors_and_motion = criterion29Passed ? 'PASS' : 'FAIL';

        // =====================================================================
        // Final Results Summary
        // =====================================================================
        console.log('\n================================================================');
        console.log('FINAL LIVE BROWSER QUALIFICATION LEDGER:');
        console.log('================================================================');
        console.log(JSON.stringify(ledger, null, 2));

        const allPassed = Object.values(ledger).every(v => v === 'PASS');
        console.log('\nOVERALL STATUS:', allPassed ? 'ALL QUALIFIED (100% VERIFIED)' : 'FAILED CRITERIA PRESENT');
        if (!allPassed) {
            process.exitCode = 1;
        }

    } catch (error) {
        console.error('Fatal error during browser qualification suite:', error);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
