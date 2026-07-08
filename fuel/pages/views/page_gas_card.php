<?php
/**
 * page_gas_card.php
 * Truck Gas Card page — active vehicle registry with filtering, CSV export, and plate calendar drill-down.
 */
?>
<!-- ═══════════════════ TRUCK GAS CARD PAGE ═══════════════════ -->
<div class="page" id="page-gas-card">
    <div class="page-title">Truck <span>Gas Card</span></div>
    <p class="page-sub">Active vehicle registry — plate numbers, type, brand and fuel details</p>

    <!-- Filter panel -->
    <div class="fuel-filter-panel">
        <div class="fuel-filter-title">🔍 Filter Vehicles</div>
        <div class="fuel-filter-row">
            <div class="fuel-filter-group">
                <label>Plate Number</label>
                <input type="text" id="gc-plate" placeholder="Search plate…" style="text-transform:uppercase">
            </div>
            <div class="fuel-filter-actions">
                <button class="btn btn-primary" onclick="loadGasCard()">Search</button>
                <button class="btn btn-secondary" onclick="clearGCFilters()">Clear</button>
                <button class="btn btn-export" onclick="exportGCCSV()" title="Export to CSV">⬇ CSV</button>
            </div>
        </div>
    </div>

    <!-- Panel -->
    <div class="panel">
        <!-- Summary bar -->
        <div class="fr-totals" id="gc-totals">
            <div class="fr-totals-item">
                <div class="fr-totals-label">Total Active Vehicles</div>
                <div class="fr-totals-val records" id="gc-count">—</div>
            </div>
        </div>

        <!-- Table -->
        <div class="tbl-wrap" id="gc-table-wrap">
            <div class="loading"><span class="spinner"></span>Loading vehicles…</div>
        </div>

        <!-- Pagination -->
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div style="font-size:12px;color:var(--muted)" id="gc-page-info"></div>
            <div class="pagination" id="gc-pagination"></div>
        </div>
    </div>
</div>
