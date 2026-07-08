<?php
/**
 * page_tank_access.php
 * Fuel Tank Access Assignment — dedicated page (superadmin only).
 * Manages which users can access TRADEWELL and TRADEWELL GUMACA suppliers.
 */
?>
<!-- ═══════════════════ FUEL TANK ACCESS PAGE ═══════════════════ -->
<div class="page" id="page-tank-access">
    <div class="page-title">Fuel Tank Access <span>Assignment</span></div>
    <p class="page-sub">Control which users can access TRADEWELL and TRADEWELL GUMACA suppliers. All other suppliers remain visible to everyone.</p>

    <!-- Header row: info + refresh -->
    <div class="ta-page-header">
        <div class="ta-page-stats" id="ta-page-stats" style="display:none">
            <div class="ta-stat">
                <div class="ta-stat-val" id="ta-stat-total">—</div>
                <div class="ta-stat-lbl">Total Users</div>
            </div>
            <div class="ta-stat-div"></div>
            <div class="ta-stat">
                <div class="ta-stat-val" style="color:var(--accent)" id="ta-stat-tw">—</div>
                <div class="ta-stat-lbl">TRADEWELL Access</div>
            </div>
            <div class="ta-stat-div"></div>
            <div class="ta-stat">
                <div class="ta-stat-val" style="color:#58a6ff" id="ta-stat-gum">—</div>
                <div class="ta-stat-lbl">GUMACA Access</div>
            </div>
        </div>
        <button class="btn btn-secondary" onclick="loadTankAccessUsers()" style="padding:8px 16px;font-size:12px;flex-shrink:0">
            ↺ Refresh
        </button>
    </div>

    <!-- Supplier legend -->
    <div class="ta-legend">
        <div class="ta-legend-item">
            <span class="ta-supplier-badge tradewell">⛽ TRADEWELL</span>
            <span class="ta-legend-desc">Main depot — restricted access</span>
        </div>
        <div class="ta-legend-sep"></div>
        <div class="ta-legend-item">
            <span class="ta-supplier-badge gumaca">⛽ TRADEWELL GUMACA</span>
            <span class="ta-legend-desc">Gumaca depot — restricted access</span>
        </div>
        <div class="ta-legend-sep"></div>
        <div class="ta-legend-item">
            <span class="ta-open-badge">🌐 Other Suppliers</span>
            <span class="ta-legend-desc">Always visible to all users</span>
        </div>
    </div>

    <!-- Search -->
    <div class="adm-search-wrap" style="margin-bottom:16px">
        <div class="adm-search-inner">
            <span class="adm-search-icon">🔍</span>
            <input type="text" id="ta-search" class="adm-search-input"
                   placeholder="Search by name…" oninput="filterTankAccessUsers()">
            <button class="adm-search-clear" id="ta-search-clear"
                    onclick="clearTankAccessSearch()" style="display:none">✕</button>
        </div>
        <div class="adm-result-count" id="ta-count-label"></div>
    </div>

    <!-- User cards -->
    <div id="ta-table-wrap">
        <div class="loading"><span class="spinner"></span>Loading tank access data…</div>
    </div>

    <!-- Pagination -->
    <div class="adm-pagination-wrap">
        <div class="adm-page-info" id="ta-page-info"></div>
        <div class="pagination" id="ta-pagination"></div>
    </div>
</div>

<style>
/* ═══════════════════════════════════════════════
   FUEL TANK ACCESS PAGE
   ═══════════════════════════════════════════════ */

/* ── Page header row ────────────────────────── */
.ta-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

/* ── Stats bar ──────────────────────────────── */
.ta-page-stats {
    display: flex;
    align-items: center;
    gap: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 22px;
    flex-wrap: wrap;
    gap: 12px;
    flex: 1;
}
.ta-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 70px;
}
.ta-stat-val {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 24px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.ta-stat-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.4px;
    color: var(--muted);
}
.ta-stat-div {
    width: 1px;
    height: 32px;
    background: var(--border);
    align-self: center;
    flex-shrink: 0;
}

/* ── Legend bar ──────────────────────────────── */
.ta-legend {
    display: flex;
    align-items: center;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 20px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 10px;
}
.ta-legend-item {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 180px;
}
.ta-legend-sep {
    width: 1px;
    height: 28px;
    background: var(--border);
    flex-shrink: 0;
}
.ta-legend-desc {
    font-size: 11px;
    color: var(--muted);
}

/* ── Supplier badges ─────────────────────────── */
.ta-supplier-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    white-space: nowrap;
}
.ta-supplier-badge.tradewell {
    background: rgba(240,165,0,.15);
    color: var(--accent);
    border: 1px solid rgba(240,165,0,.35);
}
.ta-supplier-badge.gumaca {
    background: rgba(59,130,246,.12);
    color: #58a6ff;
    border: 1px solid rgba(59,130,246,.3);
}
.ta-open-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    background: var(--surface2);
    color: var(--muted);
    border: 1px solid var(--border);
}

/* ── User cards ──────────────────────────────── */
.ta-cards {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ta-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    transition: border-color .15s, box-shadow .15s;
}
.ta-card:hover {
    border-color: rgba(240,165,0,.25);
    box-shadow: 0 2px 12px rgba(0,0,0,.18);
}
.ta-card.is-superadmin {
    border-color: rgba(240,165,0,.2);
    background: linear-gradient(135deg, rgba(240,165,0,.03) 0%, var(--surface) 60%);
}
.ta-user-info {
    flex: 1;
    min-width: 140px;
}
.ta-user-name {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: .5px;
    color: var(--text);
    text-transform: uppercase;
}
.ta-user-meta {
    font-size: 11px;
    color: var(--muted);
    margin-top: 2px;
}
.ta-toggles {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* ── Toggle switches ─────────────────────────── */
.ta-toggle-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface2);
    cursor: pointer;
    transition: all .15s;
    user-select: none;
}
.ta-toggle-item input[type="checkbox"] { display: none; }
.ta-toggle-item.on.tradewell {
    background: rgba(240,165,0,.1);
    border-color: rgba(240,165,0,.4);
}
.ta-toggle-item.on.gumaca {
    background: rgba(59,130,246,.1);
    border-color: rgba(59,130,246,.35);
}
.ta-toggle-item.locked {
    cursor: not-allowed;
    opacity: .75;
}
.ta-toggle-label {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
}
.ta-toggle-item.tradewell .ta-toggle-label { color: var(--accent); }
.ta-toggle-item.gumaca   .ta-toggle-label { color: #58a6ff; }
.ta-toggle-item:not(.on) .ta-toggle-label { color: var(--muted); }
.ta-switch {
    width: 30px;
    height: 17px;
    border-radius: 17px;
    background: var(--border);
    position: relative;
    flex-shrink: 0;
    transition: background .2s;
}
.ta-switch::after {
    content: '';
    position: absolute;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--muted);
    top: 2.5px;
    left: 2.5px;
    transition: all .2s;
}
.ta-toggle-item.on.tradewell .ta-switch { background: rgba(240,165,0,.35); }
.ta-toggle-item.on.tradewell .ta-switch::after { background: var(--accent); left: 15.5px; }
.ta-toggle-item.on.gumaca   .ta-switch { background: rgba(59,130,246,.35); }
.ta-toggle-item.on.gumaca   .ta-switch::after { background: #58a6ff; left: 15.5px; }
.ta-toggle-item.locked .ta-switch { background: rgba(240,165,0,.2); }
.ta-toggle-item.locked .ta-switch::after { background: var(--accent); left: 15.5px; }

/* ── Toast ───────────────────────────────────── */
.ta-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: var(--green);
    color: #000;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 18px;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
    transform: translateY(80px);
    opacity: 0;
    transition: all .3s;
    z-index: 9999;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ta-toast.show {
    transform: translateY(0);
    opacity: 1;
}

/* ── Empty state ─────────────────────────────── */
.ta-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.ta-empty-icon { font-size: 36px; margin-bottom: 12px; }
.ta-empty-text { font-size: 14px; }

/* ── Pagination position fix ─────────────────── */
#page-tank-access .pagination { margin-top: 0; }
</style>

<div class="ta-toast" id="ta-save-toast">✓ Saved</div>
