import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { version as pdfVersion } from 'pdfjs-dist/package.json';

const previews = new WeakMap();
const openPreviews = new Set();
let libraryPromise;

function receiptPreview(host) {
    const pages = host.querySelector('[data-proof-pages]');
    const status = host.querySelector('[data-proof-status]');
    let active = false;
    let generation = 0;
    let renderGeneration = 0;
    let request;
    let loadingTask;
    let renderTask;
    let objectUrl;
    let observer;
    let resizeTimer;
    let renderQueue = Promise.resolve();

    function message(text) {
        status.textContent = text;
        status.hidden = text === '';
    }

    function close() {
        active = false;
        generation += 1;
        renderGeneration += 1;
        request?.abort();
        renderTask?.cancel();
        loadingTask?.destroy().catch(() => {});
        observer?.disconnect();
        clearTimeout(resizeTimer);
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        request = loadingTask = renderTask = objectUrl = observer = null;
        pages.replaceChildren();
        message('Loading receipt…');
        openPreviews.delete(api);
    }

    async function open() {
        if (active) return;
        active = true;
        openPreviews.add(api);
        const current = ++generation;
        const isCurrent = () => active && generation === current;
        request = new AbortController();
        message('Loading receipt…');

        try {
            const response = await fetch(host.dataset.proofUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: request.signal,
            });
            if (!response.ok) throw new Error('Receipt unavailable');
            const mime = response.headers.get('Content-Type')?.split(';')[0];
            const file = await response.blob();
            if (!isCurrent()) return;

            if (['image/jpeg', 'image/png'].includes(mime)) {
                const image = document.createElement('img');
                image.alt = 'Submitted payment receipt';
                objectUrl = URL.createObjectURL(file);
                image.onload = () => { if (isCurrent()) message(''); };
                image.onerror = () => { if (isCurrent()) message('Unable to display this receipt. Download it below.'); };
                image.src = objectUrl;
                pages.append(image);
                return;
            }
            if (mime !== 'application/pdf') throw new Error('Unsupported receipt');

            libraryPromise ??= import('pdfjs-dist/build/pdf.mjs');
            const pdfjs = await libraryPromise;
            const data = new Uint8Array(await file.arrayBuffer());
            if (!isCurrent()) return;
            pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
            const assetRoot = `${import.meta.env.BASE_URL}pdfjs/${pdfVersion}/`;
            loadingTask = pdfjs.getDocument({
                data,
                cMapUrl: `${assetRoot}cmaps/`,
                cMapPacked: true,
                standardFontDataUrl: `${assetRoot}standard_fonts/`,
                wasmUrl: `${assetRoot}wasm/`,
                iccUrl: `${assetRoot}iccs/`,
                isEvalSupported: false,
                enableXfa: false,
            });
            const pdf = await loadingTask.promise;
            if (!isCurrent()) return;

            function render() {
                const currentRender = ++renderGeneration;
                renderTask?.cancel();
                renderQueue = renderQueue.catch(() => {}).then(async () => {
                    if (!isCurrent() || currentRender !== renderGeneration) return;
                    pages.replaceChildren();
                    const width = Math.max(1, Math.floor(pages.clientWidth));
                    for (let number = 1; number <= pdf.numPages; number += 1) {
                        if (!isCurrent() || currentRender !== renderGeneration) return;
                        message(`Loading page ${number} of ${pdf.numPages}…`);
                        const page = await pdf.getPage(number);
                        if (!isCurrent() || currentRender !== renderGeneration) return;
                        const viewport = page.getViewport({ scale: width / page.getViewport({ scale: 1 }).width });
                        const ratio = Math.min(window.devicePixelRatio || 1, 2,
                            Math.sqrt(4_000_000 / (viewport.width * viewport.height)));
                        const figure = window.document.createElement('figure');
                        figure.className = 'payment-proof-page';
                        const canvas = window.document.createElement('canvas');
                        canvas.width = Math.max(1, Math.floor(viewport.width * ratio));
                        canvas.height = Math.max(1, Math.floor(viewport.height * ratio));
                        canvas.style.width = `${Math.floor(viewport.width)}px`;
                        canvas.style.height = 'auto';
                        canvas.setAttribute('role', 'img');
                        canvas.setAttribute('aria-label', `Receipt page ${number} of ${pdf.numPages}`);
                        const caption = window.document.createElement('figcaption');
                        caption.textContent = `Page ${number} of ${pdf.numPages}`;
                        figure.append(canvas, caption);
                        pages.append(figure);
                        renderTask = page.render({
                            canvasContext: canvas.getContext('2d'), viewport,
                            transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0],
                        });
                        await renderTask.promise;
                        renderTask = null;
                        const text = await page.getTextContent();
                        const readableText = text.items.filter((item) => 'str' in item).map((item) => item.str).join(' ').trim();
                        if (readableText) {
                            const transcript = window.document.createElement('p');
                            transcript.className = 'sr-only';
                            transcript.textContent = readableText;
                            figure.append(transcript);
                        }
                        page.cleanup();
                    }
                    if (isCurrent() && currentRender === renderGeneration) message('');
                });
                return renderQueue;
            }

            await render();
            if (!isCurrent()) return;
            let previousWidth = pages.clientWidth;
            observer = new ResizeObserver(() => {
                if (!isCurrent() || Math.abs(pages.clientWidth - previousWidth) < 2) return;
                previousWidth = pages.clientWidth;
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    render().catch(() => {
                        if (isCurrent()) message('Unable to display this receipt. Download it below.');
                    });
                }, 150);
            });
            observer.observe(pages);
        } catch (error) {
            if (isCurrent() && error.name !== 'AbortError') {
                message('Unable to display this receipt. Download it below.');
            }
        }
    }

    const api = { open, close };
    return api;
}

document.addEventListener('payment-proof:open', (event) => {
    const host = event.target.closest('[data-payment-proof-preview]');
    if (!host) return;
    if (!previews.has(host)) previews.set(host, receiptPreview(host));
    previews.get(host).open();
});
document.addEventListener('payment-proof:close', (event) => {
    const host = event.target.closest('[data-payment-proof-preview]');
    if (host) previews.get(host)?.close();
});
document.addEventListener('livewire:navigating', () => {
    for (const preview of openPreviews) preview.close();
});
for (const host of document.querySelectorAll('[data-payment-proof-preview][data-proof-open="true"]')) {
    const preview = receiptPreview(host);
    previews.set(host, preview);
    preview.open();
}
