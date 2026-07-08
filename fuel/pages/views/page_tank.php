<?php
/**
 * page_tank.php
 * Fuel Tank page — supports TRADEWELL and TRADEWELL GUMACA tanks.
 */
?>
<!-- ═══════════════════ FUEL TANK PAGE ═══════════════════ -->
<div class="page" id="page-tank">
    <div style="display:flex;align-items:center;gap:12px">
        <div class="page-title">Fuel <span>Tank</span></div>
        <button onclick="loadTankDashboard()" title="Refresh Tank" style="background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:5px 10px;cursor:pointer;font-size:15px;line-height:1;transition:all .2s" onmouseover="this.style.color='var(--accent)';this.style.borderColor='var(--accent)'" onmouseout="this.style.color='var(--muted)';this.style.borderColor='var(--border)'">↻</button>
    </div>
    <p class="page-sub" id="tank-page-sub">Depot tank — 16,000 L capacity</p>

    <!-- ── Supplier tank selector ── -->
    <div class="panel" style="margin-bottom:18px;padding:14px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);white-space:nowrap">Active Tank:</label>
        <div style="position:relative;display:flex;align-items:center;gap:0">
            <span style="position:absolute;left:12px;font-size:15px;pointer-events:none">🛢</span>
            <select id="tank-supplier-select" onchange="onTankSupplierChange()"
                style="padding:8px 14px 8px 36px;border-radius:8px;border:1.5px solid var(--accent);
                       background:var(--surface2);color:var(--text);font-size:13px;font-weight:700;
                       cursor:pointer;appearance:none;-webkit-appearance:none;min-width:220px">
                <option value="TRADEWELL">TRADEWELL</option>
                <option value="TRADEWELL GUMACA">TRADEWELL GUMACA</option>
            </select>
            <span style="position:absolute;right:12px;pointer-events:none;color:var(--muted);font-size:11px">▼</span>
        </div>
        <div id="tank-supplier-badge" style="padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;
             background:rgba(240,165,0,.15);color:#f0a500;border:1px solid rgba(240,165,0,.3)">
            TRADEWELL
        </div>
    </div>

    <!-- Gauge panel -->
    <div class="panel" style="margin-bottom:20px;padding:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px">
            <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Current Tank Level</div>
            <div id="tank-gauge-label" style="font-family:'Barlow Condensed',sans-serif;font-size:20px;font-weight:800">0 / 16,000 L</div>
        </div>
        <div style="background:var(--surface2);border-radius:99px;height:28px;overflow:hidden;border:1px solid var(--border);position:relative">
            <div id="tank-gauge-bar" style="height:100%;width:0%;border-radius:99px;transition:width .6s ease;background:linear-gradient(90deg,#16a34a,#22c55e);position:relative">
                <div id="tank-gauge-pct" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;font-weight:700;color:#fff;white-space:nowrap"></div>
            </div>
        </div>
        <div id="tank-low-warning" style="display:none;margin-top:10px;padding:8px 14px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);border-radius:8px;color:#f87171;font-size:13px;font-weight:600">
            ⚠️ Low fuel level — consider scheduling a tank refill soon.
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:10px">
            <div style="font-size:13px;color:var(--muted)">
                Available Capacity: <span id="tank-available-capacity" style="font-weight:700;color:var(--text)">—</span>
            </div>
        </div>
    </div>

    <!-- 3 stat cards -->
    <div class="stats-grid" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="stat-label">⬆ Fuel Added</div>
            <div class="stat-value" id="tank-added">—</div>
            <div class="stat-sub">Total poured into tank (all time)</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">⬇ Fuel Used</div>
            <div class="stat-value" style="color:#f87171" id="tank-used">—</div>
            <div class="stat-sub" id="tank-used-sub">Dispensed to trucks</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🛢 Current Fuel</div>
            <div class="stat-value" style="color:#22c55e" id="tank-current">—</div>
            <div class="stat-sub">Estimated remaining in tank</div>
        </div>
    </div>

    <!-- Refill log -->
    <div class="panel">
        <div class="panel-header">
            <div style="font-weight:700;font-size:14px" id="tank-refill-log-title">Tank Refill Log</div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <button class="btn" id="tankFuelPriceBtn"
                        onclick="openFuelPriceModal()"
                        style="padding:6px 14px;font-size:12px;background:rgba(240,165,0,.1);color:var(--accent);border:1px solid rgba(240,165,0,.3);border-radius:7px;display:flex;align-items:center;gap:6px;cursor:pointer;transition:all .15s"
                        onmouseover="this.style.background='rgba(240,165,0,.2)'" onmouseout="this.style.background='rgba(240,165,0,.1)'">
                    ⛽ <span>Price: </span><strong id="tankFuelPriceVal">₱—</strong>
                </button>
                <button class="btn btn-primary" style="padding:6px 14px;font-size:12px;background:#22c55e;color:#000;border:none" onclick="openTankRefillModal()">+ Add Refill</button>
            </div>
        </div>
        <div class="tbl-wrap" id="tank-table-wrap">
            <div class="loading"><span class="spinner"></span>Loading…</div>
        </div>
        <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <div style="font-size:12px;color:var(--muted)" id="tank-page-info"></div>
            <div class="pagination" id="tank-pagination"></div>
        </div>
    </div>
</div>
