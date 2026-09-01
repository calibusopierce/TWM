/**
 * po_form.js
 * Powers technical/purchase_order.php:
 *  - "Item Form" -> Add to List: pick an existing item (typed/datalist
 *    or barcode scan) or type a new one, then add it as a line
 *  - Sub Total / Discount % / Tax % / Total, computed live
 *  - Create AND Edit (Edit only allowed for still-Open POs, enforced
 *    both here and server-side in save_po.php)
 *  - Print button opens print_po.php in a new tab
 */
document.addEventListener('DOMContentLoaded', function () {
  const poForm         = document.getElementById('poForm');
  const poModal         = document.getElementById('poModal');
  if (!poForm || !poModal) return;

  const poModalTitle      = document.getElementById('poModalTitle');
  const poSaveBtn           = document.getElementById('poSaveBtn');
  const poEditId             = document.getElementById('poEditId');

  const poSupplier   = document.getElementById('poSupplier');
  const poDepartment   = document.getElementById('poDepartment');
  const poRemarks        = document.getElementById('poRemarks');

  const barcodeInput  = document.getElementById('poItemBarcode');
  const itemNameInput   = document.getElementById('poItemName');
  const conditionInput  = document.getElementById('poItemCondition');
  const unitInput         = document.getElementById('poItemUnit');
  const qtyInput            = document.getElementById('poItemQty');
  const trackingMethodInput  = document.getElementById('poItemTrackingMethod');
  const addLineBtn              = document.getElementById('addPoLineBtn');

  const linesBody       = document.getElementById('poLinesBody');
  const emptyRow          = document.getElementById('poLinesEmptyRow');
  const discountInput       = document.getElementById('poDiscountInput');
  const taxInput               = document.getElementById('poTaxInput');

  const subTotalDisplay  = document.getElementById('poSubTotalDisplay');
  const discountDisplay    = document.getElementById('poDiscountDisplay');
  const taxDisplay           = document.getElementById('poTaxDisplay');
  const totalDisplay           = document.getElementById('poTotalDisplay');

  const catalog = window.PO_ITEM_CATALOG || [];
  let poLines = []; // {category, brand, model, description, unit, qty, cost, itemBarcode, tracking_method}

  function findCatalogEntry(itemName) {
    return catalog.find(function (c) { return c.ItemName === itemName; });
  }

  itemNameInput.addEventListener('change', function () {
    const entry = findCatalogEntry(itemNameInput.value.trim());
    itemNameInput.dataset.cost = entry && entry.Cost != null ? entry.Cost : '';
    itemNameInput.dataset.category = entry ? (entry.Category || '') : '';
    itemNameInput.dataset.brand    = entry ? (entry.Brand || '')    : '';
    itemNameInput.dataset.model    = entry ? (entry.Model || '')    : '';
  });

  barcodeInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = barcodeInput.value.trim();
    if (!code) return;

    fetch('get_item_by_barcode.php?barcode=' + encodeURIComponent(code))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Could not look up that barcode.');
          return;
        }
        if (!data.found) {
          alert('No item found for that barcode.');
          return;
        }
        const item = data.item;
        itemNameInput.value = item.ItemName || '';
        itemNameInput.dataset.category = item.Category || '';
        itemNameInput.dataset.brand    = item.Brand || '';
        itemNameInput.dataset.model    = item.Model || '';
        itemNameInput.dataset.cost     = item.Cost != null ? item.Cost : '';
        barcodeInput.value = '';
        qtyInput.focus();
      })
      .catch(function (err) {
        console.error(err);
        alert('Could not reach the server to look up that barcode.');
      });
  });

  function resetItemForm() {
    barcodeInput.value = '';
    itemNameInput.value = '';
    itemNameInput.dataset.category = '';
    itemNameInput.dataset.brand = '';
    itemNameInput.dataset.model = '';
    itemNameInput.dataset.cost = '';
    if (conditionInput) conditionInput.value = 'Brand New';
    if (trackingMethodInput) trackingMethodInput.value = 'Quantity-Based';
    unitInput.value = '';
    qtyInput.value = '';
    barcodeInput.focus();
  }

  function renderLines() {
    linesBody.innerHTML = '';
    if (poLines.length === 0) {
      linesBody.appendChild(emptyRow);
    } else {
      poLines.forEach(function (line, idx) {
        const tr = document.createElement('tr');
        const total = (line.qty * line.cost).toFixed(2);
        const conditionLabel = line.condition || '';
        const conditionClass = 'condition-' + conditionLabel.toLowerCase().replace(/\s+/g, '-');
        const barcodeCell = line.itemBarcode
          ? '<span class="cell-strong" style="font-family:var(--font-mono); font-size:12px;">' + escapeHtml(line.itemBarcode) + '</span>'
          : '<span class="hint" style="font-size:11px;">Auto-generated on save</span>';
        const isIndividual = line.tracking_method === 'Individual Unit Tracking';
        const trackingCell = isIndividual
          ? '<span class="condition-badge condition-used" style="white-space:nowrap;">Individual (' + line.qty + ' units)</span>'
          : '<span class="hint" style="font-size:11px;">Quantity-Based</span>';
        tr.innerHTML =
          '<td><button type="button" class="iconbtn po-line-remove" data-idx="' + idx + '" title="Remove">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>' +
          '</button></td>' +
          '<td>' + line.qty + '</td>' +
          '<td>' + (line.unit || '') + '</td>' +
          '<td><div class="cell-strong">' + escapeHtml(line.description) + '</div>' +
          '<div style="font-size:11px; color:var(--ink-300);">' + escapeHtml([line.category, [line.brand, line.model].filter(Boolean).join(' ')].filter(Boolean).join(' · ') || 'N/A') + '</div></td>' +
          '<td>' + barcodeCell + '</td>' +
          '<td>' + (conditionLabel ? '<span class="condition-badge ' + conditionClass + '">' + escapeHtml(conditionLabel) + '</span>' : '—') + '</td>' +
          '<td>' + trackingCell + '</td>' +
          '<td>' + Number(line.cost).toFixed(2) + '</td>' +
          '<td>' + total + '</td>';
        linesBody.appendChild(tr);
      });
    }
    recomputeTotals();
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  linesBody.addEventListener('click', function (e) {
    const btn = e.target.closest('.po-line-remove');
    if (!btn) return;
    const idx = parseInt(btn.dataset.idx, 10);
    poLines.splice(idx, 1);
    renderLines();
  });

  addLineBtn.addEventListener('click', function () {
    const description = itemNameInput.value.trim();
    const unit = unitInput.value.trim();
    const qty = parseFloat(qtyInput.value) || 0;

    if (!description) {
      alert('Type or pick an item first.');
      return;
    }
    if (qty <= 0) {
      alert('Enter a quantity.');
      return;
    }

    const catalogEntry = findCatalogEntry(description);
    const resolvedCost = itemNameInput.dataset.cost !== undefined && itemNameInput.dataset.cost !== ''
      ? parseFloat(itemNameInput.dataset.cost)
      : (catalogEntry && catalogEntry.Cost != null ? parseFloat(catalogEntry.Cost) : 0);

    poLines.push({
      category: itemNameInput.dataset.category || (catalogEntry ? catalogEntry.Category : '') || '',
      brand:    itemNameInput.dataset.brand    || (catalogEntry ? catalogEntry.Brand    : '') || '',
      model:    itemNameInput.dataset.model    || (catalogEntry ? catalogEntry.Model    : '') || '',
      description: description,
      condition: conditionInput ? conditionInput.value : 'Brand New',
      tracking_method: trackingMethodInput ? trackingMethodInput.value : 'Quantity-Based',
      unit: unit,
      qty: qty,
      cost: resolvedCost || 0,
      itemBarcode: '' // assigned server-side once this line is saved
    });

    renderLines();
    resetItemForm();
  });

  function recomputeTotals() {
    const subTotal = poLines.reduce(function (sum, l) { return sum + (l.qty * l.cost); }, 0);
    const discountPct = parseFloat(discountInput.value) || 0;
    const taxPct = parseFloat(taxInput.value) || 0;
    const discountAmt = subTotal * discountPct / 100;
    const afterDiscount = subTotal - discountAmt;
    const taxAmt = afterDiscount * taxPct / 100;
    const total = afterDiscount + taxAmt;

    subTotalDisplay.textContent = subTotal.toFixed(2);
    discountDisplay.textContent = discountAmt.toFixed(2);
    taxDisplay.textContent = taxAmt.toFixed(2);
    totalDisplay.textContent = total.toFixed(2);
  }

  discountInput.addEventListener('input', recomputeTotals);
  taxInput.addEventListener('input', recomputeTotals);

  function resetWholeForm() {
    poEditId.value = '';
    poModalTitle.textContent = 'Create New Purchase Order';
    poSaveBtn.textContent = 'Save Purchase Order';
    poSupplier.value = '';
    poDepartment.value = '';
    poRemarks.value = '';
    discountInput.value = '0';
    taxInput.value = '0';
    poLines = [];
    resetItemForm();
    renderLines();
  }

  // Reset every time the modal is opened fresh via the "Create New PO" button.
  const createBtn = document.getElementById('createPoBtn');
  if (createBtn) {
    createBtn.addEventListener('click', resetWholeForm);
  }

  // ---- Edit ----
  document.querySelectorAll('.po-edit-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const poId = btn.dataset.poId;
      fetch('get_po.php?id=' + encodeURIComponent(poId))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not load this PO.');
            return;
          }
          if (data.po.Status !== 'Open') {
            alert('This PO can no longer be edited — receiving has already started against it.');
            return;
          }

          resetWholeForm();
          poEditId.value = data.po.POID;
          poModalTitle.textContent = 'Edit Purchase Order ' + (data.po.PONumber || '');
          poSaveBtn.textContent = 'Update Purchase Order';
          poSupplier.value = data.po.SupplierCode || '';
          poDepartment.value = data.po.Department || '';
          poRemarks.value = data.po.Remarks || '';
          discountInput.value = data.po.Discount || 0;
          taxInput.value = data.po.Tax || 0;

          poLines = data.lines.map(function (l) {
            return {
              category: l.Category || '',
              brand: l.Brand || '',
              model: l.Model || '',
              description: l.ItemDescription || '',
              condition: l.Condition || 'Brand New',
              tracking_method: l.TrackingMethod || 'Quantity-Based',
              unit: l.Unit || '',
              qty: parseFloat(l.QtyOrdered) || 0,
              cost: parseFloat(l.UnitCost) || 0,
              itemBarcode: l.UnitBarcode || ''
            };
          });
          renderLines();

          poModal.classList.add('is-open');
        })
        .catch(function (err) {
          console.error(err);
          alert('Could not reach the server to load this PO.');
        });
    });
  });

  // ---- Print ----
  document.querySelectorAll('.po-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.open('print_po.php?id=' + encodeURIComponent(btn.dataset.poId), '_blank');
    });
  });

  // ---- Submit (create or update) ----
  poForm.addEventListener('submit', function (e) {
    e.preventDefault();

    if (!poSupplier.value || !poDepartment.value) {
      alert('Pick a supplier and a department first.');
      return;
    }
    if (poLines.length === 0) {
      alert('Add at least one line item.');
      return;
    }

    const originalLabel = poSaveBtn.textContent;
    poSaveBtn.disabled = true;
    poSaveBtn.textContent = 'Saving...';

    const payload = new FormData();
    if (poEditId.value) payload.append('po_id', poEditId.value);
    payload.append('supplier_code', poSupplier.value);
    payload.append('department', poDepartment.value);
    payload.append('remarks', poRemarks.value.trim());
    payload.append('discount', discountInput.value || 0);
    payload.append('tax', taxInput.value || 0);
    payload.append('sub_total', subTotalDisplay.textContent);
    payload.append('total', totalDisplay.textContent);
    payload.append('lines_json', JSON.stringify(poLines));

    fetch('save_po.php', { method: 'POST', body: payload })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Could not save this purchase order.');
          return;
        }
        window.location.reload();
      })
      .catch(function (err) {
        console.error(err);
        alert('Something went wrong saving the purchase order.');
      })
      .finally(function () {
        poSaveBtn.disabled = false;
        poSaveBtn.textContent = originalLabel;
      });
  });

  renderLines();
});