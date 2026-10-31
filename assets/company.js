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
                var response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-AVBK-Download': '1'}});
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
});
