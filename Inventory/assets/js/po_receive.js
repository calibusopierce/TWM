/**
 * po_receive.js
 * Powers the "Received" button + confirmation modal on
 * technical/purchase_order.php: shows a read-only summary of what's
 * about to be received (using get_po.php), then confirms via
 * save_po_received.php.
 */
document.addEventListener('DOMContentLoaded', function () {
  const receiveModal   = document.getElementById('poReceiveModal');
  const linesBody         = document.getElementById('rcvLinesBody');
  const poCodeDisplay        = document.getElementById('rcvPoCode');
  const supplierDisplay         = document.getElementById('rcvSupplier');
  const confirmBtn                = document.getElementById('confirmReceivedBtn');

  if (!receiveModal || !confirmBtn) return;

  let pendingPoId = null;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  document.querySelectorAll('.po-receive-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const poId = btn.dataset.poId;

      fetch('get_po.php?id=' + encodeURIComponent(poId))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not load this PO.');
            return;
          }
          if (!['Open', 'Partially Received'].includes(data.po.Status)) {
            alert('This PO is already ' + data.po.Status + '.');
            return;
          }

          pendingPoId = data.po.POID;
          poCodeDisplay.textContent = data.po.PONumber || '';
          supplierDisplay.textContent = data.po.SupplierCode || '';

          linesBody.innerHTML = '';
          data.lines.forEach(function (line) {
            const ordered = parseFloat(line.QtyOrdered) || 0;
            const received = parseFloat(line.QtyReceived) || 0;
            const remaining = Math.max(0, ordered - received);
            if (remaining <= 0) return; // already fully received earlier

            const cost = parseFloat(line.UnitCost) || 0;
            const total = remaining * cost;
            const tr = document.createElement('tr');
            tr.innerHTML =
              '<td>' + remaining + '</td>' +
              '<td>' + escapeHtml(line.Unit) + '</td>' +
              '<td><div class="cell-strong">' + escapeHtml(line.ItemDescription) + '</div>' +
              '<div style="font-size:11px; color:var(--ink-300);">' + escapeHtml([line.Category, [line.Brand, line.Model].filter(Boolean).join(' ')].filter(Boolean).join(' · ') || 'N/A') + '</div></td>' +
              '<td>' + cost.toFixed(2) + '</td>' +
              '<td>' + total.toFixed(2) + '</td>';
            linesBody.appendChild(tr);
          });

          receiveModal.classList.add('is-open');
        })
        .catch(function (err) {
          console.error(err);
          alert('Could not reach the server to load this PO.');
        });
    });
  });

  confirmBtn.addEventListener('click', function () {
    if (!pendingPoId) return;

    const originalLabel = confirmBtn.textContent;
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Saving...';

    const payload = new FormData();
    payload.append('po_id', pendingPoId);

    fetch('save_po_received.php', { method: 'POST', body: payload })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data.error) {
          alert(data.message || 'Could not mark this PO as received.');
          return;
        }
        window.location.reload();
      })
      .catch(function (err) {
        console.error(err);
        alert('Something went wrong saving this.');
      })
      .finally(function () {
        confirmBtn.disabled = false;
        confirmBtn.textContent = originalLabel;
      });
  });
});
