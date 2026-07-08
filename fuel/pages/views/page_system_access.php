<?php
/**
 * page_system_access.php
 * System Access Control — dedicated page (superadmin only).
 * Manages which users are allowed to log in to the system.
 */
?>
<!-- ═══════════════════ SYSTEM ACCESS CONTROL PAGE ═══════════════════ -->
<div class="page" id="page-system-access">
    <div class="page-title">System Access <span>Control</span></div>
    <p class="page-sub">Toggle access for each user. Rejected users cannot log in. Superadmins always have access.</p>

    <!-- Header row: search + refresh -->
    <div class="sa-header">
        <div class="adm-search-inner" style="min-width:200px;flex:1;max-width:380px">
            <span class="adm-search-icon">🔍</span>
            <input type="text" id="sa-search" class="adm-search-input"
                   placeholder="Search users…" oninput="filterSaUsers()">
            <button class="adm-search-clear" id="sa-search-clear"
                    onclick="clearSaSearch()" style="display:none">✕</button>
        </div>
        <button class="btn btn-secondary" onclick="loadSystemAccessUsers()" style="padding:8px 16px;font-size:12px;flex-shrink:0">↺ Refresh</button>
    </div>

    <!-- Legend -->
    <div class="ta-legend" style="margin-bottom:14px">
        <div class="ta-legend-item">
            <span class="sa-access-badge approved">✓ Approved</span>
            <span class="ta-legend-desc">User can log in normally</span>
        </div>
        <div class="ta-legend-sep"></div>
        <div class="ta-legend-item">
            <span class="sa-access-badge rejected">✗ Rejected</span>
            <span class="ta-legend-desc">User is blocked from logging in</span>
        </div>
        <div class="ta-legend-sep"></div>
        <div class="ta-legend-item">
            <span class="ta-open-badge">⭐ Superadmin</span>
            <span class="ta-legend-desc">Always has full access</span>
        </div>
    </div>

    <!-- Count label -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:8px">
        <div class="adm-result-count" id="sa-count-label"></div>
    </div>

    <!-- User list -->
    <div id="sa-table-wrap">
        <div class="loading"><span class="spinner"></span>Loading system access data…</div>
    </div>
</div>

<style>
/* ═══════════════════════════════════════════════
   SYSTEM ACCESS CONTROL PAGE
   ═══════════════════════════════════════════════ */

/* ── Page header row ────────────────────────── */
.sa-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

/* ── Access badge (legend) ───────────────────── */
.sa-access-badge {
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
.sa-access-badge.approved {
    background: rgba(34,197,94,.12);
    color: #3fb950;
    border: 1px solid rgba(34,197,94,.35);
}
.sa-access-badge.rejected {
    background: rgba(239,68,68,.12);
    color: #f87171;
    border: 1px solid rgba(239,68,68,.35);
}

/* ── User access cards ───────────────────────── */
.sa-cards { display: flex; flex-direction: column; gap: 10px; }
.sa-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    transition: border-color .15s, box-shadow .15s;
}
.sa-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.18); }
.sa-card.is-approved   { border-left: 3px solid rgba(34,197,94,.5); }
.sa-card.is-rejected   { border-left: 3px solid rgba(239,68,68,.5); opacity: .75; }
.sa-card.is-superadmin { border-left: 3px solid rgba(240,165,0,.4); }
.sa-user-info { flex: 1; min-width: 160px; }
.sa-user-name {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 16px; font-weight: 800;
    letter-spacing: .5px; text-transform: uppercase; color: var(--text);
}
.sa-user-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ── Access toggle (matches tank access style) ── */
.sa-toggle-item {
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
    min-width: 140px;
    justify-content: space-between;
}
.sa-toggle-item input[type="checkbox"] { display: none; }
.sa-toggle-item.on {
    background: rgba(34,197,94,.1);
    border-color: rgba(34,197,94,.4);
}
.sa-toggle-item.locked { cursor: not-allowed; opacity: .75; }
.sa-toggle-label {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px; font-weight: 800;
    letter-spacing: .5px; text-transform: uppercase;
}
.sa-toggle-item.on                     .sa-toggle-label { color: #3fb950; }
.sa-toggle-item:not(.on):not(.locked)  .sa-toggle-label { color: #f87171; }
.sa-toggle-item.locked                 .sa-toggle-label { color: var(--accent); }
.sa-switch {
    width: 30px; height: 17px;
    border-radius: 17px;
    background: var(--border);
    position: relative; flex-shrink: 0;
    transition: background .2s;
}
.sa-switch::after {
    content: '';
    position: absolute;
    width: 12px; height: 12px;
    border-radius: 50%;
    background: var(--muted);
    top: 2.5px; left: 2.5px;
    transition: all .2s;
}
.sa-toggle-item.on .sa-switch                    { background: rgba(34,197,94,.35); }
.sa-toggle-item.on .sa-switch::after             { background: #3fb950; left: 15.5px; }
.sa-toggle-item:not(.on):not(.locked) .sa-switch { background: rgba(239,68,68,.25); }
.sa-toggle-item:not(.on):not(.locked) .sa-switch::after { background: #f87171; }
.sa-toggle-item.locked .sa-switch                { background: rgba(240,165,0,.25); }
.sa-toggle-item.locked .sa-switch::after         { background: var(--accent); left: 15.5px; }

/* ── Toast ───────────────────────────────────── */
.sa-toast {
    position: fixed;
    bottom: 24px; right: 24px;
    background: var(--green);
    color: #000;
    font-weight: 700; font-size: 13px;
    padding: 10px 18px;
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.4);
    transform: translateY(80px); opacity: 0;
    transition: all .3s;
    z-index: 9999; pointer-events: none;
}
.sa-toast.show { transform: translateY(0); opacity: 1; }

/* ── Empty state ─────────────────────────────── */
.sa-empty { text-align: center; padding: 60px 20px; color: var(--muted); }
.sa-empty-icon { font-size: 36px; margin-bottom: 12px; }
.sa-empty-text { font-size: 14px; }
</style>

<div class="sa-toast" id="sa-save-toast">✓ Saved</div>
