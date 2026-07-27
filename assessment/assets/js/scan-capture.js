/**
 * Camera-first photo capture for the teacher's "photograph a physical
 * script" upload page. "Take a photo" launches the device camera directly
 * (via a hidden file input with the capture attribute) and each shot is
 * queued with an instant thumbnail the moment it's taken - no separate
 * step of saving photos elsewhere first. A gallery button is kept as a
 * fallback for picking already-taken photos (or for testing from a
 * desktop with no camera). The queued files are synced into the real
 * multi-file form input via DataTransfer just before submit.
 */
(function () {
    'use strict';

    var form = document.getElementById('scan-upload-form');
    if (!form) return;

    var cameraInput = document.getElementById('camera-input');
    var galleryInput = document.getElementById('gallery-input');
    var hiddenInput = document.getElementById('photos-hidden-input');
    var takePhotoBtn = document.getElementById('take-photo-btn');
    var galleryBtn = document.getElementById('add-from-gallery-btn');
    var queueList = document.getElementById('photo-queue');
    var queueStatus = document.getElementById('queue-status');
    var submitBtn = document.getElementById('submit-btn');

    var queued = []; // { file: File, url: string }

    function render() {
        queueList.innerHTML = '';
        queued.forEach(function (item, index) {
            var li = document.createElement('li');
            li.className = 'photo-queue-item';

            var img = document.createElement('img');
            img.src = item.url;
            img.alt = 'Page ' + (index + 1);
            li.appendChild(img);

            var label = document.createElement('span');
            label.textContent = 'Page ' + (index + 1);
            li.appendChild(label);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', function () { removeAt(index); });
            li.appendChild(removeBtn);

            queueList.appendChild(li);
        });

        queueStatus.textContent = queued.length
            ? queued.length + ' page' + (queued.length === 1 ? '' : 's') + ' ready to upload.'
            : 'No pages captured yet.';
        submitBtn.disabled = queued.length === 0;
        syncHiddenInput();
    }

    function addFiles(fileList) {
        Array.from(fileList).forEach(function (file) {
            if (file.type.indexOf('image/') !== 0) return;
            queued.push({ file: file, url: URL.createObjectURL(file) });
        });
        render();
    }

    function removeAt(index) {
        URL.revokeObjectURL(queued[index].url);
        queued.splice(index, 1);
        render();
    }

    function syncHiddenInput() {
        var dt = new DataTransfer();
        queued.forEach(function (item) { dt.items.add(item.file); });
        hiddenInput.files = dt.files;
    }

    takePhotoBtn.addEventListener('click', function () { cameraInput.click(); });
    galleryBtn.addEventListener('click', function () { galleryInput.click(); });

    cameraInput.addEventListener('change', function () {
        addFiles(cameraInput.files);
        cameraInput.value = '';
    });
    galleryInput.addEventListener('change', function () {
        addFiles(galleryInput.files);
        galleryInput.value = '';
    });

    form.addEventListener('submit', function () {
        syncHiddenInput();
    });

    render();
})();
