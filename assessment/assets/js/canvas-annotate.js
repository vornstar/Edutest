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

    var PLACEHOLDER_TEXT = 'Comment';
    var RENDER_SCALE = 1.8;

    var canvasEl = document.getElementById('annotation-canvas');
    var studentLayerEl = document.getElementById('annotation-student-layer');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var panel = document.querySelector('.marking-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var container = canvasEl.closest('.script-pane') || canvasEl.parentElement;
    var toolButtons = document.querySelectorAll('[data-tool]');
    var pageStorageKey = 'pdf-mark-page-' + submissionId;
    var pagination = null;
    var fabricCanvas = null;
    var studentStaticCanvas = null;
    var lastRendered = null;
    var placeholderText = null;
    // Persists across page turns, unlike fabricCanvas itself, which is
    // disposed and rebuilt fresh for every page - so the marker doesn't
    // have to reselect "Pen" every time they turn a page.
    var currentTool = 'pen';
    // Which stamp (Tick/Cross/SEEN/... or a custom one) is armed when
    // currentTool === 'stamp' - persists the same way currentTool does.
    var currentStampLabel = null;
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
            if (fabricCanvas) {
                PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, lastRendered.width, lastRendered.height);
                fabricCanvas.requestRenderAll();
            }
            if (studentStaticCanvas) {
                PdfAnnotateCore.fitCanvasToContainer(studentStaticCanvas, container, lastRendered.width, lastRendered.height);
                studentStaticCanvas.requestRenderAll();
            }
        }, 150);
    });

    // Some mobile browsers leave a canvas's on-screen pixels stale after
    // it's scrolled out of view and back (or the tab is backgrounded/
    // restored from cache) - the marking is there, it just isn't repainted
    // until something else forces the browser's hand. Force one ourselves
    // on the events known to trigger this.
    var forceRepaint = throttle(function () {
        if (fabricCanvas) fabricCanvas.requestRenderAll();
        if (studentStaticCanvas) studentStaticCanvas.requestRenderAll();
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

    function deleteSelected() {
        if (!fabricCanvas) return;
        var active = fabricCanvas.getActiveObject();
        if (!active) return;
        if (active === placeholderText) placeholderText = null;
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
        // Resume on the page the marker was last on (e.g. after a refresh)
        // rather than always restarting at page 1.
        var savedPage = parseInt(window.localStorage.getItem(pageStorageKey), 10);
        var startPage = savedPage >= 1 && savedPage <= pdfDoc.numPages ? savedPage : 1;
        pagination.setPage(startPage);
    }).catch(function (err) {
        console.error('Failed to render PDF for annotation', err);
    });

    function renderPage(pdfDoc, pageNumber) {
        // Save whatever's on the outgoing page before switching away from it -
        // must pass currentPage explicitly (see its declaration above).
        // Silent: no popup, just the small status text, so paging through a
        // multi-page script doesn't interrupt with an alert per page.
        if (fabricCanvas) {
            // No self-save here (unlike the text:editing:exited handler
            // below) - the explicit saveAnnotation(currentPage) right after
            // already captures the post-removal state.
            discardPlaceholder();
            saveAnnotation(currentPage, true);
            fabricCanvas.dispose();
        }
        if (studentStaticCanvas) studentStaticCanvas.dispose();
        currentPage = pageNumber;
        placeholderText = null;
        try { window.localStorage.setItem(pageStorageKey, String(pageNumber)); } catch (e) { /* storage unavailable - not fatal, just won't resume on refresh */ }

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, RENDER_SCALE).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            lastRendered = rendered;

            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: true });
            PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, rendered.width, rendered.height);
            applyTool(currentTool, currentStampLabel);
            fabricCanvas.on('mouse:down', function (opt) {
                if (opt.target) return; // clicking an existing object always just selects/edits it
                if (currentTool === 'text') {
                    // Stays armed after placing one text box, so the next
                    // click starts another without re-clicking "Text".
                    // No self-save needed here - the new box's own
                    // 'object:added' debounce-saves the post-discard state
                    // (see wireTools()).
                    discardPlaceholder();
                    var pointer = fabricCanvas.getPointer(opt.e);
                    var text = new fabric.IText(PLACEHOLDER_TEXT, {
                        left: pointer.x, top: pointer.y, fill: currentColor(), fontSize: 18,
                    });
                    placeholderText = text;
                    fabricCanvas.add(text);
                    fabricCanvas.setActiveObject(text);
                    text.enterEditing();
                    // Placeholder starts fully selected, so the very first
                    // keystroke replaces it instead of the marker having to
                    // clear it themselves first.
                    text.selectAll();
                } else if (currentTool === 'stamp' && currentStampLabel) {
                    // A stamp is fixed content, not something the marker
                    // types - place it and leave it selected (so it can be
                    // dragged into position), no editing/placeholder dance.
                    discardPlaceholder();
                    var stampPointer = fabricCanvas.getPointer(opt.e);
                    var stamp = new fabric.IText(currentStampLabel, {
                        left: stampPointer.x, top: stampPointer.y, fill: currentColor(), fontSize: 26, fontWeight: 'bold',
                    });
                    fabricCanvas.add(stamp);
                    fabricCanvas.setActiveObject(stamp);
                }
            });
            fabricCanvas.on('text:editing:exited', function (opt) {
                // Clicking away without typing anything leaves an empty/
                // still-placeholder box behind - remove it. Unlike the other
                // discardPlaceholder() call sites, nothing else is about to
                // save here, so trigger it explicitly.
                if (opt.target === placeholderText && discardPlaceholder()) {
                    saveAnnotation(currentPage, true);
                }
            });

            wireTools();

            // The background script/PDF page must finish painting before
            // the marker's own existing marks (and the student's read-only
            // reference layer) are loaded on top of it - doing both at once
            // (previously: fired in parallel, whichever finished last
            // "won") could leave the page's own image absent.
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, function () {
                    fabricCanvas.requestRenderAll();
                    loadOwnAnnotation(pageNumber);
                    renderStudentLayer(pageNumber, rendered.width, rendered.height);
                });
            });
        });
    }

    function isUnusedPlaceholder(textObj) {
        return !textObj || textObj.text === PLACEHOLDER_TEXT || textObj.text.trim() === '';
    }

    /**
     * Removes the tracked placeholder if it was never actually typed into.
     * @return {boolean} true if a box was actually removed.
     */
    function discardPlaceholder() {
        if (!placeholderText) return false;
        var removed = isUnusedPlaceholder(placeholderText) && !!fabricCanvas;
        if (removed) fabricCanvas.remove(placeholderText);
        placeholderText = null;
        return removed;
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
            studentStaticCanvas.loadFromJSON(PdfAnnotateCore.stripBackground(studentJson), function () {
                studentStaticCanvas.requestRenderAll();
            });
        }
    }

    function applyTool(tool, stampLabel) {
        currentTool = tool;
        if (tool === 'stamp') currentStampLabel = stampLabel;
        if (!fabricCanvas) return;
        canvasEl.style.cursor = (tool === 'text' || tool === 'stamp') ? 'crosshair' : '';
        if (tool === 'pen') {
            fabricCanvas.isDrawingMode = true;
            fabricCanvas.freeDrawingBrush.width = 3;
            fabricCanvas.freeDrawingBrush.color = currentColor();
        } else if (tool === 'highlighter') {
            fabricCanvas.isDrawingMode = true;
            fabricCanvas.freeDrawingBrush.width = 16;
            fabricCanvas.freeDrawingBrush.color = hexToRgba(currentColor(), 0.35);
        } else {
            fabricCanvas.isDrawingMode = false; // 'text' and 'stamp'
        }
        toolButtons.forEach(function (btn) {
            var isThisStamp = tool === 'stamp' && btn.dataset.tool === 'stamp' && btn.dataset.stamp === stampLabel;
            var isThisTool = tool !== 'stamp' && btn.dataset.tool === tool;
            btn.classList.toggle('is-active', isThisStamp || isThisTool);
        });
    }

    function wireTools() {
        toolButtons.forEach(function (btn) {
            var tool = btn.dataset.tool;
            if (tool === 'stamp') {
                btn.onclick = function () { applyTool('stamp', btn.dataset.stamp); };
                return;
            }
            if (tool !== 'pen' && tool !== 'highlighter' && tool !== 'text' && tool !== 'delete') return;
            btn.onclick = function () {
                if (tool === 'delete') {
                    deleteSelected();
                } else {
                    applyTool(tool);
                }
            };
        });

        var colorInput = document.querySelector('[data-tool="color"]');
        if (colorInput) {
            colorInput.oninput = function () {
                fabricCanvas.freeDrawingBrush.color = currentTool === 'highlighter' ? hexToRgba(colorInput.value, 0.35) : colorInput.value;
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
            fabricCanvas.loadFromJSON(PdfAnnotateCore.stripBackground(existing), function () {
                fabricCanvas.requestRenderAll();
            });
        }
    }

    /** @param {boolean} [silent] true for the automatic on-navigate/on-unload save - just updates the status text, no popup interrupting a multi-page marking session. */
    function saveAnnotation(page, silent) {
        if (!fabricCanvas) return;
        var pageNumber = page !== undefined ? page : currentPage;
        var statusEl = document.getElementById('annotation-save-status');
        if (statusEl) statusEl.textContent = 'Saving…';
        var json = PdfAnnotateCore.stripBackground(fabricCanvas.toJSON());
        // Keep the local cache in sync with what's actually saved - without
        // this, navigating back to this page later in the SAME session (no
        // full reload) would still be looking at whatever was here when the
        // page first loaded, not what was just marked.
        window.__existingAnnotations = window.__existingAnnotations || {};
        window.__existingAnnotations[pageNumber] = json;
        fetch('/assessment/teacher/marking/' + submissionId + '/annotation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page: pageNumber,
                fabric_json: json,
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
        if (fabricCanvas) {
            discardPlaceholder();
            saveAnnotation(currentPage, true);
        }
    });
})();
