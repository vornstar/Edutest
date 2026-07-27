/**
 * Split-screen marking canvas: renders the student's PDF/scan page by page
 * with PDF.js, shows the student's own in-PDF typing (if any - see
 * student-pdf-annotate.js) as a read-only reference layer on top of the
 * page, and layers an interactive Fabric.js canvas underneath that for the
 * marker's own freehand ink, highlighter strokes, and text-box comments.
 * The two layers are saved completely separately (different marker_id
 * rows in the same annotations table), so marking never overwrites what a
 * student wrote.
 */
(function () {
    'use strict';

    var canvasEl = document.getElementById('annotation-canvas');
    var studentLayerEl = document.getElementById('annotation-student-layer');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var panel = document.querySelector('.marking-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var pagination = null;
    var fabricCanvas = null;
    var studentStaticCanvas = null;

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.annotation-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        pagination.setPage(1);
    }).catch(function (err) {
        console.error('Failed to render PDF for annotation', err);
    });

    function renderPage(pdfDoc, pageNumber) {
        if (fabricCanvas) fabricCanvas.dispose();
        if (studentStaticCanvas) studentStaticCanvas.dispose();

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;

            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: true });
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, fabricCanvas.renderAll.bind(fabricCanvas));
            });
            fabricCanvas.freeDrawingBrush.width = 3;
            fabricCanvas.freeDrawingBrush.color = currentColor();

            wireTools();
            loadOwnAnnotation(pageNumber);
            renderStudentLayer(pageNumber, rendered.width, rendered.height);
        });
    }

    /** A non-interactive canvas stacked on top showing what the student typed/drew - pointer-events:none (set in CSS) lets clicks fall through to the marker's own canvas below. */
    function renderStudentLayer(pageNumber, width, height) {
        if (!studentLayerEl) return;
        studentLayerEl.width = width;
        studentLayerEl.height = height;
        studentStaticCanvas = new fabric.StaticCanvas(studentLayerEl);

        var studentJson = window.__studentAnnotations && window.__studentAnnotations[pageNumber];
        if (studentJson) {
            studentStaticCanvas.loadFromJSON(studentJson, studentStaticCanvas.renderAll.bind(studentStaticCanvas));
        }
    }

    function wireTools() {
        document.querySelectorAll('[data-tool]').forEach(function (btn) {
            btn.onclick = function () {
                var tool = btn.dataset.tool;
                if (tool === 'pen') {
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 3;
                    fabricCanvas.freeDrawingBrush.color = currentColor();
                } else if (tool === 'highlighter') {
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 16;
                    fabricCanvas.freeDrawingBrush.color = hexToRgba(currentColor(), 0.35);
                } else if (tool === 'text') {
                    fabricCanvas.isDrawingMode = false;
                    var text = new fabric.IText('Comment', {
                        left: 40, top: 40, fill: currentColor(), fontSize: 18,
                    });
                    fabricCanvas.add(text);
                }
            };
        });

        var colorInput = document.querySelector('[data-tool="color"]');
        if (colorInput) {
            colorInput.oninput = function () {
                fabricCanvas.freeDrawingBrush.color = colorInput.value;
            };
        }

        var saveBtn = document.getElementById('save-annotation');
        if (saveBtn) {
            saveBtn.onclick = function () { saveAnnotation(); };
        }
    }

    function currentColor() {
        var input = document.querySelector('[data-tool="color"]');
        return input ? input.value : '#e11d48';
    }

    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function loadOwnAnnotation(pageNumber) {
        var existing = window.__existingAnnotations && window.__existingAnnotations[pageNumber];
        if (existing) {
            fabricCanvas.loadFromJSON(existing, fabricCanvas.renderAll.bind(fabricCanvas));
        }
    }

    function saveAnnotation() {
        fetch('/assessment/teacher/marking/' + submissionId + '/annotation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page: pagination.getPage(),
                fabric_json: fabricCanvas.toJSON(),
                csrf_token: csrfToken,
            }),
        }).then(function (res) {
            if (!res.ok) throw new Error('save failed');
            alert('Annotations saved.');
        }).catch(function () {
            alert('Failed to save annotations.');
        });
    }
})();
