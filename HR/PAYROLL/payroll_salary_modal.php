<?php
/**
 * payroll_salary_modal.php — Basic Salary modal, included by payroll_dashboard.php.
 * Uses $canEditPayroll from the dashboard and talks to payroll_salary_ajax.php.
 */
if (!isset($canEditPayroll)) { http_response_code(403); exit; }
?>
<style>
.bs-modal { max-width: 1000px; }
.bs-searchbar { margin-bottom: .8rem; display: flex; gap: .6rem; flex-wrap: wrap; }
.bs-searchbar input[type="text"] { flex: 1 1 220px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .85rem; }
.bs-searchbar select { flex: 0 1 190px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: .85rem; background: #fff; }
@media (max-width: 700px) { .bs-searchbar select { flex: 1 1 100%; } }
#bsForm { grid-template-columns: repeat(3, 1fr); }
.bs-hint { grid-column: 1 / -1; font-size: .75rem; color: #64748b; }
.bs-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.bs-note { font-size: .75rem; color: #94a3b8; text-align: center; padding: .6rem; }
.bs-table-wrap { overflow-x: auto; }
.bs-wide { min-width: 900px; }
.bs-wide td, .bs-wide th { white-space: nowrap; }
@media (max-width: 700px) { #bsForm { grid-template-columns: 1fr 1fr; } }
</style>

<!-- Basic Salary Modal -->
<div class="pg-overlay" id="bsOverlay">
  <div class="pg-modal bs-modal">
    <div class="pg-modal-head">
      <h3><i class="bi bi-wallet2"></i> Basic Salary</h3>
      <button class="pg-close" onclick="closeBasicSalaryModal()">&times;</button>
    </div>
    <div class="pg-modal-body">

      <!-- Main view: search + employee list -->
      <div id="bsMainView">
        <div class="bs-searchbar">
          <input type="text" id="bsSearch" placeholder="Search by name or Employee ID…">
          <select id="bsDeptFilter"><option value="">All Departments</option></select>
          <select id="bsGroupFilter"><option value="">All Payroll Groups</option></select>
        </div>

        <?php if ($canEditPayroll): ?>
        <div class="pg-form" id="bsForm">
          <div style="grid-column:1 / -1;"><strong id="bsFormTitle">Set Rate</strong> — <span id="bsFormEmp"></span></div>
          <div><label for="bsMonthly">Monthly Rate</label><input type="number" step="0.01" min="0" id="bsMonthly"></div>
          <div><label for="bsCutoff">Cutoff Rate</label><input type="number" step="0.01" min="0" id="bsCutoff"></div>
          <div><label for="bsDaily">Daily Rate</label><input type="number" step="0.01" min="0" id="bsDaily"></div>
          <div><label for="bsSssEE">SSS (Employee)</label><input type="number" step="0.01" min="0" id="bsSssEE"></div>
          <div><label for="bsPagibigEE">Pag-IBIG (Employee)</label><input type="number" step="0.01" min="0" id="bsPagibigEE"></div>
          <div><label for="bsPhEE">PhilHealth (Employee)</label><input type="number" step="0.01" min="0" id="bsPhEE"></div>
          <div><label for="bsSssER">SSS (Employer)</label><input type="number" step="0.01" min="0" id="bsSssER"></div>
          <div><label for="bsPagibigER">Pag-IBIG (Employer)</label><input type="number" step="0.01" min="0" id="bsPagibigER"></div>
          <div><label for="bsPhER">PhilHealth (Employer)</label><input type="number" step="0.01" min="0" id="bsPhER"></div>
          <div><label for="bsWTax">Withholding Tax</label><input type="number" step="0.01" min="0" id="bsWTax"></div>
          <div><label for="bsAllowance">Allowance</label><input type="number" step="0.01" min="0" id="bsAllowance"></div>
          <div><label for="bsDate">Effective Date</label><input type="date" id="bsDate"></div>
          <div class="bs-hint">
            Cutoff and Daily fill in when you change Monthly (Cutoff = Monthly ÷ 2, Daily = Monthly × 12 ÷ 313) and can be overridden.
            Contributions and W-Tax are entered manually for now. Saving adds a new rate version; the previous one stays in History.
          </div>
          <div class="pg-form-actions">
            <button class="pg-btn pg-btn-secondary" onclick="closeBsForm()">Cancel</button>
            <button class="pg-btn pg-btn-primary" id="bsSaveBtn" onclick="saveBasicSalary()">Save New Rate</button>
          </div>
        </div>
        <?php endif; ?>

        <div class="bs-table-wrap">
          <table class="pg-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Payroll Group</th>
                <th class="bs-num">Monthly</th>
                <th class="bs-num">Cutoff</th>
                <th class="bs-num">Daily</th>
                <th>Effective</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="bsTableBody">
              <tr><td colspan="8" class="pg-empty">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="bs-note" id="bsNote"></div>
      </div>

      <!-- History sub-view -->
      <div id="bsHistoryView" style="display:none;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;">
          <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="closeBsHistory()"><i class="bi bi-arrow-left"></i> Back</button>
          <strong id="bsHistTitle" style="font-size:.95rem;color:#1e1b4b;"></strong>
        </div>
        <div class="bs-table-wrap">
          <table class="pg-table bs-wide">
            <thead>
              <tr>
                <th>Effective</th>
                <th class="bs-num">Monthly</th>
                <th class="bs-num">Cutoff</th>
                <th class="bs-num">Daily</th>
                <th class="bs-num">SSS EE</th>
                <th class="bs-num">SSS ER</th>
                <th class="bs-num">Pag-IBIG EE</th>
                <th class="bs-num">Pag-IBIG ER</th>
                <th class="bs-num">PH EE</th>
                <th class="bs-num">PH ER</th>
                <th class="bs-num">W-Tax</th>
                <th class="bs-num">Allowance</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="bsHistBody">
              <tr><td colspan="13" class="pg-empty">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function() {
  const bsCanEdit   = <?= $canEditPayroll ? 'true' : 'false' ?>;
  const bsOverlay   = document.getElementById('bsOverlay');
  const bsMainView  = document.getElementById('bsMainView');
  const bsHistView  = document.getElementById('bsHistoryView');
  const bsTableBody = document.getElementById('bsTableBody');
  const bsHistBody  = document.getElementById('bsHistBody');
  const bsHistTitle = document.getElementById('bsHistTitle');
  const bsNote      = document.getElementById('bsNote');
  const bsSearch    = document.getElementById('bsSearch');
  const bsDeptFilter  = document.getElementById('bsDeptFilter');
  const bsGroupFilter = document.getElementById('bsGroupFilter');
  const bsForm      = document.getElementById('bsForm');      // null for view-only users
  let filtersLoaded = false;

  // [POST field name, input id]
  const FIELDS = [
    ['SMonthly', 'bsMonthly'], ['SCutoff', 'bsCutoff'], ['SDaily', 'bsDaily'],
    ['SSSEE', 'bsSssEE'], ['SPagibigEE', 'bsPagibigEE'], ['SPHEE', 'bsPhEE'],
    ['SSSER', 'bsSssER'], ['SPagibigER', 'bsPagibigER'], ['SPHER', 'bsPhER'],
    ['SWTax', 'bsWTax'], ['SAllowance', 'bsAllowance'], ['SDateActive', 'bsDate']
  ];

  let bsRows = [];
  let editingEmp = null;
  let searchTimer = null;
  let listSeq = 0;

  function esc(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
  }

  function money(v) {
    if (v === null || v === undefined || v === '') return '—';
    return Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function todayStr() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  function showMain() {
    bsHistView.style.display = 'none';
    bsMainView.style.display = 'block';
  }

  window.openBasicSalaryModal = function() {
    bsOverlay.classList.add('open');
    showMain();
    closeBsForm();
    bsSearch.value = '';
    if (!filtersLoaded) { loadFilterOptions(); filtersLoaded = true; }
    loadList();
  };

  function loadFilterOptions() {
    fetch('payroll_salary_ajax.php?action=filters')
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        (data.data.departments || []).forEach(d => {
          const opt = document.createElement('option');
          opt.value = d; opt.textContent = d;
          bsDeptFilter.appendChild(opt);
        });
        (data.data.payrollGroups || []).forEach(g => {
          const opt = document.createElement('option');
          opt.value = g; opt.textContent = g;
          bsGroupFilter.appendChild(opt);
        });
      })
      .catch(() => {});
  }

  window.closeBasicSalaryModal = function() {
    bsOverlay.classList.remove('open');
  };

  window.closeBsForm = function() {
    if (bsForm) bsForm.classList.remove('open');
    editingEmp = null;
  };

  function loadList() {
    const seq = ++listSeq;
    bsTableBody.innerHTML = '<tr><td colspan="8" class="pg-empty">Loading…</td></tr>';
    bsNote.textContent = '';

    const qs = new URLSearchParams({
      action: 'list',
      q: bsSearch.value.trim(),
      Department: bsDeptFilter.value,
      PayrollGroup: bsGroupFilter.value
    });
    fetch('payroll_salary_ajax.php?' + qs.toString())
      .then(r => r.json())
      .then(data => {
        if (seq !== listSeq) return;   // a newer search superseded this one
        if (!data.success) {
          bsTableBody.innerHTML = '<tr><td colspan="8" class="pg-empty">' + esc(data.message || 'Failed to load.') + '</td></tr>';
          return;
        }
        bsRows = data.data;
        if (!bsRows.length) {
          bsTableBody.innerHTML = '<tr><td colspan="8" class="pg-empty">No employees found.</td></tr>';
          return;
        }
        bsTableBody.innerHTML = bsRows.map((row, i) => `
          <tr>
            <td>${esc(row.Name)}<div style="font-size:.7rem;color:#94a3b8;">ID ${esc(row.EmployeeID)}</div></td>
            <td>${esc(row.Department || '—')}</td>
            <td>${esc(row.PayrollGroup || '—')}</td>
            <td class="bs-num">${money(row.SMonthly)}</td>
            <td class="bs-num">${money(row.SCutoff)}</td>
            <td class="bs-num">${money(row.SDaily)}</td>
            <td>${esc(row.SDateActive || '—')}</td>
            <td style="white-space:nowrap;">
              ${bsCanEdit ? `<button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="editBasicSalary(${i})">${row.SalaryID ? 'Update Rate' : 'Set Rate'}</button>` : ''}
              <button class="pg-btn pg-btn-secondary pg-btn-sm" onclick="openBsHistory(${i})">History</button>
            </td>
          </tr>
        `).join('');
        if (data.truncated) {
          bsNote.textContent = 'Showing the first ' + bsRows.length + ' employees — search to narrow the list.';
        }
      })
      .catch(() => {
        if (seq !== listSeq) return;
        bsTableBody.innerHTML = '<tr><td colspan="8" class="pg-empty">Something went wrong loading the list.</td></tr>';
      });
  }

  bsSearch.addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadList, 300);
  });

  bsDeptFilter.addEventListener('change', loadList);
  bsGroupFilter.addEventListener('change', loadList);

  window.editBasicSalary = function(i) {
    const row = bsRows[i];
    if (!row || !bsForm) return;
    editingEmp = row;
    document.getElementById('bsFormTitle').textContent = row.SalaryID ? 'Update Rate' : 'Set Rate';
    document.getElementById('bsFormEmp').textContent = row.Name + ' (ID ' + row.EmployeeID + ')';
    FIELDS.forEach(([key, id]) => {
      const el = document.getElementById(id);
      if (key === 'SDateActive') { el.value = todayStr(); return; }
      el.value = (row.SalaryID && row[key] !== null && row[key] !== undefined) ? Number(row[key]).toFixed(2) : '';
    });
    bsForm.classList.add('open');
    document.querySelector('#bsOverlay .pg-modal-body').scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Monthly changed -> ask the server for Cutoff / Daily (single source of truth for the formula)
  const bsMonthly = document.getElementById('bsMonthly');
  if (bsMonthly) {
    bsMonthly.addEventListener('change', function() {
      const v = this.value.trim();
      if (!v || Number(v) <= 0) return;
      fetch('payroll_salary_ajax.php?action=compute&SMonthly=' + encodeURIComponent(v))
        .then(r => r.json())
        .then(data => {
          if (!data.success) return;
          document.getElementById('bsCutoff').value = Number(data.data.SCutoff).toFixed(2);
          document.getElementById('bsDaily').value  = Number(data.data.SDaily).toFixed(2);
          const c = data.data.contributions || {};
          const fill = (id, v) => { if (v !== null && v !== undefined) document.getElementById(id).value = Number(v).toFixed(2); };
          fill('bsSssEE', c.SSSEE);          fill('bsSssER', c.SSSER);
          fill('bsPagibigEE', c.SPagibigEE); fill('bsPagibigER', c.SPagibigER);
          fill('bsPhEE', c.SPHEE);           fill('bsPhER', c.SPHER);
          if (c.warnings && c.warnings.length) alert(c.warnings.join('\n'));
        })
        .catch(() => {});
    });
  }

  window.saveBasicSalary = function() {
    if (!editingEmp) return;
    const monthly = document.getElementById('bsMonthly').value.trim();
    if (!monthly || Number(monthly) <= 0) {
      alert('Monthly Rate is required.');
      return;
    }
    if (editingEmp.SalaryID && !confirm('Save a new rate for ' + editingEmp.Name + '? The current rate will be moved to History.')) {
      return;
    }

    const body = new URLSearchParams();
    body.set('action', 'save');
    body.set('EmployeeID', editingEmp.EmployeeID);
    FIELDS.forEach(([key, id]) => body.set(key, document.getElementById(id).value.trim()));

    const btn = document.getElementById('bsSaveBtn');
    btn.disabled = true;

    fetch('payroll_salary_ajax.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      if (data.success) {
        closeBsForm();
        loadList();
      } else {
        alert(data.message || 'Save failed.');
      }
    })
    .catch(() => {
      btn.disabled = false;
      alert('Something went wrong. Please try again.');
    });
  };

  // ---- History sub-view ----
  window.openBsHistory = function(i) {
    const row = bsRows[i];
    if (!row) return;
    bsHistTitle.textContent = 'Rate history — ' + row.Name;
    bsMainView.style.display = 'none';
    bsHistView.style.display = 'block';
    bsHistBody.innerHTML = '<tr><td colspan="13" class="pg-empty">Loading…</td></tr>';

    fetch('payroll_salary_ajax.php?action=history&EmployeeID=' + encodeURIComponent(row.EmployeeID))
      .then(r => r.json())
      .then(data => {
        if (!data.success) {
          bsHistBody.innerHTML = '<tr><td colspan="13" class="pg-empty">' + esc(data.message || 'Failed to load.') + '</td></tr>';
          return;
        }
        if (!data.data.length) {
          bsHistBody.innerHTML = '<tr><td colspan="13" class="pg-empty">No rate on record yet.</td></tr>';
          return;
        }
        bsHistBody.innerHTML = data.data.map(h => `
          <tr>
            <td>${esc(h.SDateActive || '—')}</td>
            <td class="bs-num">${money(h.SMonthly)}</td>
            <td class="bs-num">${money(h.SCutoff)}</td>
            <td class="bs-num">${money(h.SDaily)}</td>
            <td class="bs-num">${money(h.SSSEE)}</td>
            <td class="bs-num">${money(h.SSSER)}</td>
            <td class="bs-num">${money(h.SPagibigEE)}</td>
            <td class="bs-num">${money(h.SPagibigER)}</td>
            <td class="bs-num">${money(h.SPHEE)}</td>
            <td class="bs-num">${money(h.SPHER)}</td>
            <td class="bs-num">${money(h.SWTax)}</td>
            <td class="bs-num">${money(h.SAllowance)}</td>
            <td>${Number(h.SActive) ? '<span class="pg-badge-active">Current</span>' : '<span class="pg-badge-inactive">Old</span>'}</td>
          </tr>
        `).join('');
      })
      .catch(() => {
        bsHistBody.innerHTML = '<tr><td colspan="13" class="pg-empty">Something went wrong loading the history.</td></tr>';
      });
  };

  window.closeBsHistory = function() {
    showMain();
  };
})();
</script>