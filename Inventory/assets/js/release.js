/**
 * release.js
 * Powers technical/release.php:
 *  - Create New Release modal (line picker, save to save_release.php)
 *  - Return modal (qty inputs, save to save_return.php)
 *  - View modal (read-only details via get_release.php)
 *  - Search filter on the release list
 */
document.addEventListener('DOMContentLoaded', function () {

  /* ---- Shared helpers ---- */
  function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
  }

  function openOverlay(el)  { el.classList.add('is-open'); }
  function closeOverlay(el) { el.classList.remove('is-open'); }

  /* ---- Search filter ---- */
  const searchInput = document.getElementById('releaseSearch');
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      const term = this.value.trim().toLowerCase();
      const rows = document.querySelectorAll('#releaseTable tbody tr');
      let count = 0;
      rows.forEach(function (r) {
        const show = !term || r.textContent.toLowerCase().includes(term);
        r.style.display = show ? '' : 'none';
        if (show) count++;
      });
      const counter = document.getElementById('releaseCount');
      if (counter) counter.textContent = 'Showing ' + count + ' releases';
    });
  }

  /* ================================================================
     CREATE NEW RELEASE MODAL
  ================================================================ */
  const releaseModal    = document.getElementById('releaseModal');
  const createBtn       = document.getElementById('createReleaseBtn');
  const closeRelBtn     = document.getElementById('closeReleaseModal');
  const cancelRelBtn    = document.getElementById('cancelReleaseBtn');
  const releaseForm     = document.getElementById('releaseForm');
  const relSaveBtn      = document.getElementById('relSaveBtn');

  const relDepartment   = document.getElementById('relDepartment');
  const relReleasedTo   = document.getElementById('relReleasedTo');
  const relScanBarcode  = document.getElementById('relScanBarcode');
  const relItemName     = document.getElementById('relItemName');
  const relQty          = document.getElementById('relQty');
  const relLineRemarks  = document.getElementById('relLineRemarks');
  const relAvailHint    = document.getElementById('relAvailHint');
  const relRemarks      = document.getElementById('relRemarks');
  const addLineBtn      = document.getElementById('addReleaseLineBtn');
  const linesBody       = document.getElementById('relLinesBody');
  const emptyRow        = document.getElementById('relLinesEmptyRow');
  const relUnitsWrap    = document.getElementById('relUnitsWrap');
  const relUnitsBody    = document.getElementById('relUnitsBody');
  const relUnitsCount   = document.getElementById('relUnitsCount');
  const relQtyField     = relQty ? relQty.closest('.field') : null;

  let releaseLines = []; // {po_item_id, item_barcode, item_description, condition, tracking_method, qty_released, available, remarks, unit_barcodes?}
  let scannedItem  = null; // the currently-identified PO line, pending Add
  let availableUnits = []; // [{POItemUnitID, UnitBarcode}] for the currently-identified Individual Unit Tracking item

  function clearScannedItem() {
    scannedItem = null;
    availableUnits = [];
    relScanBarcode.value = '';
    relItemName.value    = '';
    relQty.value          = '';
    relQty.max            = '';
    relAvailHint.textContent = 'Scan a barcode, or pick an item from the list if no scanner is handy.';
    relUnitsWrap.style.display = 'none';
    relUnitsBody.innerHTML = '';
    if (relQtyField) relQtyField.style.display = '';
  }

  // Excludes units already staged in an unsaved line for this same
  // PO item within the current release draft, so the same physical
  // unit can't be picked twice before saving.
  function alreadyStagedBarcodes(poItemId) {
    const existing = releaseLines.find(function (l) { return l.po_item_id === poItemId; });
    return existing && existing.unit_barcodes ? existing.unit_barcodes : [];
  }

  function renderUnitsChecklist(poItemId) {
    const staged = alreadyStagedBarcodes(poItemId);
    const selectable = availableUnits.filter(function (u) { return staged.indexOf(u.UnitBarcode) === -1; });

    relUnitsBody.innerHTML = '';
    if (selectable.length === 0) {
      relUnitsBody.innerHTML = '<tr><td><div class="table-empty">No units left to select for this item.</div></td></tr>';
    } else {
      selectable.forEach(function (u) {
        const tr = document.createElement('tr');
        const id = 'relUnit_' + u.POItemUnitID;
        tr.innerHTML =
          '<td style="padding:6px 10px;">' +
          '<label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;">' +
          '<input type="checkbox" class="rel-unit-checkbox" value="' + escapeHtml(u.UnitBarcode) + '" id="' + id + '">' +
          '<span class="cell-strong" style="font-family:var(--font-mono); font-size:12px;">' + escapeHtml(u.UnitBarcode) + '</span>' +
          '</label></td>';
        relUnitsBody.appendChild(tr);
      });
    }
    updateUnitsCount();
  }

  function updateUnitsCount() {
    const checked = relUnitsBody.querySelectorAll('.rel-unit-checkbox:checked').length;
    relUnitsCount.textContent = checked > 0 ? '(' + checked + ' selected)' : '';
  }

  relUnitsBody && relUnitsBody.addEventListener('change', function (e) {
    if (e.target.classList.contains('rel-unit-checkbox')) updateUnitsCount();
  });

  function identifyItem(item) {
    scannedItem = item;
    const isIndividual = item.TrackingMethod === 'Individual Unit Tracking';

    relAvailHint.textContent = item.Available + (isIndividual ? ' unit' + (item.Available !== 1 ? 's' : '') + ' registered' : ' available') +
      (item.ItemCondition ? ' (' + item.ItemCondition + ')' : '') +
      (item.PONumber ? ' — from ' + item.PONumber : '');

    if (isIndividual) {
      if (relQtyField) relQtyField.style.display = 'none';
      relUnitsWrap.style.display = '';
      relUnitsBody.innerHTML = '<tr><td><div class="table-empty">Loading units...</div></td></tr>';

      fetch('get_available_units.php?po_item_id=' + encodeURIComponent(item.POItemID))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not load available units.');
            availableUnits = [];
          } else {
            availableUnits = data.units;
          }
          renderUnitsChecklist(item.POItemID);
        })
        .catch(function (err) {
          console.error(err);
          relUnitsBody.innerHTML = '<tr><td><div class="table-empty">Could not load units.</div></td></tr>';
        });
    } else {
      if (relQtyField) relQtyField.style.display = '';
      relUnitsWrap.style.display = 'none';
      relQty.max = item.Available;
      relQty.value = '';
      relQty.focus();
    }
  }

  function openReleaseModal() {
    releaseLines = [];
    relDepartment.value  = '';
    relReleasedTo.value  = '';
    relLineRemarks.value = '';
    relRemarks.value     = '';
    clearScannedItem();
    renderReleaseLines();
    openOverlay(releaseModal);
    relDepartment.focus();
  }

  createBtn && createBtn.addEventListener('click', openReleaseModal);
  closeRelBtn && closeRelBtn.addEventListener('click', function () { closeOverlay(releaseModal); });
  cancelRelBtn && cancelRelBtn.addEventListener('click', function () { closeOverlay(releaseModal); });
  releaseModal && releaseModal.addEventListener('click', function (e) {
    if (e.target === releaseModal) closeOverlay(releaseModal);
  });

  // Scan a barcode -- either a line's own UnitBarcode (Quantity-Based)
  // or one of its individual unit barcodes -- to quickly identify
  // which item the user is looking for.
  relScanBarcode && relScanBarcode.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = relScanBarcode.value.trim();
    if (!code) return;

    fetch('get_po_item_by_barcode.php?barcode=' + encodeURIComponent(code))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Could not look up that barcode.');
          return;
        }
        if (!data.found) {
          alert('No purchase order item found for that barcode.');
          clearScannedItem();
          return;
        }
        relItemName.value = String(data.item.POItemID); // keep the dropdown in sync if this barcode is also listed there
        identifyItem(data.item);
      })
      .catch(function (err) {
        console.error(err);
        alert('Could not reach the server to look up that barcode.');
      });
  });

  // Fallback for when there's no scanner: pick the item straight from
  // the dropdown, which carries the same PO-line/availability data a
  // scan would look up.
  relItemName && relItemName.addEventListener('change', function () {
    const opt = relItemName.options[relItemName.selectedIndex];
    if (!opt || !opt.value) {
      clearScannedItem();
      return;
    }
    const item = {
      POItemID:       parseInt(opt.dataset.poItemId, 10),
      UnitBarcode:    opt.dataset.unitBarcode || '',
      ItemDescription: opt.dataset.description || '',
      ItemCondition:  opt.dataset.condition || 'Brand New',
      TrackingMethod: opt.dataset.trackingMethod || 'Quantity-Based',
      PONumber:       opt.dataset.ponumber || '',
      Available:      parseInt(opt.dataset.available, 10) || 0
    };
    relScanBarcode.value = item.UnitBarcode; // keep the barcode field in sync too
    identifyItem(item);
  });

  function renderReleaseLines() {
    linesBody.innerHTML = '';
    if (releaseLines.length === 0) {
      linesBody.appendChild(emptyRow);
      return;
    }
    releaseLines.forEach(function (line, idx) {
      const condClass = 'condition-' + (line.condition || '').toLowerCase().replace(/\s+/g, '-');
      const tr = document.createElement('tr');
      const qtyCell = line.unit_barcodes && line.unit_barcodes.length
        ? line.qty_released + '<div style="font-size:10.5px; color:var(--ink-300); font-family:var(--font-mono); white-space:normal;">' + escapeHtml(line.unit_barcodes.join(', ')) + '</div>'
        : String(line.qty_released);
      tr.innerHTML =
        '<td><button type="button" class="iconbtn rel-line-remove" data-idx="' + idx + '" title="Remove">' +
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></td>' +
        '<td>' + escapeHtml(line.item_description) + '</td>' +
        '<td>' + (line.condition ? '<span class="condition-badge ' + condClass + '">' + escapeHtml(line.condition) + '</span>' : '—') + '</td>' +
        '<td style="text-align:center; font-family:var(--font-mono);">' + qtyCell + '</td>' +
        '<td>' + escapeHtml(line.remarks) + '</td>';
      linesBody.appendChild(tr);
    });
  }

  linesBody && linesBody.addEventListener('click', function (e) {
    const btn = e.target.closest('.rel-line-remove');
    if (!btn) return;
    releaseLines.splice(parseInt(btn.dataset.idx, 10), 1);
    renderReleaseLines();
  });

  addLineBtn && addLineBtn.addEventListener('click', function () {
    if (!scannedItem) { alert('Scan a barcode or pick an item first.'); return; }

    const isIndividual = scannedItem.TrackingMethod === 'Individual Unit Tracking';

    if (isIndividual) {
      const checkedBoxes = relUnitsBody.querySelectorAll('.rel-unit-checkbox:checked');
      if (checkedBoxes.length === 0) { alert('Select at least one unit.'); return; }

      const newBarcodes = Array.prototype.map.call(checkedBoxes, function (cb) { return cb.value; });

      const existing = releaseLines.find(function (l) { return l.po_item_id === scannedItem.POItemID; });
      if (existing) {
        existing.unit_barcodes = (existing.unit_barcodes || []).concat(newBarcodes);
        existing.qty_released = existing.unit_barcodes.length;
      } else {
        releaseLines.push({
          po_item_id:       scannedItem.POItemID,
          item_barcode:     scannedItem.UnitBarcode,
          item_description: scannedItem.ItemDescription,
          condition:        scannedItem.ItemCondition || 'Brand New',
          tracking_method:  scannedItem.TrackingMethod,
          qty_released:     newBarcodes.length,
          available:        scannedItem.Available,
          remarks:          relLineRemarks.value.trim(),
          unit_barcodes:    newBarcodes
        });
      }
    } else {
      const qty = parseInt(relQty.value, 10) || 0;
      if (qty <= 0)  { alert('Enter a quantity.'); return; }
      if (qty > scannedItem.Available) {
        alert('Cannot release more than available stock (' + scannedItem.Available + ').');
        return;
      }

      // Match on the exact PO line scanned (same barcode = same batch)
      const existing = releaseLines.find(function (l) {
        return l.po_item_id === scannedItem.POItemID;
      });
      if (existing) {
        if (existing.qty_released + qty > scannedItem.Available) {
          alert('Total quantity for this barcode would exceed available stock (' + scannedItem.Available + ').');
          return;
        }
        existing.qty_released += qty;
      } else {
        releaseLines.push({
          po_item_id:       scannedItem.POItemID,
          item_barcode:     scannedItem.UnitBarcode,
          item_description: scannedItem.ItemDescription,
          condition:        scannedItem.ItemCondition || 'Brand New',
          tracking_method:  scannedItem.TrackingMethod,
          qty_released:     qty,
          available:        scannedItem.Available,
          remarks:          relLineRemarks.value.trim()
        });
      }
    }

    relLineRemarks.value = '';
    clearScannedItem();
    renderReleaseLines();
    relScanBarcode.focus();
  });

  // Submit
  releaseForm && releaseForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!relDepartment.value) { alert('Select a department.'); return; }
    if (!relReleasedTo.value.trim()) { alert('Enter who this is released to.'); return; }
    if (releaseLines.length === 0) { alert('Add at least one item.'); return; }

    const origLabel = relSaveBtn.innerHTML;
    relSaveBtn.disabled = true;
    relSaveBtn.textContent = 'Saving...';

    const payload = new FormData();
    payload.append('department',  relDepartment.value);
    payload.append('released_to', relReleasedTo.value.trim());
    payload.append('remarks',     relRemarks.value.trim());
    payload.append('lines_json',  JSON.stringify(releaseLines));

    fetch('save_release.php', { method: 'POST', body: payload })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { alert(data.message || 'Could not save release.'); return; }
        closeOverlay(releaseModal);
        window.location.reload();
      })
      .catch(function (err) {
        console.error(err);
        alert('Connection error. Check TradewellDatabase.');
      })
      .finally(function () {
        relSaveBtn.disabled = false;
        relSaveBtn.innerHTML = origLabel;
      });
  });

  /* ================================================================
     RETURN MODAL
  ================================================================ */
  const returnModal    = document.getElementById('returnModal');
  const closeRetBtn    = document.getElementById('closeReturnModal');
  const cancelRetBtn   = document.getElementById('cancelReturnBtn');
  const confirmRetBtn  = document.getElementById('confirmReturnBtn');
  const retLinesBody   = document.getElementById('retLinesBody');
  let pendingReturnId  = null;

  closeRetBtn   && closeRetBtn.addEventListener('click', function () { closeOverlay(returnModal); });
  cancelRetBtn  && cancelRetBtn.addEventListener('click', function () { closeOverlay(returnModal); });
  returnModal   && returnModal.addEventListener('click', function (e) {
    if (e.target === returnModal) closeOverlay(returnModal);
  });

  document.querySelectorAll('.release-return-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = btn.dataset.id;
      fetch('get_release.php?id=' + encodeURIComponent(id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.error) { alert(data.message || 'Could not load release.'); return; }
          pendingReturnId = data.release.ReleaseID;
          document.getElementById('retReleaseNumber').textContent = data.release.ReleaseNumber || '—';
          document.getElementById('retDepartment').textContent    = data.release.Department    || '—';
          document.getElementById('retReleasedTo').textContent    = data.release.ReleasedTo    || '—';

          retLinesBody.innerHTML = '';
          data.lines.forEach(function (line) {
            const released  = parseFloat(line.QtyReleased)  || 0;
            const returned  = parseFloat(line.QtyReturned)  || 0;
            const remaining = Math.max(0, released - returned);
            if (remaining <= 0) return; // already fully returned

            const tr = document.createElement('tr');
            tr.innerHTML =
              '<td>' + escapeHtml(line.ItemDescription) + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono);">' + released + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono);">' + returned + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono); font-weight:600;">' + remaining + '</td>' +
              '<td><input type="number" class="ret-qty-input scan-input" style="padding:6px 8px;" ' +
              'data-item-id="' + escapeHtml(line.ReleaseItemID) + '" ' +
              'min="0" max="' + remaining + '" placeholder="0" value="' + remaining + '"></td>';
            retLinesBody.appendChild(tr);
          });

          if (retLinesBody.children.length === 0) {
            retLinesBody.innerHTML = '<tr><td colspan="5"><div class="table-empty">All items from this release have already been returned.</div></td></tr>';
          }

          openOverlay(returnModal);
        })
        .catch(function (err) {
          console.error(err);
          alert('Could not reach the server.');
        });
    });
  });

  confirmRetBtn && confirmRetBtn.addEventListener('click', function () {
    if (!pendingReturnId) return;

    const qtyInputs = retLinesBody.querySelectorAll('.ret-qty-input');
    const lines = [];
    qtyInputs.forEach(function (input) {
      const qty = parseFloat(input.value) || 0;
      if (qty > 0) {
        lines.push({ release_item_id: input.dataset.itemId, qty_returned: qty });
      }
    });

    if (lines.length === 0) { alert('Enter a return quantity for at least one item.'); return; }

    const origLabel = confirmRetBtn.innerHTML;
    confirmRetBtn.disabled = true;
    confirmRetBtn.textContent = 'Saving...';

    const payload = new FormData();
    payload.append('release_id',  pendingReturnId);
    payload.append('lines_json',  JSON.stringify(lines));

    fetch('save_return.php', { method: 'POST', body: payload })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { alert(data.message || 'Could not save return.'); return; }
        closeOverlay(returnModal);
        window.location.reload();
      })
      .catch(function (err) {
        console.error(err);
        alert('Connection error. Check TradewellDatabase.');
      })
      .finally(function () {
        confirmRetBtn.disabled = false;
        confirmRetBtn.innerHTML = origLabel;
      });
  });

  /* ================================================================
     VIEW MODAL
  ================================================================ */
  const viewModal       = document.getElementById('viewModal');
  const closeViewBtn    = document.getElementById('closeViewModal');
  const closeViewBtn2   = document.getElementById('closeViewModalBtn');
  const viewLinesBody   = document.getElementById('viewLinesBody');

  [closeViewBtn, closeViewBtn2].forEach(function (btn) {
    btn && btn.addEventListener('click', function () { closeOverlay(viewModal); });
  });
  viewModal && viewModal.addEventListener('click', function (e) {
    if (e.target === viewModal) closeOverlay(viewModal);
  });

  document.querySelectorAll('.release-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = btn.dataset.id;
      fetch('get_release.php?id=' + encodeURIComponent(id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.error) { alert(data.message || 'Could not load release.'); return; }
          const rel = data.release;
          document.getElementById('viewReleaseNumber').textContent = rel.ReleaseNumber || '—';
          document.getElementById('viewDepartment').textContent    = rel.Department    || '—';
          document.getElementById('viewReleasedTo').textContent    = rel.ReleasedTo    || '—';
          document.getElementById('viewDate').textContent          = rel.DateTimeInput || '—';
          document.getElementById('viewStatus').textContent        = rel.Status        || '—';
          document.getElementById('viewRemarks').textContent       = rel.Remarks       || '—';

          viewLinesBody.innerHTML = '';
          data.lines.forEach(function (line) {
            const released    = parseFloat(line.QtyReleased)  || 0;
            const returned    = parseFloat(line.QtyReturned)  || 0;
            const outstanding = Math.max(0, released - returned);
            const tr = document.createElement('tr');
            tr.innerHTML =
              '<td>' + escapeHtml(line.ItemDescription) + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono);">' + released + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono); color:var(--accent-dark);">' + returned + '</td>' +
              '<td style="text-align:center; font-family:var(--font-mono); font-weight:600; color:' +
              (outstanding > 0 ? 'var(--amber)' : 'var(--ink-300)') + ';">' + outstanding + '</td>';
            viewLinesBody.appendChild(tr);
          });

          document.getElementById('viewModalTitle').textContent =
            'Release: ' + (rel.ReleaseNumber || '—');
          openOverlay(viewModal);
        })
        .catch(function (err) {
          console.error(err);
          alert('Could not reach the server.');
        });
    });
  });

  // Print button -> opens print_release.php in a new tab
  document.querySelectorAll('.release-print-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      window.open('print_release.php?id=' + encodeURIComponent(btn.dataset.id), '_blank');
    });
  });

});