/**
 * items_scan.js
 * Barcode scanning panel for warehouse/items.php.
 *
 * Relies on ITEMS_DATA (array of item objects injected by PHP).
 *
 * Features:
 *  - Row scan button → opens panel focused on that item
 *  - "Scan All" button → Quick Mode (scanner auto-matches rows by barcode)
 *  - Auto-advance: after each barcode input is filled and Enter is pressed,
 *    focus jumps to the next empty field automatically
 *  - Skip button per field
 *  - Green check + field turn when a barcode is accepted
 *  - Prev / Next nav to move between items without closing the panel
 *  - Save button → POST to save_barcode.php
 *  - Progress dots in the table update immediately after a save (no reload)
 */

(function () {
  'use strict';

  /* ---- State ---- */
  var items        = window.ITEMS_DATA || [];
  var currentIndex = -1;   // which item is loaded in the panel
  var quickMode    = false; // Scan All mode
  var fieldIds     = ['bcCase', 'bcBag', 'bcPieces'];
  var fieldKeys    = ['BarcodeCs', 'BarcodeBg', 'BarcodePc'];
  var postKeys     = ['bc_cs',    'bc_bg',    'bc_pc'];

  /* ---- DOM refs ---- */
  var panel          = document.getElementById('scanPanel');
  var itemsLayout    = document.getElementById('itemsLayout');
  var closePanelBtn  = document.getElementById('closeScanPanel');
  var scanItemCode   = document.getElementById('scanItemCode');
  var scanItemName   = document.getElementById('scanItemName');
  var scanItemDept   = document.getElementById('scanItemDept');
  var scanModeBar    = document.getElementById('scanModeBar');
  var scanQuickWrap  = document.getElementById('scanQuickWrap');
  var scanQuickInput = document.getElementById('scanQuickInput');
  var scanMatchStatus= document.getElementById('scanMatchStatus');
  var scanFields     = document.getElementById('scanFields');
  var scanCounter    = document.getElementById('scanItemCounter');
  var btnSave        = document.getElementById('btnSaveBarcodes');
  var btnPrev        = document.getElementById('btnPrevItem');
  var btnNext        = document.getElementById('btnNextItem');
  var btnScanAll     = document.getElementById('btnScanAll');
  var exitQuickBtn   = document.getElementById('exitQuickMode');
  var barcodeFilter  = document.getElementById('barcodeFilter');
  var itemSearch     = document.getElementById('itemSearch');

  if (!panel) return; // not on the items page

  /* ---- Open panel for a specific item index ---- */
  function openPanel(idx) {
    if (idx < 0 || idx >= items.length) return;
    currentIndex = idx;
    var it = items[idx];

    // Header info
    scanItemCode.textContent = it.ItemCode || '—';
    scanItemName.textContent = it.ItemDescription || '—';
    scanItemDept.textContent = it.Department || '';

    // Counter
    scanCounter.textContent = (idx + 1) + ' / ' + items.length;

    // Populate and style each field
    fieldIds.forEach(function (fId, i) {
      var input = document.getElementById(fId);
      var check = document.getElementById('check_' + fId);
      var wrap  = document.getElementById('wrap_' + fId);
      var raw   = it[fieldKeys[i]];
      var val   = (raw && String(raw).trim() !== '0') ? String(raw).trim() : '';
      input.value = val;
      if (val) {
        wrap.classList.add('is-done');
        check.classList.add('is-visible');
      } else {
        wrap.classList.remove('is-done');
        check.classList.remove('is-visible');
        input.disabled = false;
      }
    });

    // Show/focus first empty field
    focusFirstEmpty();

    // Nav button states
    btnPrev.disabled = idx === 0;
    btnNext.disabled = idx === items.length - 1;

    // Highlight active row in table
    document.querySelectorAll('#itemTable tbody tr').forEach(function (r) {
      r.classList.toggle('is-scanning', r.dataset.itemId == it.ItemID);
    });

    // Open panel (push layout)
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    itemsLayout.classList.add('panel-open');
  }

  function closePanel() {
    panel.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    itemsLayout.classList.remove('panel-open');
    document.querySelectorAll('#itemTable tbody tr').forEach(function (r) {
      r.classList.remove('is-scanning');
    });
    if (quickMode) exitQuickMode();
    currentIndex = -1;
  }

  function focusFirstEmpty() {
    for (var i = 0; i < fieldIds.length; i++) {
      var input = document.getElementById(fieldIds[i]);
      if (!input.value && !input.disabled) {
        input.focus();
        return;
      }
    }
    // All filled — focus Save
    if (btnSave) btnSave.focus();
  }

  /* ---- Auto-advance on Enter ---- */
  fieldIds.forEach(function (fId, i) {
    var input = document.getElementById(fId);
    if (!input) return;

    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      acceptBarcode(fId, i);
    });

    // Also accept when the scanner fires and moves focus away (some scanners
    // send a Tab or just blur rather than Enter)
    input.addEventListener('change', function () {
      if (input.value.trim()) acceptBarcode(fId, i);
    });
  });

  function acceptBarcode(fId, idx) {
    var input = document.getElementById(fId);
    var check = document.getElementById('check_' + fId);
    var wrap  = document.getElementById('wrap_' + fId);
    var val   = input.value.trim();
    if (!val) return;

    wrap.classList.add('is-done');
    check.classList.add('is-visible');

    // Advance to next empty field
    for (var j = idx + 1; j < fieldIds.length; j++) {
      var next = document.getElementById(fieldIds[j]);
      if (!next.value && !next.disabled) {
        next.focus();
        return;
      }
    }
    btnSave.focus(); // all done
  }

  /* ---- Skip ---- */
  document.querySelectorAll('.btn-skip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var fId = btn.dataset.field;
      var idx = fieldIds.indexOf(fId);
      var input = document.getElementById(fId);
      var wrap  = document.getElementById('wrap_' + fId);
      wrap.classList.add('is-skipped');
      input.disabled = true;
      // Advance to next
      for (var j = idx + 1; j < fieldIds.length; j++) {
        var next = document.getElementById(fieldIds[j]);
        if (!next.value && !next.disabled) {
          next.focus();
          return;
        }
      }
      btnSave.focus();
    });
  });

  /* ---- Save barcodes ---- */
  btnSave.addEventListener('click', function () {
    if (currentIndex < 0) return;
    var it = items[currentIndex];

    var payload = new FormData();
    payload.append('item_id', it.ItemID);

    var anyFilled = false;
    fieldIds.forEach(function (fId, i) {
      var val = document.getElementById(fId).value.trim();
      payload.append(postKeys[i], val);
      if (val) anyFilled = true;
    });

    if (!anyFilled) {
      alert('Please scan at least one barcode before saving.');
      return;
    }

    var origLabel = btnSave.innerHTML;
    btnSave.disabled = true;
    btnSave.textContent = 'Saving…';

    fetch('save_barcode.php', { method: 'POST', body: payload })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) {
          alert('Could not save: ' + data.message);
          return;
        }

        // Update local ITEMS_DATA so nav stays consistent
        fieldIds.forEach(function (fId, i) {
          var val = document.getElementById(fId).value.trim();
          if (val) it[fieldKeys[i]] = val;
        });

        // Update barcode dots in the table row
        updateRowDots(it.ItemID, it);

        showSaveToast();

        // Auto-advance to next pending item (skip complete ones)
        if (!quickMode) {
          var nextIdx = findNextPending(currentIndex + 1);
          if (nextIdx !== -1) {
            openPanel(nextIdx);
          } else {
            closePanel();
          }
        }
      })
      .catch(function (err) {
        console.error(err);
        alert('Connection error. Check TradewellDatabase.');
      })
      .finally(function () {
        btnSave.disabled = false;
        btnSave.innerHTML = origLabel;
      });
  });

  function updateRowDots(itemId, it) {
    var row = document.querySelector('#itemTable tbody tr[data-item-id="' + itemId + '"]');
    if (!row) return;
    var dotKeys  = ['BarcodeCs', 'BarcodeBg', 'BarcodePc'];
    var dotShorts = ['CS', 'BG', 'PC'];
    var dotsEl = row.querySelector('.bc-dots');
    if (!dotsEl) return;
    dotsEl.innerHTML = dotShorts.map(function (s, i) {
      var has = !!(it[dotKeys[i]]);
      return '<span class="bc-dot ' + (has ? 'bc-dot--done' : '') + '" title="' + s + '">' + s + '</span>';
    }).join('');
    var anyDone = dotKeys.some(function (k) { return !!it[k]; });
    row.dataset.bcStatus = anyDone ? 'complete' : 'pending';
    applyFilters(false);
  }

  function findNextPending(startIdx) {
    for (var i = startIdx; i < items.length; i++) {
      var it = items[i];
      var hasAny = fieldKeys.some(function (k) { return !!it[k]; });
      if (!hasAny) return i;
    }
    return -1;
  }

  /* ---- Save toast ---- */
  function showSaveToast() {
    var t = document.getElementById('scanToast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'scanToast';
      t.className = 'scan-toast';
      document.body.appendChild(t);
    }
    t.textContent = '✓ Barcodes saved';
    t.classList.add('is-visible');
    setTimeout(function () { t.classList.remove('is-visible'); }, 2000);
  }

  /* ---- Quick Scan (Scan All) Mode ---- */
  btnScanAll && btnScanAll.addEventListener('click', function () {
    enterQuickMode();
  });

  exitQuickBtn && exitQuickBtn.addEventListener('click', function () {
    exitQuickMode();
  });

  function enterQuickMode() {
    quickMode = true;
    scanModeBar.style.display  = '';
    scanQuickWrap.style.display = '';
    scanFields.style.display   = 'none';
    panel.classList.add('is-open');
    panel.setAttribute('aria-hidden', 'false');
    itemsLayout.classList.add('panel-open');
    scanItemCode.textContent = 'Quick Scan';
    scanItemName.textContent = 'Scan any barcode to match its item';
    scanItemDept.textContent = '';
    scanMatchStatus.textContent = '';
    scanQuickInput.value = '';
    scanQuickInput.focus();
  }

  function exitQuickMode() {
    quickMode = false;
    scanModeBar.style.display   = 'none';
    scanQuickWrap.style.display = 'none';
    scanFields.style.display    = '';
    if (currentIndex >= 0) openPanel(currentIndex);
  }

  /* Quick scan: match any barcode to a row */
  scanQuickInput && scanQuickInput.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var code = scanQuickInput.value.trim();
    if (!code) return;
    var matchIdx = -1;
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (it.BarcodeCs === code || it.BarcodeBg === code ||
          it.BarcodePc === code ||
          it.ItemCode === code) {
        matchIdx = i;
        break;
      }
    }
    if (matchIdx === -1) {
      scanMatchStatus.textContent = '⚠ No matching item found for: ' + code;
      scanMatchStatus.style.color = 'var(--red)';
    } else {
      scanMatchStatus.textContent = '✓ Matched: ' + items[matchIdx].ItemCode;
      scanMatchStatus.style.color = 'var(--accent)';
      scanQuickInput.value = '';
      exitQuickMode();
      openPanel(matchIdx);
    }
  });

  /* ---- Row scan buttons ---- */
  document.querySelectorAll('.btn-scan-row').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var itemId = parseInt(btn.dataset.itemId, 10);
      var idx = items.findIndex(function (it) { return it.ItemID === itemId; });
      if (idx === -1) return;
      openPanel(idx);
    });
  });

  /* ---- Prev / Next ---- */
  btnPrev.addEventListener('click', function () { if (currentIndex > 0) openPanel(currentIndex - 1); });
  btnNext.addEventListener('click', function () { if (currentIndex < items.length - 1) openPanel(currentIndex + 1); });
  closePanelBtn.addEventListener('click', closePanel);

  /* ---- Search + barcode filter + pagination (20 per page) ---- */
  var pageSize     = 20;
  var currentPage  = 1;
  var matchedRows  = [];
  var paginationEl = document.getElementById('itemPagination');

  function computeMatches() {
    var term     = itemSearch ? itemSearch.value.trim().toLowerCase() : '';
    var bcStatus = barcodeFilter ? barcodeFilter.value : 'all';
    var rows     = Array.prototype.slice.call(
      document.querySelectorAll('#itemTable tbody tr[data-item-id]')
    );
    matchedRows = rows.filter(function (row) {
      var textMatch   = !term || row.textContent.toLowerCase().includes(term);
      var statusMatch = bcStatus === 'all' || row.dataset.bcStatus === bcStatus;
      return textMatch && statusMatch;
    });
  }

  function pageWindow(current, total) {
    var out = [];
    for (var i = 1; i <= total; i++) {
      if (i === 1 || i === total || Math.abs(i - current) <= 1) out.push(i);
    }
    var withDots = [];
    var prev = null;
    out.forEach(function (p) {
      if (prev !== null && p - prev > 1) withDots.push('…');
      withDots.push(p);
      prev = p;
    });
    return withDots;
  }

  function renderPagination(totalPages) {
    if (!paginationEl) return;
    if (totalPages <= 1) {
      paginationEl.innerHTML = '';
      return;
    }
    var html = '<button class="page-btn" data-page="prev"' + (currentPage === 1 ? ' disabled' : '') + '>&lsaquo; Prev</button>';
    pageWindow(currentPage, totalPages).forEach(function (p) {
      if (p === '…') {
        html += '<span class="page-ellipsis">&hellip;</span>';
      } else {
        html += '<button class="page-btn' + (p === currentPage ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
      }
    });
    html += '<button class="page-btn" data-page="next"' + (currentPage === totalPages ? ' disabled' : '') + '>Next &rsaquo;</button>';
    paginationEl.innerHTML = html;
  }

  function renderPage(page) {
    var totalPages = Math.max(1, Math.ceil(matchedRows.length / pageSize));
    currentPage = Math.min(Math.max(1, page), totalPages);

    var start = (currentPage - 1) * pageSize;
    var end   = start + pageSize;

    // Hide every real row first, then show only this page's slice of matches.
    document.querySelectorAll('#itemTable tbody tr[data-item-id]').forEach(function (row) {
      row.style.display = 'none';
    });
    matchedRows.slice(start, end).forEach(function (row) {
      row.style.display = '';
    });

    renderPagination(totalPages);

    var counter = document.getElementById('itemCount');
    if (counter) {
      if (matchedRows.length === 0) {
        counter.textContent = 'Showing 0 items';
      } else {
        counter.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, matchedRows.length) + ' of ' + matchedRows.length + ' items';
      }
    }
  }

  function applyFilters(resetPage) {
    computeMatches();
    renderPage(resetPage ? 1 : currentPage);
  }

  itemSearch    && itemSearch.addEventListener('input', function () { applyFilters(true); });
  barcodeFilter && barcodeFilter.addEventListener('change', function () { applyFilters(true); });

  paginationEl && paginationEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.page-btn');
    if (!btn || btn.disabled) return;
    var target = btn.dataset.page;
    if (target === 'prev') renderPage(currentPage - 1);
    else if (target === 'next') renderPage(currentPage + 1);
    else renderPage(parseInt(target, 10));
    var card = document.getElementById('itemsCard');
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  // Establish pagination on first load (all items, page 1).
  applyFilters(true);

}());
