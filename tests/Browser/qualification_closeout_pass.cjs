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
        await page.waitForSelector('#multiFactorChallengeForm\\.app\\.code', { timeout: 8000 });
        clearReplayCache();
        const otp = await getFreshOtp(mfaSecret);
        const codeInput = page.locator('#multiFactorChallengeForm\\.app\\.code');
        await codeInput.fill(otp);
        await page.click('button:has-text("Confirm sign in")');
        await page.waitForURL(url => !url.href.includes('/login'), { timeout: 15000 });
        await page.waitForLoadState('domcontentloaded');
    } else {
        await page.waitForURL(url => !url.href.includes('/login'), { timeout: 15000 });
        await page.waitForLoadState('domcontentloaded');
    }

    return page.url();
}

const canonicalViewports = [
    { name: 'desktop', width: 1366, height: 768 },
    { name: 'tablet', width: 768, height: 1024 },
    { name: 'mobile-large', width: 390, height: 844 },
    { name: 'mobile-small', width: 360, height: 800 },
];

(async () => {
    const launchOptions = {
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--renderer-process-limit=1',
            '--js-flags=--max-old-space-size=128',
            '--no-zygote',
            '--disable-extensions',
            '--mute-audio'
        ]
    };
    if (process.env.CHROME_PATH || fs.existsSync(CHROME_PATH)) {
        launchOptions.executablePath = process.env.CHROME_PATH || CHROME_PATH;
    }
    const browser = await chromium.launch(launchOptions);
    const results = [];

    try {
        console.log('================================================================');
        console.log('TALA SLICE 7 CLOSEOUT PASS: CRITERIA 19 & 28 BOUNDED EVIDENCE');
        console.log('================================================================\n');

        // =====================================================================
        // SECTION 1: CRITERION 19 — SHARED SHELL ACROSS ALL 7 ROLES
        // =====================================================================
        // Note: Deterministic staff first destinations are authoritatively verified by:
        //   tests/Feature/Auth/Clinic1ContextualAccessJourneyTest.php
        // Learner login destinations are authoritatively verified by:
        //   tests/Feature/Auth/RoleAwareLoginLandingTest.php
        // The browser script verifies the shared shell behavior at each canonical role surface:
        // active navigation item (excluding headings), workspace brand, academic term context,
        // account security & sign out in user menu, desktop sidebar collapse, and mobile drawer.
        const rolesToVerify = [
            {
                role: 'system-super-admin',
                email: 'admin@example.test',
                mfa: 'JBSWY3DPEHPK3PXP',
                loginPath: '/admin/login',
                page: '/admin/system-health',
                expectedNavLabel: 'System Health',
                expectedWorkspace: 'TALA Staff Workspace',
                requiresTermContext: false,
            },
            {
                role: 'registrar',
                email: 'registrar.test@example.test',
                mfa: 'JBSWY3DPEHPK3PXP',
                loginPath: '/admin/login',
                page: '/admin/admission-applications',
                expectedNavLabel: 'Admissions',
                expectedWorkspace: 'TALA Staff Workspace',
                requiresTermContext: false,
            },
            {
                role: 'accounting',
                email: 'accounting.test@example.test',
                mfa: 'JBSWY3DPEHPK3PXP',
                loginPath: '/admin/login',
                page: '/admin/fee-plans',
                expectedNavLabel: 'Fee Plans',
                expectedWorkspace: 'TALA Staff Workspace',
                requiresTermContext: false,
            },
            {
                role: 'faculty',
                email: 'faculty.test@example.test',
                mfa: 'JBSWY3DPEHPK3PXP',
                loginPath: '/admin/login',
                page: '/admin/my-availability',
                expectedNavLabel: 'My Availability',
                expectedWorkspace: 'TALA Staff Workspace',
                requiresTermContext: true,
            },
            {
                role: 'academic-head',
                email: 'ahead.test@example.test',
                mfa: 'JBSWY3DPEHPK3PXP',
                loginPath: '/admin/login',
                page: '/admin/academic-approvals',
                expectedNavLabel: 'Academic Oversight',
                expectedWorkspace: 'TALA Staff Workspace',
                requiresTermContext: false,
            },
            {
                role: 'student',
                email: 'student.test@example.test',
                mfa: null,
                loginPath: '/student/login',
                page: '/student',
                expectedNavLabel: 'Home',
                expectedWorkspace: 'TALA Student Hub',
                requiresTermContext: true,
            },
            {
                role: 'applicant',
                email: 'applicant.test@example.test',
                mfa: null,
                loginPath: '/applicant/login',
                page: '/applicant',
                expectedNavLabel: 'Home',
                expectedWorkspace: 'TALA Applicant Workspace',
                requiresTermContext: false,
            },
        ];

        for (const r of rolesToVerify) {
            console.log(`[Criterion 19] Verifying shared shell features for role: ${r.role}...`);
            const ctx = await browser.newContext();
            const page = await ctx.newPage();
            await page.setViewportSize({ width: 1366, height: 768 });
            const postLoginUrl = await loginUser(page, r.email, 'password', r.mfa, r.loginPath);
            if (page.url() !== `${BASE_URL}${r.page}`) {
                await page.goto(`${BASE_URL}${r.page}`, { waitUntil: 'networkidle' });
            } else {
                await page.waitForLoadState('networkidle');
            }

            // 1. Current-page indication: STRICT check on active navigation item.
            // Explicitly excludes H1, H2, or page headings.
            const activeNavVerification = await page.evaluate((expectedLabel) => {
                // Find all active navigation items or items marked with aria-current="page"
                const activeNavElements = Array.from(document.querySelectorAll('.fi-sidebar-item.fi-active, .fi-sidebar-item-active, [aria-current="page"], nav a.fi-active'));

                // Exclude any headers or headings
                const validNavs = activeNavElements.filter(el => {
                    const tag = el.tagName.toLowerCase();
                    return tag !== 'h1' && tag !== 'h2' && tag !== 'h3' && !el.classList.contains('fi-header-heading');
                });

                if (validNavs.length === 0) {
                    return { passed: false, reason: 'No active navigation item found with .fi-active or [aria-current="page"]' };
                }

                const matchingNav = validNavs.find(el => {
                    const text = (el.innerText || el.textContent || '').trim();
                    return text.toLowerCase().includes(expectedLabel.toLowerCase());
                });

                if (!matchingNav) {
                    const foundTexts = validNavs.map(el => (el.innerText || '').trim().replace(/\n/g, ' ')).join(', ');
                    return { passed: false, reason: `Active nav item does not match '${expectedLabel}'. Found: [${foundTexts}]` };
                }

                return { passed: true, matched: (matchingNav.innerText || '').trim().replace(/\n/g, ' ') };
            }, r.expectedNavLabel);

            // 2. Workspace and Term context: STRICT check.
            // Generic TALA branding alone MUST NOT pass.
            const contextVerification = await page.evaluate((conf) => {
                const brandEl = document.querySelector('.tala-brand__name');
                const brandText = (brandEl?.innerText || brandEl?.textContent || '').trim();

                if (!brandText || brandText === 'TALA') {
                    return { passed: false, reason: `Generic TALA branding rejected. Expected exact workspace: '${conf.expectedWorkspace}', got: '${brandText}'` };
                }

                if (brandText !== conf.expectedWorkspace) {
                    return { passed: false, reason: `Workspace brand mismatch. Expected '${conf.expectedWorkspace}', got '${brandText}'` };
                }

                // If role requires Term context (e.g. Faculty exact-term availability, Student hub), assert it
                if (conf.requiresTermContext) {
                    const bodyText = document.body.innerText;
                    const hasAcademicPeriod = bodyText.includes('Academic Year') || bodyText.includes('Semester') || bodyText.includes('Term');
                    if (!hasAcademicPeriod) {
                        return { passed: false, reason: `Missing required Academic Term / Semester context for ${conf.role}` };
                    }
                }

                return { passed: true, workspace: brandText };
            }, { expectedWorkspace: r.expectedWorkspace, requiresTermContext: r.requiresTermContext, role: r.role });

            // 3. Account Security and Sign-out in user menu
            const userMenuBtn = await page.waitForSelector('.fi-user-menu-trigger, button[aria-label*="user" i], button[aria-label*="User" i]', { timeout: 5000 }).catch(() => null);
            let hasAccountSecurity = false;
            let hasSignOut = false;
            if (userMenuBtn) {
                await userMenuBtn.click();
                await page.waitForTimeout(400);

                hasAccountSecurity = await page.evaluate(() => {
                    const links = Array.from(document.querySelectorAll('a, button'));
                    return links.some(l => {
                        const href = l.getAttribute('href') || '';
                        const text = l.innerText || '';
                        return href.includes('account-security') || text.includes('Account Security');
                    });
                });

                hasSignOut = await page.evaluate(() => {
                    const elements = Array.from(document.querySelectorAll('form, button, a'));
                    return elements.some(e => {
                        const action = e.getAttribute('action') || '';
                        const text = e.innerText || '';
                        return action.includes('logout') || text.includes('Sign out') || text.includes('Log out');
                    });
                });

                await page.keyboard.press('Escape');
                await page.waitForTimeout(200);
            }

            // 4. Desktop Sidebar Collapse Toggle (1366x768): Click the REAL user-facing control.
            // Fails if the control or expected state transition is absent.
            let desktopCollapsePassed = false;
            let desktopCollapseDetails = '';
            const collapseControl = page.locator('button.fi-topbar-close-collapse-sidebar-btn, .fi-topbar-collapse-sidebar-btn-ctn button:has-text("Collapse"), .fi-topbar-collapse-sidebar-btn-ctn button[title="Collapse sidebar"]').first();
            const collapseVisible = await collapseControl.isVisible().catch(() => false);

            if (!collapseVisible) {
                desktopCollapsePassed = false;
                desktopCollapseDetails = 'Desktop collapse button is absent or not visible';
            } else {
                const initialWidth = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().width || 0);
                await collapseControl.click();
                await page.waitForTimeout(400);
                const collapsedWidth = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().width || 0);

                // Expand control must now be visible
                const expandControl = page.locator('button.fi-topbar-open-collapse-sidebar-btn, .fi-topbar-collapse-sidebar-btn-ctn button[title="Expand sidebar"]').first();
                const expandVisible = await expandControl.isVisible().catch(() => false);
                await expandControl.click();
                await page.waitForTimeout(400);
                const restoredWidth = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().width || 0);

                const isCollapsed = collapsedWidth < initialWidth && collapsedWidth <= 80;
                const isReopened = restoredWidth > collapsedWidth && restoredWidth >= 200;
                desktopCollapsePassed = isCollapsed && expandVisible && isReopened;
                desktopCollapseDetails = `initialWidth=${initialWidth}, collapsedWidth=${collapsedWidth}, expandVisible=${expandVisible}, restoredWidth=${restoredWidth}`;
            }

            // 5. Mobile Drawer Toggle (390x844): Click the REAL user-facing open sidebar button.
            // Fails if the control is absent; zero overflow is NOT a substitute.
            await page.setViewportSize({ width: 390, height: 844 });
            await page.waitForTimeout(300);

            let mobileDrawerPassed = false;
            let mobileDrawerDetails = '';
            const mobileOpenBtn = page.locator('button.fi-topbar-open-sidebar-btn, button[aria-label="Expand sidebar"], button[title="Expand sidebar"]').first();
            const mobileOpenVisible = await mobileOpenBtn.isVisible().catch(() => false);

            if (!mobileOpenVisible) {
                mobileDrawerPassed = false;
                mobileDrawerDetails = 'Required labelled mobile drawer open control is absent or not visible';
            } else {
                const initialX = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().x ?? 0);
                await mobileOpenBtn.click();
                await page.waitForTimeout(400);

                const openedX = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().x ?? -999);

                await page.keyboard.press('Escape');
                await page.waitForTimeout(400);

                const closedX = await page.evaluate(() => document.querySelector('aside.fi-sidebar')?.getBoundingClientRect().x ?? 0);

                const drawerOpened = openedX >= 0;
                const drawerClosed = closedX < 0;
                mobileDrawerPassed = drawerOpened && drawerClosed;
                mobileDrawerDetails = `initialX=${initialX}, openedX=${openedX}, closedX=${closedX}`;
            }

            const shellRowPassed = activeNavVerification.passed && contextVerification.passed && hasAccountSecurity && hasSignOut && desktopCollapsePassed && mobileDrawerPassed;

            results.push({
                test_group: 'criterion_19_shared_shell',
                role: r.role,
                page: r.page,
                state: 'ordinary',
                viewport: '1366x768 / 390x844',
                result: shellRowPassed ? 'PASS' : 'FAIL',
                details: `activeNav=${activeNavVerification.passed} (${activeNavVerification.matched || activeNavVerification.reason}), context=${contextVerification.passed} (${contextVerification.workspace || contextVerification.reason}), accountSecurity=${hasAccountSecurity}, signOut=${hasSignOut}, desktopCollapse=${desktopCollapsePassed} (${desktopCollapseDetails}), mobileDrawer=${mobileDrawerPassed} (${mobileDrawerDetails}), postLoginUrl=${postLoginUrl}`
            });

            await ctx.close();
        }

        // =====================================================================
        // SECTION 2: CRITERION 28 — COMPLETE RESPONSIVE VIEWPORT AND 200% ZOOM
        // =====================================================================
        const staffRolesForViewports = [
            { role: 'registrar', email: 'registrar.test@example.test', page: '/admin/admission-applications' },
            { role: 'accounting', email: 'accounting.test@example.test', page: '/admin/fee-plans' },
            { role: 'faculty', email: 'faculty.test@example.test', page: '/admin/my-availability' },
            { role: 'academic-head', email: 'ahead.test@example.test', page: '/admin/academic-approvals' },
            { role: 'system-super-admin', email: 'admin@example.test', page: '/admin/system-health' },
        ];

        for (const staff of staffRolesForViewports) {
            console.log(`[Criterion 28] Verifying viewports and 200% zoom for ${staff.role}...`);
            const ctx = await browser.newContext();
            const p = await ctx.newPage();
            await loginUser(p, staff.email, 'password', 'JBSWY3DPEHPK3PXP', '/admin/login');

            for (const vp of canonicalViewports) {
                await p.setViewportSize({ width: vp.width, height: vp.height });
                await p.goto(`${BASE_URL}${staff.page}`, { waitUntil: 'networkidle' });
                const scrollWidth = await p.evaluate(() => document.documentElement.scrollWidth);
                const clientWidth = await p.evaluate(() => document.documentElement.clientWidth);
                const overflow = scrollWidth > (clientWidth + 1);

                results.push({
                    test_group: 'criterion_28_responsive_viewports',
                    role: staff.role,
                    page: staff.page,
                    state: 'ordinary',
                    viewport: `${vp.width}x${vp.height} (${vp.name})`,
                    result: !overflow ? 'PASS' : 'FAIL',
                    details: `clientWidth=${clientWidth}, scrollWidth=${scrollWidth}, overflow=${overflow}`
                });
            }

            // 200% Zoom check (1366x768)
            await p.setViewportSize({ width: 1366, height: 768 });
            await p.evaluate(() => { document.body.style.zoom = '200%'; });
            await p.waitForTimeout(300);
            const scrollWidthZoom = await p.evaluate(() => document.documentElement.scrollWidth);
            const clientWidthZoom = await p.evaluate(() => document.documentElement.clientWidth);
            const zoomPassed = scrollWidthZoom <= clientWidthZoom + 1;

            results.push({
                test_group: 'criterion_28_responsive_viewports',
                role: staff.role,
                page: staff.page,
                state: 'ordinary',
                viewport: '200% zoom (1366x768)',
                result: zoomPassed ? 'PASS' : 'FAIL',
                details: `clientWidth=${clientWidthZoom}, scrollWidth=${scrollWidthZoom}, overflow=${!zoomPassed}`
            });

            await ctx.close();
        }

        // =====================================================================
        // SECTION 3: CRITERION 28 — IN-FLIGHT LOADING STATE IN BROWSER
        // =====================================================================
        // Must cover: 1366x768, 768x1024, 390x844, 360x800, and 200% zoom
        console.log('[Criterion 28] Testing actual in-flight loading state across viewports & 200% zoom on /admin/system-health...');
        const loadingConditions = [
            { width: 1366, height: 768, name: 'desktop', zoom: false },
            { width: 768, height: 1024, name: 'tablet', zoom: false },
            { width: 390, height: 844, name: 'mobile-large', zoom: false },
            { width: 360, height: 800, name: 'mobile-small', zoom: false },
            { width: 1366, height: 768, name: '200% zoom (1366x768)', zoom: true },
        ];

        for (const cond of loadingConditions) {
            const adminLoadingCtx = await browser.newContext();
            const adminLoadingPage = await adminLoadingCtx.newPage();
            await adminLoadingPage.setViewportSize({ width: cond.width, height: cond.height });
            await loginUser(adminLoadingPage, 'admin@example.test', 'password', 'JBSWY3DPEHPK3PXP', '/admin/login');
            await adminLoadingPage.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });

            if (cond.zoom) {
                await adminLoadingPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await adminLoadingPage.waitForTimeout(200);
            }

            let resolveRequest;
            let intercepted = false;
            const requestHoldPromise = new Promise(resolve => { resolveRequest = resolve; });

            await adminLoadingPage.route('**/livewire*/update', async route => {
                intercepted = true;
                await requestHoldPromise;
                await route.continue();
            });

            const refreshBtn = await adminLoadingPage.waitForSelector('button:has-text("Refresh local evidence")');
            await refreshBtn.click();

            // Wait until intercepted
            const start = Date.now();
            while (!intercepted && (Date.now() - start < 5000)) {
                await adminLoadingPage.waitForTimeout(50);
            }

            // Await a tick for Livewire wire:loading delay to trigger visual updates
            await adminLoadingPage.waitForTimeout(300);

            // Assert while pending: request was intercepted, action has visible loading indicator OR disabled button
            const inFlightCheck = await adminLoadingPage.evaluate(() => {
                const btn = Array.from(document.querySelectorAll('button')).find(b => (b.innerText || '').includes('Refresh local evidence'));
                if (!btn) return { found: false };

                const isDisabled = btn.disabled || btn.hasAttribute('disabled') || btn.getAttribute('aria-disabled') === 'true' || btn.classList.contains('fi-btn-disabled');

                const spinner = btn.querySelector('.fi-loading-indicator, .animate-spin, svg[wire\\:loading]');
                let isSpinnerVisible = false;
                let spinnerRect = null;
                if (spinner) {
                    const cs = window.getComputedStyle(spinner);
                    const rect = spinner.getBoundingClientRect();
                    spinnerRect = { width: rect.width, height: rect.height };
                    isSpinnerVisible = cs.display !== 'none' && cs.visibility !== 'hidden' && (rect.width > 0 || rect.height > 0);
                }

                const scrollWidth = document.documentElement.scrollWidth;
                const clientWidth = document.documentElement.clientWidth;
                const overflow = scrollWidth > (clientWidth + 1);

                return {
                    found: true,
                    isDisabled,
                    isSpinnerVisible,
                    spinnerRect,
                    scrollWidth,
                    clientWidth,
                    overflow
                };
            });

            // Release the held request and await completion
            if (resolveRequest) {
                resolveRequest();
            }
            await adminLoadingPage.waitForLoadState('networkidle').catch(() => {});

            // Poll until button re-enables or timeout
            const startReEnable = Date.now();
            let postFlightCheck = { found: false, isReEnabled: false, overflow: false };
            while (Date.now() - startReEnable < 8000) {
                postFlightCheck = await adminLoadingPage.evaluate(() => {
                    const btn = Array.from(document.querySelectorAll('button')).find(b => (b.innerText || '').includes('Refresh local evidence'));
                    if (!btn) return { found: false, isReEnabled: false, overflow: false };

                    const isDisabled = btn.disabled || btn.hasAttribute('disabled') || btn.getAttribute('aria-disabled') === 'true' || btn.classList.contains('fi-btn-disabled');
                    const scrollWidth = document.documentElement.scrollWidth;
                    const clientWidth = document.documentElement.clientWidth;
                    const overflow = scrollWidth > (clientWidth + 1);

                    return {
                        found: true,
                        isReEnabled: !isDisabled,
                        scrollWidth,
                        clientWidth,
                        overflow
                    };
                });

                if (postFlightCheck.isReEnabled) break;
                await adminLoadingPage.waitForTimeout(250);
            }

            await adminLoadingPage.unroute('**/livewire*/update');
            await adminLoadingCtx.close();

            const inFlightPassed = intercepted && inFlightCheck.found && (inFlightCheck.isDisabled || inFlightCheck.isSpinnerVisible) && !inFlightCheck.overflow && postFlightCheck.isReEnabled && !postFlightCheck.overflow;

            results.push({
                test_group: 'criterion_28_responsive_states',
                role: 'system-super-admin',
                page: '/admin/system-health',
                state: 'loading',
                viewport: cond.zoom ? cond.name : `${cond.width}x${cond.height} (${cond.name})`,
                result: inFlightPassed ? 'PASS' : 'FAIL',
                details: `intercepted=${intercepted}, loadingObserved=${inFlightCheck.isDisabled || inFlightCheck.isSpinnerVisible} (isDisabled=${inFlightCheck.isDisabled}, isSpinnerVisible=${inFlightCheck.isSpinnerVisible}), isReEnabled=${postFlightCheck.isReEnabled}, clientWidth=${inFlightCheck.clientWidth}, scrollWidth=${inFlightCheck.scrollWidth}, inFlightOverflow=${inFlightCheck.overflow}, postFlightOverflow=${postFlightCheck.overflow}`
            });
        }

        // =====================================================================
        // SECTION 4: CRITERION 28 — ACTUAL STALE CAPTURE STATE (SAFE TEST FAILURE)
        // =====================================================================
        // Triggered via controlled test-only application failure mechanism:
        // Uses BrowserQualificationEnvironment::enableHealthCaptureFailure() with short TTL
        // and zero database schema mutations. Leaves test_tala_db schema untouched even if killed.
        console.log('[Criterion 28] Testing actual stale capture state via safe test failure mechanism...');
        const adminStaleCtx = await browser.newContext();
        const adminStalePage = await adminStaleCtx.newPage();
        await adminStalePage.setViewportSize({ width: 1366, height: 768 });
        await loginUser(adminStalePage, 'admin@example.test', 'password', 'JBSWY3DPEHPK3PXP', '/admin/login');
        await adminStalePage.goto(`${BASE_URL}/admin/system-health`, { waitUntil: 'networkidle' });

        try {
            // Enable safe test-only application failure
            runArtisan('\\Tests\\Browser\\BrowserQualificationEnvironment::enableHealthCaptureFailure();');

            const staleRefreshBtn = await adminStalePage.waitForSelector('button:has-text("Refresh local evidence")');
            await staleRefreshBtn.click();
            await adminStalePage.waitForTimeout(1500);
        } finally {
            // Restore safe failure flag immediately
            runArtisan('\\Tests\\Browser\\BrowserQualificationEnvironment::disableHealthCaptureFailure();');
        }

        // Verify the actual application rendered the stale capture notice without DOM injection
        for (const vp of canonicalViewports) {
            await adminStalePage.setViewportSize(vp);
            await adminStalePage.waitForTimeout(200);

            const staleCheck = await adminStalePage.evaluate(() => {
                const text = document.body.innerText;
                const noticeBanner = document.querySelector('[role="status"][aria-live="polite"]');
                const hasStaleHeader = text.includes('Stale capture.');
                const hasStaleMessage = text.includes('Refresh failed. The preceding capture was retained and is now marked stale.');
                const hasWarningClass = noticeBanner ? noticeBanner.className.includes('warning') : false;
                const scrollWidth = document.documentElement.scrollWidth;
                const clientWidth = document.documentElement.clientWidth;
                const overflow = scrollWidth > (clientWidth + 1);

                return {
                    hasStaleHeader,
                    hasStaleMessage,
                    hasWarningClass,
                    scrollWidth,
                    clientWidth,
                    overflow
                };
            });

            const stalePassed = staleCheck.hasStaleHeader && staleCheck.hasStaleMessage && staleCheck.hasWarningClass && !staleCheck.overflow;
            results.push({
                test_group: 'criterion_28_responsive_states',
                role: 'system-super-admin',
                page: '/admin/system-health',
                state: 'stale',
                viewport: `${vp.width}x${vp.height} (${vp.name})`,
                result: stalePassed ? 'PASS' : 'FAIL',
                details: `hasStaleHeader=${staleCheck.hasStaleHeader}, hasStaleMessage=${staleCheck.hasStaleMessage}, hasWarningClass=${staleCheck.hasWarningClass}, clientWidth=${staleCheck.clientWidth}, scrollWidth=${staleCheck.scrollWidth}, overflow=${staleCheck.overflow}`
            });
        }

        // Stale 200% zoom check
        await adminStalePage.setViewportSize({ width: 1366, height: 768 });
        await adminStalePage.evaluate(() => { document.body.style.zoom = '200%'; });
        await adminStalePage.waitForTimeout(300);
        const staleScrollWidthZoom = await adminStalePage.evaluate(() => document.documentElement.scrollWidth);
        const staleClientWidthZoom = await adminStalePage.evaluate(() => document.documentElement.clientWidth);
        const staleZoomPassed = staleScrollWidthZoom <= staleClientWidthZoom + 1;
        results.push({
            test_group: 'criterion_28_responsive_states',
            role: 'system-super-admin',
            page: '/admin/system-health',
            state: 'stale',
            viewport: '200% zoom (1366x768)',
            result: staleZoomPassed ? 'PASS' : 'FAIL',
            details: `clientWidth=${staleClientWidthZoom}, scrollWidth=${staleScrollWidthZoom}, overflow=${!staleZoomPassed}`
        });

        await adminStaleCtx.close();

        // =====================================================================
        // SECTION 5: CRITERION 28 — ACTUAL CONCURRENT STATE EVIDENCE IN BROWSER
        // =====================================================================
        // Must cover: 1366x768, 768x1024, 390x844, 360x800, and 200% zoom
        console.log('[Criterion 28] Testing actual concurrent state (session eviction) across viewports & 200% zoom in browser...');
        const concurrentConditions = [
            { width: 1366, height: 768, name: 'desktop', zoom: false },
            { width: 768, height: 1024, name: 'tablet', zoom: false },
            { width: 390, height: 844, name: 'mobile-large', zoom: false },
            { width: 360, height: 800, name: 'mobile-small', zoom: false },
            { width: 1366, height: 768, name: '200% zoom (1366x768)', zoom: true },
        ];

        for (const cond of concurrentConditions) {
            const concurrentCtx = await browser.newContext();
            const concurrentPage = await concurrentCtx.newPage();
            await concurrentPage.setViewportSize({ width: cond.width, height: cond.height });
            await loginUser(concurrentPage, 'student.test@example.test', 'password', null, '/student/login');

            if (cond.zoom) {
                await concurrentPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await concurrentPage.waitForTimeout(200);
            }

            // Confirm authenticated landing
            const initialLandingUrl = concurrentPage.url();
            const initialAuthenticated = initialLandingUrl.includes('/student') && !initialLandingUrl.includes('/login');

            // Trigger concurrent session eviction
            runArtisan("app(\\App\\Actions\\Authentication\\UserSessionService::class)->revokeAll(App\\Models\\User::where('email', 'student.test@example.test')->first());");

            // Attempt navigation on evicted session
            await concurrentPage.goto(`${BASE_URL}/student/academics`, { waitUntil: 'domcontentloaded' });
            if (cond.zoom) {
                await concurrentPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await concurrentPage.waitForTimeout(200);
            }
            const postEvictionUrl = concurrentPage.url();
            const wasEvictedToLogin = postEvictionUrl.includes('/student/login');

            const scrollWidth = await concurrentPage.evaluate(() => document.documentElement.scrollWidth);
            const clientWidth = await concurrentPage.evaluate(() => document.documentElement.clientWidth);
            const overflow = scrollWidth > (clientWidth + 1);

            const concurrentPassed = initialAuthenticated && wasEvictedToLogin && !overflow;
            results.push({
                test_group: 'criterion_28_responsive_states',
                role: 'student',
                page: '/student -> /student/academics',
                state: 'concurrent',
                viewport: cond.zoom ? cond.name : `${cond.width}x${cond.height} (${cond.name})`,
                result: concurrentPassed ? 'PASS' : 'FAIL',
                details: `initialAuthenticated=${initialAuthenticated}, wasEvictedToLogin=${wasEvictedToLogin}, postEvictionUrl=${postEvictionUrl}, clientWidth=${clientWidth}, scrollWidth=${scrollWidth}, overflow=${overflow}`
            });

            await concurrentCtx.close();
        }

        // =====================================================================
        // SECTION 6: CRITERION 28 — INACCESSIBLE AND FAILURE STATES IN BROWSER
        // =====================================================================
        console.log('[Criterion 28] Testing inaccessible (403) and failure (404) states across viewports & 200% zoom...');

        // 6A. 403 Inaccessible: Student attempts to access staff-only academic-readiness
        const forbiddenConditions = [
            { width: 1366, height: 768, name: 'desktop', zoom: false },
            { width: 768, height: 1024, name: 'tablet', zoom: false },
            { width: 390, height: 844, name: 'mobile-large', zoom: false },
            { width: 360, height: 800, name: 'mobile-small', zoom: false },
            { width: 1366, height: 768, name: '200% zoom (1366x768)', zoom: true },
        ];

        for (const cond of forbiddenConditions) {
            const stuForbiddenCtx = await browser.newContext();
            const stuForbiddenPage = await stuForbiddenCtx.newPage();
            await stuForbiddenPage.setViewportSize({ width: cond.width, height: cond.height });
            await loginUser(stuForbiddenPage, 'student.test@example.test', 'password', null, '/student/login');

            if (cond.zoom) {
                await stuForbiddenPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await stuForbiddenPage.waitForTimeout(200);
            }

            const forbiddenResp = await stuForbiddenPage.goto(`${BASE_URL}/admin/academic-readiness`, { waitUntil: 'domcontentloaded' });
            if (cond.zoom) {
                await stuForbiddenPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await stuForbiddenPage.waitForTimeout(200);
            }
            const statusCode = forbiddenResp.status();
            const scrollWidth = await stuForbiddenPage.evaluate(() => document.documentElement.scrollWidth);
            const clientWidth = await stuForbiddenPage.evaluate(() => document.documentElement.clientWidth);
            const overflow = scrollWidth > (clientWidth + 1);
            const bodyText = await stuForbiddenPage.evaluate(() => document.body.innerText);
            const hasForbiddenText = bodyText.includes('403') || bodyText.includes('Access not allowed') || bodyText.includes('Forbidden') || bodyText.includes('permission');

            const passed = (statusCode === 403 && hasForbiddenText && !overflow);
            results.push({
                test_group: 'criterion_28_responsive_states',
                role: 'student',
                page: '/admin/academic-readiness',
                state: 'inaccessible',
                viewport: cond.zoom ? cond.name : `${cond.width}x${cond.height} (${cond.name})`,
                result: passed ? 'PASS' : 'FAIL',
                details: `status=${statusCode}, hasForbiddenText=${hasForbiddenText}, clientWidth=${clientWidth}, scrollWidth=${scrollWidth}, overflow=${overflow}`
            });
            await stuForbiddenCtx.close();
        }

        // 6B. 404 Failure: Accessing non-existent route as admin
        const notFoundConditions = [
            { width: 1366, height: 768, name: 'desktop', zoom: false },
            { width: 768, height: 1024, name: 'tablet', zoom: false },
            { width: 390, height: 844, name: 'mobile-large', zoom: false },
            { width: 360, height: 800, name: 'mobile-small', zoom: false },
            { width: 1366, height: 768, name: '200% zoom (1366x768)', zoom: true },
        ];

        for (const cond of notFoundConditions) {
            const notFoundCtx = await browser.newContext();
            const notFoundPage = await notFoundCtx.newPage();
            await notFoundPage.setViewportSize({ width: cond.width, height: cond.height });
            await loginUser(notFoundPage, 'admin@example.test', 'password', 'JBSWY3DPEHPK3PXP', '/admin/login');

            if (cond.zoom) {
                await notFoundPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await notFoundPage.waitForTimeout(200);
            }

            const notFoundResp = await notFoundPage.goto(`${BASE_URL}/admin/non-existent-route`, { waitUntil: 'domcontentloaded' });
            if (cond.zoom) {
                await notFoundPage.evaluate(() => { document.body.style.zoom = '200%'; });
                await notFoundPage.waitForTimeout(200);
            }
            const statusCode = notFoundResp.status();
            const scrollWidth = await notFoundPage.evaluate(() => document.documentElement.scrollWidth);
            const clientWidth = await notFoundPage.evaluate(() => document.documentElement.clientWidth);
            const overflow = scrollWidth > (clientWidth + 1);
            const bodyText = await notFoundPage.evaluate(() => document.body.innerText);
            const hasNotFoundText = bodyText.includes('404') || bodyText.includes('Not Found');

            const passed = (statusCode === 404 && hasNotFoundText && !overflow);
            results.push({
                test_group: 'criterion_28_responsive_states',
                role: 'system-super-admin',
                page: '/admin/non-existent-route',
                state: 'failure',
                viewport: cond.zoom ? cond.name : `${cond.width}x${cond.height} (${cond.name})`,
                result: passed ? 'PASS' : 'FAIL',
                details: `status=${statusCode}, hasNotFoundText=${hasNotFoundText}, clientWidth=${clientWidth}, scrollWidth=${scrollWidth}, overflow=${overflow}`
            });
            await notFoundCtx.close();
        }

        // =====================================================================
        // SECTION 7: DOCUMENTATION OF INAPPLICABLE COMBINATIONS
        // =====================================================================
        // Note: Written justifications are printed for auditing and documentation,
        // but are NOT counted as passed browser tests in the evidence results array.
        const inapplicableCombinations = [
            {
                state: 'output-print',
                combination: '200% zoom on print CSS media (@media print)',
                justification: 'Inapplicable because official output print documents are rendered in fixed physical A4 page media (@page { size: A4 portrait/landscape }), where browser screen zoom does not scale physical paper print margins or sheet boundaries.'
            },
            {
                state: 'shared-shell-drawer',
                combination: 'Mobile drawer open on 1366x768 desktop viewport',
                justification: 'Inapplicable because desktop viewports (>= 1024px) utilize a permanently docked, collapsible sidebar governed by responsive CSS; the mobile slide-over drawer is intentionally hidden above 1024px.'
            },
            {
                state: 'loading-state',
                combination: 'In-flight Livewire loading state on static 403/404 HTTP error pages',
                justification: 'Inapplicable because HTTP 403 Forbidden and 404 Not Found error responses are synchronous full-page web responses produced by Laravel exception handling rather than reactive Livewire component instances.'
            }
        ];

        console.log('\n--- Inapplicable Exceptional-State Viewport/Zoom Justifications (Documentation only; not counted as test rows) ---');
        for (const item of inapplicableCombinations) {
            console.log(`[Justified Inapplicable] State: ${item.state} | Combination: ${item.combination}\n  Rationale: ${item.justification}\n`);
        }

        // =====================================================================
        // FINAL RESULTS REPORTING & STRICT NONZERO EXIT ENFORCEMENT
        // =====================================================================
        console.log('\n================================================================');
        console.log('TARGETED CLOSEOUT PASS BROWSER RESULTS:');
        console.log('================================================================');
        console.log(JSON.stringify(results, null, 2));

        const failedRows = results.filter(r => r.result === 'FAIL');
        if (failedRows.length > 0) {
            console.error(`\nFAILED CHECKS COUNT: ${failedRows.length}`);
            for (const f of failedRows) {
                console.error(`  FAIL: [${f.test_group}] role=${f.role} state=${f.state} viewport=${f.viewport} details=${f.details}`);
            }
            process.exitCode = 1;
        } else {
            console.log(`\nALL TARGETED CLOSEOUT CHECKS PASSED (${results.length}/${results.length} checks, strict exit code 0)`);
            process.exitCode = 0;
        }

    } catch (err) {
        console.error('Fatal error in closeout pass verification:', err);
        process.exitCode = 1;
    } finally {
        await browser.close();
    }
})();
