document.addEventListener('DOMContentLoaded', function () {
    var lightbox = document.createElement('div');
    lightbox.className = 'avbk-lightbox';
    lightbox.hidden = true;
    lightbox.innerHTML = '<button type="button" class="avbk-lightbox-close" aria-label="Sluiten">&times;</button><img class="avbk-lightbox-img">';
    document.body.appendChild(lightbox);

    var img = lightbox.querySelector('.avbk-lightbox-img');

    function open(src, alt) {
        img.src = src;
        img.alt = alt || '';
        img.classList.remove('avbk-zoomed');
        lightbox.hidden = false;
    }

    function close() {
        lightbox.hidden = true;
        img.src = '';
    }

    document.querySelectorAll('.avbk-receipt-thumb').forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            open(thumb.src, thumb.alt);
        });
    });

    img.addEventListener('click', function () {
        img.classList.toggle('avbk-zoomed');
    });

    lightbox.addEventListener('click', function (e) {
        if (e.target === lightbox) close();
    });

    lightbox.querySelector('.avbk-lightbox-close').addEventListener('click', close);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !lightbox.hidden) close();
    });

    document.querySelectorAll('.avbk-iban-select').forEach(function (select) {
        var target = document.getElementById(select.dataset.target);
        if (!target) return;
        select.addEventListener('change', function () {
            if (select.value !== '') {
                target.value = select.value;
                target.hidden = true;
            } else {
                target.value = '';
                target.hidden = false;
                target.focus();
            }
        });
    });
});
