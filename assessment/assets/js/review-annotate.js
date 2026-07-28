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

            markerCanvas = new fabric.StaticCanvas(markerLayerEl);
            PdfAnnotateCore.fitCanvasToContainer(markerCanvas, container, rendered.width, rendered.height);
            // The marker layer has no background image of its own - by
            // design, so only their ink/stamps show and the real page
            // underneath stays visible. A saved annotation JSON carries its
            // OWN backgroundImage by default (see stripBackground's doc
            // comment) - loading that here would paint a second, opaque
            // copy of the page over everything below it.
            var markerJson = window.__markerAnnotations && window.__markerAnnotations[pageNumber];
            if (markerJson) {
                markerCanvas.loadFromJSON(PdfAnnotateCore.stripBackground(markerJson), function () {
                    markerCanvas.requestRenderAll();
                });
            }

            // The background page must finish painting before the
            // student's own annotations are loaded on top of it, or
            // whichever finished last "wins" the render.
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                studentCanvas.setBackgroundImage(img, function () {
                    studentCanvas.requestRenderAll();
                    var studentJson = window.__myAnnotations && window.__myAnnotations[pageNumber];
                    if (studentJson) {
                        // loadFromJSON() resets backgroundImage to whatever
                        // the JSON says, including null/absent (which it will
                        // be, once stripBackground() has been applied at save
                        // time), unconditionally clearing the real one just
                        // set above - re-apply the same img afterward.
                        studentCanvas.loadFromJSON(studentJson, function () {
                            studentCanvas.setBackgroundImage(img, function () {
                                studentCanvas.requestRenderAll();
                            });
                        });
                    }
                });
            });
        });
    }
})();
