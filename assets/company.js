document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.avbk-company form').forEach(function (form) {
        var operation = form.querySelector('input[name="operation"]');
        if (!operation || operation.value !== 'export' || !window.fetch) return;
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var button = form.querySelector('button[type="submit"]');
            var status = form.querySelector('[role="status"]');
            var label = button.textContent;
            button.disabled = true;
            button.textContent = 'Werkblad wordt bijgewerkt…';
            if (status) status.textContent = '';
            var problem = 'Bijwerken of downloaden is niet gelukt. Ververs de pagina en controleer je sessie en het gekozen bestand.';
            try {
                // A named action input shadows the form.action DOM property.
                var response = await fetch(form.getAttribute('action'), {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-AVBK-Download': '1'}});
                if (!response.ok || !(response.headers.get('Content-Type') || '').includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
                    if ((response.headers.get('Content-Type') || '').includes('application/json')) {
                        var failure = await response.json();
                        if (failure.data && typeof failure.data.message === 'string') problem = failure.data.message;
                    }
                    throw new Error(problem);
                }
                var blob = await response.blob();
                var disposition = response.headers.get('Content-Disposition') || '';
                var match = disposition.match(/filename="([^"]+)"/);
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = match ? match[1] : 'BTW-werkmap.xlsx';
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
                var target = new URL(form.querySelector('input[name="return_to"]').value, window.location.href);
                if (target.origin !== window.location.origin) throw new Error('Ververs de pagina om het opgeslagen resultaat te bekijken.');
                ['company_id', 'book_year', 'quarter'].forEach(function (name) {
                    target.searchParams.set(name, form.querySelector('input[name="' + name + '"]').value);
                });
                target.hash = 'avbk-company-result';
                window.location.assign(target.href);
            } catch (error) {
                if (status) status.textContent = problem;
                button.disabled = false;
                button.textContent = label;
            }
        });
    });

    // Delegate so newly rendered rows keep working without a page reload.
    document.addEventListener('submit', async function (event) {
        var form = event.target;
        if (!window.fetch || !form.matches('.avbk-company-quick-review')) return;
        event.preventDefault();
        var section = form.closest('#avbk-company-result');
        if (section.dataset.reviewBusy === '1') return;
        section.dataset.reviewBusy = '1';
        var row = form.closest('tr');
        var anchor = row.nextElementSibling || row;
        var status = form.querySelector('[role="status"]');
        var button = form.querySelector('button[type="submit"]');
        var label = button.textContent;
        var buttons = Array.from(section.querySelectorAll('button')).map(function (item) { return [item, item.disabled]; });
        var body = new FormData(form);
        // FormData(form) does not include the clicked submit button.
        body.set('review_state', 'checked');
        buttons.forEach(function (item) { item[0].disabled = true; });
        button.textContent = '…';
        status.textContent = '';
        var problem = 'Bevestigen is niet gelukt. Ververs de pagina en controleer of de wijziging is opgeslagen.';
        try {
            var response = await fetch(form.getAttribute('action'), {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-AVBK-Review': '1'}});
            if (!(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error(problem);
            var result = await response.json();
            if (!response.ok || !result.success || !result.data || typeof result.data.html !== 'string') {
                if (result.data && typeof result.data.message === 'string') problem = result.data.message;
                throw new Error(problem);
            }
            var parsed = new DOMParser().parseFromString(result.data.html, 'text/html');
            var replacement = parsed.getElementById('avbk-company-result');
            if (!replacement) throw new Error(problem);
            // Preserve other open editors and their unsaved fields.
            var editors = Array.from(section.querySelectorAll('details.avbk-company-review[open]')).filter(function (details) {
                return details.closest('tr') !== row;
            }).map(function (details) {
                return {id: details.closest('tr').id, fields: Array.from(details.querySelectorAll('input:not([type="hidden"]), select, textarea')).map(function (field) { return [field.name, field.value]; })};
            });
            var top = anchor.getBoundingClientRect().top;
            var scroll = section.querySelector('.avbk-company-scroll').scrollLeft;
            var anchorId = anchor.id;
            var rowId = row.id;
            section.replaceWith(replacement);
            replacement.querySelector('.avbk-company-scroll').scrollLeft = scroll;
            editors.forEach(function (editor) {
                var target = document.getElementById(editor.id);
                var details = target && target.querySelector('details.avbk-company-review');
                if (!details) return;
                details.open = true;
                editor.fields.forEach(function (saved) {
                    var field = Array.from(details.querySelectorAll('input:not([type="hidden"]), select, textarea')).find(function (item) { return item.name === saved[0]; });
                    if (field) field.value = saved[1];
                });
            });
            requestAnimationFrame(function () {
                var next = document.getElementById(anchorId);
                if (next) {
                    window.scrollBy(0, next.getBoundingClientRect().top - top);
                    var focus = next.querySelector('button.avbk-company-ok');
                    var savedRow = document.getElementById(rowId);
                    if (!focus && savedRow) focus = savedRow.querySelector('summary');
                    if (focus) focus.focus({preventScroll: true});
                }
            });
        } catch (error) {
            status.textContent = problem;
            buttons.forEach(function (item) { item[0].disabled = item[1]; });
            button.textContent = label;
            delete section.dataset.reviewBusy;
        }
    });
});
