/* FleetLink Magazyn - Application JavaScript */

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss flash messages
    setTimeout(function () {
        document.querySelectorAll('.alert.alert-success, .alert.alert-info').forEach(function (el) {
            if (el.querySelector('.btn-close')) {
                el.querySelector('.btn-close').click();
            }
        });
    }, 5000);

    // Confirm delete actions
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm || 'Czy na pewno chcesz wykonać tę operację?')) {
                e.preventDefault();
            }
        });
    });

    // Dynamic offer items
    initOfferItems();

    // Tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});

// ================================================================
// Offer item management (table-based)
// ================================================================
function initOfferItems() {
    const container = document.getElementById('offerItems');
    if (!container) return;

    // Add item button
    document.getElementById('addItemBtn')?.addEventListener('click', addOfferItem);

    // VAT selector (dropdown + custom)
    const vatSelect = document.getElementById('vatRate');
    const vatCustomWrap = document.getElementById('vatCustomWrap');
    const vatCustomInput = document.getElementById('vatRateCustom');
    if (vatSelect) {
        vatSelect.addEventListener('change', function () {
            const isCustom = this.value === 'custom';
            if (vatCustomWrap) vatCustomWrap.style.display = isCustom ? 'block' : 'none';
            recalculateTotals();
        });
    }
    if (vatCustomInput) {
        vatCustomInput.addEventListener('input', recalculateTotals);
    }

    // Payment terms custom
    const ptSelect = document.getElementById('paymentTermsSelect');
    const ptCustomWrap = document.getElementById('paymentTermsCustomWrap');
    const ptCustomInput = document.getElementById('paymentTermsCustom');
    if (ptSelect) {
        ptSelect.addEventListener('change', function () {
            const isOther = this.value === 'other';
            if (ptCustomWrap) ptCustomWrap.style.display = isOther ? 'block' : 'none';
            // sync hidden field name
            if (ptCustomInput) ptCustomInput.name = isOther ? 'payment_terms' : '_payment_terms_custom_ignored';
            if (!isOther) ptSelect.name = 'payment_terms';
            else ptSelect.name = '_payment_terms_select_ignored';
        });
        // Init
        if (ptSelect.value === 'other') {
            if (ptCustomWrap) ptCustomWrap.style.display = 'block';
            if (ptCustomInput) ptCustomInput.name = 'payment_terms';
            ptSelect.name = '_payment_terms_select_ignored';
        }
    }

    // Discount field
    const discountField = document.getElementById('discount');
    if (discountField) {
        discountField.addEventListener('input', recalculateTotals);
    }

    // Row events (delegated)
    container.addEventListener('click', function (e) {
        const btn = e.target.closest('.remove-item');
        if (btn) {
            const rows = container.querySelectorAll('.offer-item-row');
            if (rows.length <= 1) {
                // Clear instead of remove if last row
                const row = btn.closest('.offer-item-row');
                row.querySelector('.item-desc').value = '';
                row.querySelector('.item-qty').value = '1';
                row.querySelector('.item-price').value = '0.00';
                row.querySelector('.item-total').value = '0.00';
            } else {
                btn.closest('.offer-item-row').remove();
            }
            reindexRows();
            recalculateTotals();
        }
    });
    container.addEventListener('input', function (e) {
        if (e.target.matches('.item-qty, .item-price')) {
            const row = e.target.closest('.offer-item-row');
            const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            row.querySelector('.item-total').value = (qty * price).toFixed(2);
            recalculateTotals();
        }
    });

    // Model picker modal items
    document.querySelectorAll('.add-model-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            addOfferItemWithData(this.dataset.desc, this.dataset.price, this.dataset.unit);
            // Close modal
            const modal = document.getElementById('modelPickerModal');
            if (modal) bootstrap.Modal.getInstance(modal)?.hide();
        });
    });

    // Model picker search
    const modelSearch = document.getElementById('modelSearch');
    if (modelSearch) {
        modelSearch.addEventListener('input', function () {
            const term = this.value.toLowerCase();
            document.querySelectorAll('#modelTable .model-row').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }

    recalculateTotals();
    updateRowNumbers();
}

function getEffectiveVatRate() {
    const vatSelect = document.getElementById('vatRate');
    if (!vatSelect) return 23;
    if (vatSelect.value === 'custom') {
        return parseFloat(document.getElementById('vatRateCustom')?.value) || 0;
    }
    return parseFloat(vatSelect.value) || 0;
}

function formatPLN(amount) {
    return amount.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' zł';
}

function recalculateTotals() {
    let rawNet = 0;
    document.querySelectorAll('#offerItems .item-total').forEach(function (el) {
        rawNet += parseFloat(el.value) || 0;
    });

    const discountPct = parseFloat(document.getElementById('discount')?.value) || 0;
    const discountAmt = rawNet * discountPct / 100;
    const netAfter    = rawNet - discountAmt;
    const vatRate     = getEffectiveVatRate();
    const vatAmt      = netAfter * vatRate / 100;
    const gross       = netAfter + vatAmt;

    const rawNetEl      = document.getElementById('rawNet');
    const discountRow   = document.getElementById('discountRow');
    const discountPctEl = document.getElementById('discountPct');
    const discountAmtEl = document.getElementById('discountAmt');
    const totalNetEl    = document.getElementById('totalNet');
    const totalVatEl    = document.getElementById('totalVat');
    const vatPctEl      = document.getElementById('vatPct');
    const totalGrossEl  = document.getElementById('totalGross');

    if (rawNetEl)      rawNetEl.textContent      = formatPLN(rawNet);
    if (discountRow)   discountRow.style.display  = discountPct > 0 ? '' : 'none';
    if (discountPctEl) discountPctEl.textContent  = discountPct.toFixed(discountPct % 1 === 0 ? 0 : 2).replace('.', ',');
    if (discountAmtEl) discountAmtEl.textContent  = '-' + formatPLN(discountAmt);
    if (totalNetEl)    totalNetEl.textContent      = formatPLN(netAfter);
    if (vatPctEl)      vatPctEl.textContent        = vatRate;
    if (totalVatEl)    totalVatEl.textContent      = formatPLN(vatAmt);
    if (totalGrossEl)  totalGrossEl.textContent    = formatPLN(gross);
}

function updateRowNumbers() {
    document.querySelectorAll('#offerItems .offer-item-row').forEach(function (row, i) {
        const lp = row.querySelector('.lp-cell');
        if (lp) lp.textContent = i + 1;
    });
}

function reindexRows() {
    document.querySelectorAll('#offerItems .offer-item-row').forEach(function (row, i) {
        row.querySelectorAll('input[name]').forEach(function (inp) {
            inp.name = inp.name.replace(/items\[\d+\]/, 'items[' + i + ']');
        });
        const lp = row.querySelector('.lp-cell');
        if (lp) lp.textContent = i + 1;
    });
}

function addOfferItem() {
    addOfferItemWithData('', '0.00', 'szt');
}

function addOfferItemWithData(desc, price, unit) {
    const container = document.getElementById('offerItems');
    if (!container) return;

    const tpl = document.getElementById('itemRowTemplate');
    let html;
    if (tpl) {
        html = tpl.innerHTML;
    } else {
        // Fallback: build manually
        const idx = container.querySelectorAll('.offer-item-row').length;
        html = buildItemRowHTML(idx, desc, price, unit);
    }

    const idx = container.querySelectorAll('.offer-item-row').length;
    html = html.replace(/__IDX__/g, idx);

    const tbody = container;
    const tmp = document.createElement('tbody');
    tmp.innerHTML = html;
    const newRow = tmp.querySelector('tr');
    tbody.appendChild(newRow);

    if (desc)  newRow.querySelector('.item-desc').value  = desc;
    if (price) newRow.querySelector('.item-price').value = price;
    if (unit)  newRow.querySelector('.item-unit').value  = unit;

    // Calculate total for pre-filled row
    const qty = parseFloat(newRow.querySelector('.item-qty').value) || 0;
    const p   = parseFloat(newRow.querySelector('.item-price').value) || 0;
    newRow.querySelector('.item-total').value = (qty * p).toFixed(2);

    reindexRows();
    recalculateTotals();
    newRow.querySelector('.item-desc')?.focus();
}

function buildItemRowHTML(idx, desc, price, unit) {
    desc  = (desc  || '').replace(/"/g, '&quot;');
    price = price || '0.00';
    unit  = unit  || 'szt';
    return `<tr class="offer-item-row">
        <td class="text-center text-muted align-middle lp-cell">${idx + 1}</td>
        <td><input type="text" name="items[${idx}][description]" class="form-control form-control-sm item-desc"
                   value="${desc}" placeholder="Opis pozycji / usługi" required></td>
        <td><input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm item-qty"
                   value="1" min="0.01" step="0.01"></td>
        <td><input type="text" name="items[${idx}][unit]" class="form-control form-control-sm item-unit"
                   value="${unit}" style="width:55px"></td>
        <td><input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm item-price"
                   value="${price}" min="0" step="0.01"></td>
        <td><input type="number" name="items[${idx}][total_price]" class="form-control form-control-sm item-total bg-light"
                   value="0.00" readonly tabindex="-1"></td>
        <td class="text-center align-middle">
            <button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Usuń">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>`;
}

// Print document
function printDocument() {
    window.print();
}

// Date range picker initialization
function initDatePicker() {
    const pickers = document.querySelectorAll('input[type="date"]');
    pickers.forEach(function (picker) {
        if (!picker.value && picker.dataset.default) {
            picker.value = picker.dataset.default;
        }
    });
}

// AJAX helper
function ajaxPost(url, data, callback) {
    const formData = new FormData();
    Object.keys(data).forEach(k => formData.append(k, data[k]));
    fetch(url, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(callback)
        .catch(err => console.error('AJAX error:', err));
}

// Search/filter tables
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const term = this.value.toLowerCase();
            document.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }
});

