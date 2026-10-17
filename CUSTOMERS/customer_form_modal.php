<?php
// TWM/CUSTOMERS/customer_form_modal.php
// Shared Create / Edit Customer modal. Include it inside <body> of a page that has set:
//   $cf_options = cfh_options($conn);   $cf_csrf = <that page's CSRF token>;
// Then call cfOpenCreate({qr, lat, lng}) or cfOpenEdit(row) from the page's JS.
$cf_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<style>
  .cf-backdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:4050; align-items:center; justify-content:center; padding:20px; }
  .cf-backdrop.active { display:flex; }
  .cf-modal { background:#fff; border-radius:14px; padding:20px; max-width:720px; width:100%; max-height:92vh; overflow-y:auto; box-shadow:0 10px 40px rgba(0,0,0,.35); }
  .cf-modal label { font-size:12.5px; color:#475569; margin-bottom:2px; }
  .cf-upper { text-transform:uppercase; }
</style>

<div id="cfBackdrop" class="cf-backdrop">
  <div class="cf-modal">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <h5 class="mb-0" id="cfTitle">Create Customer</h5>
      <button type="button" class="btn-close" onclick="cfClose()" aria-label="Close"></button>
    </div>

    <form id="cfForm" autocomplete="off" onsubmit="return cfSubmit(event)">
      <div class="row g-2">
        <div class="col-md-6">
          <label for="cfCode">Code</label>
          <input type="text" id="cfCode" class="form-control form-control-sm" readonly>
          <div id="cfCodeHint" class="text-muted" style="font-size:11.5px;"></div>
        </div>
        <div class="col-md-6">
          <label for="cfName">Customer Name *</label>
          <input type="text" id="cfName" class="form-control form-control-sm cf-upper" maxlength="200" required>
        </div>

        <div class="col-md-4">
          <label for="cfArea">Area *</label>
          <select id="cfArea" class="form-select form-select-sm" required onchange="cfAreaChanged()"></select>
        </div>
        <div class="col-md-4">
          <label for="cfBrgy">Barangay</label>
          <input type="text" id="cfBrgy" class="form-control form-control-sm" maxlength="100" list="cfBrgyList" oninput="cfAutoAddr()">
          <datalist id="cfBrgyList"></datalist>
        </div>
        <div class="col-md-4">
          <label for="cfProv">Province</label>
          <input type="text" id="cfProv" class="form-control form-control-sm cf-upper" maxlength="100" oninput="cfProvTouched = true; cfAutoAddr()">
        </div>

        <div class="col-12">
          <label for="cfAddr">Address</label>
          <input type="text" id="cfAddr" class="form-control form-control-sm" maxlength="300" oninput="cfAddrTouched = true">
        </div>

        <div class="col-md-6">
          <label for="cfCper">Contact Person</label>
          <input type="text" id="cfCper" class="form-control form-control-sm cf-upper" maxlength="150">
        </div>
        <div class="col-md-6">
          <label for="cfCno">Contact No.</label>
          <input type="text" id="cfCno" class="form-control form-control-sm" maxlength="50">
        </div>

        <div class="col-md-6">
          <label for="cfType">Customer Type</label>
          <select id="cfType" class="form-select form-select-sm"></select>
        </div>
        <div class="col-md-6">
          <label for="cfTin">TIN</label>
          <input type="text" id="cfTin" class="form-control form-control-sm" maxlength="50">
        </div>

        <div class="col-md-6">
          <label for="cfLat">Latitude</label>
          <input type="number" id="cfLat" class="form-control form-control-sm" step="any" min="-90" max="90">
        </div>
        <div class="col-md-6">
          <label for="cfLng">Longitude</label>
          <input type="number" id="cfLng" class="form-control form-control-sm" step="any" min="-180" max="180">
        </div>

        <div class="col-12">
          <label for="cfRmk">Remarks</label>
          <textarea id="cfRmk" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
        </div>

        <div class="col-12">
          <label for="cfFile">File (JPG, PNG, GIF, WEBP or PDF, max 5 MB)</label>
          <input type="file" id="cfFile" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
          <div id="cfFileCur" class="mt-1" style="font-size:12.5px;"></div>
        </div>

        <div class="col-12 d-none" id="cfQrRow">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="cfTagQr" checked>
            <label class="form-check-label" for="cfTagQr" style="font-size:13px;color:#0f172a;">
              Also tag QR code <strong id="cfQrText"></strong> to this new customer
            </label>
          </div>
        </div>
      </div>

      <div id="cfErr" class="alert alert-danger py-2 mt-2 mb-0 d-none" style="font-size:13px;"></div>

      <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-light" onclick="cfClose()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="cfSaveBtn">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
var CFORM = {
    csrf:  <?php echo json_encode((string)($cf_csrf ?? '')); ?>,
    base:  <?php echo json_encode(base_url()); ?>,
    opts:  <?php echo json_encode($cf_options ?? ['areas' => [], 'types' => [], 'prov' => new stdClass(), 'brgy' => new stdClass()], $cf_json_flags); ?>
};
var cfMode = 'create', cfEditId = 0, cfQr = '', cfAddrTouched = false, cfProvTouched = false, cfBusy = false;

function cfEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function cfEl(id) { return document.getElementById(id); }

// Fills a <select> with the list, adding `current` as an extra option if it is not in the list
function cfFillSelect(sel, list, current, blankLabel) {
    var html = '<option value="">' + cfEsc(blankLabel) + '</option>';
    var found = false;
    list.forEach(function (v) {
        if (v === current) found = true;
        html += '<option value="' + cfEsc(v) + '">' + cfEsc(v) + '</option>';
    });
    if (current && !found) html += '<option value="' + cfEsc(current) + '">' + cfEsc(current) + '</option>';
    sel.innerHTML = html;
    sel.value = current || '';
}

function cfAreaPrefix(area) {
    var p = String(area || '').replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3);
    return p || 'XXX';
}

function cfRefreshBarangays() {
    var area = cfEl('cfArea').value;
    var list = (CFORM.opts.brgy && CFORM.opts.brgy[area]) || [];
    cfEl('cfBrgyList').innerHTML = list.map(function (b) { return '<option value="' + cfEsc(b) + '"></option>'; }).join('');
}

function cfAutoAddr() {
    if (cfAddrTouched) return;
    var parts = [cfEl('cfBrgy').value.trim(), cfEl('cfArea').value.trim(), cfEl('cfProv').value.trim()].filter(function (x) { return x !== ''; });
    cfEl('cfAddr').value = parts.join(', ');
}

function cfAreaChanged() {
    var area = cfEl('cfArea').value;
    if (!cfProvTouched) {
        var pv = (CFORM.opts.prov && CFORM.opts.prov[area]) || '';
        cfEl('cfProv').value = pv;
    }
    cfRefreshBarangays();
    if (cfMode === 'create') {
        var lastId = CFORM.opts.last_id || 0;
        cfEl('cfCode').value = area ? ('CUS-' + cfAreaPrefix(area) + '-' + (lastId + 1)) : '';
        cfEl('cfCodeHint').textContent = 'Last ID: ' + lastId + ' (final ID is assigned on save)';
    }
    cfAutoAddr();
}

function cfShowErr(msg) {
    var e = cfEl('cfErr');
    e.textContent = msg;
    e.classList.remove('d-none');
}

function cfClearErr() {
    var e = cfEl('cfErr');
    e.textContent = '';
    e.classList.add('d-none');
}

function cfResetFields() {
    ['cfName', 'cfBrgy', 'cfProv', 'cfAddr', 'cfCper', 'cfCno', 'cfTin', 'cfLat', 'cfLng', 'cfRmk', 'cfCode'].forEach(function (id) { cfEl(id).value = ''; });
    cfEl('cfFile').value = '';
    cfEl('cfFileCur').innerHTML = '';
    cfEl('cfCodeHint').textContent = '';
    cfEl('cfQrRow').classList.add('d-none');
    cfEl('cfTagQr').checked = true;
    cfClearErr();
    cfBusy = false;
    cfEl('cfSaveBtn').disabled = false;
    cfEl('cfSaveBtn').textContent = 'Save';
}

function cfOpenCreate(o) {
    o = o || {};
    cfMode = 'create'; cfEditId = 0; cfQr = o.qr ? String(o.qr) : '';
    cfAddrTouched = false; cfProvTouched = false;
    cfResetFields();
    cfEl('cfTitle').textContent = 'Create Customer';
    cfEl('cfCodeHint').textContent = 'Last ID: ' + (CFORM.opts.last_id || 0) + ' (pick an Area to see the new code)';
    cfFillSelect(cfEl('cfArea'), CFORM.opts.areas, '', '— Select area —');
    cfFillSelect(cfEl('cfType'), CFORM.opts.types, '', '— Select type —');
    cfRefreshBarangays();
    if (o.lat !== null && o.lat !== undefined && o.lng !== null && o.lng !== undefined) {
        cfEl('cfLat').value = o.lat;
        cfEl('cfLng').value = o.lng;
    }
    if (cfQr) {
        cfEl('cfQrText').textContent = cfQr;
        cfEl('cfQrRow').classList.remove('d-none');
    }
    cfEl('cfBackdrop').classList.add('active');
    cfEl('cfName').focus();
}

function cfOpenEdit(c) {
    if (!c) return;
    cfMode = 'edit'; cfEditId = c.id; cfQr = '';
    cfAddrTouched = true; cfProvTouched = true; // keep what is saved; do not auto-overwrite
    cfResetFields();
    cfEl('cfTitle').textContent = 'Edit Customer';
    cfFillSelect(cfEl('cfArea'), CFORM.opts.areas, c.area || '', '— Select area —');
    cfFillSelect(cfEl('cfType'), CFORM.opts.types, c.ctype || '', '— Select type —');
    cfRefreshBarangays();
    cfEl('cfCode').value = c.code || '';
    cfEl('cfName').value = c.name || '';
    cfEl('cfBrgy').value = c.brgy || '';
    cfEl('cfProv').value = c.prov || '';
    cfEl('cfAddr').value = c.address || '';
    cfEl('cfCper').value = c.cper || '';
    cfEl('cfCno').value  = c.cno || '';
    cfEl('cfTin').value  = c.tin || '';
    cfEl('cfRmk').value  = c.rmk || '';
    cfEl('cfLat').value  = (c.flat === '' || c.flat == null) ? '' : c.flat;
    cfEl('cfLng').value  = (c.flng === '' || c.flng == null) ? '' : c.flng;
    if (c.fpath) {
        cfEl('cfFileCur').innerHTML = 'Current file: <a href="' + cfEsc(CFORM.base + encodeURI(c.fpath)) + '" target="_blank" rel="noopener">view</a> &middot; choosing a new file replaces it';
    }
    cfEl('cfBackdrop').classList.add('active');
    cfEl('cfName').focus();
}

function cfClose() {
    cfEl('cfBackdrop').classList.remove('active');
}

function cfSubmit(ev) {
    if (ev) ev.preventDefault();
    cfSend(false);
    return false;
}

function cfSend(force) {
    if (cfBusy) return;
    cfClearErr();

    var fd = new FormData();
    fd.append('action', cfMode === 'edit' ? 'customer_update' : 'customer_create');
    fd.append('csrf', CFORM.csrf);
    if (cfMode === 'edit') fd.append('id', cfEditId);
    fd.append('name', cfEl('cfName').value);
    fd.append('area', cfEl('cfArea').value);
    fd.append('barangay', cfEl('cfBrgy').value);
    fd.append('province', cfEl('cfProv').value);
    fd.append('address', cfEl('cfAddr').value);
    fd.append('contact_person', cfEl('cfCper').value);
    fd.append('contact_no', cfEl('cfCno').value);
    fd.append('customer_type', cfEl('cfType').value);
    fd.append('tin', cfEl('cfTin').value);
    fd.append('latitude', cfEl('cfLat').value);
    fd.append('longitude', cfEl('cfLng').value);
    fd.append('remarks', cfEl('cfRmk').value);
    if (force) fd.append('force', '1');

    if (cfMode === 'create' && cfQr) {
        fd.append('qrcode', cfQr);
        fd.append('tag_qr', cfEl('cfTagQr').checked ? '1' : '0');
    }

    var file = cfEl('cfFile').files[0];
    if (file) {
        if (file.size > 5 * 1024 * 1024) { cfShowErr('The file is larger than 5 MB.'); return; }
        fd.append('file', file);
    }

    cfBusy = true;
    var btn = cfEl('cfSaveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch(location.href, { method: 'POST', body: fd })
        .then(function (r) { return r.text(); })
        .then(function (txt) {
            var data;
            try { data = JSON.parse(txt); }
            catch (e) { throw new Error('Unexpected server response. The file may be too large, or your session may have expired.'); }

            if (data.ok) {
                btn.textContent = 'Saved — reloading...';
                setTimeout(function () { location.reload(); }, 600);
                return;
            }
            cfBusy = false;
            btn.disabled = false;
            btn.textContent = 'Save';
            if (data.duplicate) {
                if (window.confirm(data.error + '\n\nSave it anyway?')) cfSend(true);
                return;
            }
            cfShowErr(data.error || 'Save failed.');
        })
        .catch(function (e) {
            cfBusy = false;
            btn.disabled = false;
            btn.textContent = 'Save';
            cfShowErr(e.message || 'Save failed.');
        });
}
</script>
