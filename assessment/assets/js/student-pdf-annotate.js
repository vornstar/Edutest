/**
 * Lets a student type/write directly onto the exam paper PDF itself,
 * rather than (or alongside) the separate typed answer booklet. Saved as
 * Fabric.js vector JSON per page via the Annotation model, keyed by the
 * student's own id - the exact same data a teacher's marking view then
 * overlays read-only on top of the same PDF, so they see precisely what
 * the student left on the page.
 */
(function () {
    'use strict';

    var canvasEl = document.getElementById('pdf-answer-canvas');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var panel = document.querySelector('.test-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var statusEl = document.getElementById('pdf-answer-status');
    var pagination = null;
    var fabricCanvas = null;
    var saveTimer = null;

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.pdf-answer-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        pagination.setPage(1);
    }).catch(function (err) {
        console.error('Failed to load PDF for answering', err);
        if (statusEl) statusEl.textContent = 'Could not load the PDF.';
    });

    function renderPage(pdfDoc, pageNumber) {
        // Save whatever's on the current page before switching away from it.
        if (fabricCanvas) {
            saveNow();
            fabricCanvas.dispose();
        }

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;

            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: true });
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, fabricCanvas.renderAll.bind(fabricCanvas));
            });
            fabricCanvas.freeDrawingBrush.width = 2;
            fabricCanvas.freeDrawingBrush.color = currentColor();

            fabricCanvas.on('object:added', debounceSave);
            fabricCanvas.on('object:modified', debounceSave);
            fabricCanvas.on('object:removed', debounceSave);

            loadExisting(pageNumber);
        });
    }

    function currentColor() {
        var input = document.querySelector('[data-answer-tool="color"]');
        return input ? input.value : '#1d4ed8';
    }

    document.querySelectorAll('[data-answer-tool]').forEach(function (btn) {
        var tool = btn.dataset.answerTool;
        if (tool === 'color') return;
        btn.addEventListener('click', function () {
            if (!fabricCanvas) return;
            if (tool === 'pen') {
                fabricCanvas.isDrawingMode = true;
                fabricCanvas.freeDrawingBrush.width = 2;
                fabricCanvas.freeDrawingBrush.color = currentColor();
            } else if (tool === 'text') {
                fabricCanvas.isDrawingMode = false;
                var text = new fabric.IText('Type here', {
                    left: 40, top: 40, fill: currentColor(), fontSize: 16,
                });
                fabricCanvas.add(text);
                fabricCanvas.setActiveObject(text);
                text.enterEditing();
            }
        });
    });

    var colorInput = document.querySelector('[data-answer-tool="color"]');
    if (colorInput) {
        colorInput.addEventListener('input', function () {
            if (fabricCanvas) fabricCanvas.freeDrawingBrush.color = colorInput.value;
        });
    }

    function debounceSave() {
        if (statusEl) statusEl.textContent = 'Saving…';
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveNow, 800);
    }

    function saveNow() {
        if (!fabricCanvas || !pagination) return;
        fetch('/assessment/student/submissions/' + submissionId + '/annotation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page: pagination.getPage(),
                fabric_json: fabricCanvas.toJSON(),
                csrf_token: csrfToken,
            }),
        }).then(function (res) {
            if (!res.ok) throw new Error('save failed');
            if (statusEl) statusEl.textContent = 'Saved at ' + new Date().toLocaleTimeString();
        }).catch(function () {
            if (statusEl) statusEl.textContent = 'Autosave failed - check your connection.';
        });
    }

    function loadExisting(pageNumber) {
        var existing = window.__existingStudentAnnotations && window.__existingStudentAnnotations[pageNumber];
        if (existing) {
            fabricCanvas.loadFromJSON(existing, fabricCanvas.renderAll.bind(fabricCanvas));
        }
    }

    window.addEventListener('beforeunload', function () {
        if (fabricCanvas) saveNow();
    });
})();
