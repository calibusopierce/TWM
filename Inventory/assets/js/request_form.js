/**
 * request_form.js
 * technical/requests.php — Item Request / Repair Request tabs, the two
 * "New ..." forms, the barcode -> item-name lookup on Repair Request,
 * and the Approve / Reject / Mark Fulfilled row actions.
 *
 * Relies on REQ_IS_SUPERADMIN (bool, injected by requests.php) to decide
 * whether to wire up the approve/reject/fulfill buttons at all --
 * they're not rendered server-side for non-superadmins anyway, but the
 * guard here keeps this file harmless either way. The real
 * enforcement is server-side in save_request_status.php.
 */

(function () {
  'use strict';

  /* ---- Tabs ---- */
  var tabButtons = document.querySelectorAll('.reqtab');
  var panels = {
    itemRequests: document.getElementById('panel-itemRequests'),
    repairRequests: document.getElementById('panel-repairRequests')
  };

  tabButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = btn.dataset.tab;
      tabButtons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
      Object.keys(panels).forEach(function (key) {
        if (panels[key]) panels[key].style.display = (key === target) ? '' : 'none';
      });
    });
  });

  /* ---- Item Request form -> save_item_request.php ---- */
  var itemRequestForm = document.getElementById('itemRequestForm');
  if (itemRequestForm) {
    itemRequestForm.addEventListener('submit', function (e) {
      e.preventDefault();

      var payload = new FormData();
      payload.append('request_date', document.getElementById('irRequestDate').value);
      payload.append('department', document.getElementById('irDepartment').value);
      payload.append('requested_by', document.getElementById('irRequestedBy').value);
      payload.append('area', document.getElementById('irArea').value);
      payload.append('facilities', document.getElementById('irFacilities').value);
      payload.append('item_name', document.getElementById('irItemName').value);
      payload.append('quantity', document.getElementById('irQuantity').value);
      payload.append('purpose', document.getElementById('irPurpose').value);

      submitRequest(itemRequestForm, 'save_item_request.php', payload, 'itemRequestModal');
    });
  }

  /* ---- Repair Request form -> save_repair_request.php ---- */
  var repairRequestForm = document.getElementById('repairRequestForm');
  if (repairRequestForm) {
    repairRequestForm.addEventListener('submit', function (e) {
      e.preventDefault();

      var payload = new FormData();
      payload.append('request_date', document.getElementById('rrRequestDate').value);
      payload.append('department', document.getElementById('rrDepartment').value);
      payload.append('requested_by', document.getElementById('rrRequestedBy').value);
      payload.append('area', document.getElementById('rrArea').value);
      payload.append('facilities', document.getElementById('rrFacilities').value);
      payload.append('unit_barcode', document.getElementById('rrUnitBarcode').value);
      payload.append('item_name', document.getElementById('rrItemName').value);
      payload.append('problem', document.getElementById('rrProblem').value);

      var photoInput = document.getElementById('rrPhoto');
      if (photoInput && photoInput.files[0]) {
        payload.append('photo', photoInput.files[0]);
      }

      submitRequest(repairRequestForm, 'save_repair_request.php', payload, 'repairRequestModal');
    });
  }

  function submitRequest(form, url, payload, modalId) {
    var submitBtn = form.querySelector('button[type="submit"]');
    var originalLabel = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    fetch(url, { method: 'POST', body: payload })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Could not submit this request.');
          return;
        }
        form.reset();
        var overlay = document.getElementById(modalId);
        if (overlay) overlay.classList.remove('is-open');
        window.location.reload(); // simplest way to reflect the new row for now
      })
      .catch(function (err) {
        console.error(err);
        alert('Something went wrong submitting the request. Check the connection to TradewellDatabase.');
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
      });
  }

  /* ---- Repair Request: barcode -> item name lookup ----
     Fires on Enter (scanner) or blur (typed), same idea as the Stocks
     page's scan-to-find. Resolves against TBL_Technical_PO_Item_Units
     (individual unit barcodes generated at PO time), not the asset
     registry -- a repair request is always about one specific
     physical unit. Doesn't block submission if nothing matches --
     see save_repair_request.php's note on why UnitBarcode stays free text. */
  var rrBarcodeInput = document.getElementById('rrUnitBarcode');
  var rrItemNameInput = document.getElementById('rrItemName');
  var rrBarcodeHint = document.getElementById('rrBarcodeHint');

  function lookupUnitBarcode() {
    var code = rrBarcodeInput.value.trim();
    if (!code) return;

    rrBarcodeHint.textContent = 'Looking up...';

    fetch('get_repair_unit_by_barcode.php?barcode=' + encodeURIComponent(code))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error || !data.found) {
          rrItemNameInput.value = '';
          rrBarcodeHint.textContent = 'No unit found for this barcode -- you can still submit the request.';
          rrBarcodeHint.style.color = 'var(--red)';
          return;
        }
        rrItemNameInput.value = data.item.ItemDescription || '';
        rrBarcodeHint.textContent = 'Matched: ' + (data.item.ItemDescription || '');
        rrBarcodeHint.style.color = 'var(--accent)';
      })
      .catch(function () {
        rrBarcodeHint.textContent = 'Could not look up this barcode right now.';
        rrBarcodeHint.style.color = 'var(--red)';
      });
  }

  if (rrBarcodeInput) {
    rrBarcodeInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      lookupUnitBarcode();
    });
    rrBarcodeInput.addEventListener('blur', lookupUnitBarcode);
  }

  /* ---- Item Request: requester confirms receipt ----
     Not gated on REQ_IS_SUPERADMIN -- this is the requester's own
     action on their own Item Request, enforced server-side by
     ownership in confirm_item_received.php, not by role. Only
     rendered on Approved-and-not-yet-received rows in the first
     place, so the click is always meaningful when it happens. */
  document.querySelectorAll('.req-confirm-received-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      var payload = new FormData();
      payload.append('id', btn.dataset.id);

      fetch('confirm_item_received.php', { method: 'POST', body: payload })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not confirm receipt.');
            btn.disabled = false;
            return;
          }
          window.location.reload();
        })
        .catch(function (err) {
          console.error(err);
          alert('Connection error. Check TradewellDatabase.');
          btn.disabled = false;
        });
    });
  });

  /* ---- Approve / Reject / Mark Fulfilled row actions ----
     Server-side superadmin gate lives in save_request_status.php; these
     buttons are only rendered for superadmins in the first place. */
  if (window.REQ_IS_SUPERADMIN) {

    function postStatus(type, id, action, reason) {
      var payload = new FormData();
      payload.append('type', type);
      payload.append('id', id);
      payload.append('action', action);
      if (reason) payload.append('reason', reason);

      return fetch('save_request_status.php', { method: 'POST', body: payload })
        .then(function (res) { return res.json(); });
    }

    document.querySelectorAll('.req-approve-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        postStatus(btn.dataset.type, btn.dataset.id, 'approve')
          .then(function (data) {
            if (data.error) {
              alert(data.message || 'Could not approve this request.');
              btn.disabled = false;
              return;
            }
            window.location.reload();
          })
          .catch(function (err) {
            console.error(err);
            alert('Connection error. Check TradewellDatabase.');
            btn.disabled = false;
          });
      });
    });

    document.querySelectorAll('.req-fulfill-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        postStatus(btn.dataset.type, btn.dataset.id, 'fulfill')
          .then(function (data) {
            if (data.error) {
              alert(data.message || 'Could not mark this request fulfilled.');
              btn.disabled = false;
              return;
            }
            window.location.reload();
          })
          .catch(function (err) {
            console.error(err);
            alert('Connection error. Check TradewellDatabase.');
            btn.disabled = false;
          });
      });
    });

    /* Reject goes through the Reason modal instead of firing immediately. */
    var rejectModal = document.getElementById('rejectReasonModal');
    var rejectReasonInput = document.getElementById('rejectReasonInput');
    var confirmRejectBtn = document.getElementById('confirmRejectBtn');
    var pendingReject = null; // { type, id, btn }

    document.querySelectorAll('.req-reject-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        pendingReject = { type: btn.dataset.type, id: btn.dataset.id, btn: btn };
        if (rejectReasonInput) rejectReasonInput.value = '';
        if (rejectModal) rejectModal.classList.add('is-open');
      });
    });

    if (confirmRejectBtn) {
      confirmRejectBtn.addEventListener('click', function () {
        if (!pendingReject) return;
        confirmRejectBtn.disabled = true;

        postStatus(pendingReject.type, pendingReject.id, 'reject', rejectReasonInput ? rejectReasonInput.value : '')
          .then(function (data) {
            if (data.error) {
              alert(data.message || 'Could not reject this request.');
              confirmRejectBtn.disabled = false;
              return;
            }
            window.location.reload();
          })
          .catch(function (err) {
            console.error(err);
            alert('Connection error. Check TradewellDatabase.');
            confirmRejectBtn.disabled = false;
          });
      });
    }

    /* ---- View repair photo -- opens it in a new tab ---- */
    document.querySelectorAll('.repair-photo-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window.open('repair_photo.php?id=' + encodeURIComponent(btn.dataset.id), '_blank');
      });
    });
  }

  /* ---- Search filters (per-panel, so switching tabs doesn't affect the other) ---- */
  var itemReqSearch = document.getElementById('itemReqSearch');
  if (itemReqSearch) {
    itemReqSearch.addEventListener('input', function () {
      var term = itemReqSearch.value.trim().toLowerCase();
      var rows = document.querySelectorAll('#itemReqTable tbody tr[data-request-id]');
      var count = 0;
      rows.forEach(function (row) {
        var show = !term || row.textContent.toLowerCase().includes(term);
        row.style.display = show ? '' : 'none';
        if (show) count++;
      });
      var counter = document.getElementById('itemReqCount');
      if (counter) counter.textContent = 'Showing ' + count + ' requests';
    });
  }

  var repairReqSearch = document.getElementById('repairReqSearch');
  if (repairReqSearch) {
    repairReqSearch.addEventListener('input', function () {
      var term = repairReqSearch.value.trim().toLowerCase();
      var rows = document.querySelectorAll('#repairReqTable tbody tr[data-request-id]');
      var count = 0;
      rows.forEach(function (row) {
        var show = !term || row.textContent.toLowerCase().includes(term);
        row.style.display = show ? '' : 'none';
        if (show) count++;
      });
      var counter = document.getElementById('repairReqCount');
      if (counter) counter.textContent = 'Showing ' + count + ' requests';
    });
  }

}());
