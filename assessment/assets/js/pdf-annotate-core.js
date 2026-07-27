/**
 * Shared PDF.js + Fabric.js plumbing used by both the student in-PDF
 * typing UI (student-pdf-annotate.js) and the teacher/moderator marking
 * canvas (canvas-annotate.js): loading PDF.js once, rendering a given page
 * to a background image, and building interactive/read-only Fabric
 * canvases over it. Keeping this in one place means both surfaces render
 * a page identically - the whole point is that what a student typed lines
 * up exactly with what a teacher sees later.
 */
(function (global) {
    'use strict';

    var PDFJS_VERSION = '3.11.174';
    var loadingPromise = null;

    function loadPdfJs() {
        if (global.pdfjsLib) {
            return Promise.resolve(global.pdfjsLib);
        }
        if (loadingPromise) {
            return loadingPromise;
        }
        loadingPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VERSION + '/pdf.min.js';
            script.onload = function () {
                global.pdfjsLib.GlobalWorkerOptions.workerSrc =
                    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VERSION + '/pdf.worker.min.js';
                resolve(global.pdfjsLib);
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return loadingPromise;
    }

    function loadDocument(url) {
        return loadPdfJs().then(function (pdfjsLib) {
            return pdfjsLib.getDocument(url).promise;
        });
    }

    /** Renders one page to an offscreen canvas, returning its data URL and pixel size. */
    function renderPageToImage(pdfDoc, pageNumber, scale) {
        return pdfDoc.getPage(pageNumber).then(function (page) {
            var viewport = page.getViewport({ scale: scale || 1.4 });
            var renderCanvas = document.createElement('canvas');
            renderCanvas.width = viewport.width;
            renderCanvas.height = viewport.height;
            var ctx = renderCanvas.getContext('2d');
            return page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
                return { dataUrl: renderCanvas.toDataURL(), width: viewport.width, height: viewport.height };
            });
        });
    }

    /**
     * Fabric.js wraps the canvas element it's given in its own
     * ".canvas-container" div and sets that div's, and both its internal
     * canvases', inline CSS width/height to the full pixel size - plain
     * CSS (max-width etc.) on the original canvas element has no effect on
     * that wrapper, since it isn't part of the wrapper's box at all. The
     * only way to make a Fabric canvas responsive is through Fabric's own
     * cssOnly resize, which keeps the full-resolution drawing buffer
     * (crisp ink/text, correct pointer-to-coordinate math) while only
     * shrinking the on-screen box to fit its container.
     */
    function fitCanvasToContainer(fabricCanvas, containerEl, pxWidth, pxHeight) {
        if (!containerEl || !pxWidth || !pxHeight) return;
        var available = containerEl.clientWidth;
        if (!available) return;
        var displayWidth = Math.min(available, pxWidth);
        var displayHeight = displayWidth * (pxHeight / pxWidth);
        // cssOnly requires the unit suffix explicitly - Fabric skips its
        // usual "+= 'px'" step for this path, so a bare number here is
        // silently rejected by the CSSOM and the resize is a no-op.
        fabricCanvas.setDimensions({ width: displayWidth + 'px', height: displayHeight + 'px' }, { cssOnly: true });
    }

    /**
     * Wires a simple Prev/Next/page-indicator control set to a callback
     * that (re)renders a given page number. Returns an object with
     * .setPage(n) so callers can also change page programmatically (e.g.
     * after a document loads, jump to page 1).
     */
    function wirePagination(container, totalPages, onPageChange) {
        var current = 1;
        var prevBtn = container.querySelector('[data-page-prev]');
        var nextBtn = container.querySelector('[data-page-next]');
        var indicator = container.querySelector('[data-page-indicator]');

        function render() {
            if (indicator) indicator.textContent = 'Page ' + current + ' of ' + totalPages;
            if (prevBtn) prevBtn.disabled = current <= 1;
            if (nextBtn) nextBtn.disabled = current >= totalPages;
            onPageChange(current);
        }

        if (prevBtn) prevBtn.addEventListener('click', function () { if (current > 1) { current--; render(); } });
        if (nextBtn) nextBtn.addEventListener('click', function () { if (current < totalPages) { current++; render(); } });

        return {
            setPage: function (n) { current = n; render(); },
            getPage: function () { return current; },
            refresh: render,
        };
    }

    global.PdfAnnotateCore = {
        loadPdfJs: loadPdfJs,
        loadDocument: loadDocument,
        renderPageToImage: renderPageToImage,
        wirePagination: wirePagination,
        fitCanvasToContainer: fitCanvasToContainer,
    };
})(window);
