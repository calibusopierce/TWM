<?php
/**
 * index.php (formerly landing.php)
 * The first page a user sees at the site root. They pick which
 * inventory to enter — Warehouse or Technical — each lives in its own
 * folder with its own set of pages, so the choice here is really just
 * which folder to enter.
 */

 
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
auth_check(); // redirects to the real TWM login if not logged in

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tradewell — Expense Ledger</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<style>
  :root {
    --navy: #10203A;
    --navy-2: #17304F;
    --amber: #E8A33D;
    --amber-dim: #F2C983;
    --bg: #F5F5F2;
    --panel: #FFFFFF;
    --line: #DCDFE3;
    --text: #16202E;
    --text-dim: #5C6B7A;
    --service: #2E6E5B;
    --parts: #8A5A2E;
    --steel: #3B6EA8;
    --steel-2: #2F5A8A;
    --rust: #B1502E;
    --rust-2: #96421F;
    --radius: 6px;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
  }

  /* Header */
  header {
    background: var(--navy);
    color: #fff;
    padding: 18px 28px;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    border-bottom: 3px solid var(--amber);
  }
  header .brand {
    display: flex;
    align-items: baseline;
    gap: 10px;
  }
  header h1 {
    font-family: 'Barlow Condensed', sans-serif;
    font-weight: 700;
    font-size: 26px;
    letter-spacing: 0.5px;
    margin: 0;
    text-transform: uppercase;
  }
  header .subtitle {
    font-size: 12.5px;
    color: #B9C4D2;
    font-weight: 500;
  }
  #status {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
  }
  #status.ok { color: #7CD6B0; border-color: #2E6E5B; }
  #status.err { color: #F0A6A6; border-color: #7A3030; }
  .header-right {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  #backBtn {
    padding: 6px 14px;
    border: 1px solid rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.06);
    color: #fff;
    border-radius: 20px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 12.5px;
    cursor: pointer;
    display: inline-block;
    text-decoration: none;
  }
  #backBtn:hover { background: rgba(255,255,255,0.16); }

  /* Toolbar */
  .toolbar-tabs {
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    padding: 14px 28px 0;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .toolbar-filters {
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    padding: 14px 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
  }
  .tabs-group {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
  }
  .tabs {
    display: flex;
    gap: 2px;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 3px;
    margin-bottom: 0;
  }
  .tabs button {
    border: none;
    background: transparent;
    padding: 7px 16px;
    font-family: 'Barlow Condensed', sans-serif;
    font-weight: 600;
    font-size: 15px;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    color: var(--text-dim);
    border-radius: 4px;
    cursor: pointer;
  }
  .tabs button.active {
    background: var(--navy);
    color: #fff;
  }
  #refreshBtn {
    padding: 7px 14px;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--text-dim);
    border-radius: var(--radius);
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 12.5px;
    cursor: pointer;
  }
  #refreshBtn:hover { background: var(--bg); color: var(--text); }
  .field {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  .field label {
    font-family: 'Inter', sans-serif;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--text-dim);
  }
  .field input[type="date"],
  .field select {
    padding: 9px 12px;
    border: 1px solid var(--line);
    border-radius: var(--radius);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 13px;
    background: var(--bg);
    color: var(--text);
    min-width: 150px;
  }
  .field input[type="date"]:focus,
  .field select:focus { outline: 2px solid var(--amber); outline-offset: -1px; }
  .btn-row {
    display: flex;
    gap: 8px;
    margin-left: auto;
    flex-wrap: wrap;
  }
  .toolbar-filters button.action {
    padding: 9px 16px;
    border: 1px solid var(--navy);
    background: var(--navy);
    color: #fff;
    border-radius: var(--radius);
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
  }
  .toolbar-filters button.action:hover { background: var(--navy-2); }
  .toolbar-filters button.ghost {
    padding: 9px 16px;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--text-dim);
    border-radius: var(--radius);
    font-size: 13px;
    cursor: pointer;
  }
  .toolbar-filters button.ghost:hover { background: var(--bg); }

  /* Columns and Print get their own accent colors so they stand out
     from the plain ghost buttons and from each other. */
  #toggleCols {
    background: var(--steel);
    border-color: var(--steel);
    color: #fff;
  }
  #toggleCols:hover { background: var(--steel-2); border-color: var(--steel-2); }
  #printBtn {
    background: var(--rust);
    border-color: var(--rust);
    color: #fff;
  }
  #printBtn:hover { background: var(--rust-2); border-color: var(--rust-2); }

  /* Totals strip */
  .totals-bar {
    background: var(--navy);
    padding: 12px 28px;
    display: flex;
    gap: 32px;
  }
  .totals-bar .stat {
    display: flex;
    align-items: baseline;
    gap: 8px;
  }
  .totals-bar .stat-label {
    font-family: 'Inter', sans-serif;
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: #B9C4D2;
  }
  .totals-bar .stat-value {
    font-family: 'Barlow Condensed', sans-serif;
    font-weight: 700;
    font-size: 20px;
    color: #fff;
  }
  .totals-bar .stat-value.amount { color: var(--amber); }

  /* Column visibility panel */
  #colPanel {
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    padding: 12px 28px;
    display: none;
    flex-wrap: wrap;
    gap: 6px 16px;
  }
  #colPanel.open { display: flex; }
  #colPanel label {
    font-size: 12.5px;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    white-space: nowrap;
  }
  #colPanel .col-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 15px;
    height: 15px;
    padding: 0 3px;
    border-radius: 50%;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 10px;
    font-weight: 600;
    color: transparent;
  }
  #colPanel .col-order.active {
    background: var(--amber);
    color: var(--navy);
  }

  /* Table */
  .table-wrap {
    margin: 20px 28px 40px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: var(--radius);
  }
  table {
    border-collapse: collapse;
    width: 100%;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 12.5px;
  }
  thead th {
    position: sticky;
    top: 0;
    background: var(--navy);
    color: #fff;
    text-align: left;
    padding: 0;
    z-index: 2;
    border-right: 1px solid rgba(255,255,255,0.08);
  }
  thead .th-label {
    padding: 9px 10px 5px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
  }
  thead .sort-arrow { color: var(--amber); font-size: 10px; }
  tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid var(--line);
    border-right: 1px solid #EEF0F2;
    white-space: nowrap;
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  tbody tr:nth-child(even) { background: #FAFAF8; }
  tbody tr:hover { background: #FDF3E2; }
  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #fff;
  }
  .badge.Service { background: var(--service); }
  .badge.Parts { background: var(--parts); }

  #emptyState, #loadingState {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-dim);
    font-family: 'Inter', sans-serif;
  }

  /* Pagination */
  .pagination-bar {
    margin: 0 28px 40px;
    padding: 14px 20px;
    background: var(--panel);
    border: 1px solid var(--line);
    border-top: none;
    border-radius: 0 0 var(--radius) var(--radius);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
  }
  #paginationInfo {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 12.5px;
    color: var(--text-dim);
  }
  .pagination-controls {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .pagination-controls button {
    padding: 7px 14px;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--text);
    border-radius: var(--radius);
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
  }
  .pagination-controls button:hover:not(:disabled) { background: var(--bg); }
  .pagination-controls button:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
  #pageIndicator {
    font-family: 'Barlow Condensed', sans-serif;
    font-weight: 600;
    font-size: 15px;
    letter-spacing: 0.3px;
    color: var(--navy);
    white-space: nowrap;
  }
  #errorState {
    display: none;
    margin: 20px 28px;
    padding: 16px 20px;
    background: #FDECEC;
    border: 1px solid #E8A3A3;
    border-radius: var(--radius);
    color: #7A2020;
    font-size: 13.5px;
    font-family: 'Inter', sans-serif;
  }
  #errorState code {
    background: rgba(0,0,0,0.06);
    padding: 1px 5px;
    border-radius: 3px;
  }
  /* Printable report — built fresh from the currently visible columns and
     filtered rows each time Print is clicked (see buildPrintReport()).
     Everything else on the page is hidden while printing. */
  .print-only { display: none; }
  @media print {
    body { background: #fff; }
    header, .toolbar-tabs, .toolbar-filters, #colPanel, .totals-bar,
    .table-wrap, .pagination-bar, #errorState { display: none !important; }
    .print-only {
      display: block !important;
      padding: 10px;
      color: #000;
    }
    .print-header {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      border-bottom: 2.5px solid #000;
      padding-bottom: 10px;
      margin-bottom: 16px;
    }
    .print-header h1 {
      font-family: 'Barlow Condensed', sans-serif;
      font-weight: 700;
      font-size: 22px;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin: 0;
    }
    .print-date {
      font-family: 'IBM Plex Mono', monospace;
      font-size: 12px;
    }
    .print-table {
      width: 100%;
      border-collapse: collapse;
      font-family: 'IBM Plex Mono', monospace;
      font-size: 11px;
    }
    .print-table th, .print-table td {
      border: 1px solid #333;
      padding: 6px 8px;
      text-align: left;
    }
    .print-table th {
      font-family: 'Inter', sans-serif;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 10px;
      letter-spacing: 0.3px;
    }
    .print-footer {
      display: flex;
      justify-content: flex-end;
      gap: 36px;
      margin-top: 14px;
      padding-top: 10px;
      border-top: 2.5px solid #000;
      font-family: 'Barlow Condensed', sans-serif;
      font-weight: 700;
      font-size: 15px;
    }
  }
</style>
</head>
<body>

<header>
  <div class="brand">
    <h1>Expense Ledger</h1>
    <span class="subtitle"></span>
  </div>
  <div class="header-right">
<a id="backBtn" href="<?= route('home') ?>" title="Go back">‹ Back</a>
    <span id="status">...</span>
  </div>
</header>

<div class="toolbar-tabs">
  <div class="tabs-group">
    <div class="tabs" id="typeTabs">
      <button data-type="all" class="active">All</button>
      <button data-type="Service">Service</button>
      <button data-type="Parts">Parts</button>
    </div>
    <button id="refreshBtn" title="Reload data and reset columns to default">↻ Refresh</button>
  </div>
</div>

<div class="toolbar-filters">
  <div class="field">
    <label for="dateFrom">Date From</label>
    <input type="date" id="dateFrom">
  </div>
  <div class="field">
    <label for="dateTo">Date To</label>
    <input type="date" id="dateTo">
  </div>
  <div class="field">
    <label for="filterPlate">Plate Number</label>
    <select id="filterPlate"><option value="">All Plates</option></select>
  </div>
  <div class="field">
    <label for="filterDept">Department</label>
    <select id="filterDept"><option value="">All Departments</option></select>
  </div>
  <div class="btn-row">
    <button class="action" id="applyFilters">Filter</button>
    <button class="ghost" id="printBtn">Print</button>
    <button class="ghost" id="toggleCols">Columns</button>
    <button class="action" id="exportExcel">Export Excel</button>
  </div>
</div>

<div class="totals-bar">
  <div class="stat">
    <span class="stat-label">Total Records</span>
    <span class="stat-value" id="totalRecords">0</span>
  </div>
  <div class="stat">
    <span class="stat-label">Total Amount</span>
    <span class="stat-value amount" id="totalAmount">₱0.00</span>
  </div>
</div>

<div id="colPanel"></div>
<div id="errorState"></div>

<div class="table-wrap">
  <table id="dataTable">
    <thead></thead>
    <tbody></tbody>
  </table>
  <div id="loadingState">Loading data from TradewellDatabase…</div>
  <div id="emptyState" style="display:none;">No rows match the current filters.</div>
</div>

<div class="pagination-bar">
  <span id="paginationInfo"></span>
  <div class="pagination-controls">
    <button class="ghost" id="prevPage">‹ Prev</button>
    <span id="pageIndicator"></span>
    <button class="ghost" id="nextPage">Next ›</button>
  </div>
</div>

<div id="printReport" class="print-only"></div>

<script>
// ---- Column configuration -------------------------------------------------
// order = display order, common columns first, then Service-only, then Parts-only
const COLUMNS = [
  { key: 'RecordType',      label: 'Type',                 group: 'common' },
  { key: 'DateRequest',     label: 'Date Requested',       group: 'common' },
  { key: 'Department',      label: 'Department',           group: 'common' },
  { key: 'PlateNumber',     label: 'Plate No.',            group: 'common' },
  { key: 'JobPerformed',    label: 'Job Performed',        group: 'common' },
  { key: 'Amount',          label: 'Amount',               group: 'common' },
  { key: 'MID',             label: 'MID',                  group: 'common' },
  { key: 'DID',             label: 'DID',                  group: 'common' },
  { key: 'UserID',          label: 'User ID',               group: 'common' },
  { key: 'DateTimeInput',   label: 'Date/Time Input',      group: 'common' },
  { key: 'Date',            label: 'Transaction Date',     group: 'common' },
  // Service-only
  { key: 'SID',             label: 'Service ID',           group: 'Service' },
  { key: 'Type',            label: 'Service Type',         group: 'Service' },
  { key: 'Description',     label: 'Description',          group: 'Service' },
  { key: 'UserIDD',         label: 'User ID (Approver)',   group: 'Service' },
  { key: 'DateTimeInputD',  label: 'Date/Time (Approver)', group: 'Service' },
  { key: 'MID1',            label: 'MID1',                 group: 'Service' },
  // Parts-only
  { key: 'PID',       label: 'Parts ID', group: 'Parts' },
  { key: 'PONo',      label: 'PO No.',   group: 'Parts' },
  { key: 'ORNo',      label: 'OR No.',   group: 'Parts' },
  { key: 'QTY',       label: 'Qty',      group: 'Parts' },
  { key: 'Items',     label: 'Items',    group: 'Parts' },
  { key: 'Supplier',  label: 'Supplier', group: 'Parts' },
  { key: 'Remarks',   label: 'Remarks',  group: 'Parts' },
  { key: 'Expr1',     label: 'Expr1',    group: 'Parts' },
  { key: 'Expr2',     label: 'Expr2',    group: 'Parts' },
];

// Each tab remembers its own set of visible columns, starting from its own
// default list (order here = default display order).
const DEFAULT_COLUMNS = {
  all:     ['RecordType', 'Department', 'PlateNumber', 'DateRequest', 'JobPerformed', 'Amount'],
  Service: ['RecordType', 'Department', 'PlateNumber', 'DateRequest', 'Type', 'Description', 'Amount'],
  Parts:   ['RecordType', 'Department', 'PlateNumber', 'DateRequest', 'ORNo', 'QTY', 'Items', 'Amount'],
};
let visibleColsByType = {
  all: new Set(DEFAULT_COLUMNS.all),
  Service: new Set(DEFAULT_COLUMNS.Service),
  Parts: new Set(DEFAULT_COLUMNS.Parts),
};
function getCurrentVisibleCols() { return visibleColsByType[activeType]; }

let allRows = [];
let activeType = 'all';
let dateFrom = '';
let dateTo = '';
let filterPlate = '';
let filterDept = '';
let sortState = { key: 'DateRequest', dir: 'desc' };
const PAGE_SIZE = 20;
let currentPage = 1;

const $ = sel => document.querySelector(sel);
const thead = $('#dataTable thead');
const tbody = $('#dataTable tbody');

// ---- Load data --------------------------------------------------------
// Default date range: the 1st of the current month through today, so the
// initial view isn't the entire history — just the current month so far.
function getDefaultDateRange() {
  const now = new Date();
  const first = new Date(now.getFullYear(), now.getMonth(), 1);
  const pad = n => String(n).padStart(2, '0');
  const toISO = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  return { from: toISO(first), to: toISO(now) };
}

async function loadData() {
  try {
    const [healthRes, dataRes] = await Promise.all([
      fetch('api/health.php').then(r => r.json()).catch(() => ({ status: 'error' })),
      fetch('api/expenses.php')
    ]);
    const statusEl = $('#status');
    if (healthRes.status === 'connected') {
      statusEl.textContent = '●';
      statusEl.className = 'ok';
    } else {
      statusEl.textContent = '● slow';
      statusEl.className = 'err';
    }

    if (!dataRes.ok) {
      const err = await dataRes.json().catch(() => ({}));
      throw new Error(err.detail || `Server responded ${dataRes.status}`);
    }
    allRows = await dataRes.json();
    $('#loadingState').style.display = 'none';
    $('#errorState').style.display = 'none';

    const { from, to } = getDefaultDateRange();
    dateFrom = from;
    dateTo = to;
    $('#dateFrom').value = from;
    $('#dateTo').value = to;

    buildColumnPanel();
    populateFilterDropdowns();
    render();
  } catch (err) {
    $('#loadingState').style.display = 'none';
    const box = $('#errorState');
    box.style.display = 'block';
    box.innerHTML = `<strong>Could not load data.</strong> ${err.message}<br><br>
      Check that <code>config.php</code> has the correct SQL Server name/credentials and that this
      machine has network access to TradewellDatabase, then refresh the page.`;
    $('#status').textContent = '● not connected';
    $('#status').className = 'err';
  }
}

// ---- Column visibility panel -------------------------------------------

// A column belongs on the current tab if it's shared by both (common),
// or if it belongs specifically to the type currently being viewed.
function isColumnRelevant(col, type) {
  return type === 'all' || col.group === 'common' || col.group === type;
}

function buildColumnPanel() {
  const panel = $('#colPanel');
  panel.innerHTML = '';
  const visibleCols = getCurrentVisibleCols();
  COLUMNS.filter(col => isColumnRelevant(col, activeType)).forEach(col => {
    const id = 'col_' + col.key;
    const label = document.createElement('label');
    label.dataset.key = col.key;
    label.innerHTML = `<input type="checkbox" id="${id}" ${visibleCols.has(col.key) ? 'checked' : ''}> <span class="col-order"></span> ${col.label}`;
    label.querySelector('input').addEventListener('change', e => {
      // Checking always appends to the end (most-recently-selected order).
      // Unchecking removes it; if re-checked later it goes to the end again.
      if (e.target.checked) visibleCols.add(col.key); else visibleCols.delete(col.key);
      updateColumnOrderBadges();
      render();
    });
    panel.appendChild(label);
  });
  updateColumnOrderBadges();
}

// Shows each checked column's current position (1, 2, 3…) so it's clear
// what order the columns will appear in the table, live as you click.
function updateColumnOrderBadges() {
  const order = [...getCurrentVisibleCols()];
  document.querySelectorAll('#colPanel label').forEach(label => {
    const idx = order.indexOf(label.dataset.key);
    const badge = label.querySelector('.col-order');
    if (idx >= 0) {
      badge.textContent = idx + 1;
      badge.classList.add('active');
    } else {
      badge.textContent = '';
      badge.classList.remove('active');
    }
  });
}

// Returns the visible columns in the exact order they were selected
// (Set iteration order = insertion order in JS), not the COLUMNS array order.
// Also filtered to whatever's relevant on the current tab, so switching to
// Service/Parts won't show empty columns that belong to the other type.
function getVisibleColumnsOrdered() {
  return [...getCurrentVisibleCols()]
    .map(key => COLUMNS.find(c => c.key === key))
    .filter(col => col && isColumnRelevant(col, activeType));
}
$('#toggleCols').addEventListener('click', () => $('#colPanel').classList.toggle('open'));

// ---- Tabs (Service / Parts / All) --------------------------------------
$('#typeTabs').addEventListener('click', e => {
  if (e.target.tagName !== 'BUTTON') return;
  document.querySelectorAll('#typeTabs button').forEach(b => b.classList.remove('active'));
  e.target.classList.add('active');
  activeType = e.target.dataset.type;
  currentPage = 1;
  buildColumnPanel();
  render();
});

// Reloads fresh data from the database and resets every tab's column
// selection back to its own default set (undoing any column add/remove
// or reordering the user has done).
$('#refreshBtn').addEventListener('click', () => {
  visibleColsByType = {
    all: new Set(DEFAULT_COLUMNS.all),
    Service: new Set(DEFAULT_COLUMNS.Service),
    Parts: new Set(DEFAULT_COLUMNS.Parts),
  };
  currentPage = 1;
  loadData();
});


// ---- Structured filters (Date range / Plate / Department) ---------------

// Fills the Plate Number and Department dropdowns with the actual distinct
// values found in the data, so users pick from real options instead of
// typing free text.
function populateFilterDropdowns() {
  const plates = [...new Set(allRows.map(r => r.PlateNumber).filter(Boolean))].sort();
  const depts = [...new Set(allRows.map(r => r.Department).filter(Boolean))].sort();

  const plateSel = $('#filterPlate');
  plateSel.innerHTML = '<option value="">All Plates</option>';
  plates.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p; opt.textContent = p;
    plateSel.appendChild(opt);
  });

  const deptSel = $('#filterDept');
  deptSel.innerHTML = '<option value="">All Departments</option>';
  depts.forEach(d => {
    const opt = document.createElement('option');
    opt.value = d; opt.textContent = d;
    deptSel.appendChild(opt);
  });
}

// Filters only take effect once "Filter" is clicked, so users can set up
// Date From / To + Plate + Department together before applying.
$('#applyFilters').addEventListener('click', () => {
  dateFrom = $('#dateFrom').value;
  dateTo = $('#dateTo').value;
  filterPlate = $('#filterPlate').value;
  filterDept = $('#filterDept').value;
  currentPage = 1;
  render();
});

// Escapes text before dropping it into innerHTML, so descriptions/remarks
// containing &, <, > etc. don't break the report markup.
function escapeHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[ch]));
}

// Columns that hold date/datetime values from the database, and so get
// special formatting instead of being shown as raw "YYYY-MM-DD HH:MM:SS".
const DATE_COLUMNS = new Set(['DateRequest', 'Date', 'DateTimeInput', 'DateTimeInputD']);

function parseDateTimeParts(value) {
  if (value == null) return null;
  const m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/);
  if (!m) return null;
  return { y: m[1], mo: m[2], d: m[3], h: m[4], mi: m[5], s: m[6] };
}

// On-screen: if the time is midnight (00:00:00 — meaning there's no real
// time stored, just a date), show only the date. Otherwise leave it as-is
// so any column that does carry a meaningful time isn't silently trimmed.
function formatDateOnScreen(value) {
  const p = parseDateTimeParts(value);
  if (!p) return value == null ? '' : value;
  if (p.h == null || (p.h === '00' && p.mi === '00' && p.s === '00')) return `${p.y}-${p.mo}-${p.d}`;
  return value;
}

// Print: always MM/DD/YYYY, date only.
function formatDateForPrint(value) {
  const p = parseDateTimeParts(value);
  if (!p) return value == null ? '' : value;
  return `${p.mo}/${p.d}/${p.y}`;
}

// Builds the printable report using whatever columns are currently
// visible/selected (in that exact order) and whatever rows are currently
// filtered — so if the user trimmed the view down to 3 columns, the
// printout shows those same 3 columns, not the full default set.
function buildPrintReport() {
  const cols = getVisibleColumnsOrdered();

  // Print always lists records oldest-first by Date Requested, regardless
  // of whatever sort is currently applied on-screen.
  const rows = getFilteredRows().slice().sort((a, b) => {
    const av = a.DateRequest ? new Date(a.DateRequest).getTime() : 0;
    const bv = b.DateRequest ? new Date(b.DateRequest).getTime() : 0;
    return av - bv;
  });

  const totalAmount = rows.reduce((sum, r) => sum + (parseFloat(r.Amount) || 0), 0);
  const dateStr = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

  const theadHtml = '<tr>' + cols.map(c => `<th>${escapeHtml(c.label)}</th>`).join('') + '</tr>';
  const tbodyHtml = rows.map(r =>
    '<tr>' + cols.map(c => {
      const raw = r[c.key];
      const display = DATE_COLUMNS.has(c.key) ? formatDateForPrint(raw) : raw;
      return `<td>${escapeHtml(display)}</td>`;
    }).join('') + '</tr>'
  ).join('');

  $('#printReport').innerHTML = `
    <div class="print-header">
      <h1>Maintenance Expenses — Report</h1>
      <div class="print-date">Date Print: ${dateStr}</div>
    </div>
    <table class="print-table">
      <thead>${theadHtml}</thead>
      <tbody>${tbodyHtml}</tbody>
    </table>
    <div class="print-footer">
      <span>Record Count: ${rows.length.toLocaleString()}</span>
      <span>Total Amount: ₱${totalAmount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
    </div>
  `;
}

$('#printBtn').addEventListener('click', () => {
  buildPrintReport();
  window.print();
});

// ---- Export (real .xlsx, not CSV) -----------------------------------------
// Builds a formatted Excel workbook using whatever columns/rows are
// currently visible/filtered — same source data as the on-screen table
// and the print report, just as a downloadable spreadsheet instead.
async function exportToExcel(cols, rows) {
  const workbook = new ExcelJS.Workbook();
  workbook.creator = 'Tradewell Expense Ledger';
  workbook.created = new Date();
  const sheet = workbook.addWorksheet('Expenses', {
    views: [{ state: 'frozen', ySplit: 1 }],
  });

  const border = {
    top: { style: 'thin', color: { argb: 'FF999999' } },
    left: { style: 'thin', color: { argb: 'FF999999' } },
    bottom: { style: 'thin', color: { argb: 'FF999999' } },
    right: { style: 'thin', color: { argb: 'FF999999' } },
  };

  // Header row — navy fill, white bold 15pt, matching the app's branding.
  const headerRow = sheet.addRow(cols.map(c => c.label));
  headerRow.height = 24;
  headerRow.eachCell(cell => {
    cell.font = { size: 15, bold: true, color: { argb: 'FFFFFFFF' } };
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF10203A' } };
    cell.alignment = { vertical: 'middle', horizontal: 'left' };
    cell.border = border;
  });

  const amountColIdx = cols.findIndex(c => c.key === 'Amount') + 1; // 1-based; 0 if not visible

  // Data rows — 12pt body text, thin borders on every cell.
  rows.forEach(r => {
    const rowData = cols.map(c => {
      if (DATE_COLUMNS.has(c.key)) return formatDateForPrint(r[c.key]);
      if (c.key === 'Amount') return parseFloat(r[c.key]) || 0;
      return r[c.key] == null ? '' : r[c.key];
    });
    const row = sheet.addRow(rowData);
    row.eachCell(cell => {
      cell.font = { size: 12 };
      cell.border = border;
    });
    if (amountColIdx > 0) {
      const cell = row.getCell(amountColIdx);
      cell.numFmt = '#,##0.00';
      cell.alignment = { horizontal: 'right' };
    }
  });

  // Total row under the Amount column, only if Amount is currently visible.
  if (amountColIdx > 0) {
    const totalAmount = rows.reduce((sum, r) => sum + (parseFloat(r.Amount) || 0), 0);
    const totalRow = sheet.addRow([]);
    totalRow.getCell(1).value = 'TOTAL AMOUNT';
    if (amountColIdx > 1) sheet.mergeCells(totalRow.number, 1, totalRow.number, amountColIdx - 1);
    const amtCell = totalRow.getCell(amountColIdx);
    amtCell.value = totalAmount;
    amtCell.numFmt = '#,##0.00';
    amtCell.alignment = { horizontal: 'right' };
    for (let i = 1; i <= cols.length; i++) {
      const cell = totalRow.getCell(i);
      cell.font = { size: 12, bold: true };
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF2C983' } };
      cell.border = border;
    }
  }

  // Column widths sized to fit their content (header or longest value).
  cols.forEach((c, i) => {
    const maxLen = Math.max(
      c.label.length,
      ...rows.map(r => String(DATE_COLUMNS.has(c.key) ? formatDateForPrint(r[c.key]) : (r[c.key] ?? '')).length)
    );
    sheet.getColumn(i + 1).width = Math.min(Math.max(maxLen + 4, 12), 40);
  });

  const buffer = await workbook.xlsx.writeBuffer();
  const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `tradewell-expenses-${new Date().toISOString().slice(0, 10)}.xlsx`;
  a.click();
}

$('#exportExcel').addEventListener('click', () => {
  const cols = getVisibleColumnsOrdered();
  const rows = getFilteredSortedRows();
  exportToExcel(cols, rows);
});

// ---- Filtering / sorting --------------------------------------------------
function getFilteredRows() {
  let rows = allRows;
  if (activeType !== 'all') rows = rows.filter(r => r.RecordType === activeType);

  if (dateFrom) {
    const from = new Date(dateFrom);
    rows = rows.filter(r => r.DateRequest && new Date(r.DateRequest) >= from);
  }
  if (dateTo) {
    const to = new Date(dateTo);
    to.setHours(23, 59, 59, 999);
    rows = rows.filter(r => r.DateRequest && new Date(r.DateRequest) <= to);
  }
  if (filterPlate) rows = rows.filter(r => r.PlateNumber === filterPlate);
  if (filterDept) rows = rows.filter(r => r.Department === filterDept);
  return rows;
}

function getFilteredSortedRows() {
  const rows = getFilteredRows();
  if (!sortState.key) return rows;
  const { key, dir } = sortState;
  return [...rows].sort((a, b) => {
    let av = a[key], bv = b[key];
    if (av == null) av = '';
    if (bv == null) bv = '';
    const an = parseFloat(av), bn = parseFloat(bv);
    let cmp;
    if (!isNaN(an) && !isNaN(bn) && String(an) === String(av).trim() && String(bn) === String(bv).trim()) {
      cmp = an - bn;
    } else {
      cmp = String(av).localeCompare(String(bv));
    }
    return dir === 'asc' ? cmp : -cmp;
  });
}

// ---- Render -----------------------------------------------------------
function render() {
  const cols = getVisibleColumnsOrdered();
  const rows = getFilteredSortedRows(); // full filtered + sorted set (used for totals/export)

  // header
  const headRow1 = document.createElement('tr');
  cols.forEach(col => {
    const th1 = document.createElement('th');
    const arrow = sortState.key === col.key ? (sortState.dir === 'asc' ? '▲' : '▼') : '';
    th1.innerHTML = `<div class="th-label">${col.label} <span class="sort-arrow">${arrow}</span></div>`;
    th1.querySelector('.th-label').addEventListener('click', () => {
      if (sortState.key === col.key) sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
      else sortState = { key: col.key, dir: 'asc' };
      currentPage = 1;
      render();
    });
    headRow1.appendChild(th1);
  });
  thead.innerHTML = '';
  thead.appendChild(headRow1);

  // Pagination — clamp currentPage to a valid range for the current
  // filtered result, then slice out just this page's rows for display.
  const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
  if (currentPage > totalPages) currentPage = totalPages;
  if (currentPage < 1) currentPage = 1;
  const pageStart = (currentPage - 1) * PAGE_SIZE;
  const pageRows = rows.slice(pageStart, pageStart + PAGE_SIZE);

  // body
  tbody.innerHTML = '';
  const frag = document.createDocumentFragment();
  pageRows.forEach(r => {
    const tr = document.createElement('tr');
    cols.forEach(col => {
      const td = document.createElement('td');
      if (col.key === 'RecordType') {
        td.innerHTML = `<span class="badge ${r[col.key]}">${r[col.key]}</span>`;
      } else {
        const raw = r[col.key];
        const v = DATE_COLUMNS.has(col.key) ? formatDateOnScreen(raw) : raw;
        td.textContent = v == null ? '' : v;
        td.title = v == null ? '' : v;
      }
      tr.appendChild(td);
    });
    frag.appendChild(tr);
  });
  tbody.appendChild(frag);

  $('#emptyState').style.display = rows.length === 0 ? 'block' : 'none';
  // Totals strip — reflects the whole filtered set, not just this page
  const totalAmount = rows.reduce((sum, r) => sum + (parseFloat(r.Amount) || 0), 0);
  $('#totalRecords').textContent = rows.length.toLocaleString();
  $('#totalAmount').textContent = '₱' + totalAmount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Pagination bar
  const showingFrom = rows.length === 0 ? 0 : pageStart + 1;
  const showingTo = Math.min(pageStart + PAGE_SIZE, rows.length);
  $('#paginationInfo').textContent = rows.length === 0
    ? 'No rows to show'
    : `Showing ${showingFrom.toLocaleString()}–${showingTo.toLocaleString()} of ${rows.length.toLocaleString()} rows`;
  $('#pageIndicator').textContent = `Page ${currentPage} of ${totalPages}`;
  $('#prevPage').disabled = currentPage <= 1;
  $('#nextPage').disabled = currentPage >= totalPages;
}

$('#prevPage').addEventListener('click', () => {
  if (currentPage > 1) { currentPage--; render(); }
});
$('#nextPage').addEventListener('click', () => {
  currentPage++; render();
});

loadData();
</script>
</body>
</html>