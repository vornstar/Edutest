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
    var container = canvasEl.closest('.script-pane') || canvasEl.parentElement;
    var pagination = null;
    var fabricCanvas = null;
    var studentStaticCanvas = null;
    var placingText = false;
    var lastRendered = null;
    // The page whose content is currently loaded into fabricCanvas - NOT
    // the same as pagination.getPage(), which by the time renderPage() is
    // invoked already reflects the page being navigated TO. Every save
    // must be explicitly tagged with this, or a page switch saves the
    // outgoing page's content under the new page's number instead.
    var currentPage = 1;

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (!lastRendered) return;
            if (fabricCanvas) PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, lastRendered.width, lastRendered.height);
            if (studentStaticCanvas) PdfAnnotateCore.fitCanvasToContainer(studentStaticCanvas, container, lastRendered.width, lastRendered.height);
        }, 150);
    });

    function deleteSelected() {
        if (!fabricCanvas) return;
        var active = fabricCanvas.getActiveObject();
        if (!active) return;
        fabricCanvas.remove(active);
        fabricCanvas.discardActiveObject();
        fabricCanvas.requestRenderAll();
    }

    // Registered once (not per-page, unlike wireTools()) - only deletes the
    // selected object when nothing is actively being typed into, so
    // backspacing mid-edit still just deletes a character as expected.
    document.addEventListener('keydown', function (e) {
        if (!fabricCanvas || (e.key !== 'Delete' && e.key !== 'Backspace')) return;
        var active = fabricCanvas.getActiveObject();
        if (!active || active.isEditing) return;
        e.preventDefault();
        deleteSelected();
    });

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
        // Save whatever's on the outgoing page before switching away from it -
        // must pass currentPage explicitly (see its declaration above).
        // Silent: no popup, just the small status text, so paging through a
        // multi-page script doesn't interrupt with an alert per page.
        if (fabricCanvas) {
            saveAnnotation(currentPage, true);
            fabricCanvas.dispose();
        }
        if (studentStaticCanvas) studentStaticCanvas.dispose();
        currentPage = pageNumber;
        placingText = false;
        canvasEl.style.cursor = '';

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            lastRendered = rendered;

            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: true });
            PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, rendered.width, rendered.height);
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, fabricCanvas.renderAll.bind(fabricCanvas));
            });
            fabricCanvas.freeDrawingBrush.width = 3;
            fabricCanvas.freeDrawingBrush.color = currentColor();
            fabricCanvas.on('mouse:down', function (opt) {
                // Stays armed after placing one text box, so the next click
                // starts another without re-clicking "Text" - but a click
                // that lands ON an existing box edits/selects it instead.
                if (!placingText || opt.target) return;
                var pointer = fabricCanvas.getPointer(opt.e);
                var text = new fabric.IText('Comment', {
                    left: pointer.x, top: pointer.y, fill: currentColor(), fontSize: 18,
                });
                fabricCanvas.add(text);
                fabricCanvas.setActiveObject(text);
                text.enterEditing();
            });

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
        PdfAnnotateCore.fitCanvasToContainer(studentStaticCanvas, container, width, height);

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
                    placingText = false;
                    canvasEl.style.cursor = '';
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 3;
                    fabricCanvas.freeDrawingBrush.color = currentColor();
                } else if (tool === 'highlighter') {
                    placingText = false;
                    canvasEl.style.cursor = '';
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 16;
                    fabricCanvas.freeDrawingBrush.color = hexToRgba(currentColor(), 0.35);
                } else if (tool === 'text') {
                    fabricCanvas.isDrawingMode = false;
                    placingText = true;
                    canvasEl.style.cursor = 'crosshair';
                } else if (tool === 'delete') {
                    deleteSelected();
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
            saveBtn.onclick = function () { saveAnnotation(currentPage, false); };
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

    /** @param {boolean} [silent] true for the automatic on-navigate/on-unload save - just updates the status text, no popup interrupting a multi-page marking session. */
    function saveAnnotation(page, silent) {
        if (!fabricCanvas) return;
        var pageNumber = page !== undefined ? page : currentPage;
        var statusEl = document.getElementById('annotation-save-status');
        if (statusEl) statusEl.textContent = 'Saving…';
        fetch('/assessment/teacher/marking/' + submissionId + '/annotation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page: pageNumber,
                fabric_json: fabricCanvas.toJSON(),
                csrf_token: csrfToken,
            }),
        }).then(function (res) {
            if (!res.ok) throw new Error('save failed');
            if (statusEl) statusEl.textContent = 'Saved at ' + new Date().toLocaleTimeString();
            if (!silent) alert('Annotations saved.');
        }).catch(function () {
            if (statusEl) statusEl.textContent = 'Save failed - check your connection.';
            if (!silent) alert('Failed to save annotations.');
        });
    }

    window.addEventListener('beforeunload', function () {
        if (fabricCanvas) saveAnnotation(currentPage, true);
    });
})();
