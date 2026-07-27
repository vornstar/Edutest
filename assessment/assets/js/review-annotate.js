/**
 * Read-only viewer for a student to see their marked-up script: their own
 * typing/drawing (if any) plus the marker's annotations, layered on top of
 * the PDF/scan exactly like the marking screen - but with no drawing tools
 * at all, since this is purely for reviewing feedback after marking.
 */
(function () {
    'use strict';

    var canvasEl = document.getElementById('review-canvas');
    var markerLayerEl = document.getElementById('review-marker-layer');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var container = canvasEl.closest('.script-pane') || canvasEl.parentElement;
    var pagination = null;
    var studentCanvas = null;
    var markerCanvas = null;

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.review-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        pagination.setPage(1);
    }).catch(function (err) {
        console.error('Failed to load PDF for review', err);
    });

    function renderPage(pdfDoc, pageNumber) {
        if (studentCanvas) studentCanvas.dispose();
        if (markerCanvas) markerCanvas.dispose();

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            markerLayerEl.width = rendered.width;
            markerLayerEl.height = rendered.height;

            studentCanvas = new fabric.StaticCanvas(canvasEl);
            PdfAnnotateCore.fitCanvasToContainer(studentCanvas, container, rendered.width, rendered.height);
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                studentCanvas.setBackgroundImage(img, studentCanvas.renderAll.bind(studentCanvas));
            });
            var studentJson = window.__myAnnotations && window.__myAnnotations[pageNumber];
            if (studentJson) {
                studentCanvas.loadFromJSON(studentJson, studentCanvas.renderAll.bind(studentCanvas));
            }

            markerCanvas = new fabric.StaticCanvas(markerLayerEl);
            PdfAnnotateCore.fitCanvasToContainer(markerCanvas, container, rendered.width, rendered.height);
            var markerJson = window.__markerAnnotations && window.__markerAnnotations[pageNumber];
            if (markerJson) {
                markerCanvas.loadFromJSON(markerJson, markerCanvas.renderAll.bind(markerCanvas));
            }
        });
    }
})();
