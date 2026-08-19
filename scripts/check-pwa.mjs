#!/usr/bin/env node
//
// Verifica si una URL es instalable como PWA, preguntándole a Chrome.
//
//   node check-pwa.mjs https://tu-app.com/login
//
// Requiere puppeteer-core y un Chrome instalado. Si no lo tenés a mano:
//   mkdir -p /tmp/pwacheck && cd /tmp/pwacheck && npm init -y && npm i puppeteer-core
//
// Variables opcionales:
//   CHROME=<ruta al chrome.exe / google-chrome>
//   NO_WEBFONT=1   bloquea webfonts (útil para reproducir mobile sin red)

import puppeteer from 'puppeteer-core';

const URL_ARG = process.argv[2];
if (!URL_ARG) {
    console.error('Uso: node check-pwa.mjs <url>');
    process.exit(1);
}

const CANDIDATOS = [
    process.env.CHROME,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);

const fs = await import('fs');
const CHROME = CANDIDATOS.find((p) => { try { return fs.existsSync(p); } catch { return false; } });
if (!CHROME) {
    console.error('No encontré Chrome. Pasalo con CHROME=<ruta>');
    process.exit(1);
}

const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--hide-scrollbars'],
});
const page = await browser.newPage();
await page.setViewport({ width: 390, height: 800, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });

// beforeinstallprompt dispara antes de que corra cualquier script de la página.
await page.evaluateOnNewDocument(() => {
    window.__bip = false;
    window.addEventListener('beforeinstallprompt', () => { window.__bip = true; });
});

if (process.env.NO_WEBFONT) {
    await page.setRequestInterception(true);
    page.on('request', (r) => (/fonts\.(bunny|googleapis|gstatic)/.test(r.url()) ? r.abort() : r.continue()));
}

await page.goto(URL_ARG, { waitUntil: 'networkidle0' });
const client = await page.createCDPSession();

const mf = await client.send('Page.getAppManifest');
console.log('== Manifest ==');
console.log('  url    :', mf.url || '(no declarado)');
console.log('  errors :', JSON.stringify(mf.errors ?? []));
if (mf.data) {
    const d = JSON.parse(mf.data);
    console.log('  display:', d.display, '| start_url:', d.start_url);
    console.log('  icons  :', (d.icons ?? []).map((i) => `${i.sizes} ${i.purpose ?? 'any'}`).join(', '));
}

console.log('\n== Instalabilidad ==');
try {
    const i = await client.send('Page.getInstallabilityErrors');
    const errs = i.installabilityErrors ?? [];
    console.log(errs.length ? JSON.stringify(errs, null, 2) : '  [] -> Chrome la considera instalable');
} catch {
    console.log('  (Page.getInstallabilityErrors no disponible en este Chrome)');
}

await new Promise((r) => setTimeout(r, 3500));
const s = await page.evaluate(async () => {
    const regs = await navigator.serviceWorker.getRegistrations();
    return {
        beforeinstallprompt: window.__bip,
        hookEnHead: typeof window.__pwaInstall === 'object',
        promptGuardado: !!window.__pwaInstall?.prompt,
        serviceWorkers: regs.length,
        swScriptURL: regs[0]?.active?.scriptURL ?? null,
        swActivo: !!regs[0]?.active,
        caches: await caches.keys(),
        secureContext: window.isSecureContext,
        standalone: matchMedia('(display-mode: standalone)').matches,
    };
});
console.log('\n== Estado en la página ==');
for (const [k, v] of Object.entries(s)) console.log(`  ${k.padEnd(22)}`, JSON.stringify(v));

console.log('\n== Headers críticos ==');
for (const p of ['/sw.js', '/manifest.webmanifest']) {
    const r = await page.evaluate(async (u) => {
        try {
            const res = await fetch(new URL(u, location.origin), { cache: 'no-store' });
            return { status: res.status, type: res.headers.get('content-type'), cc: res.headers.get('cache-control') };
        } catch (e) { return { error: String(e) }; }
    }, p);
    console.log(`  ${p.padEnd(24)}`, JSON.stringify(r));
    if (r.cc && /max-age=(\d+)/.test(r.cc) && +RegExp.$1 > 86400) {
        console.log(`     AVISO: ${p} se cachea ${RegExp.$1}s. Las actualizaciones pueden tardar días en llegar.`);
    }
}

await browser.close();
