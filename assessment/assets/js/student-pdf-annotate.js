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
 *
 * Students can't delete what they've written - only "start over", which
 * begins a new version and leaves the old one intact in the DB for a
 * teacher to still see (see MarkingController's version picker). That's
 * enforced server-side (no delete endpoint exists for a student); there is
 * simply no delete tool offered here.
 */
(function () {
    'use strict';

    var STUDENT_COLOR = '#1d4ed8';
    var PLACEHOLDER_TEXT = 'Type here';

    var canvasEl = document.getElementById('pdf-answer-canvas');
    if (!canvasEl || typeof fabric === 'undefined' || !window.PdfAnnotateCore) return;

    var panel = document.querySelector('.test-panel');
    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var statusEl = document.getElementById('pdf-answer-status');
    var container = canvasEl.closest('.pdf-pane') || canvasEl.parentElement;
    var toolButtons = document.querySelectorAll('[data-answer-tool]');
    var pageStorageKey = 'pdf-answer-page-' + submissionId;
    var pagination = null;
    var fabricCanvas = null;
    var saveTimer = null;
    var lastRendered = null;
    // Persists across page turns, unlike fabricCanvas itself, which is
    // disposed and rebuilt fresh for every page - so the student doesn't
    // have to reselect "Pen" every time they turn a page.
    var currentTool = 'text';
    var placeholderText = null;
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
            if (fabricCanvas && lastRendered) {
                PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, lastRendered.width, lastRendered.height);
                fabricCanvas.requestRenderAll();
            }
        }, 150);
    });

    // Some mobile browsers leave a canvas's on-screen pixels stale after
    // it's scrolled out of view and back (or the tab is backgrounded/
    // restored from cache) - the drawing is there, it just isn't
    // repainted until something else forces the browser's hand. Force one
    // ourselves on the events known to trigger this, rather than leaving
    // it to look "blank until you write on it again".
    var forceRepaint = throttle(function () {
        if (fabricCanvas) fabricCanvas.requestRenderAll();
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

    PdfAnnotateCore.loadDocument(canvasEl.dataset.pdfSrc).then(function (pdfDoc) {
        pagination = PdfAnnotateCore.wirePagination(
            document.querySelector('.pdf-answer-tools'),
            pdfDoc.numPages,
            function (pageNumber) { renderPage(pdfDoc, pageNumber); }
        );
        // Resume on the page the student was last on (e.g. after a
        // refresh) rather than always restarting at page 1.
        var savedPage = parseInt(window.localStorage.getItem(pageStorageKey), 10);
        var startPage = savedPage >= 1 && savedPage <= pdfDoc.numPages ? savedPage : 1;
        pagination.setPage(startPage);
    }).catch(function (err) {
        console.error('Failed to load PDF for answering', err);
        if (statusEl) statusEl.textContent = 'Could not load the PDF.';
    });

    function renderPage(pdfDoc, pageNumber) {
        // Save whatever's on the outgoing page before switching away from it -
        // must pass currentPage explicitly (see its declaration above).
        clearTimeout(saveTimer);
        if (fabricCanvas) {
            // No self-save here (unlike the text:editing:exited handler
            // below) - the explicit saveNow(currentPage) right after already
            // captures the post-removal state, and scheduling a SECOND,
            // debounced save here would fire ~800ms later against whatever
            // canvas is current BY THEN (the next page's), mistagged with
            // this page's number.
            discardPlaceholder();
            saveNow(currentPage);
            fabricCanvas.dispose();
        }
        currentPage = pageNumber;
        placeholderText = null;
        try { window.localStorage.setItem(pageStorageKey, String(pageNumber)); } catch (e) { /* storage unavailable - not fatal, just won't resume on refresh */ }

        PdfAnnotateCore.renderPageToImage(pdfDoc, pageNumber, 1.4).then(function (rendered) {
            canvasEl.width = rendered.width;
            canvasEl.height = rendered.height;
            lastRendered = rendered;

            fabricCanvas = new fabric.Canvas(canvasEl, { isDrawingMode: false });
            PdfAnnotateCore.fitCanvasToContainer(fabricCanvas, container, rendered.width, rendered.height);
            fabricCanvas.freeDrawingBrush.width = 2;
            fabricCanvas.freeDrawingBrush.color = STUDENT_COLOR;
            applyTool(currentTool);

            fabricCanvas.on('object:added', debounceSave);
            fabricCanvas.on('object:modified', debounceSave);
            fabricCanvas.on('mouse:down', function (opt) {
                // Stays armed after placing one text box, so the next click
                // starts another without having to re-select the tool - but
                // a click that lands ON an existing box (opt.target set)
                // should edit/select it, not stack a new one on top.
                if (currentTool !== 'text' || opt.target) return;
                // No self-save needed here - the new box's own 'object:added'
                // (fired just below) debounce-saves the post-discard state.
                discardPlaceholder();
                var pointer = fabricCanvas.getPointer(opt.e);
                var text = new fabric.IText(PLACEHOLDER_TEXT, {
                    left: pointer.x, top: pointer.y, fill: STUDENT_COLOR, fontSize: 16,
                });
                placeholderText = text;
                fabricCanvas.add(text);
                fabricCanvas.setActiveObject(text);
                text.enterEditing();
                // Placeholder starts fully selected, so the very first
                // keystroke replaces it instead of the student having to
                // clear it themselves first.
                text.selectAll();
            });
            fabricCanvas.on('text:editing:exited', function (opt) {
                // Clicking away without typing anything leaves an empty/
                // still-placeholder box behind - remove it rather than
                // littering the page with useless text boxes. Unlike the
                // other discardPlaceholder() call sites, nothing else is
                // about to save here, so trigger it explicitly.
                if (opt.target === placeholderText && discardPlaceholder()) {
                    debounceSave();
                }
            });

            // The background PDF page must finish painting before the
            // student's own existing marks are loaded on top of it - doing
            // both at once (previously: fired in parallel, whichever
            // finished last "won") could leave the page's own image absent
            // if loadFromJSON's render happened to land first.
            fabric.Image.fromURL(rendered.dataUrl, function (img) {
                fabricCanvas.setBackgroundImage(img, function () {
                    fabricCanvas.requestRenderAll();
                    loadExisting(pageNumber);
                });
            });
        });
    }

    function isUnusedPlaceholder(textObj) {
        return !textObj || textObj.text === PLACEHOLDER_TEXT || textObj.text.trim() === '';
    }

    /**
     * Removes the tracked placeholder if it was never actually typed into.
     * @return {boolean} true if a box was actually removed - callers where
     * nothing else is about to save (see the text:editing:exited handler)
     * use this to know whether they need to trigger one themselves.
     */
    function discardPlaceholder() {
        if (!placeholderText) return false;
        var removed = isUnusedPlaceholder(placeholderText) && !!fabricCanvas;
        if (removed) fabricCanvas.remove(placeholderText);
        placeholderText = null;
        return removed;
    }

    function applyTool(tool) {
        currentTool = tool;
        if (!fabricCanvas) return;
        fabricCanvas.isDrawingMode = tool === 'pen';
        canvasEl.style.cursor = tool === 'text' ? 'crosshair' : '';
        toolButtons.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.dataset.answerTool === tool);
        });
    }

    toolButtons.forEach(function (btn) {
        var tool = btn.dataset.answerTool;
        if (tool !== 'pen' && tool !== 'text') return;
        btn.addEventListener('click', function () { applyTool(tool); });
    });

    var startOverBtn = document.querySelector('[data-answer-tool="start-over"]');
    if (startOverBtn) {
        startOverBtn.addEventListener('click', startOver);
    }

    function startOver() {
        if (!fabricCanvas) return;
        if (!window.confirm('Start over? What you’ve written stays saved and your teacher can still see it, but the page will clear so you can begin again.')) {
            return;
        }
        clearTimeout(saveTimer);
        placeholderText = null;
        fetch('/assessment/student/submissions/' + submissionId + '/annotation/start-over', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken }),
        }).then(function (res) {
            if (!res.ok) throw new Error('start-over failed');
            return res.json();
        }).then(function () {
            // The new version is blank everywhere - drop the cached
            // pre-start-over content so navigating to any other page
            // doesn't reload it from under the student.
            window.__existingStudentAnnotations = {};
            fabricCanvas.getObjects().slice().forEach(function (obj) { fabricCanvas.remove(obj); });
            fabricCanvas.requestRenderAll();
            if (statusEl) statusEl.textContent = 'Started over - this page is blank again.';
        }).catch(function () {
            if (statusEl) statusEl.textContent = 'Could not start over - check your connection and try again.';
        });
    }

    function debounceSave() {
        if (statusEl) statusEl.textContent = 'Saving…';
        clearTimeout(saveTimer);
        var pageToSave = currentPage;
        saveTimer = setTimeout(function () { saveNow(pageToSave); }, 800);
    }

    function saveNow(page) {
        if (!fabricCanvas) return;
        var pageNumber = page !== undefined ? page : currentPage;
        var json = fabricCanvas.toJSON();
        // Keep the local cache in sync with what's actually saved - without
        // this, navigating back to this page later in the SAME session (no
        // full reload) would still be looking at whatever was here when the
        // page first loaded, not what was just written.
        window.__existingStudentAnnotations = window.__existingStudentAnnotations || {};
        window.__existingStudentAnnotations[pageNumber] = json;
        fetch('/assessment/student/submissions/' + submissionId + '/annotation', {
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
        }).catch(function () {
            if (statusEl) statusEl.textContent = 'Autosave failed - check your connection.';
        });
    }

    function loadExisting(pageNumber) {
        var existing = window.__existingStudentAnnotations && window.__existingStudentAnnotations[pageNumber];
        if (existing) {
            fabricCanvas.loadFromJSON(existing, function () {
                fabricCanvas.requestRenderAll();
            });
        }
    }

    window.addEventListener('beforeunload', function () {
        if (fabricCanvas) {
            discardPlaceholder();
            saveNow();
        }
    });
})();
