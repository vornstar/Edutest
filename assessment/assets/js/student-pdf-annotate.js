/**
 * Lets a student type/write directly onto the exam paper PDF itself,
 * rather than (or alongside) the separate typed answer booklet. Saved as
 * Fabric.js vector JSON per page via the Annotation model, keyed by the
 * student's own id - the exact same data a teacher's marking view then
 * overlays read-only on top of the same PDF, so they see precisely what
 * the student left on the page.
 *
 * Colour is fixed (not student-selectable) - a consistent colour keeps
 * every student's work visually consistent for marking, and avoids
 * someone writing in white-on-white to hide an answer.
 */
(function () {
    'use strict';

    var STUDENT_COLOR = '#1d4ed8';

    var canvasEl = document.getElementById('pdf-answer-canvas');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var panel = document.querySelector('.test-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var statusEl = document.getElementById('pdf-answer-status');
    var container = canvasEl.closest('.pdf-pane') || canvasEl.parentElement;
    var pagination = null;
    var fabricCanvas = null;
    var saveTimer = null;
    var placingText = false;
    var lastRendered = null;

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (fabricCanvas && lastRendered) {
                PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, lastRendered.width, lastRendered.height);
            }
        }, 150);
    });

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
        canvasEl.style.cursor = 'crosshair';

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            lastRendered = rendered;

            // Text is the default/primary way to answer a PDF exam paper -
            // ready to click-and-type immediately, no need to pick a tool first.
            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: false });
            placingText = true;
            PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, rendered.width, rendered.height);
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, fabricCanvas.renderAll.bind(fabricCanvas));
            });
            fabricCanvas.freeDrawingBrush.width = 2;
            fabricCanvas.freeDrawingBrush.color = STUDENT_COLOR;

            fabricCanvas.on('object:added', debounceSave);
            fabricCanvas.on('object:modified', debounceSave);
            fabricCanvas.on('object:removed', debounceSave);
            fabricCanvas.on('mouse:down', function (opt) {
                // Stays armed after placing one text box, so the next click
                // starts another without having to re-select the tool - but
                // a click that lands ON an existing box (opt.target set)
                // should edit/select it, not stack a new one on top.
                if (!placingText || opt.target) return;
                var pointer = fabricCanvas.getPointer(opt.e);
                var text = new fabric.IText('Type here', {
                    left: pointer.x, top: pointer.y, fill: STUDENT_COLOR, fontSize: 16,
                });
                fabricCanvas.add(text);
                fabricCanvas.setActiveObject(text);
                text.enterEditing();
            });

            loadExisting(pageNumber);
        });
    }

    document.querySelectorAll('[data-answer-tool]').forEach(function (btn) {
        var tool = btn.dataset.answerTool;
        btn.addEventListener('click', function () {
            if (!fabricCanvas) return;
            if (tool === 'pen') {
                placingText = false;
                canvasEl.style.cursor = '';
                fabricCanvas.isDrawingMode = true;
                fabricCanvas.freeDrawingBrush.width = 2;
                fabricCanvas.freeDrawingBrush.color = STUDENT_COLOR;
            } else if (tool === 'text') {
                fabricCanvas.isDrawingMode = false;
                placingText = true;
                canvasEl.style.cursor = 'crosshair';
            } else if (tool === 'delete') {
                deleteSelected();
            }
        });
    });

    function deleteSelected() {
        if (!fabricCanvas) return;
        var active = fabricCanvas.getActiveObject();
        if (!active) return;
        fabricCanvas.remove(active);
        fabricCanvas.discardActiveObject();
        fabricCanvas.requestRenderAll();
    }

    // Delete/Backspace removes the selected stroke/text box - but only when
    // nothing is actively being typed into, so backspacing while editing
    // text still just deletes a character as expected.
    document.addEventListener('keydown', function (e) {
        if (!fabricCanvas || (e.key !== 'Delete' && e.key !== 'Backspace')) return;
        var active = fabricCanvas.getActiveObject();
        if (!active || active.isEditing) return;
        e.preventDefault();
        deleteSelected();
    });

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
