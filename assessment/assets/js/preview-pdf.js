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

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        PdfAnnotateCore.wirePagination(
            document.querySelector('.pdf-preview-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        ).setPage(1);
    }).catch(function (err) {
        console.error('Failed to load PDF for preview', err);
        if (statusEl) statusEl.textContent = 'Could not load the PDF.';
    });

    function renderPage(pdfDoc, pageNumber) {
        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0); };
            img.src = rendered.dataUrl;
        });
    }
})();
