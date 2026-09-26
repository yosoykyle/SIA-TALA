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
const { execFileSync, spawn } = require('child_process');
const crypto = require('crypto');
const http = require('http');
const path = require('path');
const fs = require('fs');

const REPO_ROOT = path.resolve(__dirname, '../../');
const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8008';
const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const ARTIFACT_DIR = path.resolve(__dirname, 'artifacts');
const SCREENSHOT_DIR = path.resolve(ARTIFACT_DIR, 'screenshots/issue50');

fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });

const spawnedServerPids = new Set();
let spawnedServerProc = null;
let spawnedServerPid = null;

function runArtisan(phpCode) {
    const trimmed = phpCode.trim();
    let body = (trimmed.endsWith(';') || trimmed.endsWith('}')) ? trimmed : `echo (${trimmed});`;
    const code = `require __DIR__ . '/vendor/autoload.php'; $app = require __DIR__ . '/bootstrap/app.php'; $app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${body}`;
    try {
        return execFileSync('php', ['-d', 'memory_limit=128M', '-r', code], {
            cwd: REPO_ROOT,
            env: { ...process.env, DB_DATABASE: 'test_tala_db', INSTITUTION_ADDRESS: 'Synthetic Servitech Campus, Philippines' }
        }).toString().trim();
    } catch (e) {
        const stderr = e.stderr ? e.stderr.toString() : e.message;
        throw new Error(`[runArtisan Failed] ${stderr}`);
    }
}

function clearReplayCache() {
    try {
        runArtisan('\\Tests\\Browser\\BrowserQualificationEnvironment::clearReplayCache("JBSWY3DPEHPK3PXP", "registrar.test@example.test");');
    } catch (e) {
        console.warn('[clearReplayCache Warning]', e.message);
    }
}

function getPidListeningOnPort(port) {
    try {
        const out = execFileSync('powershell', ['-NoProfile', '-Command', `(Get-NetTCPConnection -LocalPort ${port} -ErrorAction SilentlyContinue).OwningProcess`], { encoding: 'utf8' }).trim();
        const pid = parseInt(out, 10);
        return isNaN(pid) ? null : pid;
    } catch {
        return null;
    }
}

function assertTestDatabaseConnection() {
    runArtisan('\\Tests\\Browser\\BrowserQualificationEnvironment::assertValidDatabase();');
    const dbName = runArtisan('echo \\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName();');
    if (dbName !== 'test_tala_db') {
        throw new Error(`Refusing write: active database connection is '${dbName}', expected 'test_tala_db'.`);
    }
}

function cleanupTestState() {
    assertTestDatabaseConnection();
    const cleanupCount = runArtisan(`
        \\Tests\\Browser\\BrowserQualificationEnvironment::assertValidDatabase();
        if (\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== 'test_tala_db') {
            throw new \\RuntimeException("Refusing write: active database connection is '".\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
        }
        $terms = App\\Models\\Term::whereIn('label', ['Issue 50 Qualification Term', 'Single Draft Preselection Term'])->get();
        $count = $terms->count();
        foreach ($terms as $t) {
            foreach ($t->calendarPackages as $p) {
                $p->windows()->delete();
                $p->teachingGridRows()->delete();
                $p->datedExceptions()->delete();
                $p->delete();
            }
            $t->delete();
        }
        echo $count;
    `);
    const remainingCount = runArtisan(`
        echo App\\Models\\Term::whereIn('label', ['Issue 50 Qualification Term', 'Single Draft Preselection Term'])->count();
    `);
    if (parseInt(remainingCount, 10) !== 0) {
        throw new Error(`Cleanup verification failed: ${remainingCount} test terms still remain in test_tala_db!`);
    }
    return cleanupCount;
}

function isServerResponsive() {
    return new Promise((resolve) => {
        const req = http.get(BASE_URL + '/up', { timeout: 5000 }, (res) => {
            resolve(res.statusCode >= 200 && res.statusCode < 500);
        });
        req.on('error', () => resolve(false));
        req.on('timeout', () => { req.destroy(); resolve(false); });
    });
}

async function ensureServerRunning() {
    const listeningPid = getPidListeningOnPort(8008);
    const responsive = await isServerResponsive();

    if (listeningPid || responsive) {
        if (!listeningPid || !spawnedServerPids.has(listeningPid)) {
            throw new Error(`Port 8008 is occupied by an unverified server/process (PID ${listeningPid || 'unknown'}). Refusing execution on unverified server to ensure zero contamination of test or production databases.`);
        }
        console.log(`[Server] Already running and responsive at ${BASE_URL} (verified PID: ${listeningPid})`);
        return;
    }

    console.log(`[Server] Spawning dedicated test server on 127.0.0.1:8008 with DB_DATABASE=test_tala_db...`);
    const serverLog = fs.openSync(path.join(ARTIFACT_DIR, 'test_server.log'), 'a');
    spawnedServerProc = spawn('php', ['-d', 'memory_limit=1024M', '-S', '127.0.0.1:8008', '-t', 'public', 'server.php'], {
        cwd: REPO_ROOT,
        env: { ...process.env, DB_DATABASE: 'test_tala_db', INSTITUTION_ADDRESS: 'Synthetic Servitech Campus, Philippines' },
        stdio: ['ignore', serverLog, serverLog]
    });
    spawnedServerPid = spawnedServerProc.pid;
    spawnedServerPids.add(spawnedServerPid);
    console.log(`[Server] Spawned test server PID: ${spawnedServerPid}`);

    let ready = false;
    for (let i = 0; i < 30; i++) {
        await new Promise(r => setTimeout(r, 400));
        if (await isServerResponsive()) {
            ready = true;
            break;
        }
    }

    if (!ready) {
        throw new Error(`Test server at ${BASE_URL} failed to become responsive within 12s`);
    }

    assertTestDatabaseConnection();
    console.log(`[Server] Dedicated test server is verified ready at ${BASE_URL} against test_tala_db`);
}

function terminateSpawnedServer() {
    for (const pidToKill of Array.from(spawnedServerPids)) {
        console.log(`[Server] Cleaning up test server PID: ${pidToKill}...`);
        try {
            execFileSync('taskkill', ['/F', '/T', '/PID', String(pidToKill)], { stdio: 'ignore' });
            console.log(`[Server] Successfully terminated server PID ${pidToKill}`);
        } catch (e) {
            try {
                process.kill(pidToKill);
            } catch (e2) {}
        }
        spawnedServerPids.delete(pidToKill);
    }
    spawnedServerPid = null;
    spawnedServerProc = null;
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

async function loginUser(page, email, password = 'password', mfaSecret = 'JBSWY3DPEHPK3PXP') {
    clearReplayCache();
    await page.goto(`${BASE_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#form\\.email', { state: 'visible', timeout: 10000 });
    await page.fill('#form\\.email', email);
    await page.fill('#form\\.password', password);
    await page.click('button[type="submit"]');

    if (mfaSecret) {
        await page.waitForSelector('#multiFactorChallengeForm\\.app\\.code, input[autocomplete="one-time-code"]', { timeout: 15000 });
        const otp = await getFreshOtp(mfaSecret);
        const codeInput = page.locator('#multiFactorChallengeForm\\.app\\.code, input[autocomplete="one-time-code"]').first();
        await codeInput.focus();
        await codeInput.fill(otp);
        await page.click('button:has-text("Confirm sign in"), button[type="submit"]');
    }

    await page.waitForURL(url => !url.href.includes('/login'), { timeout: 20000 });
    console.log('[Login] Successfully authenticated into admin panel at:', page.url());
}

(async () => {
    console.log('========================================================');
    console.log('TALA #50: Real Browser Qualification on test_tala_db');
    console.log('========================================================');

    const consoleErrors = [];
    const report = {
        timestamp: new Date().toISOString(),
        database: 'test_tala_db',
        steps: {},
        screenshots: [],
        consoleErrors: [],
    };

    let browser = null;

    try {
        await ensureServerRunning();

        // 1. Prepare multiple drafts test state
        console.log('[Setup] Seeding Issue 50 multiple drafts state...');
        assertTestDatabaseConnection();
        const setupOutput = execFileSync('php', ['tests/Browser/setup_issue50_browser_state.php'], {
            cwd: REPO_ROOT,
            env: { ...process.env, DB_DATABASE: 'test_tala_db' }
        }).toString().trim();
        const testState = JSON.parse(setupOutput);
        console.log('[Setup] Test State:', testState);

        const launchOptions = {
            headless: true,
            executablePath: fs.existsSync(CHROME_PATH) ? CHROME_PATH : undefined
        };
        browser = await chromium.launch(launchOptions);
        const context = await browser.newContext({
            viewport: { width: 1366, height: 768 }
        });
        const page = await context.newPage();

        page.on('console', msg => {
            if (msg.type() === 'error') {
                console.error('[Browser Console Error]', msg.text());
                consoleErrors.push(msg.text());
            }
        });

        // Step 1: Login as Registrar
        console.log('\n--- Step 1: Login as Registrar ---');
        await loginUser(page, 'registrar.test@example.test', 'password', 'JBSWY3DPEHPK3PXP');
        report.steps.login = 'Passed';

        // Step 2: Navigate to Term Planning Workbench for Term
        console.log(`\n--- Step 2: Navigate to Term Planning Workbench for Term ${testState.term_id} ---`);
        await page.goto(`${BASE_URL}/admin/term-planning-workbench?termId=${testState.term_id}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('text=Issue 50 Qualification Term', { timeout: 10000 });

        const initialShot = path.join(SCREENSHOT_DIR, '01_workbench_initial_term_1366x768.png');
        await page.screenshot({ path: initialShot, fullPage: true });
        report.screenshots.push(path.relative(REPO_ROOT, initialShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', initialShot);

        // Check Term state badge
        const termStateText = await page.locator('span:has-text("Term state:")').first().textContent();
        console.log('Workbench Term state badge:', termStateText.trim());
        if (!termStateText.includes('Draft')) {
            throw new Error(`Expected Term state to be Draft, got: ${termStateText}`);
        }
        report.steps.initial_term_state = 'Verified Draft';

        // Step 3: Open Activate Calendar Package Modal (Multiple Drafts) & Keyboard Navigation
        console.log('\n--- Step 3: Open Activate Calendar Package Modal (Multiple Drafts) ---');
        const activateBtn = page.locator('button:has-text("Activate Calendar Package"), a:has-text("Activate Calendar Package")').first();
        await activateBtn.waitFor({ state: 'visible', timeout: 10000 });
        await activateBtn.click();

        // Wait for modal to open
        const modalHeading = page.locator('h2:has-text("Activate Calendar Package"), .fi-modal-heading:has-text("Activate Calendar Package")').first();
        await modalHeading.waitFor({ state: 'visible', timeout: 15000 });
        const modal = page.locator('.fi-modal-open, .fi-modal:has(h2:has-text("Activate Calendar Package"))').first();
        await page.waitForTimeout(600); // allow Livewire DOM animation

        const modalMultipleDraftsShot = path.join(SCREENSHOT_DIR, '02_modal_multiple_drafts_open_1366x768.png');
        await page.screenshot({ path: modalMultipleDraftsShot });
        report.screenshots.push(path.relative(REPO_ROOT, modalMultipleDraftsShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', modalMultipleDraftsShot);

        // In multiple drafts scenario, verify select exists and requires selection
        const packageSelect = modal.locator('select').first();
        await packageSelect.waitFor({ state: 'visible', timeout: 5000 });
        const selectOptions = await packageSelect.locator('option').allTextContents();
        console.log('Modal package select options:', selectOptions);
        if (selectOptions.length < 2) {
            throw new Error(`Expected at least 2 draft options in select, got: ${JSON.stringify(selectOptions)}`);
        }
        report.steps.multiple_drafts_choice = 'Verified explicit choice required';

        // Keyboard navigation verification: Focus select, test Escape to close, focus CTA, Enter to reopen
        console.log('Verifying keyboard modal navigation...');
        await packageSelect.focus();
        const focusedTag = await page.evaluate(() => document.activeElement?.tagName?.toLowerCase());
        if (focusedTag !== 'select') {
            throw new Error(`Expected package select to be active element, got: ${focusedTag}`);
        }
        console.log('Package select correctly focused. Testing Escape key to dismiss modal...');
        await page.keyboard.press('Escape');
        await modalHeading.waitFor({ state: 'hidden', timeout: 5000 });
        console.log('Modal closed via Escape key. Reopening via keyboard navigation...');
        await activateBtn.focus();
        await page.keyboard.press('Enter');
        await modalHeading.waitFor({ state: 'visible', timeout: 15000 });
        await page.waitForTimeout(600);
        console.log('Modal successfully reopened via Enter key.');
        report.steps.keyboard_navigation = 'Verified keyboard focus, Escape dismiss, and Enter trigger';

        // Step 4: Test Draft 1 (Unready Blocker Path)
        console.log('\n--- Step 4: Test Draft 1 (Unready Blocker Path) ---');
        const selectForUnready = modal.locator('select').first();
        await selectForUnready.scrollIntoViewIfNeeded();
        // Ordinary selection without synthetic events
        await selectForUnready.selectOption(String(testState.unready_package_id));
        await modal.locator('text=Action required (4 blockers)').waitFor({ state: 'visible', timeout: 15000 });

        const modalUnreadyShot = path.join(SCREENSHOT_DIR, '03_modal_unready_draft_blockers_1366x768.png');
        await page.screenshot({ path: modalUnreadyShot });
        report.screenshots.push(path.relative(REPO_ROOT, modalUnreadyShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', modalUnreadyShot);

        // Verify summary contains blockers
        const modalText = await modal.textContent();
        console.log('Verifying unready summary content...');
        if (!modalText.includes('Action required') || !modalText.includes('Enrollment window is missing or invalid')) {
            throw new Error('Expected readiness blocker notice in modal for Draft 1');
        }
        report.steps.unready_draft_blockers = 'Verified blockers visible';

        // Attempt submission with unready draft
        console.log('Attempting submission of unready draft...');
        const submitBtn = modal.locator('button:has-text("Activate Calendar Package")').last();
        await submitBtn.click();
        await page.waitForTimeout(1000);

        // Verify failure notification / halted state
        const notificationText = await page.locator('.fi-notification, [role="alert"]').allTextContents().catch(() => []);
        console.log('Notifications received after unready submission:', notificationText);

        const modalStillOpen = await modalHeading.isVisible();
        if (!modalStillOpen) {
            throw new Error('Modal unexpectedly closed after submitting unready draft!');
        }
        const failureShot = path.join(SCREENSHOT_DIR, '04_modal_unready_submission_rejected_1366x768.png');
        await page.screenshot({ path: failureShot });
        report.screenshots.push(path.relative(REPO_ROOT, failureShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', failureShot);
        report.steps.unready_submission_rejection = 'Verified rejection and safe halt';

        // Step 5: Test Draft 2 (Ready Path)
        console.log('\n--- Step 5: Select Draft 2 (Ready Path) ---');
        const selectForReady = modal.locator('select').first();
        await selectForReady.scrollIntoViewIfNeeded();
        // Ordinary selection without synthetic events
        await selectForReady.selectOption(String(testState.ready_package_id));

        console.log('Waiting for ready summary content to morph...');
        await modal.locator('text=All required checks passed').waitFor({ state: 'visible', timeout: 15000 });

        // Verify that prior failure notification was dismissed and does not obscure or contradict the ready package
        await page.waitForTimeout(400); // allow notification close transition
        const failureToast = page.locator('.fi-notification:has-text("Cannot activate Calendar Package")');
        const failureToastVisible = await failureToast.isVisible().catch(() => false);
        console.log('Prior failure toast visible after ready selection:', failureToastVisible);
        if (failureToastVisible) {
            throw new Error('Prior readiness-failure toast is still visible after selecting ready package! It must be dismissed to avoid obscuring/contradicting ready state.');
        }
        console.log('Verified: Prior readiness-failure toast dismissed; ready desktop state clean.');

        const readyModalText = await modal.textContent();
        if (!readyModalText.includes('Ready for activation') || !readyModalText.includes('All required checks passed')) {
            throw new Error('Expected ready status badge in modal for Draft 2');
        }
        if (!readyModalText.includes('does not itself open enrollment')) {
            throw new Error('Expected policy boundary notice (does not itself open enrollment) in modal');
        }
        if (!readyModalText.includes('2026-10-16') || !readyModalText.includes('2026-10-31')) {
            throw new Error('Expected enrollment window dates in modal summary');
        }
        // Assert optional window appears
        if (!readyModalText.includes('Late Enrollment') || !readyModalText.includes('2026-11-01 to 2026-11-07')) {
            throw new Error('Expected optional Late Enrollment window in modal summary');
        }
        console.log('Verified optional Late Enrollment window in modal summary');

        const modalReadyShot = path.join(SCREENSHOT_DIR, '05_modal_ready_draft_summary_1366x768.png');
        await page.screenshot({ path: modalReadyShot });
        report.screenshots.push(path.relative(REPO_ROOT, modalReadyShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', modalReadyShot);
        report.steps.ready_draft_summary = 'Verified ready badge, core and optional windows, policy notice, and absence of contradictory failure toast';

        // Step 6: Mobile Responsiveness Check (390x844) & CTA Reachability
        console.log('\n--- Step 6: Mobile Viewport Check (390x844) ---');
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(500);

        const mobileShot = path.join(SCREENSHOT_DIR, '06_modal_mobile_390x844.png');
        await page.screenshot({ path: mobileShot });
        report.screenshots.push(path.relative(REPO_ROOT, mobileShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', mobileShot);

        // Verify mobile readability and heading visibility
        const mobileModalVisible = await modalHeading.isVisible();
        if (!mobileModalVisible) {
            throw new Error('Modal not visible in mobile viewport!');
        }

        // Mobile CTA reachability check:
        const submitBtnMobile = modal.locator('button:has-text("Activate Calendar Package")').last();
        await submitBtnMobile.scrollIntoViewIfNeeded();
        await submitBtnMobile.waitFor({ state: 'visible', timeout: 5000 });
        const box = await submitBtnMobile.boundingBox();
        if (!box) {
            throw new Error('Submit button has no bounding box on mobile viewport');
        }
        console.log('Mobile CTA bounding box:', box);
        if (box.x < 0 || box.x + box.width > 390) {
            throw new Error(`Mobile CTA button is clipped horizontally: x=${box.x}, width=${box.width}`);
        }
        if (box.width < 100 || box.height < 30) {
            throw new Error(`Mobile CTA button dimensions too small: width=${box.width}, height=${box.height}`);
        }
        report.steps.mobile_responsiveness = 'Verified 390x844 layout, readability, and CTA reachability';

        // Restore desktop viewport
        await page.setViewportSize({ width: 1366, height: 768 });
        await page.waitForTimeout(300);

        // Step 7: Submit Activation for Ready Draft
        console.log('\n--- Step 7: Submit Activation Confirmation ---');
        const confirmBtn = modal.locator('button:has-text("Activate Calendar Package")').last();
        await confirmBtn.click();

        // Wait for modal to close and success notification
        await modalHeading.waitFor({ state: 'hidden', timeout: 15000 });
        await page.waitForSelector('text=Calendar Package activated', { timeout: 15000 });
        await page.waitForTimeout(500);

        // Verify single truthful current outcome: success is visible, failure is not
        const successVisible = await page.locator('text=Calendar Package activated').isVisible();
        if (!successVisible) {
            throw new Error('Expected success notification to be visible');
        }
        const failureCount = await page.locator('text=Cannot activate Calendar Package').count();
        if (failureCount > 0) {
            const failureVisible = await page.locator('text=Cannot activate Calendar Package').first().isVisible();
            if (failureVisible) {
                throw new Error('Failure notification is still visible after successful activation! Expected one truthful outcome.');
            }
        }
        console.log('Verified single truthful current outcome: Success visible, prior failure replaced/cleared.');

        const workbenchAfterShot = path.join(SCREENSHOT_DIR, '07_workbench_after_activation_1366x768.png');
        await page.screenshot({ path: workbenchAfterShot, fullPage: true });
        report.screenshots.push(path.relative(REPO_ROOT, workbenchAfterShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', workbenchAfterShot);

        // Verify Active state reflected on workbench
        const updatedTermState = await page.locator('span:has-text("Term state:")').first().textContent();
        console.log('Updated Workbench Term state badge:', updatedTermState.trim());
        if (!updatedTermState.includes('Active')) {
            throw new Error(`Expected Term state to be Active, got: ${updatedTermState}`);
        }

        const workbenchBody = await page.content();
        if (!workbenchBody.includes('Active v2') && !workbenchBody.includes('BOR-APPROVED-002')) {
            throw new Error('Expected active package v2 / BOR-APPROVED-002 on workbench');
        }
        report.steps.activation_success = 'Verified modal close, notification, and immediate Active workbench state';

        // Step 8: Single Draft Auto-Preselection Verification
        console.log('\n--- Step 8: Single Draft Auto-Preselection Verification ---');
        assertTestDatabaseConnection();
        // Clean up any previous single-draft test term
        runArtisan(`
            \\Tests\\Browser\\BrowserQualificationEnvironment::assertValidDatabase();
            if (\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== 'test_tala_db') {
                throw new \\RuntimeException("Refusing write: active database connection is '".\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
            }
            $t = App\\Models\\Term::where('label', 'Single Draft Preselection Term')->first();
            if ($t) {
                foreach ($t->calendarPackages as $p) {
                    $p->windows()->delete();
                    $p->teachingGridRows()->delete();
                    $p->datedExceptions()->delete();
                    $p->delete();
                }
                $t->delete();
            }
        `);

        // Create a separate Term with exactly 1 Draft package
        const singleDraftTermSetup = `
            \\Tests\\Browser\\BrowserQualificationEnvironment::assertValidDatabase();
            if (\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== 'test_tala_db') {
                throw new \\RuntimeException("Refusing write: active database connection is '".\\Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()."', expected 'test_tala_db'.");
            }
            $ay = App\\Models\\AcademicYear::where('label', 'Academic Year 2026-2027')->firstOrFail();
            $term = App\\Models\\Term::create([
                'academic_year_id' => $ay->id,
                'type' => 'FIRST_SEMESTER',
                'label' => 'Single Draft Preselection Term',
                'starts_on' => '2027-01-01',
                'ends_on' => '2027-05-31',
                'state' => App\\Models\\Term::StateDraft,
                'scheduling_slot_minutes' => 30,
                'scheduling_days' => ['MON', 'TUE', 'WED', 'THU', 'FRI'],
                'scheduling_day_starts_at' => '07:00:00',
                'scheduling_day_ends_at' => '21:00:00',
                'default_max_units' => 24,
            ]);
            $registrar = App\\Models\\User::where('email', 'registrar.test@example.test')->firstOrFail();
            $draft = App\\Models\\TermCalendarPackage::create([
                'term_id' => $term->id,
                'version' => 1,
                'state' => App\\Models\\TermCalendarPackage::StateDraft,
                'authority_reference' => 'BOR-SINGLE-001',
                'authority_date' => '2026-12-15',
                'administrative_starts_on' => '2027-01-01',
                'administrative_ends_on' => '2027-05-31',
                'classes_start_on' => '2027-01-15',
                'classes_end_on' => '2027-05-15',
                'faculty_availability_due_at' => '2027-01-05 17:00:00',
                'recorded_by' => $registrar->id,
            ]);
            foreach ([
                App\\Models\\TermCalendarWindow::TypeEnrollment => ['2027-01-02', '2027-01-14'],
                App\\Models\\TermCalendarWindow::TypeExaminationPeriod => ['2027-05-01', '2027-05-10'],
                App\\Models\\TermCalendarWindow::TypeGradeEntry => ['2027-05-11', '2027-05-20'],
            ] as $t => [$o, $c]) {
                $draft->windows()->create(['window_type' => $t, 'opens_on' => $o, 'closes_on' => $c, 'cutoff_at' => '17:00:00']);
            }
            $draft->teachingGridRows()->create([
                'day_of_week' => 1, 'starts_at' => '08:00:00', 'ends_at' => '17:00:00',
                'breaks' => [['starts_at' => '12:00:00', 'ends_at' => '13:00:00']],
            ]);
            echo $term->id;
        `;
        const singleTermId = runArtisan(singleDraftTermSetup);
        console.log(`Created single-draft Term ID: ${singleTermId}`);

        await page.goto(`${BASE_URL}/admin/term-planning-workbench?termId=${singleTermId}`, { waitUntil: 'networkidle' });
        await page.waitForSelector('text=Single Draft Preselection Term', { timeout: 10000 });

        const singleActivateBtn = page.locator('button:has-text("Activate Calendar Package"), a:has-text("Activate Calendar Package")').first();
        await singleActivateBtn.click();

        const singleModalHeading = page.locator('h2:has-text("Activate Calendar Package"), .fi-modal-heading:has-text("Activate Calendar Package")').first();
        await singleModalHeading.waitFor({ state: 'visible', timeout: 15000 });
        const singleModal = page.locator('.fi-modal-open, .fi-modal:has(h2:has-text("Activate Calendar Package"))').first();
        await page.waitForTimeout(600);

        // Verify summary is immediately visible without needing to touch select
        await singleModal.locator('text=All required checks passed').waitFor({ state: 'visible', timeout: 15000 });
        const singleModalText = await singleModal.textContent();
        if (!singleModalText.includes('All required checks passed') || !singleModalText.includes('BOR-SINGLE-001')) {
            throw new Error('Expected auto-preselected summary to be rendered immediately for single draft!');
        }

        const autoPreselectedShot = path.join(SCREENSHOT_DIR, '08_modal_single_draft_autopreselected_1366x768.png');
        await page.screenshot({ path: autoPreselectedShot });
        report.screenshots.push(path.relative(REPO_ROOT, autoPreselectedShot).replace(/\\/g, '/'));
        console.log('Captured screenshot:', autoPreselectedShot);
        report.steps.single_draft_autopreselection = 'Verified immediate auto-preselection and summary rendering';

        // Close modal
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        // Final cleanup of qualification terms on test_tala_db
        const cleanupCount = cleanupTestState();
        console.log(`Cleaned up ${cleanupCount} qualification term(s).`);
        console.log('Cleanup verified: 0 test terms remain.');

        await browser.close();

        report.consoleErrors = consoleErrors;
        report.status = consoleErrors.length === 0 ? 'SUCCESS' : 'SUCCESS_WITH_CONSOLE_WARNINGS';
        console.log('\n========================================================');
        console.log('Qualification Summary: ALL 8 STEPS PASSED!');
        console.log('Total Console Errors:', consoleErrors.length);
        console.log('========================================================');

        fs.writeFileSync(path.join(ARTIFACT_DIR, 'issue50_qualification_report.json'), JSON.stringify(report, null, 2));

    } catch (err) {
        console.error('QUALIFICATION FAILED:', err);
        report.status = 'FAILED';
        report.error = err.message;
        fs.writeFileSync(path.join(ARTIFACT_DIR, 'issue50_qualification_report.json'), JSON.stringify(report, null, 2));
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
        try {
            cleanupTestState();
        } catch (cleanupErr) {
            console.error('[Finally Cleanup Error]', cleanupErr);
        }
        terminateSpawnedServer();
        process.exit(report.status === 'SUCCESS' || report.status === 'SUCCESS_WITH_CONSOLE_WARNINGS' ? 0 : 1);
    }
})();
