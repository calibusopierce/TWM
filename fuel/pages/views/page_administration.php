<?php
/**
 * page_administration.php
 * Administration page — redesigned with card-based user layout.
 */
?>
<!-- ═══════════════════ ADMINISTRATION PAGE ═══════════════════ -->
<div class="page" id="page-administration">
    <div class="page-title">Administration <span>Panel</span></div>
    <p class="page-sub">Manage user access permissions and department assignments</p>

    <!-- Stats bar -->
    <div class="adm-stats-bar" id="adm-stats-bar" style="display:none">
        <div class="adm-stat">
            <div class="adm-stat-val" id="adm-stat-total">—</div>
            <div class="adm-stat-lbl">Total Users</div>
        </div>
        <div class="adm-stat-div"></div>
        <div class="adm-stat">
            <div class="adm-stat-val" style="color:var(--accent)" id="adm-stat-super">—</div>
            <div class="adm-stat-lbl">Superadmins</div>
        </div>
        <div class="adm-stat-div"></div>
        <div class="adm-stat">
            <div class="adm-stat-val" style="color:var(--blue)" id="adm-stat-regular">—</div>
            <div class="adm-stat-lbl">Regular Users</div>
        </div>
    </div>

    <!-- Search bar -->
    <div class="adm-search-wrap">
        <div class="adm-search-inner">
            <span class="adm-search-icon">🔍</span>
            <input type="text" id="adm-search" class="adm-search-input"
                   placeholder="Search by name…" oninput="filterAdminUsers()">
            <button class="adm-search-clear" id="adm-search-clear" onclick="clearAdmSearch()" style="display:none">✕</button>
        </div>
        <div class="adm-result-count" id="adm-count-label"></div>
    </div>

    <!-- User cards grid -->
    <div id="adm-table-wrap">
        <div class="loading"><span class="spinner"></span>Loading users…</div>
    </div>

    <!-- Pagination -->
    <div class="adm-pagination-wrap">
        <div class="adm-page-info" id="adm-page-info"></div>
        <div class="pagination" id="adm-pagination"></div>
    </div>

    <!-- ══════════════════════════════════════════
         SYSTEM ACCESS APPROVAL SECTION
         ══════════════════════════════════════════ -->
</div>

<style>
/* ═══════════════════════════════════════════════
   ADMINISTRATION PAGE — Card-based redesign
   ═══════════════════════════════════════════════ */

/* ── Stats bar ──────────────────────────────── */
.adm-stats-bar {
    display: flex;
    align-items: center;
    gap: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 24px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 12px;
}
.adm-stat {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 80px;
}
.adm-stat-val {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 26px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.adm-stat-lbl {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--muted);
}
.adm-stat-div {
    width: 1px;
    height: 36px;
    background: var(--border);
    align-self: center;
    flex-shrink: 0;
}

/* ── Search ─────────────────────────────────── */
.adm-search-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.adm-search-inner {
    position: relative;
    flex: 1;
    min-width: 220px;
    max-width: 420px;
}
.adm-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    pointer-events: none;
}
.adm-search-input {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: 'Barlow', sans-serif;
    font-size: 13px;
    padding: 9px 36px 9px 36px;
    transition: border-color .15s;
}
.adm-search-input:focus {
    outline: none;
    border-color: var(--accent);
}
.adm-search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 12px;
    padding: 2px 4px;
    border-radius: 4px;
    transition: color .15s;
}
.adm-search-clear:hover { color: var(--text); }
.adm-result-count {
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
}

/* ── User card grid ─────────────────────────── */
.adm-cards {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.adm-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
}
.adm-card:hover {
    border-color: rgba(240,165,0,.3);
    box-shadow: 0 2px 16px rgba(0,0,0,.2);
}
.adm-card.is-superadmin {
    border-color: rgba(240,165,0,.25);
    background: linear-gradient(135deg, rgba(240,165,0,.04) 0%, var(--surface) 50%);
}

/* Card top row — identity */
.adm-card-identity {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px 12px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.adm-avatar {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 18px;
    font-weight: 800;
    flex-shrink: 0;
    background: var(--surface2);
    color: var(--accent);
    border: 1px solid var(--border);
    text-transform: uppercase;
    letter-spacing: 0;
}
.adm-card.is-superadmin .adm-avatar {
    background: rgba(240,165,0,.12);
    border-color: rgba(240,165,0,.3);
    color: var(--accent);
}
.adm-identity-info {
    flex: 1;
    min-width: 0;
}
.adm-display-name {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: .5px;
    color: var(--text);
    text-transform: uppercase;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.adm-username {
    font-size: 11px;
    color: var(--muted);
    margin-top: 1px;
}
.adm-role-badge {
    padding: 3px 10px;
    border-radius: 6px;
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    flex-shrink: 0;
}
.adm-role-badge.superadmin {
    background: rgba(240,165,0,.15);
    color: var(--accent);
    border: 1px solid rgba(240,165,0,.3);
}
.adm-role-badge.regular {
    background: var(--surface2);
    color: var(--muted);
    border: 1px solid var(--border);
}

/* Card body — dept + perms side by side */
.adm-card-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
}
@media (max-width: 700px) {
    .adm-card-body { grid-template-columns: 1fr; }
}

.adm-card-section {
    padding: 14px 20px;
}
.adm-card-section + .adm-card-section {
    border-left: 1px solid var(--border);
}
@media (max-width: 700px) {
    .adm-card-section + .adm-card-section {
        border-left: none;
        border-top: 1px solid var(--border);
    }
}

.adm-section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--muted);
    margin-bottom: 10px;
}

/* Dept tags */
.adm-dept-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}
.adm-dept-tag {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 6px;
    border: 1px solid;
    cursor: pointer;
    transition: all .15s;
    user-select: none;
}
.adm-dept-tag input[type="checkbox"] {
    display: none;
}
.adm-dept-tag-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
.adm-dept-tag-text {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
}
.adm-dept-tag.on {
    background: rgba(255,255,255,.04);
}
.adm-dept-tag.off {
    background: transparent;
    opacity: .4;
}
.adm-dept-tag.off:hover { opacity: .7; }
.adm-dept-tag.on:hover { opacity: .8; }
.adm-dept-tag.locked {
    cursor: not-allowed;
    opacity: 1 !important;
}

.adm-all-depts-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 0;
}
.adm-no-dept-warn {
    font-size: 11px;
    color: #f87171;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Permission pill grid */
.adm-perm-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}
@media (max-width: 500px) {
    .adm-perm-grid { grid-template-columns: repeat(2, 1fr); }
}

.adm-perm-pill {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: var(--surface2);
    cursor: pointer;
    transition: all .15s;
    user-select: none;
}
.adm-perm-pill input[type="checkbox"] { display: none; }
.adm-perm-pill-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--muted);
    transition: color .15s;
    line-height: 1.2;
}
.adm-perm-pill-switch {
    width: 28px;
    height: 16px;
    border-radius: 16px;
    background: var(--border);
    position: relative;
    flex-shrink: 0;
    transition: background .2s;
}
.adm-perm-pill-switch::after {
    content: '';
    position: absolute;
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: var(--muted);
    top: 2.5px;
    left: 2.5px;
    transition: all .2s;
}
.adm-perm-pill.on {
    background: rgba(240,165,0,.07);
    border-color: rgba(240,165,0,.3);
}
.adm-perm-pill.on .adm-perm-pill-label { color: var(--text); }
.adm-perm-pill.on .adm-perm-pill-switch {
    background: rgba(240,165,0,.3);
}
.adm-perm-pill.on .adm-perm-pill-switch::after {
    background: var(--accent);
    left: 14.5px;
}
.adm-perm-pill.locked {
    cursor: not-allowed;
    background: rgba(240,165,0,.05);
    border-color: rgba(240,165,0,.2);
}
.adm-perm-pill.locked .adm-perm-pill-label { color: var(--accent); opacity: .7; }
.adm-perm-pill.locked .adm-perm-pill-switch { background: rgba(240,165,0,.2); }
.adm-perm-pill.locked .adm-perm-pill-switch::after { background: var(--accent); left: 14.5px; }

/* Pagination row */
.adm-pagination-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
    padding: 0 4px;
}
.adm-page-info {
    font-size: 12px;
    color: var(--muted);
}
/* Override pagination margin for this page */
#page-administration .pagination { margin-top: 0; }

/* Save toast */
.save-toast {
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
.save-toast.show {
    transform: translateY(0);
    opacity: 1;
}

/* Empty state */
.adm-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.adm-empty-icon { font-size: 36px; margin-bottom: 12px; }
.adm-empty-text { font-size: 14px; }

/* ── Section divider ─────────────────────────── */
.adm-section-divider {
    display: flex;
    align-items: center;
    gap: 14px;
    margin: 36px 0 22px;
}
.adm-section-divider-line {
    flex: 1;
    height: 1px;
    background: var(--border);
}
.adm-section-divider-label {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--muted);
    white-space: nowrap;
    padding: 4px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 99px;
}
</style>

<div class="save-toast" id="adm-save-toast">✓ Permissions saved</div>
