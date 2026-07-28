/**
 * Read-only, paginated PDF preview ("Preview as student") - renders with
 * the same PDF.js pipeline as the interactive student/marking canvases
 * (pdf-annotate-core.js), rather than an <iframe src="...pdf">. A plain
 * iframe relies on the browser's own PDF viewer, which mobile Chrome
 * (unlike desktop) doesn't support inline at all - it shows a blank
 * "open externally" card instead of the paper. No Fabric.js needed here
 * since nothing is interactive, just draw each rendered page straight onto
 * a plain 2D canvas.
 */
(function () {
    'use strict';

    var canvasEl = document.getElementById('preview-pdf-canvas');
    if (!canvasEl || !window.PdfAnnotateCore) return;

    var ctx = canvasEl.getContext('2d');
    var statusEl = document.getElementById('preview-pdf-status');
    // Scoped to this specific PDF, not just the paper, so a mark scheme
    // preview (if ever added) wouldn't clash with the exam paper's own page.
    var pageStorageKey = 'pdf-preview-page-' + canvasEl.dataset.pdfSrc;
    var lastImg = null;

    // A plain <canvas> can still end up with stale on-screen pixels after
    // being scrolled out of view and back, or the tab backgrounded/restored
    // - repaint the last rendered page on those events rather than leaving
    // it blank until something else forces a browser repaint.
    var forceRepaint = throttle(function () {
        if (lastImg) ctx.drawImage(lastImg, 0, 0);
    }, 200);
    window.addEventListener('scroll', forceRepaint, { passive: true });
    document.addEventListener('visibilitychange', forceRepaint);
    window.addEventListener('pageshow', forceRepaint);

    function throttle(fn, ms) {
        var last = 0, timer = null;
        return function () {
            var now = Date.now();
            var remaining = ms - (now - last);
            if (remaining <= 0) {
                last = now;
                fn();
            } else {
                clearTimeout(timer);
                timer = setTimeout(function () { last = Date.now(); fn(); }, remaining);
            }
        };
    }

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        var pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.pdf-preview-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        // Resume on the page last viewed (e.g. after a refresh) rather than
        // always restarting at page 1.
        var savedPage = parseInt(window.localStorage.getItem(pageStorageKey), 10);
        var startPage = savedPage >= 1 && savedPage <= pdfDoc.numPages ? savedPage : 1;
        pagination.setPage(startPage);
    }).catch(function (err) {
        console.error('Failed to load PDF for preview', err);
        if (statusEl) statusEl.textContent = 'Could not load the PDF.';
    });

    function renderPage(pdfDoc, pageNumber) {
        try { window.localStorage.setItem(pageStorageKey, String(pageNumber)); } catch (e) { /* storage unavailable - not fatal, just won't resume on refresh */ }
        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, PdfAnnotateCore.RENDER_SCALE).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            var img = new Image();
            img.onload = function () {
                lastImg = img;
                ctx.drawImage(img, 0, 0);
            };
            img.src = rendered.dataUrl;
        });
    }
})();
