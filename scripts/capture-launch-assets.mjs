#!/usr/bin/env node
/**
 * Launch-video asset capture — shoots every storyboard frame from a LOCAL
 * BookTheStyle against the LaunchSalonSeeder fixture, at 3x, with animations
 * off and the app clock frozen to the seeder's anchor.
 *
 *   npm run capture:launch            → docs/launch-video/assets/
 *   npm run capture:launch -- --out=/abs/path
 *
 * Full pipeline + shot list: docs/launch-video/README.md.
 *
 * HARD RULE — LOCAL ONLY. Production holds real client PII; it must never
 * appear in a marketing asset. Any non-local --base is refused (exit 1).
 *
 * The script never writes SQL: all fixture mutations go through
 * `php artisan launch:capture …` (itself local-only). Screenshots are
 * written to --out and are NEVER committed (docs/launch-video/assets/ is
 * gitignored); the manifest — the committed record — goes to
 * docs/launch-video/manifest.json.
 */

import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// ---------------------------------------------------------------------------
// Arguments + the local-only guard
// ---------------------------------------------------------------------------

const args = Object.fromEntries(
    process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => {
        const [k, ...v] = a.replace(/^--/, '').split('=');
        return [k, v.join('=') || true];
    }),
);

const base = String(args.base ?? 'http://lvh.me:8000').replace(/\/$/, '');

const LOCAL_HOSTS = /^(.+\.)?(lvh\.me|localtest\.me|localhost|127\.0\.0\.1)$/;
const baseHost = new URL(base).hostname;
if (!LOCAL_HOSTS.test(baseHost)) {
    console.error(`REFUSED: --base=${base} is not a local host.`);
    console.error('Capture runs against LOCAL ONLY — production contains real client PII');
    console.error('and must never end up in a marketing asset.');
    process.exit(1);
}

const outDir = path.resolve(String(args.out ?? path.join(repoRoot, 'docs/launch-video/assets')));
fs.mkdirSync(outDir, { recursive: true });

// ---------------------------------------------------------------------------
// Fixture prep (all DB writes stay in reviewed PHP — local-only artisan)
// ---------------------------------------------------------------------------

function artisan(...a) {
    const out = execFileSync('php', ['artisan', ...a], { cwd: repoRoot, encoding: 'utf8' });
    const json = out.slice(out.indexOf('{'));
    try { return JSON.parse(json); } catch { return {}; }
}

const info = artisan('launch:capture', 'prepare');
if (!info.slug) {
    console.error('launch:capture prepare did not return fixture info — is this a BookTheStyle checkout?');
    process.exit(1);
}

const { port, hostname } = new URL(base);
const portSuffix = port ? `:${port}` : '';
const appBase = `${new URL(base).protocol}//app.${hostname}${portSuffix}`;
const salonBase = `${new URL(base).protocol}//${info.slug}.${hostname}${portSuffix}`;
const widgetUrl = `${salonBase}/widget/${info.widget_public_id}`;

// ---------------------------------------------------------------------------
// Local server: reuse a running one (must be time-frozen) or boot our own
// ---------------------------------------------------------------------------

if (fs.existsSync(path.join(repoRoot, 'public/hot'))) {
    console.error('public/hot exists — a Vite dev server owns the assets. Stop `npm run dev`');
    console.error('(and delete public/hot) so captures use the committed production build.');
    process.exit(1);
}

async function up() {
    try { return (await fetch(`${base}/up`, { signal: AbortSignal.timeout(1500) })).ok; } catch { return false; }
}

let serverProc = null;
if (await up()) {
    console.log(`Using the already-running server at ${base} (its APP_FAKE_NOW is verified below).`);
} else {
    console.log(`Booting php artisan serve with APP_FAKE_NOW=${info.fake_now} …`);
    serverProc = spawn('php', ['artisan', 'serve', '--host=0.0.0.0', `--port=${port || 8000}`], {
        cwd: repoRoot,
        // BOOKING_WIDGET_MIN_SECONDS=0: the widget's bot gate requires the
        // page token to AGE before submitting — but under the frozen clock
        // age is pinned at zero, so the human-speed floor must be lifted for
        // the confirmation shot to exist. BOOKING_WIDGET_RATE_LIMIT: the
        // funnel + accent beat fire far more widget API calls per minute
        // than a human would. Local capture server only.
        env: {
            ...process.env,
            APP_FAKE_NOW: info.fake_now,
            BOOKING_WIDGET_MIN_SECONDS: '0',
            BOOKING_WIDGET_RATE_LIMIT: '1000',
        },
        stdio: 'ignore',
        detached: false,
    });
    const deadline = Date.now() + 20000;
    while (!(await up())) {
        if (Date.now() > deadline) { console.error('Server did not come up on ' + base); process.exit(1); }
        await new Promise((r) => setTimeout(r, 300));
    }
    // Never orphan the capture server — a crashed run would otherwise leave
    // a WRONGLY-CONFIGURED server holding the port for the next run.
    process.on('exit', () => serverProc?.kill());
}

// ---------------------------------------------------------------------------
// Motion mode (--motion): record the mobile widget booking flow as VIDEO for
// the film's "her side" beat — the ONE place real screen motion beats stills.
// Same fixture, same frozen clock, same local-only guard; reduced-motion is
// deliberately OFF here so the app's real transitions are on tape, and the
// pacing between steps is slow and human-plausible. Output lands next to the
// stills (gitignored) and registers in the manifest under its own key.
// ---------------------------------------------------------------------------

if (args.motion) {
    const motionBrowser = await chromium.launch();
    const motionContext = await motionBrowser.newContext({
        viewport: {width: 390, height: 844},
        deviceScaleFactor: 3,
        isMobile: true,
        hasTouch: true,
        colorScheme: 'light',
        recordVideo: {dir: outDir, size: {width: 390, height: 844}},
    });
    const motionPage = await motionContext.newPage();
    const pause = (ms) => motionPage.waitForTimeout(ms);

    const widgetInfoUrl = `${salonBase}/widget/${info.widget_public_id}`;
    console.log('Recording widget motion (reduced-motion OFF, human pacing)…');
    const started = Date.now();

    await motionPage.goto(widgetInfoUrl);
    await motionPage.waitForSelector('#bts-services .wb-opt');
    await motionPage.evaluate(async () => { await document.fonts.ready; });
    await pause(1400); // land — let the brand read

    await motionPage.locator('#bts-services .wb-opt', {hasText: 'Full colour'}).click();
    await pause(1100); // service picked → stylist step

    await motionPage.locator('#bts-stylists .wb-opt', {hasText: 'Sofia'}).click();
    await motionPage.waitForSelector('.wb-day[data-available="true"]');
    await pause(1300); // the availability calendar breathes

    await motionPage.locator('.wb-day[data-available="true"]').nth(2).click();
    await motionPage.waitForSelector('.wb-chip');
    await pause(1500); // open times appear

    await motionPage.locator('.wb-chip').first().click();
    await pause(1300); // added — the visit summary fills in

    await motionPage.locator('#bts-finalize').click();
    await pause(900);
    await motionPage.locator('#bts-name').pressSequentially('Jamie Rivera', {delay: 55});
    await pause(350);
    await motionPage.locator('#bts-phone').pressSequentially(info.capture_client_phone, {delay: 45});
    await pause(700);

    await motionPage.locator('#bts-submit').click();
    await motionPage.waitForSelector('section[data-step="confirmed"]:not([hidden])');
    await pause(2000); // hold the confirmation

    const durationMs = Date.now() - started;
    await motionPage.close();
    await motionContext.close();
    const recording = await (motionPage.video()).path();
    const finalPath = path.join(outDir, 'widget-motion.webm');
    fs.renameSync(recording, finalPath);
    await motionBrowser.close();

    // Merge into the committed manifest without touching the stills entries.
    const manifestPath = path.join(repoRoot, 'docs/launch-video/manifest.json');
    const existing = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    existing.assets = existing.assets.filter((asset) => asset.file !== 'widget-motion.webm');
    existing.assets.push({
        file: 'widget-motion.webm',
        beat: '03 · Her side — the real booking, end to end',
        route: '/widget',
        viewport: 'mobile',
        theme: 'marble',
        accent: info.accent,
        width: 390,
        height: 844,
        type: 'video',
        duration_ms: durationMs,
    });
    fs.writeFileSync(manifestPath, JSON.stringify(existing, null, 2) + '\n');

    if (serverProc) serverProc.kill();
    console.log(`\nMotion capture done: ${finalPath} (~${(durationMs / 1000).toFixed(1)}s)`);
    console.log(`Manifest updated: ${manifestPath}`);
    process.exit(0);
}

// ---------------------------------------------------------------------------
// Capture plumbing
// ---------------------------------------------------------------------------

const FREEZE_CSS = `
    *, *::before, *::after { transition: none !important; animation: none !important; caret-color: transparent !important; }
    html { scroll-behavior: auto !important; }
`;

const manifest = [];
let style = { accent: info.accent, theme: 'marble' }; // what launch:capture style currently has applied

function pngSize(buffer) {
    return { width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20) };
}

async function settle(page) {
    await page.addStyleTag({ content: FREEZE_CSS }).catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(Array.from(document.images)
            .filter((img) => !img.complete)
            .map((img) => new Promise((resolve) => { img.onload = img.onerror = resolve; })));
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    });
    await page.waitForTimeout(250);
}

async function shoot(page, file, meta, locator = null) {
    const target = locator ?? page;
    const buffer = await target.screenshot({ path: path.join(outDir, file), type: 'png' });
    const { width, height } = pngSize(buffer);
    manifest.push({
        file,
        beat: meta.beat,
        route: meta.route,
        viewport: meta.viewport,
        theme: style.theme,
        accent: meta.accent ?? style.accent,
        width,
        height,
        crop: locator !== null,
    });
    console.log(`  ✓ ${file} (${width}x${height})`);
}

const browser = await chromium.launch();
const desktop = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 3,
    reducedMotion: 'reduce',
    colorScheme: 'light',
});
const mobile = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    reducedMotion: 'reduce',
    colorScheme: 'light',
});

// Programmatic login (owner) — a session redirect, never the login form.
const page = await desktop.newPage();
await page.goto(`${appBase}/_capture/login?email=${encodeURIComponent(info.owner_email)}&to=/dashboard`);
await settle(page);

// Sanity: the server clock MUST be frozen to the seeder anchor, or every
// date-dependent screen shoots empty/misdated. The dashboard overline
// renders the salon-local "today".
await page.goto(`${salonBase}/`);
await settle(page);
const overline = await page.locator('.bts-overline').first().textContent();
if (!/15\s+September/i.test(overline ?? '')) {
    console.error(`Server clock is NOT frozen (dashboard says "${overline?.trim()}").`);
    console.error(`Stop the running server and either let this script boot one, or start yours with:`);
    console.error(`  APP_FAKE_NOW="${info.fake_now}" BOOKING_WIDGET_MIN_SECONDS=0 php artisan serve`);
    process.exit(1);
}

// ---------------------------------------------------------------------------
// Owner-facing (desktop) — baseline: Marble, brand plum accent
// ---------------------------------------------------------------------------

console.log('Owner screens (desktop / Marble / baseline accent):');

await shoot(page, 'owner-dashboard--marble.png', { beat: "09 · The owner's morning — today at a glance", route: '/', viewport: 'desktop' });
await shoot(page, 'crop-stat-tile.png', { beat: '09a · Callout — a single stat tile', route: '/', viewport: 'desktop' },
    page.locator('.bts-stat').first());
await shoot(page, 'crop-appointment-row.png', { beat: '09b · Callout — a single appointment row', route: '/', viewport: 'desktop' },
    page.locator('table tbody tr').first());

await page.goto(`${salonBase}/calendar`);
await settle(page);
await page.getByRole('button', { name: 'Week', exact: true }).click();
await settle(page);
await shoot(page, 'owner-calendar-week.png', { beat: '10 · The whole week, every chair, one master calendar', route: '/calendar', viewport: 'desktop' });

await page.getByRole('button', { name: 'Day', exact: true }).click();
await settle(page);
await shoot(page, 'owner-calendar-day.png', { beat: '11 · Day view — the day, chair by chair', route: '/calendar', viewport: 'desktop' });

await page.goto(`${salonBase}/clients`);
await settle(page);
await shoot(page, 'owner-clients-directory.png', { beat: '12 · A real client book', route: '/clients', viewport: 'desktop' });

await page.locator('a[href*="/clients/"]').first().click();
await page.waitForURL('**/clients/**');
await settle(page);
await shoot(page, 'owner-client-profile.png', { beat: '13 · One client — history, formulas, allergies', route: '/clients/{id}', viewport: 'desktop' });

await page.goto(`${salonBase}/reports`);
await settle(page);
await shoot(page, 'owner-reports.png', { beat: '14 · The month in numbers — source mix front and centre', route: '/reports', viewport: 'desktop' });

await page.goto(`${salonBase}/settings#branding`);
await settle(page);
await page.getByText('Accent color', { exact: true }).scrollIntoViewIfNeeded();
await settle(page);
await shoot(page, 'owner-settings-branding.png', { beat: '15 · Branding — accent picker visible', route: '/settings#branding', viewport: 'desktop' });

await page.goto(`${salonBase}/widgets`);
await settle(page);
await shoot(page, 'owner-widgets.png', { beat: '16 · Widgets — list and editor', route: '/widgets', viewport: 'desktop' });
await shoot(page, 'crop-embed-code.png', { beat: '17 · Callout — the embed snippet', route: '/widgets', viewport: 'desktop' },
    page.locator('div.bg-field').filter({ hasText: '<iframe' }).last());

await page.goto(`${salonBase}/services`);
await settle(page);
await shoot(page, 'owner-services.png', { beat: '16b · The menu — owner-ordered, reorder controls visible', route: '/services', viewport: 'desktop' });

await page.goto(`${salonBase}/availability`);
await settle(page);
await shoot(page, 'owner-availability.png', { beat: '18a · Availability — the stylist schedule cards', route: '/availability', viewport: 'desktop' });

// The weekly-hours card lives in the right-docked drawer — open one stylist.
await page.locator('[id^="availability-card-"]').first().click();
await page.waitForSelector('[role="dialog"] div[class*="rounded-[18px]"]');
await settle(page);
await shoot(page, 'crop-availability-card.png', { beat: '18b · Callout — the weekly availability card', route: '/availability', viewport: 'desktop' },
    page.locator('[role="dialog"] div[class*="rounded-[18px]"]').first());
await page.keyboard.press('Escape');
await settle(page);

await page.goto(`${salonBase}/setup`);
await settle(page);
// The wizard auto-opens the first incomplete step (the dense GHL connect
// screed); the storyboard wants ONE CLEAN STEP — open "Salon basics".
// Button, not text: once the wizard remembers this step, the PANEL heading
// says "Salon basics" too and a text locator goes ambiguous.
await page.getByRole('button', { name: 'Salon basics' }).first().click();
await settle(page);
await shoot(page, 'owner-onboarding-step.png', { beat: '18 · Guided setup — one clean step (Salon basics)', route: '/setup', viewport: 'desktop' });

// ---------------------------------------------------------------------------
// The theme beat: shot 9 again in Glacier, clearly labeled
// ---------------------------------------------------------------------------

console.log('Theme beat (Glacier):');
artisan('launch:capture', 'style', '--theme=glacier');
style = { ...style, theme: 'glacier' };
await page.goto(`${salonBase}/`);
await settle(page);
await shoot(page, 'owner-dashboard--glacier.png', { beat: '09 · Theme variant — same morning, Glacier', route: '/', viewport: 'desktop' });
artisan('launch:capture', 'style', '--theme=marble');
style = { ...style, theme: 'marble' };

// ---------------------------------------------------------------------------
// The accent beat: shots 9 + 10 + mobile widget landing × four accents.
// Same screen, same data, same scroll — ONLY the accent differs, so the
// cross-dissolve reads as real product behavior.
// ---------------------------------------------------------------------------

const ACCENTS = [
    ['accent-01', '#C0613E', 'terracotta'],
    ['accent-02', '#5B3E96', 'deep violet'],
    ['accent-03', '#5C7458', 'sage'],
    ['accent-04', '#211C18', 'near-black'],
];

const widgetPage = await mobile.newPage();

for (const [tag, hex, label] of ACCENTS) {
    console.log(`Accent beat — ${label} (${hex}):`);
    artisan('launch:capture', 'style', `--accent=${hex}`);
    style = { ...style, accent: hex };

    await page.goto(`${salonBase}/`);
    await settle(page);
    await shoot(page, `owner-dashboard--${tag}.png`, { beat: `19 · Accent beat (${label}) — dashboard`, route: '/', viewport: 'desktop', accent: hex });

    await page.goto(`${salonBase}/calendar`);
    await settle(page);
    await page.getByRole('button', { name: 'Week', exact: true }).click();
    await settle(page);
    await shoot(page, `owner-calendar-week--${tag}.png`, { beat: `19 · Accent beat (${label}) — week calendar`, route: '/calendar', viewport: 'desktop', accent: hex });

    await widgetPage.goto(widgetUrl);
    await widgetPage.waitForSelector('#bts-services .wb-opt');
    await settle(widgetPage);
    await shoot(widgetPage, `widget-landing--${tag}.png`, { beat: `19 · Accent beat (${label}) — widget landing`, route: '/widget', viewport: 'mobile', accent: hex });

    // The landing carries little accent; the calendar step is where the
    // recolor really shows (tinted day circles, selected day, slot chips) —
    // give the film the strong frame too. Same service, same date, same
    // scroll on every variant; no slot is clicked, so nothing is booked.
    await widgetPage.locator('#bts-services .wb-opt', { hasText: 'Full colour' }).click();
    await settle(widgetPage);
    await widgetPage.locator('#bts-stylists .wb-opt', { hasText: 'Sofia' }).click();
    await widgetPage.waitForSelector('.wb-day[data-available="true"]');
    await settle(widgetPage);
    await widgetPage.locator('.wb-day[data-available="true"]').nth(2).click();
    await widgetPage.waitForSelector('.wb-chip');
    await settle(widgetPage);
    await widgetPage.locator('#bts-cal').scrollIntoViewIfNeeded();
    await settle(widgetPage);
    await shoot(widgetPage, `widget-calendar--${tag}.png`, { beat: `19 · Accent beat (${label}) — widget calendar step`, route: '/widget', viewport: 'mobile', accent: hex });
}

artisan('launch:capture', 'style', `--accent=${info.accent}`);
style = { ...style, accent: info.accent };

// ---------------------------------------------------------------------------
// Client-facing widget (mobile) — the full booking funnel, baseline accent
// ---------------------------------------------------------------------------

console.log('Widget funnel (mobile / baseline):');

await widgetPage.goto(widgetUrl);
await widgetPage.waitForSelector('#bts-services .wb-opt');
await settle(widgetPage);
await shoot(widgetPage, 'widget-01-landing.png', { beat: '01 · Client meets the brand', route: '/widget', viewport: 'mobile' });

await widgetPage.locator('#bts-services').scrollIntoViewIfNeeded();
await settle(widgetPage);
await shoot(widgetPage, 'widget-02-services.png', { beat: '02 · Pick a service — priced, timed', route: '/widget', viewport: 'mobile' });

// Service 1: Full colour with Sofia → open calendar → open a date → pick a slot.
await widgetPage.locator('#bts-services .wb-opt', { hasText: 'Full colour' }).click();
await settle(widgetPage);
await widgetPage.locator('section[data-step="stylist"]').scrollIntoViewIfNeeded();
await shoot(widgetPage, 'widget-04-stylist.png', { beat: '04 · Choose your stylist (or any)', route: '/widget', viewport: 'mobile' });

await widgetPage.locator('#bts-stylists .wb-opt', { hasText: 'Sofia' }).click();
await widgetPage.waitForSelector('.wb-day[data-available="true"]');
await settle(widgetPage);

const day = widgetPage.locator('.wb-day[data-available="true"]').nth(2);
await day.click();
await widgetPage.waitForSelector('.wb-chip');
await settle(widgetPage);
await widgetPage.locator('#bts-cal').scrollIntoViewIfNeeded();
await settle(widgetPage);
await shoot(widgetPage, 'widget-05-calendar-open.png', { beat: '05 · Real availability, inline — a date open', route: '/widget', viewport: 'mobile' });
await shoot(widgetPage, 'crop-widget-calendar-card.png', { beat: '05a · Callout — the availability calendar card', route: '/widget', viewport: 'mobile' },
    widgetPage.locator('#bts-cal'));

await widgetPage.locator('#bts-slots').scrollIntoViewIfNeeded();
await settle(widgetPage);
await shoot(widgetPage, 'widget-06-slots.png', { beat: '06 · Pick a time — open slots only', route: '/widget', viewport: 'mobile' });

await widgetPage.locator('.wb-chip').first().click();
await settle(widgetPage);

// Service 2 → the multi-service visit state.
await widgetPage.locator('#bts-add-more').click();
await settle(widgetPage);
await widgetPage.locator('#bts-services .wb-opt', { hasText: 'Blowout' }).click();
await settle(widgetPage);
await widgetPage.locator('#bts-stylists .wb-opt').first().click(); // any available stylist
await widgetPage.waitForSelector('.wb-day[data-available="true"]');
await settle(widgetPage);
await widgetPage.locator('.wb-day[data-available="true"]').nth(3).click();
await widgetPage.waitForSelector('.wb-chip');
await settle(widgetPage);
await widgetPage.locator('.wb-chip').first().click();
await settle(widgetPage);

await widgetPage.evaluate(() => window.scrollTo(0, 0));
await settle(widgetPage);
await shoot(widgetPage, 'widget-03-multi-service.png', { beat: '03 · Build a visit — two services, independent times', route: '/widget', viewport: 'mobile' });

// Details, partially filled (sentinel client — cleaned up by `prepare`).
await widgetPage.locator('#bts-finalize').click();
await settle(widgetPage);
await widgetPage.locator('#bts-name').fill('Jamie Rivera');
await widgetPage.locator('#bts-phone').fill(info.capture_client_phone);
await widgetPage.locator('#bts-email').focus();
await widgetPage.locator('section[data-step="details"]').scrollIntoViewIfNeeded();
await settle(widgetPage);
await shoot(widgetPage, 'widget-07-details.png', { beat: '07 · No account, no app — name and phone', route: '/widget', viewport: 'mobile' });

await widgetPage.locator('#bts-submit').click();
try {
    await widgetPage.waitForSelector('section[data-step="confirmed"]:not([hidden])');
} catch (error) {
    const widgetError = await widgetPage.locator('#bts-error').textContent().catch(() => null);
    if (widgetError?.trim()) {
        console.error(`Widget refused the booking: "${widgetError.trim()}"`);
        console.error('If this is the bot gate ("too fast"), the server must run with BOOKING_WIDGET_MIN_SECONDS=0');
        console.error('— the frozen clock pins the page-token age at zero. Restart it (or let this script boot it).');
    }
    throw error;
}
await settle(widgetPage);
await widgetPage.locator('section[data-step="confirmed"]').scrollIntoViewIfNeeded();
await shoot(widgetPage, 'widget-08-confirmed.png', { beat: '08 · Booked — confirmation', route: '/widget', viewport: 'mobile' });

// ---------------------------------------------------------------------------
// Widget on desktop — the split branded container, service + calendar steps
// ---------------------------------------------------------------------------

console.log('Widget (desktop split container):');

const widgetDesktop = await desktop.newPage();
await widgetDesktop.goto(widgetUrl);
await widgetDesktop.waitForSelector('#bts-services .wb-opt');
await settle(widgetDesktop);
await shoot(widgetDesktop, 'widget-desktop-services.png', { beat: 'W1 · The branded split container — service step', route: '/widget', viewport: 'desktop' });

await widgetDesktop.locator('#bts-services .wb-opt', { hasText: 'Hair cut' }).click();
await settle(widgetDesktop);
await widgetDesktop.locator('#bts-stylists .wb-opt', { hasText: 'Maya' }).click();
await widgetDesktop.waitForSelector('.wb-day[data-available="true"]');
await settle(widgetDesktop);
await widgetDesktop.locator('.wb-day[data-available="true"]').nth(1).click();
await widgetDesktop.waitForSelector('.wb-chip');
await settle(widgetDesktop);
await shoot(widgetDesktop, 'widget-desktop-calendar.png', { beat: 'W2 · The branded split container — calendar step', route: '/widget', viewport: 'desktop' });

// ---------------------------------------------------------------------------
// Manifest + teardown
// ---------------------------------------------------------------------------

// A stills run must not clobber the motion entries (--motion runs merge
// theirs the same way in reverse).
const manifestPath = path.join(repoRoot, 'docs/launch-video/manifest.json');
let motionEntries = [];
try {
    motionEntries = JSON.parse(fs.readFileSync(manifestPath, 'utf8')).assets.filter((a) => a.type === 'video');
} catch { /* first run — no manifest yet */ }
manifest.push(...motionEntries);
fs.writeFileSync(manifestPath, JSON.stringify({
    fixture: {
        salon: info.name,
        slug: info.slug,
        anchor: info.anchor,
        timezone: info.timezone,
        fake_now: info.fake_now,
        baseline_accent: info.accent,
        note: 'All data is fictional. Assets are regenerable and never committed — see docs/launch-video/README.md.',
    },
    capture: {
        desktop: { viewport: '1440x900', deviceScaleFactor: 3 },
        mobile: { viewport: '390x844', deviceScaleFactor: 3 },
    },
    assets: manifest,
}, null, 2) + '\n');

await browser.close();
if (serverProc) serverProc.kill();

const total = fs.readdirSync(outDir).filter((f) => f.endsWith('.png'))
    .reduce((sum, f) => sum + fs.statSync(path.join(outDir, f)).size, 0);
console.log(`\nDone: ${manifest.length} assets → ${outDir} (${(total / 1024 / 1024).toFixed(1)} MB)`);
console.log(`Manifest: ${manifestPath}`);
