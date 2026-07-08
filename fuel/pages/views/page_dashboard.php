<?php
/**
 * page_dashboard.php
 * Dashboard page content — daily stats, refills, top consumers, schedules.
 */
?>
<!-- ═══════════════════ DASHBOARD PAGE ═══════════════════ -->
<div class="page active" id="page-dashboard">
    <div class="page-title">Daily <span>Fuel</span> Dashboard</div>
    <p class="page-sub">Real-time fuel consumption monitoring across all departments</p>

    <div class="date-bar">
        <label style="font-size:13px;color:var(--muted);">Date:</label>
        <input type="date" id="dashDate" value="">
        <button class="btn btn-primary" onclick="loadDashboard()">Refresh</button>
        <button class="btn btn-secondary" onclick="document.getElementById('dashDate').value=todayStr;loadDashboard()">Today</button>
    </div>

    <div id="dept-stats" class="stats-grid"></div>

    <div class="grid2">
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Today's Fuel Refills</span>
                <span id="today-refills-count" style="font-size:12px;color:var(--muted)"></span>
            </div>
            <div class="panel-body" style="padding:0">
                <div class="tbl-wrap" id="today-refills-table"><div class="loading"><span class="spinner"></span>Loading...</div></div>
            </div>
            <div style="padding:8px 16px;border-top:1px solid var(--border);">
                <div class="pagination" id="today-refills-pagination"></div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">Top Consumer Today</span>
            </div>
            <div class="panel-body" style="padding:0">
                <div class="tbl-wrap" id="top-consumers"><div class="loading"><span class="spinner"></span>Loading...</div></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Today's Truck Schedules</span>
            <span id="sched-count" style="font-size:12px;color:var(--muted)"></span>
        </div>
        <div style="padding:0">
            <div class="tbl-wrap" id="sched-table"><div class="loading"><span class="spinner"></span>Loading...</div></div>
        </div>
        <div style="padding:8px 16px;border-top:1px solid var(--border);">
            <div class="pagination" id="sched-pagination"></div>
        </div>
    </div>
</div>
