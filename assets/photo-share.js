/* Upload one photo at a time; Google account tokens and media URLs stay on the server. */
document.querySelectorAll('.avbk-photo-upload').forEach((box) => {
    const form = box.querySelector('form');
    const input = form.querySelector('input[type=file]');
    const start = box.querySelector('.avbk-google-photos-start');
    const resume = box.querySelector('.avbk-google-photos-resume');
    const cancel = box.querySelector('.avbk-google-photos-cancel');
    const open = box.querySelector('.avbk-google-photos-open');
    const status = box.querySelector('.avbk-photo-upload-status');
    const results = box.querySelector('.avbk-photo-upload-results');
    const storageKey = 'avbk-google-photos-pending';
    let busy = false;
    let picking = false;
    let timer;
    const remember = (value) => {
        try {
            if (value) sessionStorage.setItem(storageKey, '1');
            else sessionStorage.removeItem(storageKey);
        } catch (_) { /* Selection also works when browser storage is disabled. */ }
    };
    const request = async (action, data = {}) => {
        const body = new FormData();
        body.set('action', action);
        body.set('nonce', box.dataset.nonce);
        Object.entries(data).forEach(([key, value]) => body.set(key, value));
        const response = await fetch(box.dataset.url, { method: 'POST', credentials: 'same-origin', body });
        let json;
        try { json = await response.json(); }
        catch (_) { throw new Error('De verbinding is onderbroken. Controleer of de foto is opgeslagen en probeer opnieuw.'); }
        if (!json.success) throw new Error(json.data?.message || 'Het uploaden is niet gelukt. Ververs de pagina en probeer opnieuw.');
        return json.data;
    };
    const controls = () => {
        form.querySelector('button').disabled = busy || picking;
        input.disabled = busy || picking;
        if (start) start.disabled = busy || picking;
        if (resume) { resume.hidden = !picking; resume.disabled = busy; }
        if (cancel) { cancel.hidden = !picking; cancel.disabled = busy; }
    };
    const result = (name, message) => {
        const row = document.createElement('li');
        row.textContent = `${name}: ${message}`;
        results.append(row);
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || picking || !input.files.length) return;
        const files = Array.from(input.files);
        busy = true; controls(); results.replaceChildren();
        let succeeded = 0;
        for (const [index, file] of files.entries()) {
            status.textContent = `Foto ${index + 1} van ${files.length} uploaden… Laat deze pagina open.`;
            try {
                if (file.size > Number(box.dataset.limit)) throw new Error('Deze foto is groter dan de aangegeven limiet.');
                await request('avbk_photo_upload', { photo: file });
                succeeded++;
                result(file.name, 'opgeslagen');
            } catch (error) { result(file.name, error.message); }
        }
        input.value = '';
        status.textContent = `${succeeded} van ${files.length} foto’s opgeslagen. Je kunt mislukte foto’s opnieuw kiezen.`;
        busy = false; controls();
    });
    const poll = async () => {
        clearTimeout(timer);
        if (busy || !picking) return;
        busy = true; controls();
        try {
            const data = await request('avbk_google_photos_status');
            if (!data.ready) {
                if (data.url) { open.href = data.url; open.hidden = false; }
                status.textContent = 'Kies je foto’s in Google Photos en keer daarna terug naar deze pagina.';
                if (data.timeout > 0) timer = setTimeout(poll, Math.max(1000, data.interval * 1000));
                else status.textContent = 'Keer na het kiezen terug en tik op ‘Controleer selectie’.';
            } else {
                open.hidden = true; results.replaceChildren();
                let succeeded = 0;
                for (const [index, item] of data.items.entries()) {
                    status.textContent = `Foto ${index + 1} van ${data.items.length} kopiëren… Laat deze pagina open.`;
                    try {
                        await request('avbk_google_photos_import', { index });
                        succeeded++; result(item.name, 'opgeslagen');
                    } catch (error) { result(item.name, error.message); }
                }
                status.textContent = `${succeeded} van ${data.items.length} foto’s opgeslagen.`
                    + (data.skipped ? ` ${data.skipped} video’s of niet-ondersteunde bestanden overgeslagen.` : '');
                if (succeeded === data.items.length) {
                    await request('avbk_google_photos_finish');
                    picking = false; remember(false);
                } else status.textContent += ' Tik op ‘Controleer selectie’ om opnieuw te proberen.';
            }
        } catch (error) {
            status.textContent = error.message;
        } finally { busy = false; controls(); }
    };
    if (start) {
        start.addEventListener('click', async () => {
            if (busy || picking) return;
            // Open during the tap so mobile browsers do not block an async popup.
            const tab = window.open('about:blank', '_blank');
            if (tab) tab.opener = null;
            busy = true; controls(); results.replaceChildren();
            status.textContent = 'Google Photos openen…';
            try {
                const data = await request('avbk_google_photos_start');
                open.href = data.url; open.hidden = false;
                if (tab) tab.location.replace(data.url);
                picking = true; remember(true);
            } catch (error) {
                if (tab) tab.close();
                status.textContent = error.message;
            } finally { busy = false; controls(); }
            if (picking) void poll();
        });
        resume.addEventListener('click', () => { void poll(); });
        cancel.addEventListener('click', async () => {
            if (busy) return;
            clearTimeout(timer); busy = true; controls();
            try {
                await request('avbk_google_photos_finish');
                picking = false; remember(false); open.hidden = true;
                status.textContent = 'Selectie geannuleerd. Reeds opgeslagen foto’s blijven in je map.';
            } catch (error) { status.textContent = error.message; }
            finally { busy = false; controls(); }
        });
        window.addEventListener('focus', () => { if (picking && !busy) void poll(); });
        document.addEventListener('visibilitychange', () => { if (!document.hidden && picking && !busy) void poll(); });
        try { picking = sessionStorage.getItem(storageKey) === '1'; } catch (_) { /* No storage. */ }
        if (new URLSearchParams(location.search).has('google_photos_error')) picking = true;
        if (picking) { controls(); void poll(); }
    }
});
