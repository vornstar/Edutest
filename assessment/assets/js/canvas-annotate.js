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
    // Matches the built-in Tick stamp's label exactly (see partials/stamp_toolbar.php) -
    // used to count placed ticks per page for the marks-per-page helper below.
    var TICK_LABEL = '✓';

    var canvasEl = document.getElementById('annotation-canvas');
    var studentLayerEl = document.getElementById('annotation-student-layer');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;
    // Must match every other surface that renders this same PDF (student
    // typing, review, preview) - see PdfAnnotateCore's own comment on this
    // constant for why a mismatch here silently misplaces annotations.
    var RENDER_SCALE = PdfAnnotateCore.RENDER_SCALE;

    var panel = document.querySelector('.marking-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var container = canvasEl.closest('.script-pane') || canvasEl.parentElement;
    var toolButtons = document.querySelectorAll('[data-tool]');
    var pageStorageKey = 'pdf-mark-page-' + submissionId;
    var marksStorageKey = 'pdf-mark-marks-' + submissionId;
    var pagination = null;
    var fabricCanvas = null;
    var studentStaticCanvas = null;
    var lastRendered = null;
    var placeholderText = null;
    // Set once by setupPageMarks() below, only on papers with a #page-marks-list
    // in the mark-pane (see mark_submission.php/moderation_review.php's "Overall
    // score" fieldset) - null everywhere else (e.g. per-question papers).
    var pageMarksApi = null;
    // The ellipse currently being dragged out by the Circle tool, and where the
    // drag started - both null except mid-drag, reset per page like placeholderText.
    var drawingCircle = null;
    var circleStartPointer = null;
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

    /**
     * Keyboard shortcuts for both stamps and tools (see partials/stamp_toolbar.php's
     * "Keyboard shortcuts" dropdown - shortcuts are per-marker, and cover the
     * Pen/Highlighter/Text/Circle/Delete buttons as well as built-in and custom stamps).
     * Just clicks whichever button carries the matching data-shortcut - wireTools()
     * already gives every tool/stamp button the right onclick, so this needs no
     * per-tool special-casing. Registered once, like the delete-key handler above -
     * queries the DOM fresh on every keypress rather than caching button references,
     * so it keeps working across renderPage()'s per-page rebuilds.
     */
    document.addEventListener('keydown', function (e) {
        if (!fabricCanvas || e.ctrlKey || e.metaKey || e.altKey) return;
        var activeEl = document.activeElement;
        var tag = activeEl ? activeEl.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || (activeEl && activeEl.isContentEditable)) return;
        var activeObj = fabricCanvas.getActiveObject();
        if (activeObj && activeObj.isEditing) return;

        var btn = document.querySelector('[data-shortcut="' + e.key.toLowerCase() + '"]');
        if (btn) {
            e.preventDefault();
            btn.click();
        }
    });

    /**
     * Arrow-key navigation while marking/moderating: Up/Down scroll the
     * browser window (there's often more script above/below the toolbar
     * than fits on screen at once), Left/Right move to the previous/next
     * PDF page - same as clicking the Prev/Next buttons. Fixed, not
     * user-configurable like the shortcuts above (arrow keys aren't offered
     * there anyway). Skipped while typing, editing a text object, or when a
     * <select> has focus (its own Up/Down already changes the selection -
     * e.g. the "Student's attempt" version picker).
     */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        var activeEl = document.activeElement;
        var tag = activeEl ? activeEl.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (activeEl && activeEl.isContentEditable)) return;
        var activeObj = fabricCanvas ? fabricCanvas.getActiveObject() : null;
        if (activeObj && activeObj.isEditing) return;

        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            window.scrollBy({ top: e.key === 'ArrowUp' ? -120 : 120, behavior: 'smooth' });
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            var pageBtn = document.querySelector(e.key === 'ArrowLeft' ? '[data-page-prev]' : '[data-page-next]');
            if (pageBtn && !pageBtn.disabled) {
                e.preventDefault();
                pageBtn.click();
            }
        }
    });

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.annotation-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        setupPageMarks(pdfDoc.numPages);
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
        drawingCircle = null;
        circleStartPointer = null;
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
                        // left/top otherwise anchor the box's top-left corner,
                        // not its centre - without this a stamp visibly lands
                        // below and to the right of where the marker clicked.
                        originX: 'center', originY: 'center',
                    });
                    fabricCanvas.add(stamp);
                    fabricCanvas.setActiveObject(stamp);
                } else if (currentTool === 'circle') {
                    // Drag to size it around whatever's being circled; a plain
                    // click with no real drag (see mouse:up) places a sensible
                    // default size instead, so it still works as a one-click stamp.
                    var circlePointer = fabricCanvas.getPointer(opt.e);
                    circleStartPointer = circlePointer;
                    drawingCircle = new fabric.Ellipse({
                        left: circlePointer.x, top: circlePointer.y,
                        rx: 0, ry: 0,
                        originX: 'center', originY: 'center',
                        fill: 'transparent',
                        stroke: currentColor(),
                        strokeWidth: 3,
                        selectable: false,
                        evented: false,
                    });
                    fabricCanvas.add(drawingCircle);
                }
            });
            fabricCanvas.on('mouse:move', function (opt) {
                if (!drawingCircle || !circleStartPointer) return;
                var pointer = fabricCanvas.getPointer(opt.e);
                drawingCircle.set({
                    left: (pointer.x + circleStartPointer.x) / 2,
                    top: (pointer.y + circleStartPointer.y) / 2,
                    rx: Math.abs(pointer.x - circleStartPointer.x) / 2,
                    ry: Math.abs(pointer.y - circleStartPointer.y) / 2,
                });
                drawingCircle.setCoords();
                fabricCanvas.requestRenderAll();
            });
            fabricCanvas.on('mouse:up', function () {
                if (!drawingCircle) return;
                if (drawingCircle.rx < 8 && drawingCircle.ry < 8) {
                    drawingCircle.set({ rx: 30, ry: 20 });
                    drawingCircle.setCoords();
                }
                drawingCircle.set({ selectable: true, evented: true });
                fabricCanvas.setActiveObject(drawingCircle);
                fabricCanvas.requestRenderAll();
                drawingCircle = null;
                circleStartPointer = null;
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
            // Keeps the "N ticks on this page" hint (see setupPageMarks()) live as
            // stamps are placed/removed/dragged off - a no-op when pageMarksApi is
            // null (per-question papers have no marks-per-page list to refresh).
            fabricCanvas.on('object:added', refreshTickHintsForCurrentPage);
            fabricCanvas.on('object:removed', refreshTickHintsForCurrentPage);

            wireTools();

            // The background script/PDF page must finish painting before
            // the marker's own existing marks (and the student's read-only
            // reference layer) are loaded on top of it - doing both at once
            // (previously: fired in parallel, whichever finished last
            // "won") could leave the page's own image absent.
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, function () {
                    fabricCanvas.requestRenderAll();
                    loadOwnAnnotation(pageNumber, img);
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
        canvasEl.style.cursor = (tool === 'text' || tool === 'stamp' || tool === 'circle') ? 'crosshair' : '';
        if (tool === 'pen') {
            fabricCanvas.isDrawingMode = true;
            fabricCanvas.freeDrawingBrush.width = 3;
            fabricCanvas.freeDrawingBrush.color = currentColor();
        } else if (tool === 'highlighter') {
            fabricCanvas.isDrawingMode = true;
            fabricCanvas.freeDrawingBrush.width = 16;
            fabricCanvas.freeDrawingBrush.color = hexToRgba(currentColor(), 0.35);
        } else {
            fabricCanvas.isDrawingMode = false; // 'text', 'stamp', and 'circle' (its own drag-to-size handled via mouse:down/move/up, not the free-drawing brush)
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
            if (tool !== 'pen' && tool !== 'highlighter' && tool !== 'text' && tool !== 'circle' && tool !== 'delete') return;
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

    /**
     * How many Tick stamps are on a given page. For the page currently on
     * screen this reads the live canvas; for any other page it reads
     * window.__existingAnnotations, which renderPage() keeps in sync with
     * whatever was last saved (see saveAnnotation() below) - so it's still
     * accurate for a page the marker has navigated away from, without
     * needing to reload it.
     */
    function countTicksOnPage(pageNumber) {
        var objects;
        if (pageNumber === currentPage && fabricCanvas) {
            objects = fabricCanvas.getObjects();
        } else {
            var existing = window.__existingAnnotations && window.__existingAnnotations[pageNumber];
            objects = existing && existing.objects;
        }
        if (!objects) return 0;
        var count = 0;
        objects.forEach(function (o) { if (o.text === TICK_LABEL) count++; });
        return count;
    }

    function refreshTickHintsForCurrentPage() {
        if (pageMarksApi) pageMarksApi.refreshTickHints();
    }

    /**
     * Builds a "mark for this page" input per PDF page inside #page-marks-list
     * (only present on whole-paper/no-question papers - see the "Overall
     * score" fieldset in mark_submission.php/moderation_review.php), summing
     * them live into the #overall-score-input total. Entered marks persist
     * to localStorage (like the current-page number already does) so a
     * refresh mid-marking doesn't lose them - nothing is sent to the server
     * until the marker actually submits the form, same as any other field.
     */
    function setupPageMarks(numPages) {
        var listEl = document.getElementById('page-marks-list');
        var totalInput = document.getElementById('overall-score-input');
        if (!listEl || !totalInput) return;

        var saved = {};
        try { saved = JSON.parse(window.localStorage.getItem(marksStorageKey) || '{}'); } catch (e) { saved = {}; }

        var rows = {};
        listEl.innerHTML = '';
        for (var p = 1; p <= numPages; p++) {
            (function (pageNumber) {
                var row = document.createElement('div');
                row.className = 'page-mark-row';

                var label = document.createElement('span');
                label.className = 'page-mark-label';
                label.textContent = 'P' + pageNumber;
                label.title = 'Page ' + pageNumber;
                row.appendChild(label);

                var input = document.createElement('input');
                input.type = 'number';
                input.step = '0.5';
                input.min = '0';
                input.className = 'page-mark-input';
                if (saved[pageNumber] !== undefined) input.value = saved[pageNumber];
                input.addEventListener('input', recalculate);
                row.appendChild(input);

                // Doubles as the "use tick count" action - a separate button
                // for that plus a text hint was too wide for the mark-pane's
                // narrow column, so a single tappable badge does both.
                var tickBtn = document.createElement('button');
                tickBtn.type = 'button';
                tickBtn.className = 'page-tick-badge';
                tickBtn.title = "Tap to use this page's tick count as its mark";
                tickBtn.onclick = function () {
                    input.value = countTicksOnPage(pageNumber);
                    recalculate();
                };
                row.appendChild(tickBtn);

                listEl.appendChild(row);
                rows[pageNumber] = { input: input, tickBtn: tickBtn };
            })(p);
        }

        function recalculate() {
            var total = 0;
            var hasAny = false;
            var toSave = {};
            Object.keys(rows).forEach(function (pageNumber) {
                var raw = rows[pageNumber].input.value;
                var value = parseFloat(raw);
                if (raw !== '' && !isNaN(value)) {
                    total += value;
                    hasAny = true;
                    toSave[pageNumber] = raw;
                }
            });
            totalInput.value = hasAny ? String(total) : '';
            try { window.localStorage.setItem(marksStorageKey, JSON.stringify(toSave)); } catch (e) { /* storage unavailable - not fatal, marks just won't survive a refresh */ }
        }

        function refreshTickHints() {
            Object.keys(rows).forEach(function (pageNumber) {
                var count = countTicksOnPage(parseInt(pageNumber, 10));
                rows[pageNumber].tickBtn.textContent = count + '✓';
            });
        }

        recalculate();
        refreshTickHints();
        pageMarksApi = { refreshTickHints: refreshTickHints };
    }

    /**
     * @param {fabric.Image} img the already-loaded page image, so it can be
     * re-applied as the background after loadFromJSON() - which resets
     * backgroundImage to whatever's in the JSON (nothing, once
     * stripBackground() has been applied at save time - see
     * saveAnnotation()), unconditionally clearing the real one that's
     * already showing, regardless of whether the loaded JSON itself
     * carries one.
     */
    function loadOwnAnnotation(pageNumber, img) {
        var existing = window.__existingAnnotations && window.__existingAnnotations[pageNumber];
        if (existing) {
            fabricCanvas.loadFromJSON(existing, function () {
                fabricCanvas.setBackgroundImage(img, function () {
                    fabricCanvas.requestRenderAll();
                    refreshTickHintsForCurrentPage();
                });
            });
        } else {
            // Nothing to load (blank page so far) - still refresh so a page
            // with a since-cleared tick count shows 0, not last page's stale count.
            refreshTickHintsForCurrentPage();
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
