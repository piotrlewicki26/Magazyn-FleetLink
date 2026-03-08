/* FleetLink Magazyn - Application JavaScript */

function initDarkMode() {
    var btn = document.getElementById('darkModeToggle');
    if (!btn) return;

    function updateIcon(theme) {
        var icon = btn.querySelector('i');
        if (!icon) return;
        if (theme === 'dark') {
            icon.className = 'fas fa-sun';
            btn.setAttribute('title', 'Tryb jasny');
        } else {
            icon.className = 'fas fa-moon';
            btn.setAttribute('title', 'Tryb ciemny');
        }
    }

    var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
    updateIcon(current);

    btn.addEventListener('click', function () {
        var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('fl-theme', next);
        updateIcon(next);
    });
}

/* ── Main Init ─────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    initDarkMode();

    // Auto-dismiss flash messages after 5 seconds
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

    // Table search
    var searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.toLowerCase();
            document.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    }
});

/* ── Offer Item Management ─────────────────────────────── */
function initOfferItems() {
    var container = document.getElementById('offerItems');
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
            var row = e.target.closest('.offer-item-row');
            var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            var price = parseFloat(row.querySelector('.item-price').value) || 0;
            row.querySelector('.item-total').value = (qty * price).toFixed(2);
            recalculateTotal();
        }
    });
    recalculateTotal();
}

function addOfferItem() {
    var container = document.getElementById('offerItems');
    var idx = container.querySelectorAll('.offer-item-row').length;
    var row = document.createElement('div');
    row.className = 'offer-item-row row g-2 mb-2 align-items-center';
    row.innerHTML =
        '<div class="col-md-5"><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm" placeholder="Opis pozycji" required></div>' +
        '<div class="col-md-1"><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm item-qty" value="1" min="0.01" step="0.01"></div>' +
        '<div class="col-md-1"><input type="text" name="items[' + idx + '][unit]" class="form-control form-control-sm" value="szt" placeholder="j.m."></div>' +
        '<div class="col-md-2"><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm item-price" value="0.00" min="0" step="0.01"></div>' +
        '<div class="col-md-2"><input type="number" name="items[' + idx + '][total_price]" class="form-control form-control-sm item-total" value="0.00" readonly></div>' +
        '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger remove-item w-100"><i class="fas fa-times"></i></button></div>';
    container.appendChild(row);
}

function recalculateTotal() {
    var total = 0;
    document.querySelectorAll('.item-total').forEach(function (el) {
        total += parseFloat(el.value) || 0;
    });
    var totalNetEl = document.getElementById('totalNet');
    var totalGrossEl = document.getElementById('totalGross');
    if (totalNetEl) {
        totalNetEl.textContent = total.toFixed(2).replace('.', ',') + ' zł';
        var vatRateEl = document.getElementById('vatRate');
        var vatRate = parseFloat(vatRateEl ? vatRateEl.value : 23) / 100;
        var gross = total * (1 + vatRate);
        if (totalGrossEl) totalGrossEl.textContent = gross.toFixed(2).replace('.', ',') + ' zł';
    }
}

/* ── Misc ──────────────────────────────────────────────── */
function printDocument() { window.print(); }

function ajaxPost(url, data, callback) {
    var formData = new FormData();
    Object.keys(data).forEach(function (k) { formData.append(k, data[k]); });
    fetch(url, { method: 'POST', body: formData })
        .then(function (r) { return r.json(); })
        .then(callback)
        .catch(function (err) { console.error('AJAX error:', err); });
}
