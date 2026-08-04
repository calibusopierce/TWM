/**
 * stocks_scan.js
 * Powers technical/stocks.php: scan/type a barcode to pull up an item,
 * then take an action (Assign/Transfer, Under Repair, Return to Stock,
 * Retire) which gets logged to TBL_Technical_Transactions.
 */
document.addEventListener('DOMContentLoaded', function () {
  const scanInput      = document.getElementById('barcodeScanInput');
  const lookupBtn       = document.getElementById('barcodeLookupBtn');
  const emptyState       = document.getElementById('scanEmptyState');
  const notFoundState    = document.getElementById('scanNotFound');
  const previewWrap      = document.getElementById('itemPreviewWrap');

  const previewThumb        = document.getElementById('previewThumb');
  const previewName          = document.getElementById('previewName');
  const previewMeta           = document.getElementById('previewMeta');
  const previewBarcode         = document.getElementById('previewBarcode');
  const previewStatusBadge      = document.getElementById('previewStatusBadge');
  const previewDepartment        = document.getElementById('previewDepartment');
  const previewAssignedTo         = document.getElementById('previewAssignedTo');

  const txnItemId    = document.getElementById('txnItemId');
  const txnForm       = document.getElementById('transactionForm');
  const assignFields   = document.getElementById('assignFields');
  const actionRadios    = document.querySelectorAll('#actionTypeGroup input[name="action_type"]');

  if (!scanInput || !lookupBtn) return;

  function setState(state) {
    // state: 'empty' | 'notfound' | 'found'
    emptyState.style.display    = state === 'empty'    ? '' : 'none';
    notFoundState.style.display = state === 'notfound' ? '' : 'none';
    previewWrap.style.display   = state === 'found'     ? '' : 'none';
  }

  function statusToClass(status) {
    if (status === 'Retired') return 'status-inactive';
    if (status === 'Under Repair') return 'status-inactive';
    return 'status-active';
  }

  function renderItem(item) {
    txnItemId.value = item.ItemID;
    previewName.textContent = item.ItemName || '—';
    const metaParts = [item.Category, [item.Brand, item.Model].filter(Boolean).join(' '), item.SerialNumber]
      .filter(Boolean);
    previewMeta.textContent = metaParts.length ? metaParts.join(' · ') : '—';
    previewBarcode.textContent = item.Barcode || '';
    previewDepartment.textContent = item.Department || '—';
    previewAssignedTo.textContent = item.AssignedTo || '—';

    const status = item.ItemStatus || 'In Stock';
    previewStatusBadge.textContent = status;
    previewStatusBadge.className = 'status ' + statusToClass(status);

    previewThumb.src = item.HasImage ? ('image_item.php?id=' + item.ItemID) : '';
    previewThumb.style.visibility = item.HasImage ? 'visible' : 'hidden';

    setState('found');
  }

  function lookupBarcode() {
    const code = scanInput.value.trim();
    if (!code) return;

    fetch('get_item_by_barcode.php?barcode=' + encodeURIComponent(code))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Something went wrong looking up that barcode.');
          return;
        }
        if (!data.found) {
          setState('notfound');
          return;
        }
        renderItem(data.item);
      })
      .catch(function (err) {
        console.error(err);
        alert('Could not reach the server. Check the connection to TradewellDatabase.');
      });
  }

  lookupBtn.addEventListener('click', lookupBarcode);
  scanInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      lookupBarcode();
    }
  });

  actionRadios.forEach(function (radio) {
    radio.addEventListener('change', function () {
      assignFields.style.display = (radio.value === 'assign' && radio.checked) ? '' : 'none';
    });
  });

  if (txnForm) {
    txnForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = txnForm.querySelector('button[type="submit"]');
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';

      fetch('save_transaction.php', {
        method: 'POST',
        body: new FormData(txnForm)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not save this transaction.');
            return;
          }
          // Refresh the page so both the preview and the transaction log reflect the change.
          window.location.reload();
        })
        .catch(function (err) {
          console.error(err);
          alert('Something went wrong saving the transaction.');
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }
});
