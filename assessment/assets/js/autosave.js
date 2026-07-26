/**
 * Autosaves digital/PDF-booklet answers as the student types, debounced
 * per-question so we don't flood the server on every keystroke.
 */
(function () {
    'use strict';

    var panel = document.querySelector('.test-panel');
    if (!panel) return;

    var submissionId = panel.dataset.submissionId;
    var csrfToken = panel.dataset.csrf;
    var statusEl = document.getElementById('autosave-status');
    var timers = {};

    function debounceSave(questionId, value) {
        clearTimeout(timers[questionId]);
        timers[questionId] = setTimeout(function () {
            save(questionId, value);
        }, 800);
    }

    function save(questionId, value) {
        if (statusEl) statusEl.textContent = 'Saving…';
        fetch('/assessment/student/submissions/' + submissionId + '/autosave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                question_id: questionId,
                answer_text: value,
                csrf_token: csrfToken,
            }),
        })
            .then(function (res) {
                if (!res.ok) throw new Error('save failed');
                return res.json();
            })
            .then(function () {
                if (statusEl) statusEl.textContent = 'Saved at ' + new Date().toLocaleTimeString();
            })
            .catch(function () {
                if (statusEl) statusEl.textContent = 'Autosave failed - check your connection.';
            });
    }

    panel.querySelectorAll('.question-block').forEach(function (block) {
        var questionId = block.dataset.questionId;

        block.querySelectorAll('.answer-input').forEach(function (input) {
            var eventName = input.type === 'radio' ? 'change' : 'input';
            input.addEventListener(eventName, function () {
                var value = input.type === 'radio'
                    ? block.querySelector('.answer-input:checked').value
                    : input.value;
                debounceSave(questionId, value);
            });
        });
    });
})();
