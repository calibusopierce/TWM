<?php
/**
 * page_fuel_records.php
 * Fuel Records page — full transaction history with filtering, totals, pagination, and print.
 */
?>
<!-- ═══════════════════ FUEL RECORDS PAGE ═══════════════════ -->
<div class="page" id="page-fuel-records">
    <div style="display:flex;align-items:center;gap:12px">
        <div class="page-title">Fuel <span>Records</span></div>
        <button onclick="loadFuelRecords(frCurrentPage)" title="Refresh Records" style="background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:5px 10px;cursor:pointer;font-size:15px;line-height:1;transition:all .2s" onmouseover="this.style.color='var(--accent)';this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)';this.style.borderColor='var(--border)'">↻</button>
    </div>
    <p class="page-sub">Complete fuel transaction history with advanced filtering</p>

    <!-- Filter panel -->
    <div class="fuel-filter-panel">
        <div class="fuel-filter-header">
            <div class="fuel-filter-title">🔍 Filter Records</div>
            <button class="btn btn-primary btn-add-fuel" onclick="openAddModal()">
                <span>＋</span> Add Fuel Record
            </button>
        </div>
        <div class="fuel-filter-row">
            <div class="fuel-filter-group">
                <label>Plate Number</label>
                <input type="text" id="fr-plate" placeholder="Search plate…" style="text-transform:uppercase">
            </div>
            <div class="fuel-filter-group">
                <label>Supplier</label>
                <div class="plate-input-wrap" style="position:relative">
                    <input type="text" id="fr-supplier" placeholder="Search supplier…"
                        autocomplete="off" oninput="onFRSupplierInput(this)" onfocus="onFRSupplierInput(this)">
                    <div class="plate-suggestions" id="fr-supplier-suggestions"></div>
                </div>
            </div>
            <div class="fuel-filter-group">
                <label>Date From</label>
                <input type="date" id="fr-date-from">
            </div>
            <div class="fuel-filter-group">
                <label>Date To</label>
                <input type="date" id="fr-date-to">
            </div>
            <div class="fuel-filter-group">
                <label>Requested By</label>
                <input type="text" id="fr-requested" placeholder="Search name…">
            </div>
            <div class="fuel-filter-actions">
                <button class="btn btn-primary" onclick="loadFuelRecords(1)">Search</button>
                <button class="btn btn-secondary" onclick="clearFRFilters()">Clear</button>
                <button class="btn btn-export" onclick="printFuelReport()" title="Print Report" style="background:rgba(96,165,250,.15);color:#60a5fa;border-color:#60a5fa">🖨 Print</button>
            </div>
        </div>
    </div>


    <!-- Panel -->
    <div class="panel">
        <!-- Totals bar -->
        <div class="fr-totals" id="fr-totals">
            <div class="fr-totals-item">
                <div class="fr-totals-label">Total Records</div>
                <div class="fr-totals-val records" id="frt-records">—</div>
            </div>
            <div class="fr-totals-item">
                <div class="fr-totals-label">Total Liters</div>
                <div class="fr-totals-val liters" id="frt-liters">—</div>
            </div>
            <div class="fr-totals-item">
                <div class="fr-totals-label">Total Amount</div>
                <div class="fr-totals-val amount" id="frt-amount">—</div>
            </div>
        </div>

        <!-- Table -->
        <div class="tbl-wrap" id="fr-table-wrap">
            <div class="loading"><span class="spinner"></span>Loading records…</div>
        </div>

        <!-- Pagination -->
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div style="font-size:12px;color:var(--muted)" id="fr-page-info"></div>
            <div class="pagination" id="fr-pagination"></div>
        </div>
    </div>
</div>
