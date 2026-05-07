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

// Offer item management
function initOfferItems() {
    const container = document.getElementById('offerItems');
    if (!container) return;

    document.getElementById('addItemBtn')?.addEventListener('click', addOfferItem);
    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-item')) {
            e.target.closest('.offer-item-row').remove();
            recalculateTotal();
        }
    });
    container.addEventListener('input', function (e) {
        if (e.target.matches('.item-qty, .item-price')) {
            const row = e.target.closest('.offer-item-row');
            const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            row.querySelector('.item-total').value = (qty * price).toFixed(2);
            recalculateTotal();
        }
    });
    recalculateTotal();
}

function addOfferItem() {
    const container = document.getElementById('offerItems');
    const idx = container.querySelectorAll('.offer-item-row').length;
    const row = document.createElement('div');
    row.className = 'offer-item-row row g-2 mb-2 align-items-center';
    row.innerHTML = `
        <div class="col-md-5">
            <input type="text" name="items[${idx}][description]" class="form-control form-control-sm" placeholder="Opis pozycji" required>
        </div>
        <div class="col-md-1">
            <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm item-qty" value="1" min="0.01" step="0.01">
        </div>
        <div class="col-md-1">
            <input type="text" name="items[${idx}][unit]" class="form-control form-control-sm" value="szt" placeholder="j.m.">
        </div>
        <div class="col-md-2">
            <input type="number" name="items[${idx}][unit_price]" class="form-control form-control-sm item-price" value="0.00" min="0" step="0.01">
        </div>
        <div class="col-md-2">
            <input type="number" name="items[${idx}][total_price]" class="form-control form-control-sm item-total" value="0.00" readonly>
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-sm btn-outline-danger remove-item w-100"><i class="fas fa-times"></i></button>
        </div>`;
    container.appendChild(row);
}

function recalculateTotal() {
    let total = 0;
    document.querySelectorAll('.item-total').forEach(function (el) {
        total += parseFloat(el.value) || 0;
    });
    const totalNetEl = document.getElementById('totalNet');
    const totalGrossEl = document.getElementById('totalGross');
    if (totalNetEl) {
        totalNetEl.textContent = total.toFixed(2).replace('.', ',') + ' zł';
        const vatRate = parseFloat(document.getElementById('vatRate')?.value || 23) / 100;
        const gross = total * (1 + vatRate);
        if (totalGrossEl) totalGrossEl.textContent = gross.toFixed(2).replace('.', ',') + ' zł';
    }
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
