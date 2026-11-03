document.addEventListener('DOMContentLoaded', function () {
    var preset = document.getElementById('avbk-bank-preset');
    function updateCustomLayoutVisibility() {
        if (!preset) return;
        document.querySelectorAll('.avbk-custom-layout').forEach(function (row) {
            row.style.display = preset.value === 'custom' ? '' : 'none';
        });
    }
    if (preset) {
        preset.addEventListener('change', updateCustomLayoutVisibility);
        updateCustomLayoutVisibility();
    }

    document.querySelectorAll('.avbk-dropzone').forEach(function (dropzone) {
        var fileInput = dropzone.querySelector('input[type="file"]');
        var text = dropzone.querySelector('.avbk-dropzone-text');
        if (!fileInput || !text) return;

        var defaultText = text.textContent;

        function showFileName() {
            var files = fileInput.files;
            text.textContent = files && files.length ? (files.length > 1 ? files.length + ' bestanden: ' : '') + Array.from(files).map(function (file) { return file.name; }).join(', ') : defaultText;
            var error = '';
            if (files && fileInput.dataset.maxFiles && files.length > Number(fileInput.dataset.maxFiles)) error = 'Kies maximaal ' + fileInput.dataset.maxFiles + ' bestanden per upload.';
            if (files && fileInput.dataset.maxBytes && Array.from(files).reduce(function (sum, file) { return sum + file.size; }, 0) > Number(fileInput.dataset.maxBytes)) error = 'Kies samen maximaal 20 MB per upload.';
            fileInput.setCustomValidity(error);
        }

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.add('avbk-dropzone-dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function () {
                dropzone.classList.remove('avbk-dropzone-dragover');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            e.preventDefault();
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files[0]) {
                fileInput.files = files;
                showFileName();
            }
        });

        fileInput.addEventListener('change', showFileName);
    });
});
