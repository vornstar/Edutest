/**
 * Split-screen marking canvas: renders page 1 of the student's PDF/scan
 * with PDF.js, then layers a Fabric.js canvas on top for freehand ink,
 * highlighter strokes, and text-box annotations. The vector overlay is
 * saved as JSON (not flattened into the PDF) so it can be re-edited later.
 */
(function () {
    'use strict';

    var canvasEl = document.getElementById('annotation-canvas');
    if (!canvasEl || typeof fabric === 'undefined') return;

    var panel = document.querySelector('.marking-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var currentPage = 1;

    var pdfjsScript = document.createElement('script');
    pdfjsScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    pdfjsScript.onload = initViewer;
    document.head.appendChild(pdfjsScript);

    function initViewer() {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        window.pdfjsLib.getDocument(canvasEl.dataset.pdfSrc).promise.then(function (pdf) {
            return pdf.getPage(currentPage);
        }).then(function (page) {
            var viewport = page.getViewport({ scale: 1.4 });
            canvasEl.width = viewport.width;
            canvasEl.height = viewport.height;

            var renderCanvas = document.createElement('canvas');
            renderCanvas.width = viewport.width;
            renderCanvas.height = viewport.height;
            var ctx = renderCanvas.getContext('2d');

            page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
                var fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: true });
                fabric.Image.fromURL(renderCanvas.toDataURL(), function (img) {
                    fabricCanvas.setBackgroundImage(img, fabricCanvas.renderAll.bind(fabricCanvas));
                });
                fabricCanvas.freeDrawingBrush.width = 3;
                fabricCanvas.freeDrawingBrush.color = '#e11d48';

                wireTools(fabricCanvas);
                loadExistingAnnotation(fabricCanvas);
            });
        }).catch(function (err) {
            console.error('Failed to render PDF for annotation', err);
        });
    }

    function wireTools(fabricCanvas) {
        document.querySelectorAll('[data-tool]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tool = btn.dataset.tool;
                if (tool === 'pen') {
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 3;
                    fabricCanvas.freeDrawingBrush.color = currentColor(fabricCanvas);
                } else if (tool === 'highlighter') {
                    fabricCanvas.isDrawingMode = true;
                    fabricCanvas.freeDrawingBrush.width = 16;
                    fabricCanvas.freeDrawingBrush.color = hexToRgba(currentColor(fabricCanvas), 0.35);
                } else if (tool === 'text') {
                    fabricCanvas.isDrawingMode = false;
                    var text = new fabric.IText('Comment', {
                        left: 40, top: 40, fill: currentColor(fabricCanvas), fontSize: 18,
                    });
                    fabricCanvas.add(text);
                }
            });
        });

        var colorInput = document.querySelector('[data-tool="color"]');
        if (colorInput) {
            colorInput.addEventListener('input', function () {
                fabricCanvas.freeDrawingBrush.color = colorInput.value;
            });
        }

        var saveBtn = document.getElementById('save-annotation');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveAnnotation(fabricCanvas);
            });
        }
    }

    function currentColor(fabricCanvas) {
        var input = document.querySelector('[data-tool="color"]');
        return input ? input.value : '#e11d48';
    }

    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function loadExistingAnnotation(fabricCanvas) {
        var existing = window.__existingAnnotations && window.__existingAnnotations[currentPage];
        if (existing) {
            fabricCanvas.loadFromJSON(existing, fabricCanvas.renderAll.bind(fabricCanvas));
        }
    }

    function saveAnnotation(fabricCanvas) {
        fetch('/assessment/teacher/marking/' + submissionId + '/annotation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page: currentPage,
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
