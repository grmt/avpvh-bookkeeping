/**
 * Dynamic line-item repeater and design card interactions for T-shirt ordering.
 */
document.addEventListener('DOMContentLoaded', function () {
    const tableBody = document.getElementById('avbk-tshirt-items-body');
    const addBtn = document.getElementById('avbk-add-shirt-btn');
    const grandTotalVal = document.getElementById('avbk-grand-total-val');
    const unitPriceHidden = document.getElementById('avbk-tshirt-unit-price');
    const template = document.getElementById('avbk-tshirt-row-template');

    if (!tableBody || !addBtn || !grandTotalVal) {
        return;
    }

    const unitPrice = parseFloat(unitPriceHidden ? unitPriceHidden.value : 17.50) || 17.50;
    let rowIndexCounter = tableBody.querySelectorAll('.avbk-tshirt-item-row').length;

    function formatEur(amount) {
        return amount.toLocaleString('nl-NL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getRowUnitPrice(row) {
        const typeSelect = row.querySelector('.avbk-tshirt-type-select');
        if (typeSelect && typeSelect.selectedOptions && typeSelect.selectedOptions[0]) {
            const opt = typeSelect.selectedOptions[0];
            const p = parseFloat(opt.getAttribute('data-price'));
            if (!isNaN(p) && p > 0) {
                return p;
            }
        }
        const designSelect = row.querySelector('.avbk-tshirt-design-select');
        if (designSelect && designSelect.selectedOptions && designSelect.selectedOptions[0]) {
            const opt = designSelect.selectedOptions[0];
            const p = parseFloat(opt.getAttribute('data-price'));
            if (!isNaN(p) && p > 0) {
                return p;
            }
        }
        return unitPrice;
    }

    function updateRowSubtotal(row) {
        const qtyInput = row.querySelector('.avbk-tshirt-qty-input');
        const priceSpan = row.querySelector('.avbk-price-val');
        const subtotalSpan = row.querySelector('.avbk-subtotal-val');
        const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1, 10) || 1);
        const rowUnitPrice = getRowUnitPrice(row);
        if (priceSpan) {
            priceSpan.textContent = formatEur(rowUnitPrice);
        }
        const subtotal = qty * rowUnitPrice;
        if (subtotalSpan) {
            subtotalSpan.textContent = formatEur(subtotal);
        }
    }

    function updateRowNumbers() {
        const rows = tableBody.querySelectorAll('.avbk-tshirt-item-row');
        rows.forEach(function (row, idx) {
            const numSpan = row.querySelector('.avbk-tshirt-item-idx');
            if (numSpan) {
                numSpan.textContent = (idx + 1).toString();
            }
        });
    }

    function updateGrandTotal() {
        const rows = tableBody.querySelectorAll('.avbk-tshirt-item-row');
        let total = 0;
        rows.forEach(function (row) {
            const qtyInput = row.querySelector('.avbk-tshirt-qty-input');
            const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : 1, 10) || 1);
            total += qty * getRowUnitPrice(row);
        });
        grandTotalVal.textContent = formatEur(total);
        updateRemoveButtonsVisibility();
        updateRowNumbers();
    }

    function updateRemoveButtonsVisibility() {
        const rows = tableBody.querySelectorAll('.avbk-tshirt-item-row');
        rows.forEach(function (row) {
            const btn = row.querySelector('.avbk-remove-item-btn');
            if (btn) {
                btn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
            }
        });
    }

    function attachRowEvents(row) {
        const markTouched = function () {
            row.dataset.userTouched = '1';
        };

        const designSelect = row.querySelector('.avbk-tshirt-design-select');
        if (designSelect) {
            designSelect.addEventListener('change', function () {
                markTouched();
                updateRowSubtotal(row);
                updateGrandTotal();
            });
        }

        const typeSelect = row.querySelector('.avbk-tshirt-type-select');
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                markTouched();
                updateRowSubtotal(row);
                updateGrandTotal();
            });
        }

        const colorSelect = row.querySelector('.avbk-tshirt-color-select');
        if (colorSelect) {
            colorSelect.addEventListener('change', markTouched);
        }

        const sizeSelect = row.querySelector('.avbk-tshirt-size-select');
        if (sizeSelect) {
            sizeSelect.addEventListener('change', markTouched);
        }

        const qtyInput = row.querySelector('.avbk-tshirt-qty-input');
        if (qtyInput) {
            qtyInput.addEventListener('input', function () {
                markTouched();
                updateRowSubtotal(row);
                updateGrandTotal();
            });
            qtyInput.addEventListener('change', function () {
                markTouched();
                if (parseInt(this.value, 10) < 1 || isNaN(parseInt(this.value, 10))) {
                    this.value = 1;
                }
                updateRowSubtotal(row);
                updateGrandTotal();
            });
        }

        const removeBtn = row.querySelector('.avbk-remove-item-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                const rows = tableBody.querySelectorAll('.avbk-tshirt-item-row');
                if (rows.length > 1) {
                    row.remove();
                    updateGrandTotal();
                }
            });
        }
    }

    function addRow(preselectedDesignId, preselectedType) {
        if (!template) return;
        const currentCount = tableBody.querySelectorAll('.avbk-tshirt-item-row').length;
        const index = rowIndexCounter++;
        let cloneHtml = template.innerHTML.replace(/__INDEX__/g, index);
        cloneHtml = cloneHtml.replace(/__INDEX_PLUS_1__/g, (currentCount + 1));
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = cloneHtml.trim();
        const newRow = tempDiv.firstElementChild;

        if (preselectedDesignId) {
            const select = newRow.querySelector('.avbk-tshirt-design-select');
            if (select) {
                select.value = preselectedDesignId;
            }
        }

        if (preselectedType) {
            const typeSelect = newRow.querySelector('.avbk-tshirt-type-select');
            if (typeSelect) {
                typeSelect.value = preselectedType;
            }
        }

        tableBody.appendChild(newRow);
        attachRowEvents(newRow);
        updateRowSubtotal(newRow);
        updateGrandTotal();
        return newRow;
    }

    // Initialize existing rows
    tableBody.querySelectorAll('.avbk-tshirt-item-row').forEach(function (row) {
        attachRowEvents(row);
        updateRowSubtotal(row);
    });
    updateGrandTotal();

    // Add button handler
    addBtn.addEventListener('click', function (e) {
        e.preventDefault();
        addRow();
    });

    function selectDesignAndType(designId, productType) {
        const rows = tableBody.querySelectorAll('.avbk-tshirt-item-row');
        let targetRow = null;

        if (rows.length === 1 && !rows[0].dataset.userTouched) {
            const dSelect = rows[0].querySelector('.avbk-tshirt-design-select');
            const tSelect = rows[0].querySelector('.avbk-tshirt-type-select');
            if (dSelect && designId) dSelect.value = designId;
            if (tSelect && productType) tSelect.value = productType;
            rows[0].dataset.userTouched = '1';
            updateRowSubtotal(rows[0]);
            updateGrandTotal();
            targetRow = rows[0];
        } else {
            targetRow = addRow(designId, productType);
            if (targetRow) targetRow.dataset.userTouched = '1';
        }

        // Scroll down to selector section
        const selectorSection = document.getElementById('avbk-tshirt-selector-section');
        if (selectorSection) {
            selectorSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (targetRow) {
            targetRow.style.transition = 'background-color 0.4s ease';
            targetRow.style.backgroundColor = '#e7f1fb';
            setTimeout(function () {
                targetRow.style.backgroundColor = '';
            }, 1200);
        }
    }

    // Gallery cards "+ Bestel T-shirt / Hoodie" buttons
    document.querySelectorAll('.avbk-select-design-type-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const designId = this.dataset.designId;
            const productType = this.dataset.productType || 'tshirt';
            selectDesignAndType(designId, productType);
        });
    });

    // Gallery cards "Kies dit design" legacy buttons
    document.querySelectorAll('.avbk-select-design-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const designId = this.dataset.designId;
            selectDesignAndType(designId, 'tshirt');
        });
    });

    // Email check AJAX for T-shirt ordering
    const emailForm = document.getElementById('avbk-check-email-form');
    const emailInput = document.getElementById('avbk_check_email_input');
    const resultContainer = document.getElementById('avbk-check-email-result');
    const emailSubmitBtn = document.getElementById('avbk_check_email_btn');

    function scrollToCheckEmail(el) {
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // On page load, if email was checked (via reload or query param), stay at/scroll to the email box
    if (window.location.search.indexOf('email_check=') !== -1 || window.location.hash.indexOf('avbk-check-email') !== -1) {
        const initialTarget = document.getElementById('avbk-check-email-box') || document.getElementById('avbk-login-banner');
        if (initialTarget) {
            setTimeout(function () {
                scrollToCheckEmail(initialTarget);
                const firstName = document.getElementById('first_name');
                if (firstName && window.location.search.indexOf('email_check=not_found') !== -1) {
                    try { firstName.focus({ preventScroll: true }); } catch (e) {}
                }
            }, 120);
        }
    }

    if (emailForm && emailInput && resultContainer && emailSubmitBtn) {
        emailForm.addEventListener('submit', function (e) {
            const email = (emailInput.value || '').trim();
            if (!email || !email.includes('@')) {
                return;
            }
            e.preventDefault();

            emailSubmitBtn.disabled = true;
            emailSubmitBtn.textContent = 'Controleren...';
            resultContainer.innerHTML = '<div class="avbk-book-notice avbk-book-notice-info"><p>E-mailadres wordt gecontroleerd...</p></div>';

            const formData = new FormData(emailForm);
            formData.append('ajax', '1');

            let postUrl = emailForm.getAttribute('action') || '';
            try {
                const parsed = new URL(postUrl, window.location.href);
                postUrl = parsed.pathname + parsed.search;
            } catch (err) {}

            fetch(postUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                emailSubmitBtn.disabled = false;
                emailSubmitBtn.textContent = 'Controleren';

                if (data.success && data.data) {
                    const status = data.data.status;
                    const message = data.data.message || '';
                    const guestEmailInput = document.getElementById('email');

                    if (status === 'reset_sent') {
                        const loginUrl = data.data.login_url || '/avpvh-login/';
                        resultContainer.innerHTML = '<div class="avbk-book-notice avbk-book-notice-success">' +
                            '<p><strong>E-mail verzonden!</strong> ' + escapeHtml(message) + ' Zodra je een wachtwoord hebt aangemaakt, kun je <a href="' + escapeHtml(loginUrl) + '">inloggen</a> en bestellen.</p>' +
                            '</div>';
                        scrollToCheckEmail(resultContainer);
                    } else if (status === 'already_active') {
                        const loginUrl = data.data.login_url || '/avpvh-login/';
                        const resetUrl = data.data.reset_url || '#';
                        resultContainer.innerHTML = '<div class="avbk-book-notice avbk-book-notice-info">' +
                            '<p>' + escapeHtml(message) + ' <a href="' + escapeHtml(loginUrl) + '">Log hier in</a> om direct te bestellen. Weet je je wachtwoord niet meer? <a href="' + escapeHtml(resetUrl) + '" target="_blank" rel="noopener">Wachtwoord opnieuw instellen</a>.</p>' +
                            '</div>';
                        scrollToCheckEmail(resultContainer);
                    } else if (status === 'not_found') {
                        resultContainer.innerHTML = '<div class="avbk-book-notice avbk-book-notice-neutral">' +
                            '<p>' + escapeHtml(message) + '</p>' +
                            '</div>';
                        if (guestEmailInput) {
                            guestEmailInput.value = email;
                        }
                        const checkEmailBox = document.getElementById('avbk-check-email-box') || document.getElementById('avbk-login-banner');
                        scrollToCheckEmail(checkEmailBox || resultContainer);
                        const firstName = document.getElementById('first_name');
                        if (firstName) {
                            try { firstName.focus({ preventScroll: true }); } catch (e) {}
                        }
                    }
                } else {
                    const errMsg = (data.data && data.data.message) ? data.data.message : 'Er is een fout opgetreden.';
                    resultContainer.innerHTML = '<div class="avbk-book-notice avbk-book-error"><p>' + escapeHtml(errMsg) + '</p></div>';
                    scrollToCheckEmail(resultContainer);
                }
            })
            .catch(function () {
                emailSubmitBtn.disabled = false;
                emailSubmitBtn.textContent = 'Controleren';
                if (emailForm.action.indexOf('#') === -1) {
                    emailForm.action = emailForm.action + '#avbk-check-email-box';
                }
                emailForm.submit();
            });
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
