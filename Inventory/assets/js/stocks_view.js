/**
 * stocks_view.js
 * Powers the "View" action on technical/stocks.php: shows every
 * individual barcoded unit under an item name, then a chosen unit's
 * full details (reusing get_item_by_barcode.php).
 */
document.addEventListener('DOMContentLoaded', function () {
  const viewModal       = document.getElementById('stockViewModal');
  const modalTitle         = document.getElementById('stockViewModalTitle');
  const pickerStep            = document.getElementById('unitPickerStep');
  const pickerBody               = document.getElementById('unitPickerBody');
  const detailStep                  = document.getElementById('unitDetailStep');
  const backBtn                        = document.getElementById('backToUnitListBtn');

  const detailThumb  = document.getElementById('detailThumb');
  const detailName     = document.getElementById('detailName');
  const detailMeta        = document.getElementById('detailMeta');
  const detailBarcode        = document.getElementById('detailBarcode');
  const detailStatusBadge       = document.getElementById('detailStatusBadge');
  const detailDepartment           = document.getElementById('detailDepartment');
  const detailAssignedTo               = document.getElementById('detailAssignedTo');
  const detailCondition                   = document.getElementById('detailCondition');
  const detailSerial                         = document.getElementById('detailSerial');

  if (!viewModal) return;

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function showPicker() {
    pickerStep.style.display = '';
    detailStep.style.display = 'none';
  }

  function statusToClass(status) {
    if (status === 'Retired') return 'status-inactive';
    if (status === 'Under Repair') return 'status-inactive';
    return 'status-active';
  }

  function showDetail(item) {
    detailName.textContent = item.ItemName || '—';
    const metaParts = [item.Category, [item.Brand, item.Model].filter(Boolean).join(' ')].filter(Boolean);
    detailMeta.textContent = metaParts.length ? metaParts.join(' · ') : '—';
    detailBarcode.textContent = item.Barcode || '';
    detailDepartment.textContent = item.Department || '—';
    detailAssignedTo.textContent = item.AssignedTo || '—';
    detailCondition.textContent = item.Condition || '—';
    detailSerial.textContent = item.SerialNumber || '—';

    const status = item.ItemStatus || 'In Stock';
    detailStatusBadge.textContent = status;
    detailStatusBadge.className = 'status ' + statusToClass(status);

    detailThumb.src = item.HasImage ? ('image_item.php?id=' + item.ItemID) : '';
    detailThumb.style.visibility = item.HasImage ? 'visible' : 'hidden';

    pickerStep.style.display = 'none';
    detailStep.style.display = '';
  }

  backBtn.addEventListener('click', showPicker);

  document.querySelectorAll('.stock-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const itemName = btn.dataset.itemName;
      modalTitle.textContent = 'View: ' + itemName;

      fetch('get_item_units.php?item_name=' + encodeURIComponent(itemName))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not load this item.');
            return;
          }

          pickerBody.innerHTML = '';
          data.units.forEach(function (unit) {
            const tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            tr.innerHTML =
              '<td class="cell-strong">' + escapeHtml(unit.Barcode) + '</td>' +
              '<td><span class="status ' + statusToClass(unit.ItemStatus || 'In Stock') + '">' + escapeHtml(unit.ItemStatus || 'In Stock') + '</span></td>' +
              '<td>' + escapeHtml(unit.Department) + '</td>' +
              '<td>' + escapeHtml(unit.AssignedTo) + '</td>';
            tr.addEventListener('click', function () {
              fetch('get_item_by_barcode.php?barcode=' + encodeURIComponent(unit.Barcode))
                .then(function (res) { return res.json(); })
                .then(function (detailData) {
                  if (detailData.error || !detailData.found) {
                    alert('Could not load this unit\'s details.');
                    return;
                  }
                  showDetail(detailData.item);
                })
                .catch(function (err) {
                  console.error(err);
                  alert('Could not reach the server.');
                });
            });
            pickerBody.appendChild(tr);
          });

          showPicker();
          viewModal.classList.add('is-open');
        })
        .catch(function (err) {
          console.error(err);
          alert('Could not reach the server to load this item.');
        });
    });
  });
});
