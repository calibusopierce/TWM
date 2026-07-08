<?php
/**
 * layout_footer.php
 * Shared bottom section: closes <main> and <layout>, modals, and all JS.
 * Include at the bottom of every page view.
 */
?>
    </main>
</div>



<!-- ═══════════ EDIT FUEL RECORD MODAL ═══════════ -->
<div class="modal-overlay" id="editFuelModal">
    <div class="modal" style="width:640px;max-width:97vw">
        <div class="modal-header">
            <span class="modal-title">✏️ Edit Fuel Record</span>
            <button class="modal-close" onclick="closeModal('editFuelModal')">✕</button>
        </div>
        <div class="modal-body" style="padding:20px">
            <input type="hidden" id="ef-fuel-id">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Plate Number *</label>
                    <input type="text" id="ef-plate" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px;text-transform:uppercase" placeholder="e.g. ABC 123">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Department *</label>
                    <select id="ef-dept" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px"></select>
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Fuel Date *</label>
                    <input type="date" id="ef-date" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Fuel Time</label>
                    <input type="time" id="ef-time" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Liters *</label>
                    <input type="number" id="ef-liters" step="0.01" min="0" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px" oninput="efCalcAmount()">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Price (₱/L) *</label>
                    <input type="number" id="ef-price" step="0.01" min="0" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px" oninput="efCalcAmount()">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Amount (₱)</label>
                    <input type="text" id="ef-amount" readonly style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--accent);padding:8px 10px;border-radius:6px;font-size:13px;font-weight:700;cursor:not-allowed">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Supplier</label>
                    <select id="ef-supplier" onchange="onEfPoOrInputChanged()" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px"></select>
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Area</label>
                    <input type="text" id="ef-area" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Requested By</label>
                    <input type="text" id="ef-requested" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">PO #</label>
                    <input type="text" id="ef-po" oninput="onEfPoOrInputChanged()" onblur="onEfPoOrInputChanged()" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                    <div id="ef-po-hint" style="font-size:11px;margin-top:3px"></div>
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">OR #</label>
                    <input type="text" id="ef-or" oninput="onEfPoOrInputChanged()" onblur="onEfPoOrInputChanged()" style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 10px;border-radius:6px;font-size:13px">
                    <div id="ef-or-hint" style="font-size:11px;margin-top:3px"></div>
                </div>
            </div>
            <div id="ef-error" style="display:none;margin-top:12px;padding:8px 12px;background:rgba(239,68,68,.15);border:1px solid #ef4444;border-radius:6px;color:#ef4444;font-size:13px"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button class="btn btn-secondary" onclick="closeModal('editFuelModal')">Cancel</button>
                <button class="btn btn-primary" id="ef-save-btn" onclick="saveEditFuel()">💾 Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ DELETE FUEL CONFIRM MODAL ═══════════ -->
<div class="modal-overlay" id="deleteFuelModal">
    <div class="modal" style="width:420px;max-width:95vw">
        <div class="modal-body" style="padding:32px 28px 24px;text-align:center">
            <div style="width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.15);border:2px solid rgba(239,68,68,.4);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px">🗑️</div>
            <div style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:8px">Delete Fuel Record?</div>
            <div style="font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:24px">
                This action is <strong style="color:#ef4444">permanent</strong> and cannot be undone.<br>
                The fuel record will be removed from the system.
            </div>
            <div style="display:flex;gap:10px;justify-content:center">
                <button class="btn btn-secondary" onclick="closeModal('deleteFuelModal')" style="min-width:110px">Cancel</button>
                <button id="df-confirm-btn" onclick="executeDeleteFuel()" style="min-width:110px;background:#ef4444;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;transition:opacity .2s">Yes, Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ PLATE CALENDAR MODAL ═══════════ -->
<div class="modal-overlay" id="plateCalModal">
    <div class="modal" style="width:820px;max-width:97vw">
        <div class="modal-header">
            <div>
                <span class="modal-title">📅 <span id="pcm-plate-title">—</span></span>
                <span id="pcm-dept-badge" style="margin-left:10px"></span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <select id="pcm-year" onchange="loadPlateCalendar()" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:4px 10px;border-radius:6px;font-size:13px"></select>
                <button class="modal-close" onclick="closeModal('plateCalModal')">✕</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="pcm-body">
                <div class="loading"><span class="spinner"></span>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ PLATE MONTH DETAIL MODAL ═══════════ -->
<div class="modal-overlay" id="plateMonthModal">
    <div class="modal" style="width:960px;max-width:98vw">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <button class="btn btn-secondary" onclick="closePlateMonthModal()" style="padding:4px 12px;font-size:12px">← Back</button>
                <span class="modal-title" id="pmm-title">—</span>
                <span id="pmm-refill-badge" style="background:var(--accent);color:#000;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700"></span>
            </div>
            <button class="modal-close" onclick="closeModal('plateMonthModal')">✕</button>
        </div>
        <div class="modal-body" style="padding:16px">
            <div id="pmm-body">
                <div class="loading"><span class="spinner"></span>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ TANK REFILL MODAL ═══════════ -->
<div class="modal-overlay" id="tankRefillModal">
    <div class="modal" style="width:480px;max-width:95vw">
        <div class="modal-header">
            <span class="modal-title">⛽ Add Tank Refill</span>
            <button class="modal-close" onclick="closeModal('tankRefillModal')">✕</button>
        </div>
        <div class="modal-body">
            <div id="tank-modal-alert"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Refill Date *</label>
                    <input type="date" id="tr-date">
                </div>
                <div class="form-group">
                    <label>Time of Arrival</label>
                    <input type="time" id="tr-time">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Liters Added *</label>
                    <input type="number" id="tr-liters" step="0.01" min="0" placeholder="e.g. 5000">
                </div>
                <div class="form-group">
                    <label>Supplier</label>
                    <input type="text" id="tr-supplier" placeholder="e.g. Petron">
                </div>
            </div>
            <div class="form-group">
                <label>Attendant</label>
                <input type="text" id="tr-attendant" placeholder="Name of attendant">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <input type="text" id="tr-notes" placeholder="Optional notes">
            </div>
            <div class="form-group">
                <label>Receipt</label>
                <input type="file" id="tr-receipt" accept="image/*,application/pdf" capture="environment">
                <div style="font-size:11px;color:var(--muted);margin-top:3px">Attach a photo of the receipt — on a phone this can open the camera directly. JPG/PNG/PDF, max 10 MB.</div>
            </div>
            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('tankRefillModal')">Cancel</button>
                <button class="btn btn-primary" style="background:#22c55e;color:#000;border:none" onclick="submitTankRefill()">Save Refill</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ SET FUEL PRICE MODAL ═══════════ -->
<div class="modal-overlay" id="fuelPriceModal">
    <div class="modal" style="width:480px;max-width:95vw">
        <div class="modal-header">
            <div>
                <span class="modal-title">⛽ Set Fuel Price per Liter</span>
                <div id="fpm-supplier-badge" style="display:inline-block;margin-left:10px;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700;background:rgba(240,165,0,.15);color:#f0a500;border:1px solid rgba(240,165,0,.3);vertical-align:middle">TRADEWELL</div>
            </div>
            <button class="modal-close" onclick="closeModal('fuelPriceModal')">✕</button>
        </div>
        <div class="modal-body">
            <div id="fpm-alert"></div>

            <!-- Current price display -->
            <div class="fpm-current-card">
                <div class="fpm-current-label">Current Price</div>
                <div class="fpm-current-price" id="fpmCurrentPrice">₱—</div>
                <div class="fpm-current-meta" id="fpmCurrentMeta"></div>
            </div>

            <div class="form-group" style="margin-top:18px">
                <label>New Price per Liter (₱) *</label>
                <input type="number" id="fpm-price" step="0.01" min="0.01" placeholder="e.g. 62.50"
                       oninput="fpmPreview()" style="font-size:18px;font-weight:700;color:var(--accent)">
                <div id="fpm-preview" style="font-size:12px;color:var(--muted);margin-top:5px"></div>
            </div>

            <div class="form-group">
                <label>Reason / Note <span style="color:var(--muted);font-size:11px">(optional)</span></label>
                <input type="text" id="fpm-note" placeholder="e.g. Petron price increase May 2026">
            </div>

            <!-- History accordion -->
            <div class="fpm-history-toggle" onclick="toggleFpmHistory()">
                📜 <span id="fpm-history-label">Show Price History</span>
            </div>
            <div id="fpm-history-wrap" style="display:none;margin-top:8px">
                <div id="fpm-history-body" class="loading"><span class="spinner"></span>Loading…</div>
                <div id="fpm-history-pagination" style="display:none;justify-content:center;align-items:center;gap:4px;margin-top:8px;flex-wrap:wrap"></div>
            </div>

            <div class="form-actions" style="margin-top:20px">
                <button class="btn btn-secondary" onclick="closeModal('fuelPriceModal')">Cancel</button>
                <button class="btn btn-primary" onclick="submitFuelPrice()" style="background:#f0a500;color:#000">
                    ✓ Apply New Price
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Fuel Price Modal styles ── */
.fpm-current-card {
    background: rgba(240,165,0,.07);
    border: 1px solid rgba(240,165,0,.25);
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.fpm-current-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--muted);
}
.fpm-current-price {
    font-family: 'Barlow Condensed', sans-serif;
    font-size: 36px;
    font-weight: 800;
    color: var(--accent);
    line-height: 1;
}
.fpm-current-meta {
    font-size: 11px;
    color: var(--muted);
}
.fpm-history-toggle {
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    padding: 6px 0;
    transition: color .15s;
    user-select: none;
    margin-top: 10px;
}
.fpm-history-toggle:hover { color: var(--text); }
.fpm-history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.fpm-history-table th {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    padding: 5px 8px;
    border-bottom: 1px solid var(--border);
    text-align: left;
}
.fpm-history-table td {
    padding: 6px 8px;
    border-bottom: 1px solid rgba(48,54,61,.4);
    vertical-align: middle;
}
.fpm-page-btn {
    min-width: 28px;
    height: 28px;
    padding: 0 7px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: transparent;
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
    line-height: 26px;
    text-align: center;
}
.fpm-page-btn:hover { background: rgba(240,165,0,.12); color: var(--accent); border-color: var(--accent); }
.fpm-page-btn.active { background: var(--accent); color: #000; border-color: var(--accent); }
.fpm-page-btn:disabled { opacity: .35; cursor: not-allowed; }
</style>


<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title">⛽ Add Fuel Record</span>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <div class="modal-body">
            <div id="modal-alert"></div>

            <div class="form-row">
                <div class="form-group">
                    <label>Department *</label>
                    <select id="add-dept" onchange="loadVehiclesForDept()">
                        <option value="">Select...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Plate Number *</label>
                    <div class="plate-combo" id="plate-combo-wrap">
                        <select id="add-plate-select" disabled onchange="onPlateSelectChange()" style="display:none"></select>
                        <div class="plate-input-wrap" id="plate-input-wrap" style="display:none">
                            <input type="text" id="add-plate-text" placeholder="Type plate number..." autocomplete="off" oninput="onPlateTextInput(this)">
                            <div class="plate-suggestions" id="plate-suggestions"></div>
                        </div>
                        <div class="plate-locked" id="plate-locked">Select a department first</div>
                    </div>
                    <div class="plate-mode-toggle" id="plate-mode-toggle" style="display:none">
                        <label class="toggle-label">
                            <input type="checkbox" id="plate-custom-toggle" onchange="togglePlateMode()">
                            <span>Enter custom plate not in list</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Fuel Date *</label>
                    <input type="date" id="add-date">
                </div>
                <div class="form-group">
                    <label>Fuel Time</label>
                    <input type="time" id="add-time">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Area / Route</label>
                    <input type="text" id="add-area" placeholder="e.g. LUCENA">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Liters *</label>
                    <input type="number" id="add-liters" step="0.01" min="0" oninput="calcAmount()">
                </div>
                <div class="form-group">
                    <label>Price per Liter *
                        <span id="add-price-badge" style="font-size:10px;font-weight:600;margin-left:6px;padding:2px 7px;border-radius:10px;background:#f0a500;color:#000;">🔒 Auto (Tradewell)</span>
                    </label>
                    <input type="number" id="add-price" step="0.01" min="0" value="60.30"
                           readonly tabindex="-1"
                           style="background:var(--bg-alt,#e6edf3);font-weight:700;color:var(--accent);cursor:not-allowed;pointer-events:none;opacity:0.8;"
                           oninput="calcAmount()">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Total Amount</label>
                    <input type="text" id="add-amount" readonly style="background:var(--bg);font-weight:700;color:var(--accent)">
                </div>
                <div class="form-group">
                    <label>Supplier</label>
                    <div class="plate-input-wrap" style="position:relative">
                        <input type="text" id="add-supplier" placeholder="Search supplier..." autocomplete="off"
                            oninput="onSupplierInput(this); applySupplierPriceLogic(this.value); onPoOrInputChanged();" onfocus="onSupplierInput(this)">
                        <div class="plate-suggestions" id="supplier-suggestions"></div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>PO Number</label>
                    <input type="text" id="add-po" oninput="onPoOrInputChanged()" onblur="onPoOrInputChanged()">
                    <div id="add-po-hint" style="font-size:11px;margin-top:3px"></div>
                </div>
                <div class="form-group">
                    <label>OR Number</label>
                    <input type="text" id="add-or" oninput="onPoOrInputChanged()" onblur="onPoOrInputChanged()">
                    <div id="add-or-hint" style="font-size:11px;margin-top:3px"></div>
                </div>
            </div>

            <div class="form-group">
                <label>Requested By</label>
                <div class="plate-input-wrap" style="position:relative">
                    <input type="text" id="add-requested" placeholder="Type name to search…"
                           autocomplete="off" oninput="onRequestedInput(this)">
                    <div class="plate-suggestions" id="requested-suggestions"></div>
                </div>
                <div id="requested-hint" style="font-size:11px;color:var(--muted);margin-top:3px"></div>
            </div>

            <div class="form-actions">
                <button class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button class="btn btn-primary" onclick="submitFuelRecord()">Save Record</button>
            </div>
        </div>
    </div>
</div>


<script>
const API     = '<?= rtrim(dirname($_SERVER['PHP_SELF']), '/') === '' ? '' : dirname($_SERVER['PHP_SELF']) ?>/pages/api.php';
let todayStr  = new Date().toISOString().split('T')[0];

// ── Active Department State ───────────────────────────────
// VISIBLE_DEPTS and activeDept are set after loadDepartments() resolves
let VISIBLE_DEPTS = [];
let activeDept    = '';
let pendingDept   = '';

// DEPT_COLORS is populated dynamically via loadDepartments() on page load
// deptColor() helper — safe fallback
function deptColor(name) {
    return DEPT_COLORS[name] || DEPT_COLORS[''] || '#f0a500';
}

function deptDotClass(val) {
    // Generate a CSS-safe slug from dept name
    const slug = val ? val.toLowerCase().replace(/\s+/g, '') : 'all';
    return slug || 'all';
}

// Update the trigger button to reflect current activeDept
function updateDeptTrigger() {
    const label  = activeDept || 'All Departments';
    const dotCls = deptDotClass(activeDept);
    document.getElementById('deptTriggerLabel').textContent = label;
    const dot = document.getElementById('deptTriggerDot');
    dot.className = `dept-trigger-dot dot-${dotCls}`;
}

// Build the options list inside the dropdown
function buildDeptOptions() {
    const list     = document.getElementById('deptOptionsList');
    const showAll  = VISIBLE_DEPTS.length > 1;
    const depts    = showAll
        ? [{ label: 'All Departments', val: '' }, ...VISIBLE_DEPTS.map(d => ({ label: d, val: d }))]
        : VISIBLE_DEPTS.map(d => ({ label: d, val: d }));
    list.innerHTML = '';
    depts.forEach(({ label, val }) => {
        const color = DEPT_COLORS[val] || '#888';
        const btn   = document.createElement('button');
        btn.className = `dept-option${val === pendingDept ? ' selected' : ''}`;
        btn.innerHTML = `
            <span class="dept-option-dot" style="background:${color}"></span>
            <span class="dept-option-name">${label}</span>
            <span class="dept-option-check">✓</span>`;
        btn.onclick = (e) => {
            e.stopPropagation();
            pendingDept = val;
            buildDeptOptions(); // re-render to update checkmark
        };
        list.appendChild(btn);
    });
}

function toggleDeptDropdown() {
    const btn      = document.getElementById('deptTriggerBtn');
    const dropdown = document.getElementById('deptDropdown');
    const isOpen   = dropdown.classList.contains('open');
    if (isOpen) {
        closeDeptDropdown();
    } else {
        pendingDept = activeDept; // reset pending to current on open
        buildDeptOptions();
        dropdown.classList.add('open');
        btn.classList.add('open');
    }
}

function closeDeptDropdown() {
    document.getElementById('deptDropdown').classList.remove('open');
    document.getElementById('deptTriggerBtn').classList.remove('open');
}

function applyDeptSelection(e) {
    if (e) e.stopPropagation();
    closeDeptDropdown();
    if (pendingDept === activeDept) return;
    selectDept(pendingDept);
}

// Close dropdown on outside click — use mousedown so it fires before blur/click chain
document.addEventListener('mousedown', e => {
    const wrap = document.getElementById('deptSwitcherWrap');
    if (wrap && !wrap.contains(e.target)) closeDeptDropdown();
});

// Prevent clicks INSIDE the dropdown from bubbling to the document mousedown handler
document.getElementById('deptDropdown') && document.getElementById('deptDropdown').addEventListener('mousedown', e => {
    e.stopPropagation();
});

function selectDept(dept) {
    activeDept = dept;
    updateDeptTrigger();

    // Reload whichever page is currently active
    const activePage = document.querySelector('.page.active');
    if (!activePage) return;
    const pageId = activePage.id.replace('page-','');
    if (pageId === 'dashboard')    loadDashboard();
    if (pageId === 'fuel-records') loadFuelRecords(1);
    if (pageId === 'gas-card')     loadGasCard();
}

// ── Clock ─────────────────────────────────────────────────
function updateClock() {
    document.getElementById('clock').textContent =
        new Date().toLocaleString('en-PH', {weekday:'short',month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
setInterval(updateClock, 1000); updateClock();

// ── Permission enforcement ────────────────────────────────
// Map page names → permission keys & sidebar/button IDs
const PAGE_PERM_MAP = {
    'fuel-records': 'perm_fuel_records',
    'gas-card':     'perm_driver_card',
    'tank':         'perm_fuel_tank',
    // 'administration', 'tank-access', and 'system-access' are NOT in this map — controlled by IS_SUPERADMIN role
};

function applyPermissions() {
    // Hide sidebar links the user cannot access
    document.querySelectorAll('.sidebar a').forEach(link => {
        const onclick = link.getAttribute('onclick') || '';
        const match   = onclick.match(/showPage\('([^']+)'/);
        if (!match) return;
        const page    = match[1];
        // Administration, Tank Access & System Access: superadmin-only (PHP already hides them server-side, JS is extra guard)
        if ((page === 'administration' || page === 'tank-access' || page === 'system-access') && !IS_SUPERADMIN) {
            link.style.display = 'none';
            return;
        }
        const permKey = PAGE_PERM_MAP[page];
        if (permKey && !USER_PERMS[permKey]) {
            link.style.display = 'none';
        }
    });

    // Hide "Add Fuel Record" navbar button
    if (!USER_PERMS.perm_add_fuel) {
        const addBtn = document.querySelector('.navbar .btn-primary');
        if (addBtn) addBtn.style.display = 'none';
    }

    // Hide "Tank Refill" navbar button
    if (!USER_PERMS.perm_tank_fill) {
        const refillBtn = document.querySelector('.navbar .btn-secondary');
        if (refillBtn) refillBtn.style.display = 'none';
    }

    // Hide "Set Fuel Price" button on tank page
    if (!USER_PERMS.perm_edit_fuel_price) {
        const priceBtn = document.getElementById('tankFuelPriceBtn');
        if (priceBtn) priceBtn.style.display = 'none';
    }
}

// ── Init ──────────────────────────────────────────────────
// ── Department toast notification ────────────────────────
let _deptToastTimer = null;
function showDeptToast(msg) {
    let toast = document.getElementById('dept-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'dept-toast';
        toast.style.cssText = `
            position:fixed; bottom:24px; right:24px; z-index:99999;
            background:#3fb950; color:#000; font-weight:700; font-size:13px;
            padding:12px 20px; border-radius:10px;
            box-shadow:0 4px 20px rgba(0,0,0,.4);
            transform:translateY(80px); opacity:0;
            transition:all .3s cubic-bezier(.34,1.56,.64,1);
            pointer-events:none; display:flex; align-items:center; gap:8px;
            max-width:320px;
        `;
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    // Show
    requestAnimationFrame(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity   = '1';
    });
    // Auto-hide after 3s
    clearTimeout(_deptToastTimer);
    _deptToastTimer = setTimeout(() => {
        toast.style.transform = 'translateY(80px)';
        toast.style.opacity   = '0';
    }, 3000);
}


async function loadDepartments() {
    try {
        const res   = await fetch(`${API}?action=get_departments&active_only=1`);
        const depts = await res.json();

        // Populate global arrays/maps
        ALL_DEPTS   = depts.map(d => d.DepartmentName);
        DEPT_COLORS = {};
        DEPT_TAG_COLORS = {};
        depts.forEach(d => {
            const c = d.Color || '#888888';
            DEPT_COLORS[d.DepartmentName] = c;
            // Generate subtle bg/border from the main color
            DEPT_TAG_COLORS[d.DepartmentName] = {
                color:  c,
                bg:     hexToRgba(c, 0.12),
                border: hexToRgba(c, 0.35)
            };
        });
        DEPT_COLORS[''] = '#f0a500'; // fallback for "all"

        // Set VISIBLE_DEPTS based on user permissions
        VISIBLE_DEPTS = (IS_SUPERADMIN || USER_ALLOWED_DEPTS.length === 0)
            ? ALL_DEPTS
            : USER_ALLOWED_DEPTS.filter(d => ALL_DEPTS.includes(d));

        // Set default active dept
        activeDept  = VISIBLE_DEPTS.length === 1
            ? VISIBLE_DEPTS[0]
            : (USER_DEPT && VISIBLE_DEPTS.includes(USER_DEPT) ? USER_DEPT : '');
        pendingDept = activeDept;

        // Populate the Add Fuel dept dropdown
        const addDeptSel = document.getElementById('add-dept');
        if (addDeptSel) {
            addDeptSel.innerHTML = '<option value="">Select...</option>';
            ALL_DEPTS.forEach(d => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = d;
                addDeptSel.appendChild(opt);
            });
        }

        // Populate fuel records dept filter
        const frDeptSel = document.getElementById('filter-dept');
        if (frDeptSel) {
            const current = frDeptSel.value;
            frDeptSel.innerHTML = '<option value="">All Departments</option>';
            VISIBLE_DEPTS.forEach(d => {
                const opt = document.createElement('option');
                opt.value = opt.textContent = d;
                if (d === current) opt.selected = true;
                frDeptSel.appendChild(opt);
            });
        }

    } catch (e) {
        console.error('Failed to load departments:', e);
    }
}

// Helper: convert hex color to rgba string
function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}

window.onload = async () => {
    document.getElementById('dashDate').value = todayStr;
    document.getElementById('add-date').value = todayStr;
    applyPermissions();
    await loadDepartments();   // fetch depts from DB first
    updateDeptTrigger();
    buildDeptOptions();
    loadDashboard();
    loadFuelPrice();
    // Initialize sidebar pending badge from server count
    if (IS_SUPERADMIN && typeof PENDING_ACCESS_COUNT !== 'undefined' && PENDING_ACCESS_COUNT > 0) {
        const badge = document.getElementById('adm-pending-badge');
        if (badge) { badge.textContent = PENDING_ACCESS_COUNT; badge.style.display = 'inline-flex'; }
    }
};

// ── Page routing ──────────────────────────────────────────
function showPage(name, el) {
    // Block access if user lacks permission
    const permKey = PAGE_PERM_MAP[name];
    if (permKey && !USER_PERMS[permKey]) {
        alert('⛔ You do not have permission to access this page.');
        return false;
    }
    // Administration, Tank Access & System Access are superadmin-only
    if ((name === 'administration' || name === 'tank-access' || name === 'system-access' || name === 'department') && !IS_SUPERADMIN) {
        alert('⛔ This panel is restricted to Super Admins only.');
        return false;
    }

    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sidebar a').forEach(a => a.classList.remove('active'));
    document.getElementById('page-' + name).classList.add('active');
    if (el) el.classList.add('active');
    if (name === 'dashboard')      loadDashboard();
    if (name === 'fuel-records')   loadFuelRecords(1);
    if (name === 'gas-card')       loadGasCard();
    if (name === 'tank')           { initTankSelector(); }
    if (name === 'administration') loadAdminUsers();
    if (name === 'tank-access')    loadTankAccessUsers();
    if (name === 'system-access')  loadSystemAccessUsers();
    if (name === 'department')     loadDeptMgmt();
    return false;
}

// ╔══════════════════════════════════════════════════════════╗
// ║                    DASHBOARD                            ║
// ╚══════════════════════════════════════════════════════════╝
async function loadDashboard() {
    const date   = document.getElementById('dashDate').value;
    const params = new URLSearchParams({ action: 'dashboard', date });
    if (activeDept) params.set('department', activeDept);
    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();
    renderDeptStats(data.deptStats, activeDept);
    renderTodayRefills(data.todayRefills || []);
    renderTopConsumers(data.topConsumers);
    renderSchedulesPanel(data.schedules);
}

function deptClass(d) {
    return d ? d.toLowerCase().replace(/\s+/g, '') : 'all';
}

function renderDeptStats(stats, dept) {
    const map = {};
    stats.forEach(s => map[s.Department] = s);
    let totalL=0, totalA=0, totalT=0;
    stats.forEach(s => { totalL+=parseFloat(s.TotalLiters||0); totalA+=parseFloat(s.TotalAmount||0); totalT+=parseInt(s.TrucksRefueled||0); });

    // Show only the dept selected, or all visible depts if "All Departments"
    const showDepts = dept ? [dept] : VISIBLE_DEPTS;

    let html = `<div class="stat-card all">
                    <div class="stat-label">${dept ? dept + ' — Today' : 'Total Liters Today'}</div>
                    <div class="stat-val">${totalL.toFixed(1)}<small style="font-size:14px;font-weight:400"> L</small></div>
                    <div class="stat-sub">₱${totalA.toLocaleString('en',{minimumFractionDigits:2})} • ${totalT} trucks</div>
                </div>`;
    showDepts.forEach(d => {
        const s = map[d] || {};
        const color = DEPT_COLORS[d] || '#888';
        html += `<div class="stat-card ${deptClass(d)}" style="border-top-color:${color}">
                    <div class="stat-label">${d}</div>
                    <div class="stat-val">${parseFloat(s.TotalLiters||0).toFixed(1)}<small style="font-size:14px;font-weight:400"> L</small></div>
                    <div class="stat-sub">₱${parseFloat(s.TotalAmount||0).toLocaleString('en',{minimumFractionDigits:2})}</div>
                    <div class="stat-sub">${s.TrucksRefueled||0} trucks • ${s.TotalRefills||0} refills</div>
                </div>`;
    });
    document.getElementById('dept-stats').innerHTML = html;
}

let todayRefillsData = [], todayRefillsPage = 0;
const TODAY_PAGE_SIZE = 10;

function renderTodayRefills(data) { todayRefillsData = data; todayRefillsPage = 0; _drawTodayRefillsPage(); }

function _drawTodayRefillsPage() {
    const total = todayRefillsData.length;
    const start = todayRefillsPage * TODAY_PAGE_SIZE;
    const page  = todayRefillsData.slice(start, start + TODAY_PAGE_SIZE);
    document.getElementById('today-refills-count').textContent = `${total} refill${total!==1?'s':''}`;
    if (!total) {
        document.getElementById('today-refills-table').innerHTML = '<div class="loading">No refills recorded today</div>';
        document.getElementById('today-refills-pagination').innerHTML = '';
        return;
    }
    let html = `<table><thead><tr><th>#</th><th>Plate</th><th>Dept</th><th>Area</th><th>Liters</th><th>Amount</th><th>Supplier</th></tr></thead><tbody>`;
    page.forEach((r,i) => {
        html += `<tr>
            <td style="color:var(--muted);font-size:12px">${start+i+1}</td>
            <td><strong>${r.PlateNumber}</strong>${r.Brand?`<br><small style="color:var(--muted)">${r.Brand} ${r.Model||''}</small>`:''}</td>
            <td><span class="badge dept-${deptClass(r.Department)}">${r.Department}</span></td>
            <td style="font-size:12px">${r.Area||'-'}</td>
            <td style="text-align:right"><strong>${parseFloat(r.Liters||0).toFixed(2)}</strong></td>
            <td style="text-align:right;color:var(--accent);font-weight:700">₱${parseFloat(r.Amount||0).toLocaleString('en',{minimumFractionDigits:2})}</td>
            <td style="font-size:12px">${r.Supplier||'-'}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('today-refills-table').innerHTML = html;
    _paginate('today-refills-pagination', Math.ceil(total/TODAY_PAGE_SIZE), todayRefillsPage, p => { todayRefillsPage=p; _drawTodayRefillsPage(); });
}

function renderTopConsumers(data) {
    if (!data.length) { document.getElementById('top-consumers').innerHTML='<div class="loading">No data</div>'; return; }
    let html = '<table><thead><tr><th>#</th><th>Plate</th><th>Dept</th><th>Area</th><th>Liters</th><th>Cost</th></tr></thead><tbody>';
    data.forEach((r,i) => {
        html += `<tr>
            <td style="color:var(--muted)">${i+1}</td>
            <td><strong>${r.PlateNumber}</strong></td>
            <td><span class="badge dept-${deptClass(r.Department)}">${r.Department}</span></td>
            <td style="color:var(--muted);font-size:12px">${r.Area||'-'}</td>
            <td><strong>${parseFloat(r.TotalLiters||0).toFixed(1)}L</strong></td>
            <td style="color:var(--accent)">₱${parseFloat(r.TotalCost||0).toLocaleString('en',{minimumFractionDigits:2})}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('top-consumers').innerHTML = html;
}

let schedPanelData = [], schedPanelPage = 0;
const SCHED_PAGE_SIZE = 10;

function renderSchedulesPanel(data) { schedPanelData = data; schedPanelPage = 0; _drawSchedPanelPage(); }
function _drawSchedPanelPage() {
    const total = schedPanelData.length;
    const start = schedPanelPage * SCHED_PAGE_SIZE;
    const page  = schedPanelData.slice(start, start + SCHED_PAGE_SIZE);
    document.getElementById('sched-count').textContent = `${total} schedule${total!==1?'s':''}`;
    if (!total) {
        document.getElementById('sched-table').innerHTML = '<div class="loading">No schedules found</div>';
        document.getElementById('sched-pagination').innerHTML = '';
        return;
    }
    let html = '<table><thead><tr><th>ID</th><th>Dept</th><th>Plate</th><th>Area</th><th>Remarks</th></tr></thead><tbody>';
    page.forEach(r => {
        html += `<tr>
            <td style="color:var(--muted);font-size:12px">${r.TruckScheduleID}</td>
            <td><span class="badge dept-${deptClass(r.Department)}">${r.Department}</span></td>
            <td><strong>${r.PlateNumber||'-'}</strong></td>
            <td style="font-size:12px">${r.Area||'-'}</td>
            <td style="font-size:12px;color:var(--muted)">${r.Remarks||''}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('sched-table').innerHTML = html;
    _paginate('sched-pagination', Math.ceil(total/SCHED_PAGE_SIZE), schedPanelPage, p => { schedPanelPage=p; _drawSchedPanelPage(); });
}

// ╔══════════════════════════════════════════════════════════╗
// ║                  FUEL RECORDS PAGE                      ║
// ╚══════════════════════════════════════════════════════════╝
let frCurrentPage = 1;
let frAllData     = null;

async function loadFuelRecords(page) {
    frCurrentPage = page;

    const dept      = activeDept;
    const plate     = document.getElementById('fr-plate').value.trim();
    const supplier  = document.getElementById('fr-supplier').value.trim();
    const dateFrom  = document.getElementById('fr-date-from').value;
    const dateTo    = document.getElementById('fr-date-to').value;
    const requested = document.getElementById('fr-requested').value.trim();

    const wrap = document.getElementById('fr-table-wrap');
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading records…</div>';

    const params = new URLSearchParams({
        action: 'fuel_records',
        page,
        pageSize: 20,
        department: dept,
        plate,
        supplier,
        date_from: dateFrom,
        date_to:   dateTo,
        requested,
    });

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();
    frAllData  = data;

    renderFuelRecordsTable(data);
}

function renderFuelRecordsTable(data) {
    const { records, total, page, pageSize, totalPages, totals } = data;

    document.getElementById('frt-records').textContent = Number(totals.TotalRecords||0).toLocaleString('en');
    document.getElementById('frt-liters').textContent  = parseFloat(totals.TotalLiters||0).toLocaleString('en',{minimumFractionDigits:2}) + ' L';
    document.getElementById('frt-amount').textContent  = '₱' + parseFloat(totals.TotalAmount||0).toLocaleString('en',{minimumFractionDigits:2});

    const wrap = document.getElementById('fr-table-wrap');

    if (!records.length) {
        wrap.innerHTML = '<div class="loading">No records found for the selected filters.</div>';
        document.getElementById('fr-page-info').textContent = '';
        document.getElementById('fr-pagination').innerHTML  = '';
        return;
    }

    let html = `<table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Time</th>
                <th>Department</th>
                <th>Plate No.</th>
                <th>Area</th>
                <th style="text-align:right">Liters</th>
                <th style="text-align:right">Price</th>
                <th style="text-align:right">Amount</th>
                <th>Supplier</th>
                <th>PO #</th>
                <th>OR #</th>
                <th>Requested By</th>
                <th>Input Date</th>
                ${(IS_SUPERADMIN || USER_PERMS.perm_edit_fuel || USER_PERMS.perm_delete_fuel) ? '<th style="text-align:center">Actions</th>' : ''}
            </tr>
        </thead>
        <tbody id="fr-table-body">`;

    const start = (page - 1) * pageSize;
    records.forEach((r, i) => {
        const rowNum  = start + i + 1;
        const fmtDate = r.FuelDate  ? formatDate(r.FuelDate)  : '—';
        const fmtInp  = r.InputDate ? r.InputDate : '—';
        const fmtTime = r.FuelTime  ? r.FuelTime.substring(0,5) : '—';
        const liters  = parseFloat(r.Liters||0).toFixed(2);
        const price   = '₱' + parseFloat(r.Price||0).toFixed(2);
        const amount  = '₱' + parseFloat(r.Amount||0).toLocaleString('en',{minimumFractionDigits:2});

        html += `<tr>
            <td style="color:var(--muted);font-size:12px">${rowNum}</td>
            <td style="font-size:13px;white-space:nowrap">${fmtDate}</td>
            <td style="font-size:12px;color:var(--muted);white-space:nowrap">${fmtTime}</td>
            <td><span class="badge dept-${deptClass(r.Department)}">${r.Department||'—'}</span></td>
            <td><strong style="font-family:'Barlow Condensed',sans-serif;font-size:14px;letter-spacing:.5px">${r.PlateNumber||'—'}</strong></td>
            <td style="font-size:12px;color:var(--muted)">${r.Area||'—'}</td>
            <td style="text-align:right;font-weight:700;color:var(--blue)">${liters}</td>
            <td style="text-align:right;font-size:12px;color:var(--muted)">${price}</td>
            <td style="text-align:right;font-weight:700;color:var(--accent)">${amount}</td>
            <td style="font-size:12px">${r.Supplier||'—'}</td>
            <td style="font-size:12px;color:var(--muted)">${r.POnum||'—'}</td>
            <td style="font-size:12px;color:var(--muted)">${r.ORnumber||'—'}</td>
            <td style="font-size:12px">${r.Requested||'—'}</td>
            <td style="font-size:12px;color:var(--muted);white-space:nowrap">${fmtInp}</td>
            ${(IS_SUPERADMIN || USER_PERMS.perm_edit_fuel || USER_PERMS.perm_delete_fuel) ? `<td style="text-align:center;white-space:nowrap">
                ${(IS_SUPERADMIN || USER_PERMS.perm_edit_fuel)   ? `<button onclick='openEditFuelModal(${JSON.stringify(r)})' title="Edit" style="background:rgba(96,165,250,.15);color:#60a5fa;border:1px solid #60a5fa;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px;margin-right:4px">✏️</button>` : ''}
                ${(IS_SUPERADMIN || USER_PERMS.perm_delete_fuel) ? `<button onclick='confirmDeleteFuel(${r.FuelID})' title="Delete" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid #ef4444;border-radius:5px;padding:3px 8px;cursor:pointer;font-size:12px">🗑️</button>` : ''}
            </td>` : ''}
        </tr>`;
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;

    const from = start + 1;
    const to   = Math.min(start + pageSize, total);
    document.getElementById('fr-page-info').textContent = `Showing ${from}–${to} of ${total.toLocaleString('en')} records`;

    _paginate('fr-pagination', totalPages, page - 1, p => loadFuelRecords(p + 1));
}

function formatDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'2-digit'});
}

function clearFRFilters() {
    document.getElementById('fr-plate').value     = '';
    document.getElementById('fr-supplier').value  = '';
    document.getElementById('fr-supplier-suggestions').innerHTML = '';
    document.getElementById('fr-date-from').value = '';
    document.getElementById('fr-date-to').value   = '';
    document.getElementById('fr-requested').value = '';
    loadFuelRecords(1);
}

// ── Edit Fuel Record ──────────────────────────────────────
async function openEditFuelModal(record) {
    // Ensure supplierList is loaded (reuse global from Add Fuel)
    if (!supplierList.length) await loadSuppliers();

    // Populate department dropdown from VISIBLE_DEPTS global
    const deptSel = document.getElementById('ef-dept');
    deptSel.innerHTML = '<option value="">Select...</option>';
    VISIBLE_DEPTS.forEach(d => {
        const o = document.createElement('option');
        o.value = o.textContent = d;
        deptSel.appendChild(o);
    });

    // Populate supplier dropdown from supplierList global (AccountName field)
    const supSel = document.getElementById('ef-supplier');
    supSel.innerHTML = '<option value="">Select...</option>';
    supplierList.forEach(s => {
        const o = document.createElement('option');
        o.value = o.textContent = s.AccountName;
        supSel.appendChild(o);
    });

    // Populate fields — FuelDate may come as ISO datetime, trim to date part
    const fuelDate = (record.FuelDate || '').substring(0, 10);
    const fuelTime = (record.FuelTime || '').substring(0, 5);

    document.getElementById('ef-fuel-id').value  = record.FuelID;
    document.getElementById('ef-plate').value     = record.PlateNumber || '';
    document.getElementById('ef-dept').value      = record.Department  || '';
    document.getElementById('ef-date').value      = fuelDate;
    document.getElementById('ef-time').value      = fuelTime;
    document.getElementById('ef-liters').value    = record.Liters      || '';
    document.getElementById('ef-price').value     = record.Price       || '';
    document.getElementById('ef-area').value      = record.Area        || '';
    document.getElementById('ef-requested').value = record.Requested   || '';
    document.getElementById('ef-po').value        = record.POnum       || '';
    document.getElementById('ef-or').value        = record.ORnumber    || '';
    document.getElementById('ef-supplier').value  = record.Supplier    || '';
    efCalcAmount();
    document.getElementById('ef-error').style.display = 'none';
    document.getElementById('ef-po-hint').textContent = '';
    document.getElementById('ef-or-hint').textContent = '';
    poOrDuplicateState.edit = { po: false, or: false };
    document.getElementById('editFuelModal').classList.add('show');  // use .show like all other modals
}

function efCalcAmount() {
    const liters = parseFloat(document.getElementById('ef-liters').value) || 0;
    const price  = parseFloat(document.getElementById('ef-price').value)  || 0;
    document.getElementById('ef-amount').value = '₱' + (liters * price).toLocaleString('en', {minimumFractionDigits: 2});
}

async function saveEditFuel() {
    const fuelId = document.getElementById('ef-fuel-id').value;
    const plate  = document.getElementById('ef-plate').value.trim().toUpperCase();
    const date   = document.getElementById('ef-date').value;
    const liters = document.getElementById('ef-liters').value;
    const errEl  = document.getElementById('ef-error');

    if (!plate || !date || !liters) {
        errEl.textContent = 'Plate Number, Date, and Liters are required.';
        errEl.style.display = 'block';
        return;
    }
    if (poOrDuplicateState.edit.po || poOrDuplicateState.edit.or) {
        errEl.textContent = 'Please resolve the duplicate PO #/OR # before saving.';
        errEl.style.display = 'block';
        return;
    }

    const btn = document.getElementById('ef-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('action',     'edit_fuel');
    fd.append('FuelID',     fuelId);
    fd.append('PlateNumber', plate);
    fd.append('Department', document.getElementById('ef-dept').value);
    fd.append('Fueldate',   date);
    fd.append('FuelTime',   document.getElementById('ef-time').value);
    fd.append('Liters',     liters);
    fd.append('Price',      document.getElementById('ef-price').value);
    fd.append('Area',       document.getElementById('ef-area').value.trim());
    fd.append('Requested',  document.getElementById('ef-requested').value.trim());
    fd.append('POnum',      document.getElementById('ef-po').value.trim());
    fd.append('ORnumber',   document.getElementById('ef-or').value.trim());
    fd.append('Supplier',   document.getElementById('ef-supplier').value);

    try {
        const res  = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('editFuelModal');
            loadFuelRecords(frCurrentPage);
            if (window.liveUpdater) window.liveUpdater.markDirty();
            window.showToast('✅', 'Record Updated', 'Fuel record has been successfully updated.', 'success');
        } else {
            errEl.textContent = data.message || 'Failed to save.';
            errEl.style.display = 'block';
        }
    } catch(e) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
    }

    btn.disabled = false;
    btn.textContent = '💾 Save Changes';
}

let _pendingDeleteFuelId = null;

function confirmDeleteFuel(fuelId) {
    _pendingDeleteFuelId = fuelId;
    const btn = document.getElementById('df-confirm-btn');
    btn.disabled = false;
    btn.textContent = 'Yes, Delete';
    btn.style.opacity = '1';
    document.getElementById('deleteFuelModal').classList.add('show');
}

async function executeDeleteFuel() {
    if (!_pendingDeleteFuelId) return;

    const btn = document.getElementById('df-confirm-btn');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    btn.style.opacity = '0.7';

    const fd = new FormData();
    fd.append('action', 'delete_fuel');
    fd.append('FuelID', _pendingDeleteFuelId);

    try {
        const res  = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        closeModal('deleteFuelModal');
        if (data.success) {
            loadFuelRecords(frCurrentPage);
            if (window.liveUpdater) window.liveUpdater.markDirty();
            window.showToast('🗑️', 'Record Deleted', 'Fuel record has been permanently deleted.', 'warning');
        } else {
            alert(data.message || 'Failed to delete record.');
        }
    } catch(e) {
        closeModal('deleteFuelModal');
        alert('Network error. Please try again.');
    }

    _pendingDeleteFuelId = null;
}




// ── Print Fuel Report ─────────────────────────────────────
const LOGO_B64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAZAAAAA/CAIAAABB4AkUAAABCGlDQ1BJQ0MgUHJvZmlsZQAAeJxjYGA8wQAELAYMDLl5JUVB7k4KEZFRCuwPGBiBEAwSk4sLGHADoKpv1yBqL+viUYcLcKakFicD6Q9ArFIEtBxopAiQLZIOYWuA2EkQtg2IXV5SUAJkB4DYRSFBzkB2CpCtkY7ETkJiJxcUgdT3ANk2uTmlyQh3M/Ck5oUGA2kOIJZhKGYIYnBncAL5H6IkfxEDg8VXBgbmCQixpJkMDNtbGRgkbiHEVBYwMPC3MDBsO48QQ4RJQWJRIliIBYiZ0tIYGD4tZ2DgjWRgEL7AwMAVDQsIHG5TALvNnSEfCNMZchhSgSKeDHkMyQx6QJYRgwGDIYMZAKbWPz9HbOBQAABW9ElEQVR42u19d3wVxdr/zO6ec3Jaeu8FCAECgVADSLlSpFfpKCgIAmLBApaL5YpIEa9oaIJ0EASpBgVCCUkACTWhJKTXk+SctNN3d35/PDrv3pNCwHt9fX8388mHz2F3dvo888xTvg8mhKD/M0lAIiZYwFiGECGIYMSgltSSWtJ/Tfo/teEJI2IBY5mxtrhKfxMjhJDYMoUtqSW1EKy/IrlCSGCwzFD+a+bD10rLjQgxhAgtU9iSWlILwfprkSpCRIR4hLjC/J/tprHVVe4+Pt0RIghxLVPYklpSC8H6S5ErEWNe4GU5OdtdFBMqDRq1dqGbq4wQgjFumcKW1JJaCNZfilqxFgubn/2Bm2a2jOXzi+d06NgOtVCrltSS/vvSX/lKRQgRMOZqausqSxZ7u21Va1DK1T4RbSY6yTAhBKMWgtWSWlILwfr3EBsE9hIOVhMYyAxGj2SPgFrpy/Oqql4K9DwlVzC6MneL7fmIVkGEiBi3GDS0pJbUQrD+QBLF38gTwyD8KJJECBJFhBHCTIM5BYw5XeFNi/X5UK8bDK8SRXN61t8io0diJBJCWrirltSSWgjW43NSSCQiISLDMIhhEPqdkJiMgr6S6PX2mhrRasa8QBQy7KQkGmfs7s65u7NOKpZlfydOgogQZhiCMUaEIIwIYQvzT3DopWDvIsGmQAprTkGwQjXf30dLCI+YFuVgS2pJLQTr8ZIgioSImOUYxGCE7Pl5QtpVS1oqf/eOLTdHrKzgao3IbuNF4iQixCCeZQS5jKidWS9vEhaBYmLUPXvLO3eTubgxCGEiElG0Mywj8Fxh7kalaom3xiTaOcTYbTY2O29Cz769ESIYc42zeI+2I2UYprFsGOPGBPmEEOoSUD+b9O0jC3xkIzHGDdbVWBscnsOTpsvHGNNmNKfXTeR5slGibx85GgzDNJHtkY2Xtl+aU9oYqKLBOWq6F/U/bGaxj9upxiai6T469KKZs9PYq8Zq+ZMTfjLXHFFERBRZjkEIlZaYTh4xnvxRvHZF1BmIDSlYJMgQwyLMYAFhBmEGIZFBGImYEMIjYkeMgEQB8U6IiQhDA4fgSdPUPfvIEJKZjfbSoo89nf+hVRHRLiNIZJVC2p1OCtm+9p0jm5Be/busHARB+B/e7w8nQogoiv/GAlvSI6dMFEVCSP0xFwSBYZj/E8ploDX1Sd4jF6ogCP9GmtLY6hUEAZr35w/mYxMsQpAoEJbDCKE7t0xbN1YfO2jP0ykw4pwQknMMxiIiSEQYEZBpMQgRhDBBBCGEsMggjBGLMEGYEYjdxjNmJGoZa7+nNXMW2Dp1fMtdtUOlYARBRjDPYlRjll++8cXAQS+xbFPUCiGUnJysVqtdXV1hvuFfjDHDMJAhOzu7c+fOer0ehlu6IAghGo3G29vbgfbBb51OZzAYOI7jed7NzQ2y0bcVFRUVFRUcx0FdtEw3NzdXV1faPIyx2WzOz8+HFeCwDiDPw4cPO3ToUFVVJZPJBEHQaDQBAQFQS01NTUlJCcdxgiDI5fLQ0FB4Xl1dXVJSIpPJrFarp6en1Wo1m83QO4fTmGGY4uJijUbTsWPHhw8fiqIoiqJWq/X3969P7gkhubm5PM83kcfhtCgqKqqrq4MWajQaf39/aZ7q6uqysjKWZWEPhIeH2+32nJwcGLH6o4ExzsjIiIuLU6vVOTk59QeNEOLs7Ozp6SmdMrqNDQZDbm5uZWUly7K+vr6tWrWSyWQIIZ7nOY6DQYOmhoSEODk50WKtVmtubi7LsjzP+/n5ubi4SDuel5dntVpZlhUEISAgQK1WS9/m5+dbLBaGYViWDQgIkMvltFiLxZKfnw+8bWhoqEKhyM7OBvpCZ4oQolAoPD09FQqFtC8lJSU1NTUMw3AcFxoaCoUwDGOxWB48eKDT6aCPERERtEZCSE5OjiAIoig6Ozv7+flVVVWVlpbCuvLy8nJ1dZXOZn5+vtVqFUWRLm+oAiFkNptzcnLKysrgw7CwMGdn5/81Wk4eJwkCLxIrISQry7hwbpG3Uw6LSrRI7yWr8JSVe7Dl7ozOndG5M+W//1v/r8Kdqfj9h86DKfVgK3yVpWpUp2Ju7f52ODEioZLlKxhez9gqZKQWJSUOzX2oI4QXBbGxhtntdkLI3//+d+maViqVKpWK4zh6V/L39zcajVOmTFEqlRqNxmEo3N3du3fv/t1334HB8nuXBULIjRs33NzcVCqVs7Pzr7/+Sp/DvxkZGb6+viqVSrpAMca+vr79+vXbtGkTFCgIgslkGjhwoEqlUqlU9S8CCKGAgACDwTBt2jTIk5CQQAjheZ4QUltbGxsbq1KpnJyctmzZQp9XV1d3795drVZHRUUVFxfHx8c7OTlptdr6xAWe7Nu3jxDy2WefKZVKtVr9888/044oINz+jO/O3FxLkRW4cR5DvbMLSJBKFwSGIWdh4VZHqFJniMKvH0kNWqiVVGJ3a7KhqiGSXoQx9l7jj7t3gSd6XZJfSyXn+/p//9/b+b555i/jJKHtUqiXeHBWqhPTxXhKjbZWVuIjuqkHLBHZAtHn/8tI3WGJuRhLlHxADtfOq+i/RuBJ/b4Gqfam8g9VbHlpXi6JW1hFBkIQQ2W/+JMUkGMjS6h7y0bQaGAl2DJfRZnfPVqbXhAkM/T0ULWJd6m/WnqT9KXeWNFTFIkHrTSwJOBDv5rT5RI9J4FWJVqiFBdoUVkR+MVA3IXSD0C9EHOqJqrV3r/4y30FfwEJmWqLT+wHHW4xt6Hqx25oIzfwsQJQAACqnSURBVCZuGjTv37/J9FjgfwMR3Wj6K6AKAAAAABJRU5ErkJggg==';

async function printFuelReport() {
    const dept      = activeDept;
    const plate     = document.getElementById('fr-plate').value.trim();
    const supplier  = document.getElementById('fr-supplier').value.trim();
    const dateFrom  = document.getElementById('fr-date-from').value;
    const dateTo    = document.getElementById('fr-date-to').value;
    const requested = document.getElementById('fr-requested').value.trim();

    const params = new URLSearchParams({
        action:    'fuel_records',
        page:      1,
        pageSize:  9999,
        sort_asc:  1,
        department: dept,
        plate,
        supplier,
        date_from: dateFrom,
        date_to:   dateTo,
        requested,
    });

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();
    const recs = data.records || [];

    const parts = [];
    if (dept)      parts.push(`Department: ${dept}`);
    if (plate)     parts.push(`Plate: ${plate}`);
    if (supplier)  parts.push(`Supplier: ${supplier}`);
    if (dateFrom)  parts.push(`From: ${dateFrom}`);
    if (dateTo)    parts.push(`To: ${dateTo}`);
    if (requested) parts.push(`Requested By: ${requested}`);
    const filterLine = parts.length ? parts.join('  |  ') : 'All Records';

    const printDate = new Date().toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'});

    const totLiters = recs.reduce((s,r) => s + parseFloat(r.Liters||0), 0);
    const totAmount = recs.reduce((s,r) => s + parseFloat(r.Amount||0), 0);

    const supplierMap = {};
    recs.forEach(r => {
        const s = r.Supplier || 'Unknown';
        supplierMap[s] = (supplierMap[s] || 0) + 1;
    });
    const supplierSummary = Object.entries(supplierMap)
        .sort((a,b) => b[1]-a[1])
        .map(([name, count]) => Object.keys(supplierMap).length > 1 ? `${name} (${count})` : name)
        .join(' | ');

    let rows = '';
    recs.forEach((r, i) => {
        const fmtDate = r.FuelDate ? new Date(r.FuelDate).toLocaleDateString('en-PH',{year:'numeric',month:'2-digit',day:'2-digit'}) : '—';
        rows += `<tr>
            <td>${i+1}</td>
            <td>${r.DID||'—'}</td>
            <td>${r.PlateNumber||'—'}</td>
            <td>${r.POnum||'—'}</td>
            <td>${r.ORnumber||'—'}</td>
            <td>${fmtDate}</td>
            <td style="text-align:right">${parseFloat(r.Liters||0).toFixed(2)}</td>
            <td style="text-align:right">${parseFloat(r.Price||0).toFixed(2)}</td>
            <td style="text-align:right">${parseFloat(r.Amount||0).toLocaleString('en',{minimumFractionDigits:2})}</td>
        </tr>`;
    });

    const html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Fuel Report</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: Arial, sans-serif; font-size: 12px; color: #000; background: #fff; padding: 24px; }
    .header { display:flex; align-items:center; gap:20px; margin-bottom:6px; }
    .header img { height:70px; }
    .header-text { text-align:right; flex:1; }
    .header-text .company { font-size:11px; font-weight:bold; }
    .header-text .report-title { font-size:16px; font-weight:bold; margin-top:2px; }
    .header-text .report-dept { font-size:13px; font-weight:bold; }
    .header-text .print-date { font-size:11px; color:#555; margin-top:2px; }
    .filter-line { font-size:11px; color:#444; margin-bottom:10px; padding: 4px 8px; background:#fffbe6; border:1px solid #e6c800; border-radius:4px; }
    hr { border:none; border-top:2px solid #000; margin:8px 0; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th { background:#f5c800; color:#000; font-size:11px; font-weight:bold; padding:6px 8px; text-align:left; border:1px solid #cca800; }
    td { padding:5px 8px; border:1px solid #ddd; font-size:11px; vertical-align:top; }
    tr:nth-child(even) td { background:#fffde7; }
    .totals-row td { font-weight:bold; background:#f5c800; border:1px solid #cca800; }
    .footer { margin-top:14px; display:flex; justify-content:space-between; font-size:11px; }
    .footer .record-count { font-weight:bold; }
    .footer .total-block { text-align:right; }
    .total-block div { margin-top:2px; }
    .total-label { display:inline-block; width:110px; }
    .total-val { display:inline-block; width:90px; text-align:right; font-weight:bold; font-size:13px; }
    @media print {
        body { padding:10px; }
        @page { margin:10mm; }
    }
</style>
</head>
<body>

<div class="header">
    <img src="${LOGO_B64}" alt="Logo">
    <div class="header-text">
        <div class="report-title">Fuel Report</div>
        <div class="report-dept">${dept || 'All Departments'}</div>
        <div class="print-date">Date Print: ${printDate}</div>
    </div>
</div>

<hr>



<table>
    <thead>
        <tr>
            <th>#</th>
            <th>DID</th>
            <th>Plate#</th>
            <th>PO#</th>
            <th>OR#</th>
            <th>Date</th>
            <th style="text-align:right">Liters</th>
            <th style="text-align:right">Price</th>
            <th style="text-align:right">Amount</th>
        </tr>
    </thead>
    <tbody>
        ${rows}
    </tbody>
</table>

<div class="footer">
    <div>
        <div class="record-count">Record Count: &nbsp; ${recs.length}</div>
        <div style="margin-top:6px;font-size:11px">
            <span style="font-weight:bold">Supplier:</span> ${supplierSummary}
        </div>
    </div>
    <div class="total-block">
        <div><span class="total-label">Total Liters:</span><span class="total-val">${totLiters.toLocaleString('en',{minimumFractionDigits:2})}</span></div>
        <div><span class="total-label">Total Amount:</span><span class="total-val">${totAmount.toLocaleString('en',{minimumFractionDigits:2})}</span></div>
    </div>
</div>

</body>
</html>`;

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => win.print(), 600);
}


// ── Universal paginator ───────────────────────────────────
function _paginate(containerId, totalPages, currentPage, onGo) {
    if (totalPages <= 1) { document.getElementById(containerId).innerHTML = ''; return; }
    let html = '';
    if (currentPage > 0) html += `<button class="pg-btn" onclick="(${onGo.toString()})(${currentPage-1})">‹</button>`;
    const start = Math.max(0, currentPage - 2);
    const end   = Math.min(totalPages - 1, currentPage + 2);
    if (start > 0) { html += `<button class="pg-btn" onclick="(${onGo.toString()})(0)">1</button>`; if (start > 1) html += `<span class="pg-info">…</span>`; }
    for (let p = start; p <= end; p++) {
        html += `<button class="pg-btn ${p===currentPage?'active':''}" onclick="(${onGo.toString()})(${p})">${p+1}</button>`;
    }
    if (end < totalPages - 1) { if (end < totalPages - 2) html += `<span class="pg-info">…</span>`; html += `<button class="pg-btn" onclick="(${onGo.toString()})(${totalPages-1})">${totalPages}</button>`; }
    if (currentPage < totalPages - 1) html += `<button class="pg-btn" onclick="(${onGo.toString()})(${currentPage+1})">›</button>`;
    document.getElementById(containerId).innerHTML = html;
}

// ╔══════════════════════════════════════════════════════════╗
// ║               ADD FUEL MODAL                            ║
// ╚══════════════════════════════════════════════════════════╝
let plateVehicles = [], supplierList = [];

function openAddModal() {
    if (!USER_PERMS.perm_add_fuel) {
        alert('⛔ You do not have permission to add fuel records.');
        return;
    }
    document.getElementById('addModal').classList.add('show');
    document.getElementById('modal-alert').innerHTML = '';

    // Rebuild dept options to only show departments this user is allowed to see
    const deptSel = document.getElementById('add-dept');
    if (deptSel) {
        deptSel.innerHTML = '<option value="">Select...</option>';
        VISIBLE_DEPTS.forEach(d => {
            const opt = document.createElement('option');
            opt.value = opt.textContent = d;
            deptSel.appendChild(opt);
        });
        // Pre-fill with user's assigned dept
        deptSel.value = USER_DEPT && VISIBLE_DEPTS.includes(USER_DEPT) ? USER_DEPT : '';
        if (deptSel.value) loadVehiclesForDept();
    }
    document.getElementById('plate-locked').textContent = deptSel.value ? 'Loading vehicles…' : 'Select a department first';
    document.getElementById('plate-locked').style.display = deptSel.value ? 'none' : '';
    document.getElementById('add-plate-select').style.display = 'none';
    document.getElementById('add-plate-select').disabled = true;
    document.getElementById('plate-input-wrap').style.display = 'none';
    document.getElementById('plate-mode-toggle').style.display = 'none';
    document.getElementById('plate-custom-toggle').checked = false;
    document.getElementById('add-plate-text').value = '';
    document.getElementById('plate-suggestions').innerHTML = '';
    document.getElementById('add-time').value = '';
    document.getElementById('add-supplier').value = '';
    document.getElementById('supplier-suggestions').innerHTML = '';
    // Reset price field — default to TRADEWELL-locked state on fresh open
    // (no supplier chosen yet; once the user picks one, applySupplierPriceLogic fires)
    applySupplierPriceLogic('TRADEWELL');
    plateVehicles = [];
    if (!supplierList.length) loadSuppliers();
    document.getElementById('add-requested').value      = '';
    document.getElementById('requested-suggestions').innerHTML = '';
    document.getElementById('requested-hint').textContent = '';
    document.getElementById('add-po').value = '';
    document.getElementById('add-or').value = '';
    document.getElementById('add-po-hint').textContent = '';
    document.getElementById('add-or-hint').textContent = '';
    poOrDuplicateState.add = { po: false, or: false };
}

function closeModal(id) { document.getElementById(id).classList.remove('show'); }

async function loadSuppliers() {
    const res  = await fetch(`${API}?action=suppliers`);
    const raw  = await res.json();
    // Filter out restricted tank suppliers the user has no access to.
    // USER_TANK_SUPPLIERS is injected server-side; RESTRICTED_TANK_SUPPLIERS = ['TRADEWELL','TRADEWELL GUMACA'].
    supplierList = raw.filter(s => {
        const name = (s.AccountName || '').trim().toUpperCase();
        if (RESTRICTED_TANK_SUPPLIERS.includes(name)) {
            return IS_SUPERADMIN || USER_TANK_SUPPLIERS.map(x => x.toUpperCase()).includes(name);
        }
        return true; // non-restricted suppliers are always visible
    });
}

function onSupplierInput(input) {
    const val = (typeof input === 'string' ? input : input.value).trim().toUpperCase();
    if (typeof input !== 'string') input.value = input.value.toUpperCase();
    const box = document.getElementById('supplier-suggestions');
    const matches = val ? supplierList.filter(s => s.AccountName.toUpperCase().includes(val)) : supplierList;
    if (!matches.length) { box.innerHTML = ''; return; }
    box.innerHTML = matches.map(s =>
        `<div class="plate-suggestion-item" onclick="pickSupplier('${s.AccountName.replace(/'/g,"\\'")}')">
            <strong>${s.AccountName}</strong>${s.SupplierCode?`<span style="color:var(--muted);font-size:11px"> · ${s.SupplierCode}</span>`:''}
         </div>`
    ).join('');
}

function pickSupplier(name) {
    document.getElementById('add-supplier').value = name;
    document.getElementById('supplier-suggestions').innerHTML = '';
    applySupplierPriceLogic(name);
    onPoOrInputChanged();
    // Move focus to price field only if it's editable (non-TRADEWELL supplier)
    const key = name.trim().toUpperCase();
    if (key !== 'TRADEWELL' && key !== 'TRADEWELL GUMACA') {
        document.getElementById('add-price').focus();
    }
}

/**
 * Locks or unlocks the Price Per Liter field depending on the selected supplier.
 * TRADEWELL and TRADEWELL GUMACA: auto-fill from cache, field locked.
 * All other suppliers: manual entry, field editable.
 */
function applySupplierPriceLogic(name) {
    const key        = (name || '').trim().toUpperCase();
    const isAutoPrice = (key === 'TRADEWELL' || key === 'TRADEWELL GUMACA');
    const priceInput  = document.getElementById('add-price');
    const priceBadge  = document.getElementById('add-price-badge');

    if (isAutoPrice) {
        const autoPrice = getPriceFor(key);
        const label     = key === 'TRADEWELL GUMACA' ? '\uD83D\uDD12 Auto (Gumaca)' : '\uD83D\uDD12 Auto (Tradewell)';
        const badgeBg   = key === 'TRADEWELL GUMACA' ? '#3b82f6' : '#f0a500';
        const badgeFg   = '#000';

        priceInput.value               = autoPrice.toFixed(2);
        priceInput.readOnly            = true;
        priceInput.tabIndex            = -1;
        priceInput.style.background    = 'var(--bg-alt,#e6edf3)';
        priceInput.style.fontWeight    = '700';
        priceInput.style.color         = 'var(--accent)';
        priceInput.style.cursor        = 'not-allowed';
        priceInput.style.pointerEvents = 'none';
        priceInput.style.opacity       = '0.8';
        priceInput.style.border        = '1.5px solid ' + badgeBg;
        if (priceBadge) {
            priceBadge.style.display    = 'inline';
            priceBadge.textContent      = label;
            priceBadge.style.background = badgeBg;
            priceBadge.style.color      = badgeFg;
        }
    } else {
        priceInput.value               = '';
        priceInput.readOnly            = false;
        priceInput.tabIndex            = 0;
        priceInput.style.background    = '';
        priceInput.style.fontWeight    = '';
        priceInput.style.color         = '';
        priceInput.style.cursor        = '';
        priceInput.style.pointerEvents = '';
        priceInput.style.opacity       = '';
        priceInput.style.border        = '1.5px solid var(--accent,#58a6ff)';
        if (priceBadge) {
            priceBadge.style.display    = 'inline';
            priceBadge.textContent      = '\u270F\uFE0F Manual Entry';
            priceBadge.style.background = 'var(--bg-alt,#30363d)';
            priceBadge.style.color      = 'var(--fg,#c9d1d9)';
        }
        // Do NOT call priceInput.focus() here — it steals focus from the supplier field mid-typing
    }
    calcAmount();
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#add-supplier') && !e.target.closest('#supplier-suggestions')) {
        const box = document.getElementById('supplier-suggestions');
        if (box) box.innerHTML = '';
    }
    if (!e.target.closest('#add-requested') && !e.target.closest('#requested-suggestions')) {
        const box = document.getElementById('requested-suggestions');
        if (box) box.innerHTML = '';
    }
    if (!e.target.closest('#fr-supplier') && !e.target.closest('#fr-supplier-suggestions')) {
        const box = document.getElementById('fr-supplier-suggestions');
        if (box) box.innerHTML = '';
    }
});

// ── Filter-bar supplier autocomplete ──
function onFRSupplierInput(input) {
    if (!supplierList.length) loadSuppliers();
    const val = (typeof input === 'string' ? input : input.value).trim().toUpperCase();
    const box = document.getElementById('fr-supplier-suggestions');
    const matches = val
        ? supplierList.filter(s => s.AccountName.toUpperCase().includes(val))
        : supplierList;
    if (!matches.length) { box.innerHTML = ''; return; }
    box.innerHTML = matches.map(s =>
        `<div class="plate-suggestion-item" onclick="pickFRSupplier('${s.AccountName.replace(/'/g,"\\'")}')">
            <strong>${s.AccountName}</strong>${s.SupplierCode?`<span style="color:var(--muted);font-size:11px"> · ${s.SupplierCode}</span>`:''}
         </div>`
    ).join('');
}

function pickFRSupplier(name) {
    document.getElementById('fr-supplier').value = name;
    document.getElementById('fr-supplier-suggestions').innerHTML = '';
}

// ── Employee / Requested By autocomplete ─────────────────
let requestedSearchTimer = null;

async function onRequestedInput(input) {
    const val  = input.value.trim();
    const box  = document.getElementById('requested-suggestions');
    const hint = document.getElementById('requested-hint');
    const dept = document.getElementById('add-dept').value;

    if (!val) { box.innerHTML = ''; return; }

    hint.textContent = dept
        ? `Searching employees in: ${dept}`
        : 'Searching all departments';

    clearTimeout(requestedSearchTimer);
    requestedSearchTimer = setTimeout(async () => {
        const params = new URLSearchParams({ action: 'employees', search: val });
        if (dept) params.set('department', dept);

        const res   = await fetch(`${API}?${params}`);
        const list  = await res.json();

        if (!list.length) {
            box.innerHTML = `<div class="plate-suggestion-item" style="color:var(--muted);cursor:default">No employees found</div>`;
            return;
        }

        box.innerHTML = list.map(emp => {
            const display  = `${emp.FirstName}, ${emp.LastName}`;
            const sub      = [emp.Department, emp.Position_held].filter(Boolean).join(' · ');
            const safeName = display.replace(/'/g, "\\'");
            return `<div class="plate-suggestion-item" onclick="pickRequested('${safeName}')">
                        <strong>${display}</strong>
                        ${sub ? `<span style="color:var(--muted);font-size:11px"> · ${sub}</span>` : ''}
                    </div>`;
        }).join('');
    }, 250);
}

function pickRequested(name) {
    document.getElementById('add-requested').value = name;
    document.getElementById('requested-suggestions').innerHTML = '';
    document.getElementById('requested-hint').textContent = '';
}

async function loadVehiclesForDept() {
    const dept    = document.getElementById('add-dept').value;
    const locked  = document.getElementById('plate-locked');
    const selWrap = document.getElementById('add-plate-select');
    const inpWrap = document.getElementById('plate-input-wrap');
    const toggle  = document.getElementById('plate-mode-toggle');
    const cb      = document.getElementById('plate-custom-toggle');

    cb.checked = false;
    document.getElementById('add-plate-text').value = '';
    document.getElementById('plate-suggestions').innerHTML = '';

    const reqVal  = document.getElementById('add-requested').value.trim();
    const reqHint = document.getElementById('requested-hint');
    if (reqVal && reqHint) {
        reqHint.textContent = dept
            ? `Searching employees in: ${dept}`
            : 'Searching all departments';
    }

    if (!dept) {
        locked.textContent   = 'Select a department first';
        locked.style.display  = '';
        selWrap.style.display = 'none';
        inpWrap.style.display = 'none';
        toggle.style.display  = 'none';
        selWrap.disabled = true;
        plateVehicles = [];
        return;
    }

    locked.textContent = 'Loading plates…';
    locked.style.display = '';
    selWrap.style.display = 'none';
    inpWrap.style.display = 'none';
    toggle.style.display  = 'none';

    const res = await fetch(`${API}?action=vehicles&department=${dept}`);
    plateVehicles = await res.json();

    const sel = document.getElementById('add-plate-select');
    sel.innerHTML = '<option value="">Select plate...</option>';
    plateVehicles.forEach(v => {
        sel.innerHTML += `<option value="${v.PlateNumber}">${v.PlateNumber}${v.Brand?' — '+v.Brand+' '+(v.Model||''):''}</option>`;
    });

    locked.style.display  = 'none';
    selWrap.style.display = '';
    selWrap.disabled = false;
    toggle.style.display  = '';
}

function togglePlateMode() {
    const isCustom = document.getElementById('plate-custom-toggle').checked;
    const selWrap  = document.getElementById('add-plate-select');
    const inpWrap  = document.getElementById('plate-input-wrap');
    if (isCustom) {
        selWrap.style.display = 'none';
        inpWrap.style.display = '';
        document.getElementById('add-plate-text').focus();
    } else {
        selWrap.style.display = '';
        inpWrap.style.display = 'none';
        document.getElementById('add-plate-text').value = '';
        document.getElementById('plate-suggestions').innerHTML = '';
    }
}

function onPlateSelectChange() { /* value read at submit */ }

function onPlateTextInput(input) {
    const cursorPos = input.selectionStart;
    input.value = input.value.toUpperCase();
    input.setSelectionRange(cursorPos, cursorPos);

    const val = input.value.trim();
    const box = document.getElementById('plate-suggestions');
    if (!val) { box.innerHTML = ''; return; }
    const matches = plateVehicles.filter(v => v.PlateNumber.toUpperCase().includes(val)).slice(0,8);
    if (!matches.length) { box.innerHTML = ''; return; }
    box.innerHTML = matches.map(v =>
        `<div class="plate-suggestion-item" onclick="pickSuggestion('${v.PlateNumber}')">
            <strong>${v.PlateNumber}</strong>${v.Brand?`<span> — ${v.Brand} ${v.Model||''}</span>`:''}
         </div>`
    ).join('');
}

function pickSuggestion(plate) {
    document.getElementById('add-plate-text').value = plate;
    document.getElementById('plate-suggestions').innerHTML = '';
}

function getPlateValue() {
    return document.getElementById('plate-custom-toggle').checked
        ? document.getElementById('add-plate-text').value.trim()
        : document.getElementById('add-plate-select').value;
}

function calcAmount() {
    const l = parseFloat(document.getElementById('add-liters').value)||0;
    const p = parseFloat(document.getElementById('add-price').value)||0;
    document.getElementById('add-amount').value = '₱ '+(l*p).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});
}

// ── PO # / OR # uniqueness check (live, while typing) ─────
// Tracks whether the currently-typed PO/OR for the Add modal and the
// Edit modal are duplicates of another record, so Save can be blocked
// client-side before even hitting the server (server still re-checks).
let poOrDuplicateState = {
    add: { po: false, or: false },
    edit: { po: false, or: false }
};
let _poOrCheckTimer = null;

async function _checkPoOrDuplicate(po, or, excludeFuelId, supplier) {
    const params = new URLSearchParams({ action: 'check_po_or', po: po || '', or: or || '', supplier: supplier || '' });
    if (excludeFuelId) params.set('exclude_fuel_id', excludeFuelId);
    const res = await fetch(`${API}?${params}`);
    return res.json();
}

function onPoOrInputChanged() {
    clearTimeout(_poOrCheckTimer);
    _poOrCheckTimer = setTimeout(async () => {
        const po       = document.getElementById('add-po').value.trim();
        const or       = document.getElementById('add-or').value.trim();
        const supplier = document.getElementById('add-supplier').value.trim();
        const poHint   = document.getElementById('add-po-hint');
        const orHint   = document.getElementById('add-or-hint');

        if (!po) { poHint.textContent = ''; poOrDuplicateState.add.po = false; }
        if (!or) { orHint.textContent = ''; poOrDuplicateState.add.or = false; }
        if (!po && !or) return;

        if (!supplier) {
            if (po) { poHint.style.color = 'var(--muted)'; poHint.textContent = 'Select a supplier to check for duplicates'; }
            if (or) { orHint.style.color = 'var(--muted)'; orHint.textContent = 'Select a supplier to check for duplicates'; }
            poOrDuplicateState.add.po = false;
            poOrDuplicateState.add.or = false;
            return;
        }

        const result = await _checkPoOrDuplicate(po, or, null, supplier);

        if (po) {
            poOrDuplicateState.add.po = !!result.po_duplicate;
            poHint.style.color = result.po_duplicate ? 'var(--red, #f85149)' : 'var(--green, #3fb950)';
            poHint.textContent = result.po_duplicate
                ? `⚠️ Already used on record #${result.po_fuelid} for ${supplier}`
                : '✓ Available';
        }
        if (or) {
            poOrDuplicateState.add.or = !!result.or_duplicate;
            orHint.style.color = result.or_duplicate ? 'var(--red, #f85149)' : 'var(--green, #3fb950)';
            orHint.textContent = result.or_duplicate
                ? `⚠️ Already used on record #${result.or_fuelid} for ${supplier}`
                : '✓ Available';
        }
    }, 400);
}

function onEfPoOrInputChanged() {
    clearTimeout(_poOrCheckTimer);
    _poOrCheckTimer = setTimeout(async () => {
        const fuelId   = document.getElementById('ef-fuel-id').value;
        const po       = document.getElementById('ef-po').value.trim();
        const or       = document.getElementById('ef-or').value.trim();
        const supplier = document.getElementById('ef-supplier').value.trim();
        const poHint   = document.getElementById('ef-po-hint');
        const orHint   = document.getElementById('ef-or-hint');

        if (!po) { poHint.textContent = ''; poOrDuplicateState.edit.po = false; }
        if (!or) { orHint.textContent = ''; poOrDuplicateState.edit.or = false; }
        if (!po && !or) return;

        if (!supplier) {
            if (po) { poHint.style.color = 'var(--muted)'; poHint.textContent = 'Select a supplier to check for duplicates'; }
            if (or) { orHint.style.color = 'var(--muted)'; orHint.textContent = 'Select a supplier to check for duplicates'; }
            poOrDuplicateState.edit.po = false;
            poOrDuplicateState.edit.or = false;
            return;
        }

        const result = await _checkPoOrDuplicate(po, or, fuelId, supplier);

        if (po) {
            poOrDuplicateState.edit.po = !!result.po_duplicate;
            poHint.style.color = result.po_duplicate ? 'var(--red, #f85149)' : 'var(--green, #3fb950)';
            poHint.textContent = result.po_duplicate
                ? `⚠️ Already used on record #${result.po_fuelid} for ${supplier}`
                : '✓ Available';
        }
        if (or) {
            poOrDuplicateState.edit.or = !!result.or_duplicate;
            orHint.style.color = result.or_duplicate ? 'var(--red, #f85149)' : 'var(--green, #3fb950)';
            orHint.textContent = result.or_duplicate
                ? `⚠️ Already used on record #${result.or_fuelid} for ${supplier}`
                : '✓ Available';
        }
    }, 400);
}

async function submitFuelRecord() {
    const dept   = document.getElementById('add-dept').value;
    const plate  = getPlateValue();
    const date   = document.getElementById('add-date').value;
    const liters = document.getElementById('add-liters').value;
    const price  = document.getElementById('add-price').value;

    if (!dept || !plate || !date || !liters || !price) {
        document.getElementById('modal-alert').innerHTML = '<div class="alert alert-error">Please fill in all required fields.</div>';
        return;
    }
    if (poOrDuplicateState.add.po || poOrDuplicateState.add.or) {
        document.getElementById('modal-alert').innerHTML = '<div class="alert alert-error">Please resolve the duplicate PO #/OR # before saving.</div>';
        return;
    }

    const fd = new FormData();
    fd.append('action',     'add_fuel');
    fd.append('Department', dept);
    fd.append('PlateNumber', plate);
    fd.append('Fueldate',   date);
    fd.append('Liters',     liters);
    fd.append('Price',      price);
    fd.append('Area',       document.getElementById('add-area').value);
    fd.append('FuelTime',   document.getElementById('add-time').value);
    fd.append('POnum',      document.getElementById('add-po').value);
    fd.append('ORnumber',   document.getElementById('add-or').value);
    fd.append('Requested',  document.getElementById('add-requested').value);
    fd.append('Supplier',   document.getElementById('add-supplier').value);

    const res  = await fetch(API, {method:'POST', body:fd});
    const data = await res.json();

    if (data.success) {
        document.getElementById('modal-alert').innerHTML = '<div class="alert alert-success">✓ Fuel record saved successfully!</div>';
        setTimeout(() => {
            closeModal('addModal');
            if (window.liveUpdater) window.liveUpdater.markDirty();
            if (document.getElementById('page-dashboard').classList.contains('active')) loadDashboard();
            if (document.getElementById('page-fuel-records').classList.contains('active')) loadFuelRecords(frCurrentPage);
        }, 1200);
    } else {
        document.getElementById('modal-alert').innerHTML = `<div class="alert alert-error">Error: ${data.message}</div>`;
    }
}

// ── Sidebar (mobile) ──────────────────────────────────────
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    const o = document.getElementById('sidebarOverlay');
    const h = document.getElementById('hamburger');
    if (s.classList.contains('open')) { s.classList.remove('open'); o.classList.remove('show'); h.classList.remove('open'); }
    else { s.classList.add('open'); o.classList.add('show'); h.classList.add('open'); }
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
    document.getElementById('hamburger').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key==='Escape') closeSidebar(); });

// ╔══════════════════════════════════════════════════════════╗
// ║               DRIVER GAS CARD                           ║
// ╚══════════════════════════════════════════════════════════╝
let gcAllData = null;

async function loadGasCard(page = 1) {
    const wrap = document.getElementById('gc-table-wrap');
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading vehicles…</div>';
    document.getElementById('gc-pagination').innerHTML = '';
    document.getElementById('gc-page-info').textContent = '';

    const dept  = activeDept;
    const plate = document.getElementById('gc-plate').value.trim();

    const params = new URLSearchParams({
        action:   'gas_card',
        page:     page,
        pageSize: 20,
    });
    if (dept)  params.set('department', dept);
    if (plate) params.set('plate', plate);

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();
    gcAllData  = data;

    renderGCTable(data);
}

function renderGCTable(data) {
    const { records, total, page, pageSize, totalPages } = data;

    document.getElementById('gc-count').textContent = Number(total || 0).toLocaleString('en');

    const wrap = document.getElementById('gc-table-wrap');

    if (!records.length) {
        wrap.innerHTML = '<div class="loading">No active vehicles found.</div>';
        document.getElementById('gc-page-info').textContent = '';
        document.getElementById('gc-pagination').innerHTML  = '';
        return;
    }

    const start = (page - 1) * pageSize;

    let html = `<table>
        <thead><tr>
            <th>#</th>
            <th>Plate Number</th>
            <th>Department</th>
            <th>Vehicle Type</th>
            <th>Brand</th>
            <th>Model</th>
            <th>Fuel Type</th>
        </tr></thead><tbody>`;

    records.forEach((v, i) => {
        const rowNum    = start + i + 1;
        const fuelBadge = v.FuelType
            ? `<span style="background:${v.FuelType==='Diesel'?'#1e40af':'#166534'};color:#fff;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600">${v.FuelType}</span>`
            : '—';
        html += `<tr>
            <td style="color:var(--muted);font-size:12px">${rowNum}</td>
            <td>
                <strong style="font-family:'Barlow Condensed',sans-serif;font-size:14px;letter-spacing:.5px;cursor:pointer;color:var(--accent)"
                    onclick="onGasCardPlateClick('${v.PlateNumber}','${v.Department||''}')">
                    ${v.PlateNumber}
                </strong>
            </td>
            <td><span class="badge dept-${deptClass(v.Department)}">${v.Department||'—'}</span></td>
            <td style="font-size:13px">${v.Vehicletype||'—'}</td>
            <td style="font-size:13px">${v.Brand||'—'}${v.Year?' <span style="color:var(--muted);font-size:11px">('+v.Year+')</span>':''}</td>
            <td style="font-size:13px">${v.Model||'—'}</td>
            <td>${fuelBadge}</td>
        </tr>`;
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;

    const from = start + 1;
    const to   = Math.min(start + pageSize, total);
    document.getElementById('gc-page-info').textContent = `Showing ${from}–${to} of ${Number(total).toLocaleString('en')} vehicles`;

    _paginate('gc-pagination', totalPages, page - 1, p => loadGasCard(p + 1));
}

function clearGCFilters() {
    document.getElementById('gc-plate').value = '';
    loadGasCard();
}

// ╔══════════════════════════════════════════════════════════╗
// ║            PLATE CALENDAR VIEW                          ║
// ╚══════════════════════════════════════════════════════════╝
let pcmCurrentPlate = '', pcmCurrentDept = '';
const MONTH_NAMES = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

function onGasCardPlateClick(plate, dept) {
    pcmCurrentPlate = plate;
    pcmCurrentDept  = dept || '';

    const yearSel  = document.getElementById('pcm-year');
    const thisYear = new Date().getFullYear();
    yearSel.innerHTML = '';
    for (let y = thisYear; y >= thisYear - 4; y--) {
        yearSel.innerHTML += `<option value="${y}" ${y===thisYear?'selected':''}>${y}</option>`;
    }

    document.getElementById('pcm-plate-title').textContent = plate;
    document.getElementById('pcm-dept-badge').innerHTML    = dept
        ? `<span class="badge dept-${deptClass(dept)}">${dept}</span>` : '';

    document.getElementById('plateCalModal').classList.add('show');
    loadPlateCalendar();
}

async function loadPlateCalendar() {
    document.getElementById('pcm-body').innerHTML =
        '<div class="loading"><span class="spinner"></span>Loading…</div>';

    const year   = document.getElementById('pcm-year').value;
    const params = new URLSearchParams({
        action: 'plate_calendar',
        plate:  pcmCurrentPlate,
        year:   year,
    });

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();

    const totLiters  = data.reduce((s, m) => s + parseFloat(m.TotalLiters  || 0), 0);
    const totAmount  = data.reduce((s, m) => s + parseFloat(m.TotalAmount  || 0), 0);
    const totRefills = data.reduce((s, m) => s + parseInt(m.TotalRefills   || 0), 0);

    let html = `
    <div style="display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap">
        <div style="flex:1;min-width:120px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center">
            <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)">Year Total Liters</div>
            <div style="font-size:22px;font-weight:800;color:var(--blue);font-family:'Barlow Condensed',sans-serif;margin-top:4px">${totLiters.toLocaleString('en',{minimumFractionDigits:2})} L</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center">
            <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)">Year Total Amount</div>
            <div style="font-size:22px;font-weight:800;color:var(--accent);font-family:'Barlow Condensed',sans-serif;margin-top:4px">₱${totAmount.toLocaleString('en',{minimumFractionDigits:2})}</div>
        </div>
        <div style="flex:1;min-width:120px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px 16px;text-align:center">
            <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)">Total Refills</div>
            <div style="font-size:22px;font-weight:800;color:var(--text);font-family:'Barlow Condensed',sans-serif;margin-top:4px">${totRefills.toLocaleString('en')}</div>
        </div>
    </div>
    <div class="pcm-grid">`;

    data.forEach((m, idx) => {
        const liters  = parseFloat(m.TotalLiters  || 0);
        const amount  = parseFloat(m.TotalAmount  || 0);
        const refills = parseInt(m.TotalRefills   || 0);
        const hasData = refills > 0;

        html += `
        <div class="pcm-card ${hasData ? 'pcm-card--active' : 'pcm-card--empty'}"
             onclick="onPlateMonthClick('${pcmCurrentPlate}', ${document.getElementById('pcm-year').value}, ${idx+1})">
            <div class="pcm-card-month">${MONTH_NAMES[idx]}</div>
            <div class="pcm-card-liters">${liters.toLocaleString('en',{minimumFractionDigits:2})} L</div>
            <div class="pcm-card-amount">₱${amount.toLocaleString('en',{minimumFractionDigits:2})}</div>
            <div class="pcm-card-refills">${refills} refill${refills!==1?'s':''}</div>
        </div>`;
    });

    html += '</div>';
    document.getElementById('pcm-body').innerHTML = html;
}

// ╔══════════════════════════════════════════════════════════╗
// ║          PLATE MONTH DETAIL VIEW                        ║
// ╚══════════════════════════════════════════════════════════╝
const MONTH_FULL = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];

function onPlateMonthClick(plate, year, month) {
    document.getElementById('plateCalModal').classList.remove('show');
    document.getElementById('plateMonthModal').classList.add('show');

    const monthName = MONTH_FULL[month - 1];
    document.getElementById('pmm-title').textContent = `${plate} — ${monthName} ${year}`;
    document.getElementById('pmm-refill-badge').textContent = '';
    document.getElementById('pmm-body').innerHTML =
        '<div class="loading"><span class="spinner"></span>Loading…</div>';

    loadPlateMonthDetail(plate, year, month);
}

function closePlateMonthModal() {
    document.getElementById('plateMonthModal').classList.remove('show');
    document.getElementById('plateCalModal').classList.add('show');
}

async function loadPlateMonthDetail(plate, year, month) {
    const params = new URLSearchParams({
        action: 'plate_month_detail',
        plate,
        year,
        month,
    });

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();

    const { days, refillCount, totalDays } = data;

    document.getElementById('pmm-refill-badge').textContent =
        `${refillCount} of ${totalDays} days refilled`;

    let html = `
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="border-bottom:2px solid var(--border)">
                <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);white-space:nowrap">Date</th>
                <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Time</th>
                <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Area</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Liters</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Price</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Amount</th>
                <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Supplier</th>
                <th style="padding:8px 10px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Requested By</th>
            </tr>
        </thead>
        <tbody>`;

    days.forEach(d => {
        const hasRefill = d.Liters !== null && d.Liters !== undefined;

        if (hasRefill) {
            const liters  = parseFloat(d.Liters  || 0).toFixed(2);
            const price   = '₱' + parseFloat(d.Price  || 0).toFixed(2);
            const amount  = '₱' + parseFloat(d.Amount || 0).toLocaleString('en', {minimumFractionDigits:2});
            const time    = d.FuelTime ? d.FuelTime.substring(0,5) : '—';

            html += `<tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px 10px;white-space:nowrap;font-weight:600">${formatDate(d.FuelDate)}</td>
                <td style="padding:8px 10px;color:var(--muted);white-space:nowrap">${time}</td>
                <td style="padding:8px 10px;color:var(--muted)">${d.Area || '—'}</td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;color:var(--blue)">${liters}</td>
                <td style="padding:8px 10px;text-align:right;color:var(--muted)">${price}</td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;color:var(--accent)">${amount}</td>
                <td style="padding:8px 10px;font-size:12px">${d.Supplier || '—'}</td>
                <td style="padding:8px 10px;font-size:12px">${d.Requested || '—'}</td>
            </tr>`;
        } else {
            html += `<tr class="pmm-no-refill">
                <td style="padding:8px 10px;white-space:nowrap;font-weight:600">${formatDate(d.FuelDate)}</td>
                <td colspan="7" style="padding:8px 10px;font-size:12px;font-style:italic;opacity:.7">No refuel this day</td>
            </tr>`;
        }
    });

    html += `</tbody></table></div>`;
    document.getElementById('pmm-body').innerHTML = html;
}

async function exportGCCSV() {
    const dept  = activeDept;
    const plate = document.getElementById('gc-plate').value.trim();

    const params = new URLSearchParams({ action: 'gas_card', page: 1, pageSize: 9999 });
    if (dept)  params.set('department', dept);
    if (plate) params.set('plate', plate);

    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();
    const all  = data.records || [];

    if (!all.length) return;

    const cols  = ['PlateNumber','Department','Vehicletype','Brand','Model','FuelType','Year'];
    const heads = ['Plate Number','Department','Vehicle Type','Brand','Model','Fuel Type','Year'];
    let csv = heads.join(',') + '\n';
    all.forEach(v => {
        csv += cols.map(c => `"${(v[c]||'').toString().replace(/"/g,'""')}"`).join(',') + '\n';
    });
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'gas_card_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
}


// ╔══════════════════════════════════════════════════════════╗
// ║               FUEL TANK DASHBOARD                       ║
// ╚══════════════════════════════════════════════════════════╝
const TANK_MAX_BY_SUPPLIER = { 'TRADEWELL': 16000, 'TRADEWELL GUMACA': 12000 };
function getTankMax(supplier) {
    return TANK_MAX_BY_SUPPLIER[supplier] ?? 16000;
}

// Currently selected supplier tank — driven by the dropdown in page_tank.php
let activeTankSupplier = 'TRADEWELL';

// Filter tank selector to only show tanks the user has access to
function initTankSelector() {
    const sel = document.getElementById('tank-supplier-select');
    if (!sel) return;

    // Remove options the user doesn't have access to
    Array.from(sel.options).forEach(opt => {
        const isRestricted = RESTRICTED_TANK_SUPPLIERS.includes(opt.value);
        if (isRestricted && !USER_TANK_SUPPLIERS.includes(opt.value)) {
            opt.remove();
        }
    });

    // If only one option remains, hide the selector row entirely
    if (sel.options.length === 1) {
        sel.closest('.panel').style.display = 'none';
    }

    // Set default active tank to the first allowed option
    if (sel.options.length > 0) {
        activeTankSupplier = sel.options[0].value;
        sel.value = activeTankSupplier;
    }
    onTankSupplierChange();
}

function onTankSupplierChange() {
    const sel = document.getElementById('tank-supplier-select');
    if (!sel) return;
    activeTankSupplier = sel.value;

    // Update badge text + colour
    const badge = document.getElementById('tank-supplier-badge');
    if (badge) {
        badge.textContent = activeTankSupplier;
        if (activeTankSupplier === 'TRADEWELL GUMACA') {
            badge.style.background = 'rgba(59,130,246,.15)';
            badge.style.color      = '#60a5fa';
            badge.style.border     = '1px solid rgba(59,130,246,.3)';
        } else {
            badge.style.background = 'rgba(240,165,0,.15)';
            badge.style.color      = '#f0a500';
            badge.style.border     = '1px solid rgba(240,165,0,.3)';
        }
    }

    // Update sub-heading
    const sub = document.getElementById('tank-page-sub');
    if (sub) sub.textContent = activeTankSupplier + ' depot tank — ' + getTankMax(activeTankSupplier).toLocaleString('en') + ' L capacity';

    // Update refill log title
    const logTitle = document.getElementById('tank-refill-log-title');
    if (logTitle) logTitle.textContent = activeTankSupplier + ' — Tank Refill Log';

    // Update "Fuel Used" sub-label
    const usedSub = document.getElementById('tank-used-sub');
    if (usedSub) usedSub.textContent = 'Dispensed to trucks (' + activeTankSupplier + ')';

    // Refresh price badge for the newly selected supplier
    updateFuelPriceDisplay();
    loadTankDashboard();
}

async function loadTankDashboard() {
    loadTankStats();
    loadTankRefills(1);
}

async function loadTankStats() {
    const params = new URLSearchParams({ action: 'tank_stats', supplier: activeTankSupplier });
    const res  = await fetch(`${API}?${params}`);
    const data = await res.json();

    const added   = parseFloat(data.totalAdded   || 0);
    const used    = parseFloat(data.totalUsed    || 0);
    const current = parseFloat(data.currentFuel  || 0);
    const pct     = Math.min(100, parseFloat(data.percentage || 0));
    const avail   = parseFloat(data.availableCapacity ?? (getTankMax(activeTankSupplier) - current));

    document.getElementById('tank-added').textContent   = added.toLocaleString('en',{minimumFractionDigits:2}) + ' L';
    document.getElementById('tank-used').textContent    = used.toLocaleString('en',{minimumFractionDigits:2}) + ' L';
    document.getElementById('tank-current').textContent = current.toLocaleString('en',{minimumFractionDigits:2}) + ' L';
    document.getElementById('tank-available-capacity').textContent = avail.toLocaleString('en',{minimumFractionDigits:2}) + ' L';

    document.getElementById('tank-gauge-label').textContent =
        `${current.toLocaleString('en',{minimumFractionDigits:2})} / ${getTankMax(activeTankSupplier).toLocaleString('en')} L`;
    document.getElementById('tank-gauge-bar').style.width   = pct + '%';
    document.getElementById('tank-gauge-pct').textContent   = pct > 5 ? pct + '%' : '';

    const bar = document.getElementById('tank-gauge-bar');
    if (pct <= 15)      bar.style.background = 'linear-gradient(90deg,#dc2626,#ef4444)';
    else if (pct <= 35) bar.style.background = 'linear-gradient(90deg,#d97706,#f59e0b)';
    else                bar.style.background = 'linear-gradient(90deg,#16a34a,#22c55e)';

    document.getElementById('tank-low-warning').style.display = pct <= 15 ? '' : 'none';
}

async function loadTankRefills(page = 1) {
    const wrap = document.getElementById('tank-table-wrap');
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading…</div>';

    const params = new URLSearchParams({ action: 'tank_refills', page, pageSize: 20, supplier: activeTankSupplier });
    const res    = await fetch(`${API}?${params}`);
    const data   = await res.json();

    const { records, total, totalPages, pageSize } = data;

    if (!records.length) {
        wrap.innerHTML = '<div style="padding:40px;text-align:center;color:var(--muted)">No refill records yet.</div>';
        document.getElementById('tank-page-info').textContent = '';
        document.getElementById('tank-pagination').innerHTML  = '';
        return;
    }

    const start = (page - 1) * pageSize;
    const from  = start + 1;
    const to    = Math.min(start + pageSize, total);
    document.getElementById('tank-page-info').textContent =
        `Showing ${from}–${to} of ${total.toLocaleString('en')} records`;

    let html = `<table>
        <thead><tr>
            <th>#</th>
            <th>Date</th>
            <th>Time of Arrival</th>
            <th style="text-align:right">Liters Added</th>
            <th>Attendant</th>
            <th>Supplier</th>
            <th>Notes</th>
            <th>Receipt</th>
            <th>Input Date</th>
        </tr></thead><tbody>`;

    records.forEach((r, i) => {
        const fmtDate = r.RefillDate ? formatDate(r.RefillDate) : '—';
        const receiptCell = r.ReceiptPath
            ? `<a href="${r.ReceiptPath}" target="_blank" rel="noopener" title="View / download receipt"
                   style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:var(--surface2,#1f2937);text-decoration:none">🧾</a>`
            : '<span style="color:var(--muted)">—</span>';
        html += `<tr>
            <td style="color:var(--muted);font-size:12px">${start + i + 1}</td>
            <td style="white-space:nowrap;font-weight:600">${fmtDate}</td>
            <td style="color:var(--muted)">${r.ArrivalTime || '—'}</td>
            <td style="text-align:right;font-weight:700;color:#22c55e">${parseFloat(r.LitersAdded||0).toLocaleString('en',{minimumFractionDigits:2})} L</td>
            <td style="font-size:13px">${r.Attendant || '—'}</td>
            <td style="font-size:13px">${r.Supplier  || '—'}</td>
            <td style="font-size:12px;color:var(--muted)">${r.Notes || '—'}</td>
            <td style="text-align:center">${receiptCell}</td>
            <td style="font-size:12px;color:var(--muted);white-space:nowrap">${r.InputDate || '—'}</td>
        </tr>`;
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
    _paginate('tank-pagination', totalPages, page - 1, p => loadTankRefills(p + 1));
}

// ── Tank Refill Modal ─────────────────────────────────────
function openTankRefillModal() {
    if (!USER_PERMS.perm_tank_fill) {
        alert('⛔ You do not have permission to add tank refills.');
        return;
    }
    document.getElementById('tankRefillModal').classList.add('show');
    document.getElementById('tank-modal-alert').innerHTML = '';
    document.getElementById('tr-date').value     = todayStr;
    document.getElementById('tr-time').value     = '';
    document.getElementById('tr-liters').value   = '';
    // Auto-fill supplier from the active tank selection and lock it
    document.getElementById('tr-supplier').value    = activeTankSupplier;
    document.getElementById('tr-supplier').readOnly = true;
    document.getElementById('tr-supplier').style.background    = 'var(--bg-alt,#e6edf3)';
    document.getElementById('tr-supplier').style.cursor        = 'not-allowed';
    document.getElementById('tr-supplier').style.fontWeight    = '700';
    document.getElementById('tr-supplier').style.color         = 'var(--accent)';
    // Update modal title to reflect active tank
    const modalTitle = document.querySelector('#tankRefillModal .modal-title');
    if (modalTitle) modalTitle.textContent = '⛽ Add Tank Refill — ' + activeTankSupplier;
    document.getElementById('tr-attendant').value= '';
    document.getElementById('tr-notes').value    = '';
    document.getElementById('tr-receipt').value  = '';
}

async function submitTankRefill() {
    const date    = document.getElementById('tr-date').value;
    const liters  = document.getElementById('tr-liters').value;
    const alertEl = document.getElementById('tank-modal-alert');

    if (!date || !liters) {
        alertEl.innerHTML = '<div class="alert alert-error">Date and Liters are required.</div>';
        return;
    }

    const fd = new FormData();
    fd.append('action',      'add_tank_refill');
    fd.append('RefillDate',  date);
    fd.append('ArrivalTime', document.getElementById('tr-time').value);
    fd.append('LitersAdded', liters);
    fd.append('Supplier',    activeTankSupplier);   // always use the active tank supplier
    fd.append('Attendant',   document.getElementById('tr-attendant').value);
    fd.append('Notes',       document.getElementById('tr-notes').value);
    const receiptFile = document.getElementById('tr-receipt').files[0];
    if (receiptFile) fd.append('receipt', receiptFile);

    const res  = await fetch(API, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
        alertEl.innerHTML = '<div class="alert alert-success">✓ Tank refill saved!</div>';
        setTimeout(() => {
            closeModal('tankRefillModal');
            if (window.liveUpdater) window.liveUpdater.markDirty();
            if (document.getElementById('page-tank').classList.contains('active')) {
                loadTankDashboard();
            }
        }, 900);
    } else {
        alertEl.innerHTML = `<div class="alert alert-error">Error: ${data.message}</div>`;
    }
}

// ╔══════════════════════════════════════════════════════════╗
// ║                   ADMINISTRATION                        ║
// ╚══════════════════════════════════════════════════════════╝

const ADM_PERMISSIONS = [
    { key: 'perm_dashboard',        label: 'Dashboard' },
    { key: 'perm_fuel_records',     label: 'Fuel Record' },
    { key: 'perm_driver_card',      label: 'Truck Gas Card' },
    { key: 'perm_fuel_tank',        label: 'Fuel Tank' },
    { key: 'perm_add_fuel',         label: 'Add Fuel' },
    { key: 'perm_edit_fuel',        label: 'Edit Fuel' },
    { key: 'perm_delete_fuel',      label: 'Delete Fuel' },
    { key: 'perm_tank_fill',        label: 'Tank Fill' },
    { key: 'perm_edit_fuel_price',  label: 'Edit Price' },
    // 'Administration' panel access is controlled by the superadmin role — not a toggle
];

// ALL_DEPT_LIST — alias for ALL_DEPTS (populated dynamically from DB)
const getAllDeptList = () => ALL_DEPTS;

let admAllUsers  = [];
let admFiltered  = [];
let admPage      = 1;
const ADM_PAGE_SIZE = 10;

async function loadAdminUsers() {
    const wrap = document.getElementById('adm-table-wrap');
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading users…</div>';
    document.getElementById('adm-pagination').innerHTML = '';
    document.getElementById('adm-page-info').textContent = '';

    try {
        const res  = await fetch(`${API}?action=admin_users`);
        const data = await res.json();
        admAllUsers = data.users || [];
        admFiltered = [...admAllUsers];
        renderAdminTable();
    } catch (e) {
        wrap.innerHTML = '<div class="loading">Failed to load users.</div>';
    }
}

function filterAdminUsers() {
    const q = (document.getElementById('adm-search').value || '').toLowerCase().trim();
    admFiltered = q
        ? admAllUsers.filter(u => (u.DisplayName || u.username || '').toLowerCase().includes(q))
        : [...admAllUsers];
    admPage = 1;
    // Show/hide clear button
    const clr = document.getElementById('adm-search-clear');
    if (clr) clr.style.display = q ? 'block' : 'none';
    renderAdminTable();
}

function clearAdmSearch() {
    document.getElementById('adm-search').value = '';
    const clr = document.getElementById('adm-search-clear');
    if (clr) clr.style.display = 'none';
    filterAdminUsers();
}

// DEPT_TAG_COLORS is built dynamically in loadDepartments()
let DEPT_TAG_COLORS = {};

// Helper to get tag color style for a dept name
function deptTagColor(name) {
    const c = DEPT_TAG_COLORS[name];
    if (c) return c;
    // Fallback: derive from DEPT_COLORS
    const base = DEPT_COLORS[name] || '#888';
    return { color: base, bg: 'rgba(128,128,128,.12)', border: 'rgba(128,128,128,.3)' };
}

function renderAdminTable() {
    const wrap  = document.getElementById('adm-table-wrap');
    const total = admFiltered.length;
    const pages = Math.max(1, Math.ceil(total / ADM_PAGE_SIZE));
    if (admPage > pages) admPage = pages;

    const start  = (admPage - 1) * ADM_PAGE_SIZE;
    const slice  = admFiltered.slice(start, start + ADM_PAGE_SIZE);

    document.getElementById('adm-count-label').textContent =
        `${total} user${total !== 1 ? 's' : ''}${admFiltered.length < admAllUsers.length ? ' (filtered)' : ''}`;

    if (!slice.length) {
        wrap.innerHTML = `<div class="adm-empty">
            <div class="adm-empty-icon">🔍</div>
            <div class="adm-empty-text">No users found matching your search.</div>
        </div>`;
        document.getElementById('adm-page-info').textContent = '';
        document.getElementById('adm-pagination').innerHTML = '';
        return;
    }

    // Update stats bar
    const statsBar = document.getElementById('adm-stats-bar');
    if (statsBar) {
        const superCount   = admAllUsers.filter(u => (u.user_type||'').toLowerCase() === 'superadmin').length;
        const regularCount = admAllUsers.length - superCount;
        statsBar.style.display = 'flex';
        document.getElementById('adm-stat-total').textContent   = admAllUsers.length;
        document.getElementById('adm-stat-super').textContent   = superCount;
        document.getElementById('adm-stat-regular').textContent = regularCount;
    }

    // Build user cards
    let cards = '';
    slice.forEach((u) => {
        const uid          = u.id;
        const isSuperAdmin = (u.user_type || '').toLowerCase() === 'superadmin';
        const allowedDepts = u.allowed_depts || [];
        const name         = escHtml(u.DisplayName || u.username || '?');
        const initials     = (u.DisplayName || u.username || '?').slice(0,2).toUpperCase();
        const username     = escHtml(u.username || '');

        // Department tags
        let deptsHtml = '';
        if (isSuperAdmin) {
            deptsHtml = `<div class="adm-all-depts-label">⭐ All Departments</div>`;
        } else {
            deptsHtml = getAllDeptList().map(d => {
                const c    = deptTagColor(d);
                const isOn = allowedDepts.includes(d);
                return `<label class="adm-dept-tag ${isOn ? 'on' : 'off'}"
                    style="border-color:${isOn ? c.border : 'var(--border)'}; background:${isOn ? c.bg : 'transparent'}"
                    title="${isOn ? 'Click to remove' : 'Click to add'} ${d}">
                    <input type="checkbox" ${isOn ? 'checked' : ''}
                           onchange="toggleUserDeptTag(${uid}, '${d}', this, this.closest('.adm-dept-tag'), '${c.bg}', '${c.border}')">
                    <span class="adm-dept-tag-dot" style="background:${c.color}"></span>
                    <span class="adm-dept-tag-text" style="color:${c.color}">${d}</span>
                </label>`;
            }).join('');
            const warn = allowedDepts.length === 0
                ? `<div class="adm-no-dept-warn">⚠ No dept assigned — sees all</div>`
                : '';
            deptsHtml = `<div class="adm-dept-tags">${deptsHtml}</div>${warn}`;
        }

        // Permission pills
        const permHtml = ADM_PERMISSIONS.map(p => {
            const isOn = isSuperAdmin || !!u[p.key];
            const cls  = isSuperAdmin ? 'locked' : (isOn ? 'on' : '');
            const change = isSuperAdmin
                ? ''
                : `onchange="savePillPerm(${uid}, '${p.key}', this)"`;
            return `<label class="adm-perm-pill ${cls}" title="${isSuperAdmin ? 'Superadmins always have full access' : ''}">
                <input type="checkbox" ${isOn ? 'checked' : ''} ${isSuperAdmin ? 'disabled' : ''} ${change}>
                <span class="adm-perm-pill-label">${p.label}</span>
                <span class="adm-perm-pill-switch"></span>
            </label>`;
        }).join('');

        cards += `
        <div class="adm-card ${isSuperAdmin ? 'is-superadmin' : ''}">
            <div class="adm-card-identity">
                <div class="adm-avatar">${initials}</div>
                <div class="adm-identity-info">
                    <div class="adm-display-name">${name}</div>
                    ${username ? `<div class="adm-username">@${username}</div>` : ''}
                </div>
                <span class="adm-role-badge ${isSuperAdmin ? 'superadmin' : 'regular'}">
                    ${isSuperAdmin ? '⭐ Superadmin' : 'User'}
                </span>
            </div>
            <div class="adm-card-body">
                <div class="adm-card-section">
                    <div class="adm-section-title">🏢 Department Access</div>
                    ${deptsHtml}
                </div>
                <div class="adm-card-section">
                    <div class="adm-section-title">🔐 Module Permissions</div>
                    <div class="adm-perm-grid">${permHtml}</div>
                </div>
            </div>
        </div>`;
    });

    wrap.innerHTML = `<div class="adm-cards">${cards}</div>`;

    // Page info
    document.getElementById('adm-page-info').textContent =
        `Showing ${start + 1}–${Math.min(start + ADM_PAGE_SIZE, total)} of ${total}`;

    // Pagination
    const pgEl = document.getElementById('adm-pagination');
    pgEl.innerHTML = '';
    if (pages <= 1) return;

    const mkBtn = (label, page, active = false, disabled = false) => {
        const btn = document.createElement('button');
        btn.className = 'pg-btn' + (active ? ' active' : '');
        btn.textContent = label;
        btn.disabled = disabled;
        btn.onclick = () => { admPage = page; renderAdminTable(); };
        return btn;
    };

    pgEl.appendChild(mkBtn('‹', admPage - 1, false, admPage === 1));
    const range = pagRange(admPage, pages);
    range.forEach(p => {
        if (p === '…') {
            const sp = document.createElement('span');
            sp.className = 'pg-info';
            sp.textContent = '…';
            pgEl.appendChild(sp);
        } else {
            pgEl.appendChild(mkBtn(p, p, p === admPage));
        }
    });
    pgEl.appendChild(mkBtn('›', admPage + 1, false, admPage === pages));
}

// Pill perm toggle — updates visual state immediately, then saves
function savePillPerm(userId, permKey, checkbox) {
    const pill = checkbox.closest('.adm-perm-pill');
    if (pill) {
        if (checkbox.checked) {
            pill.classList.add('on');
        } else {
            pill.classList.remove('on');
        }
    }
    // Update local cache
    const u = admAllUsers.find(u => u.id == userId);
    if (u) u[permKey] = checkbox.checked ? 1 : 0;
    savePermission(userId, permKey, checkbox.checked);
}

// Dept tag toggle — updates tag visual immediately, then saves
function toggleUserDeptTag(userId, dept, checkbox, tagEl, bg, border) {
    const isOn = checkbox.checked;
    tagEl.classList.toggle('on', isOn);
    tagEl.classList.toggle('off', !isOn);
    tagEl.style.background    = isOn ? bg    : 'transparent';
    tagEl.style.borderColor   = isOn ? border : 'var(--border)';
    toggleUserDept(userId, dept, isOn);
}

async function savePermission(userId, permKey, value) {
    try {
        const form = new FormData();
        form.append('action',   'save_permission');
        form.append('user_id',  userId);
        form.append('perm_key', permKey);
        form.append('value',    value ? '1' : '0');

        const res  = await fetch(API, { method: 'POST', body: form });
        const data = await res.json();

        if (data.success) {
            // Update local cache (visual already updated by savePillPerm)
            const u = admAllUsers.find(u => u.id == userId);
            if (u) u[permKey] = value ? 1 : 0;
            showAdmToast('✓ Permission saved');
        } else {
            // Revert checkbox if save failed
            showAdmToast('✗ Save failed — please retry');
        }
    } catch (e) {
        console.error('Permission save failed', e);
        showAdmToast('✗ Network error');
    }
}

async function toggleUserDept(userId, dept, add) {
    // Update local cache immediately for responsiveness
    const u = admAllUsers.find(u => u.id == userId);
    if (u) {
        if (!u.allowed_depts) u.allowed_depts = [];
        if (add) {
            if (!u.allowed_depts.includes(dept)) u.allowed_depts.push(dept);
        } else {
            u.allowed_depts = u.allowed_depts.filter(d => d !== dept);
        }
        // Update the summary label in the same row without full re-render
        const row   = document.querySelector(`[data-uid="${userId}"]`);
        const label = document.getElementById(`dept-label-${userId}`);
        if (label) {
            label.textContent = u.allowed_depts.length === 0
                ? '⚠ No dept assigned — sees all'
                : `${u.allowed_depts.length} dept${u.allowed_depts.length>1?'s':''} assigned`;
            label.style.color = u.allowed_depts.length === 0 ? '#f87171' : 'var(--muted)';
        }
        // Save to server
        try {
            const form = new FormData();
            form.append('action',  'save_user_depts');
            form.append('user_id', userId);
            form.append('depts',   JSON.stringify(u.allowed_depts));
            const res  = await fetch(API, { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) showAdmToast('✓ Department access saved');
        } catch (e) {
            console.error('Dept save failed', e);
        }
    }
}

function showAdmToast(msg) {
    const t = document.getElementById('adm-save-toast');
    t.textContent = msg || '✓ Permissions saved';
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 2000);
}

// ╔══════════════════════════════════════════════════════════╗
// ║            SYSTEM ACCESS CONTROL                         ║
// ╚══════════════════════════════════════════════════════════╝

let saAllUsers  = [];
let saFiltered  = [];

async function loadSystemAccessUsers() {
    const wrap = document.getElementById('sa-table-wrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading system access data…</div>';
    try {
        const res  = await fetch(`${API}?action=get_system_access_users`);
        const data = await res.json();
        saAllUsers = data.users || [];
        saFiltered = [...saAllUsers];
        updateSaBadge();
        filterSaUsers();
    } catch (e) {
        wrap.innerHTML = '<div class="loading">Failed to load data.</div>';
    }
}

function filterSaUsers() {
    const q = (document.getElementById('sa-search')?.value || '').toLowerCase().trim();
    saFiltered = q
        ? saAllUsers.filter(u => (u.DisplayName || u.username || '').toLowerCase().includes(q)
                               || (u.username || '').toLowerCase().includes(q))
        : [...saAllUsers];
    const clr = document.getElementById('sa-search-clear');
    if (clr) clr.style.display = q ? 'block' : 'none';
    renderSaCards();
}

function clearSaSearch() {
    const inp = document.getElementById('sa-search');
    if (inp) inp.value = '';
    const clr = document.getElementById('sa-search-clear');
    if (clr) clr.style.display = 'none';
    filterSaUsers();
}

function updateSaBadge() {
    const rejectedCount = saAllUsers.filter(u => !u.isApproved).length;
    const badge = document.getElementById('adm-pending-badge');
    if (badge) {
        badge.textContent = rejectedCount;
        badge.style.display = rejectedCount > 0 ? 'inline-flex' : 'none';
    }
}

function renderSaCards() {
    const wrap = document.getElementById('sa-table-wrap');
    if (!wrap) return;

    const total   = saFiltered.length;
    const countEl = document.getElementById('sa-count-label');
    if (countEl) countEl.textContent =
        `${total} user${total !== 1 ? 's' : ''}${total < saAllUsers.length ? ' (filtered)' : ''}`;

    if (!total) {
        wrap.innerHTML = `<div class="adm-empty">
            <div class="adm-empty-icon">🔍</div>
            <div class="adm-empty-text">No users found.</div>
        </div>`;
        return;
    }

    let html = '<div class="sa-cards">';
    saFiltered.forEach(u => {
        const uid          = u.id;
        const isSuperAdmin = (u.user_type || '').toLowerCase() === 'superadmin';
        const isApproved   = isSuperAdmin ? true : !!u.isApproved;
        const name         = escHtml(u.DisplayName || u.username || '?');
        const username     = escHtml(u.username || '');
        const dept         = escHtml(u.Department || '—');
        const roleLbl      = isSuperAdmin ? '⭐ Superadmin' : escHtml(u.user_type || 'User');

        const cardCls   = isSuperAdmin ? 'is-superadmin' : (isApproved ? 'is-approved' : 'is-rejected');
        const onCls     = isApproved ? 'on' : '';
        const lockCls   = isSuperAdmin ? 'locked' : '';
        const toggleLbl = isSuperAdmin ? 'SUPERADMIN' : (isApproved ? 'APPROVED' : 'REJECTED');
        const chgAttr   = isSuperAdmin
            ? 'disabled'
            : `onchange="toggleSaAccess(${uid}, this)"`;
        const titleTip  = isSuperAdmin
            ? 'Superadmins always have full access'
            : (isApproved ? 'Click to reject access' : 'Click to approve access');

        html += `
        <div class="sa-card ${cardCls}" id="sa-card-${uid}">
            <div class="sa-user-info">
                <div class="sa-user-name">${name}</div>
                <div class="sa-user-meta">
                    @${username}
                    &nbsp;·&nbsp; ${dept}
                    &nbsp;·&nbsp;
                    <span class="adm-role-badge ${isSuperAdmin ? 'superadmin' : 'regular'}" style="font-size:10px;padding:2px 8px">
                        ${roleLbl}
                    </span>
                </div>
            </div>
            <label class="sa-toggle-item ${onCls} ${lockCls}" title="${titleTip}">
                <input type="checkbox" ${isApproved ? 'checked' : ''} ${chgAttr}>
                <span class="sa-toggle-label">${toggleLbl}</span>
                <span class="sa-switch"></span>
            </label>
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

async function toggleSaAccess(userId, checkbox) {
    const isApproved = checkbox.checked;
    const label      = checkbox.closest('.sa-toggle-item');
    const card       = checkbox.closest('.sa-card');

    // Optimistic UI
    if (label) {
        label.classList.toggle('on', isApproved);
        const lbl = label.querySelector('.sa-toggle-label');
        if (lbl) lbl.textContent = isApproved ? 'APPROVED' : 'REJECTED';
    }
    if (card) {
        card.classList.toggle('is-approved', isApproved);
        card.classList.toggle('is-rejected', !isApproved);
    }

    // Update cache
    const u = saAllUsers.find(u => u.id == userId);
    if (u) u.isApproved = isApproved ? 1 : 0;
    updateSaBadge();

    try {
        const form = new FormData();
        form.append('action',      'set_system_access');
        form.append('user_id',     userId);
        form.append('is_approved', isApproved ? '1' : '0');
        const res  = await fetch(API, { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            showSaToast(`✓ Access ${isApproved ? 'approved' : 'rejected'}`);
        } else {
            // Revert
            if (label) {
                label.classList.toggle('on', !isApproved);
                const lbl = label.querySelector('.sa-toggle-label');
                if (lbl) lbl.textContent = !isApproved ? 'APPROVED' : 'REJECTED';
            }
            if (card) {
                card.classList.toggle('is-approved', !isApproved);
                card.classList.toggle('is-rejected', isApproved);
            }
            checkbox.checked = !isApproved;
            if (u) u.isApproved = !isApproved ? 1 : 0;
            updateSaBadge();
            showSaToast('✗ ' + (data.message || 'Save failed'));
        }
    } catch (e) {
        console.error(e);
        // Revert
        if (label) {
            label.classList.toggle('on', !isApproved);
            const lbl = label.querySelector('.sa-toggle-label');
            if (lbl) lbl.textContent = !isApproved ? 'APPROVED' : 'REJECTED';
        }
        if (card) {
            card.classList.toggle('is-approved', !isApproved);
            card.classList.toggle('is-rejected', isApproved);
        }
        checkbox.checked = !isApproved;
        if (u) u.isApproved = !isApproved ? 1 : 0;
        updateSaBadge();
        showSaToast('✗ Network error');
    }
}

function showSaToast(msg) {
    const t = document.getElementById('sa-save-toast');
    if (!t) return;
    t.textContent = msg || '✓ Saved';
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 2200);
}


// ╔══════════════════════════════════════════════════════════╗
// ║            FUEL TANK ACCESS PAGE                         ║
// ╚══════════════════════════════════════════════════════════╝

const TANK_RESTRICTED = ['TRADEWELL', 'TRADEWELL GUMACA'];
const TA_PAGE_SIZE    = 10;

let taAllUsers = [];
let taFiltered = [];
let taPage     = 1;

async function loadTankAccessUsers() {
    const wrap = document.getElementById('ta-table-wrap');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading tank access data…</div>';
    const pgEl   = document.getElementById('ta-pagination');
    const infoEl = document.getElementById('ta-page-info');
    if (pgEl)   pgEl.innerHTML     = '';
    if (infoEl) infoEl.textContent = '';

    try {
        const res  = await fetch(`${API}?action=admin_tank_access`);
        const data = await res.json();
        taAllUsers = data.users || [];
        taFiltered = [...taAllUsers];
        taPage     = 1;
        renderTankAccessCards();
        updateTankAccessStats();
    } catch (e) {
        wrap.innerHTML = '<div class="loading">Failed to load tank access data.</div>';
    }
}

function filterTankAccessUsers() {
    const q = (document.getElementById('ta-search')?.value || '').toLowerCase().trim();
    taFiltered = q
        ? taAllUsers.filter(u => (u.DisplayName || u.username || '').toLowerCase().includes(q))
        : [...taAllUsers];
    taPage = 1;
    const clr = document.getElementById('ta-search-clear');
    if (clr) clr.style.display = q ? 'block' : 'none';
    const countEl = document.getElementById('ta-count-label');
    if (countEl) countEl.textContent =
        `${taFiltered.length} user${taFiltered.length !== 1 ? 's' : ''}${taFiltered.length < taAllUsers.length ? ' (filtered)' : ''}`;
    renderTankAccessCards();
}

function clearTankAccessSearch() {
    const inp = document.getElementById('ta-search');
    if (inp) inp.value = '';
    const clr = document.getElementById('ta-search-clear');
    if (clr) clr.style.display = 'none';
    filterTankAccessUsers();
}

function updateTankAccessStats() {
    const statsBar = document.getElementById('ta-page-stats');
    if (!statsBar) return;
    const twCount  = taAllUsers.filter(u =>
        (u.user_type||'').toLowerCase() === 'superadmin' || (u.tank_suppliers||[]).includes('TRADEWELL')).length;
    const gumCount = taAllUsers.filter(u =>
        (u.user_type||'').toLowerCase() === 'superadmin' || (u.tank_suppliers||[]).includes('TRADEWELL GUMACA')).length;
    statsBar.style.display = 'flex';
    document.getElementById('ta-stat-total').textContent = taAllUsers.length;
    document.getElementById('ta-stat-tw').textContent    = twCount;
    document.getElementById('ta-stat-gum').textContent   = gumCount;
}

function renderTankAccessCards() {
    const wrap   = document.getElementById('ta-table-wrap');
    const pgEl   = document.getElementById('ta-pagination');
    const infoEl = document.getElementById('ta-page-info');
    if (!wrap) return;

    const total = taFiltered.length;
    const pages = Math.max(1, Math.ceil(total / TA_PAGE_SIZE));
    if (taPage > pages) taPage = pages;

    const countEl = document.getElementById('ta-count-label');
    if (countEl && !document.getElementById('ta-search')?.value) {
        countEl.textContent = `${total} user${total !== 1 ? 's' : ''}`;
    }

    if (!total) {
        wrap.innerHTML = `<div class="ta-empty">
            <div class="ta-empty-icon">🔍</div>
            <div class="ta-empty-text">No users found matching your search.</div>
        </div>`;
        if (pgEl)   pgEl.innerHTML     = '';
        if (infoEl) infoEl.textContent = '';
        return;
    }

    const start = (taPage - 1) * TA_PAGE_SIZE;
    const slice = taFiltered.slice(start, start + TA_PAGE_SIZE);

    let html = '<div class="ta-cards">';
    slice.forEach(u => {
        const uid          = u.id;
        const isSuperAdmin = (u.user_type || '').toLowerCase() === 'superadmin';
        const assigned     = u.tank_suppliers || [];
        const name         = escHtml(u.DisplayName || u.username || '?');
        const username     = escHtml(u.username || '');
        const roleCls      = isSuperAdmin ? 'is-superadmin' : '';

        const twOn  = isSuperAdmin || assigned.includes('TRADEWELL');
        const gumOn = isSuperAdmin || assigned.includes('TRADEWELL GUMACA');

        const mkToggle = (supplier, isOn, cls) => {
            const lockedAttr = isSuperAdmin ? 'disabled' : '';
            const lockedCls  = isSuperAdmin ? 'locked'   : '';
            const onCls      = isOn ? 'on' : '';
            const changeCall = isSuperAdmin
                ? ''
                : `onchange="toggleTankAccess(${uid}, '${supplier}', this)"`;
            return `<label class="ta-toggle-item ${cls} ${onCls} ${lockedCls}"
                        title="${isSuperAdmin ? 'Superadmins always have full access' : (isOn ? 'Click to revoke' : 'Click to grant')} ${supplier}">
                <input type="checkbox" ${isOn ? 'checked' : ''} ${lockedAttr} ${changeCall}>
                <span class="ta-toggle-label">${supplier}</span>
                <span class="ta-switch"></span>
            </label>`;
        };

        html += `
        <div class="ta-card ${roleCls}" id="ta-card-${uid}">
            <div class="ta-user-info">
                <div class="ta-user-name">${name}</div>
                <div class="ta-user-meta">
                    @${username}
                    &nbsp;·&nbsp;
                    <span class="adm-role-badge ${isSuperAdmin ? 'superadmin' : 'regular'}" style="font-size:10px;padding:2px 8px">
                        ${isSuperAdmin ? '⭐ Superadmin' : 'User'}
                    </span>
                    ${isSuperAdmin ? '&nbsp;·&nbsp;<span style="font-size:11px;color:var(--accent)">Full access (unrestricted)</span>' : ''}
                </div>
            </div>
            <div class="ta-toggles">
                ${mkToggle('TRADEWELL',        twOn,  'tradewell')}
                ${mkToggle('TRADEWELL GUMACA', gumOn, 'gumaca')}
            </div>
        </div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;

    if (infoEl) infoEl.textContent =
        `Showing ${start + 1}–${Math.min(start + TA_PAGE_SIZE, total)} of ${total}`;

    if (pgEl) {
        pgEl.innerHTML = '';
        if (pages <= 1) return;
        const mkBtn = (label, page, active = false, disabled = false) => {
            const btn = document.createElement('button');
            btn.className = 'pg-btn' + (active ? ' active' : '');
            btn.textContent = label;
            btn.disabled = disabled;
            btn.onclick = () => { taPage = page; renderTankAccessCards(); };
            return btn;
        };
        pgEl.appendChild(mkBtn('‹', taPage - 1, false, taPage === 1));
        pagRange(taPage, pages).forEach(p => {
            if (p === '…') {
                const sp = document.createElement('span');
                sp.className = 'pg-info';
                sp.textContent = '…';
                pgEl.appendChild(sp);
            } else {
                pgEl.appendChild(mkBtn(p, p, p === taPage));
            }
        });
        pgEl.appendChild(mkBtn('›', taPage + 1, false, taPage === pages));
    }
}

async function toggleTankAccess(userId, supplier, checkbox) {
    const isActive = checkbox.checked;
    const label    = checkbox.closest('.ta-toggle-item');
    if (label) label.classList.toggle('on', isActive);

    const u = taAllUsers.find(u => u.id == userId);
    if (u) {
        if (!u.tank_suppliers) u.tank_suppliers = [];
        if (isActive) {
            if (!u.tank_suppliers.includes(supplier)) u.tank_suppliers.push(supplier);
        } else {
            u.tank_suppliers = u.tank_suppliers.filter(s => s !== supplier);
        }
    }

    try {
        const form = new FormData();
        form.append('action',        'set_tank_access');
        form.append('user_id',       userId);
        form.append('supplier_name', supplier);
        form.append('is_active',     isActive ? '1' : '0');

        const res  = await fetch(API, { method: 'POST', body: form });
        const data = await res.json();

        if (data.success) {
            updateTankAccessStats();
            showTaToast(`✓ ${supplier} access ${isActive ? 'granted' : 'revoked'}`);
        } else {
            if (label) label.classList.toggle('on', !isActive);
            checkbox.checked = !isActive;
            showTaToast('✗ Save failed — please retry');
        }
    } catch (e) {
        console.error('Tank access save failed', e);
        if (label) label.classList.toggle('on', !isActive);
        checkbox.checked = !isActive;
        showTaToast('✗ Network error');
    }
}

function showTaToast(msg) {
    const t = document.getElementById('ta-save-toast');
    if (!t) return;
    t.textContent = msg || '✓ Saved';
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 2200);
}

// ╔══════════════════════════════════════════════════════════╗
// ║                  FUEL PRICE MANAGEMENT                  ║
// ╚══════════════════════════════════════════════════════════╝

// Per-supplier price cache  { 'TRADEWELL': 60.30, 'TRADEWELL GUMACA': 60.30 }
const fuelPrices = { 'TRADEWELL': 60.30, 'TRADEWELL GUMACA': 60.30 };

// Which supplier the Set-Price modal is currently editing
let fpmActiveSupplier = 'TRADEWELL';

/** Return the cached price for a supplier (normalised, falls back to 60.30). */
function getPriceFor(supplier) {
    const key = (supplier || '').trim().toUpperCase();
    if (key === 'TRADEWELL GUMACA') return fuelPrices['TRADEWELL GUMACA'];
    return fuelPrices['TRADEWELL'];
}

/** Fetch both supplier prices on page load and prime the caches. */
async function loadFuelPrice() {
    try {
        const [r1, r2] = await Promise.all([
            fetch(`${API}?action=get_fuel_price&supplier=TRADEWELL`),
            fetch(`${API}?action=get_fuel_price&supplier=TRADEWELL%20GUMACA`)
        ]);
        const [d1, d2] = await Promise.all([r1.json(), r2.json()]);
        fuelPrices['TRADEWELL']        = parseFloat(d1.price) || 60.30;
        fuelPrices['TRADEWELL GUMACA'] = parseFloat(d2.price) || 60.30;
        updateFuelPriceDisplay();
    } catch (e) {
        console.warn('Could not load fuel prices', e);
    }
}

/** Refresh the tank-page badge to match the active tank supplier's price. */
function updateFuelPriceDisplay() {
    const price     = getPriceFor(activeTankSupplier);
    const tankBadge = document.getElementById('tankFuelPriceVal');
    if (tankBadge) tankBadge.textContent = '₱' + price.toFixed(2);

    // Sync Add Fuel modal price field when it's locked to an auto-price supplier
    const addSupplierVal = ((document.getElementById('add-supplier') || {}).value || '').trim().toUpperCase();
    if (addSupplierVal === 'TRADEWELL' || addSupplierVal === 'TRADEWELL GUMACA') {
        const addPriceInput = document.getElementById('add-price');
        if (addPriceInput) {
            addPriceInput.value = getPriceFor(addSupplierVal).toFixed(2);
            calcAmount();
        }
    }
}

/**
 * Open the Set Fuel Price modal.
 * @param {string} [supplierOverride] – force a specific supplier to edit.
 */
async function openFuelPriceModal(supplierOverride) {
    if (!USER_PERMS.perm_edit_fuel_price) {
        alert('⛔ You do not have permission to edit the fuel price.');
        return;
    }

    fpmActiveSupplier = ((supplierOverride || activeTankSupplier || 'TRADEWELL') + '').trim().toUpperCase();

    // Update badge in modal header
    const badge = document.getElementById('fpm-supplier-badge');
    if (badge) {
        badge.textContent = fpmActiveSupplier;
        if (fpmActiveSupplier === 'TRADEWELL GUMACA') {
            badge.style.background = 'rgba(59,130,246,.15)';
            badge.style.color      = '#60a5fa';
            badge.style.border     = '1px solid rgba(59,130,246,.3)';
        } else {
            badge.style.background = 'rgba(240,165,0,.15)';
            badge.style.color      = '#f0a500';
            badge.style.border     = '1px solid rgba(240,165,0,.3)';
        }
    }

    const currentPrice = getPriceFor(fpmActiveSupplier);
    document.getElementById('fuelPriceModal').classList.add('show');
    document.getElementById('fpm-alert').innerHTML     = '';
    document.getElementById('fpm-price').value         = currentPrice.toFixed(2);
    document.getElementById('fpm-note').value          = '';
    document.getElementById('fpm-preview').textContent = '';
    document.getElementById('fpmCurrentPrice').textContent = '₱' + currentPrice.toFixed(2);
    document.getElementById('fpmCurrentMeta').textContent  = '';

    try {
        const res  = await fetch(`${API}?action=get_fuel_price&supplier=${encodeURIComponent(fpmActiveSupplier)}`);
        const data = await res.json();
        const price = parseFloat(data.price) || currentPrice;
        fuelPrices[fpmActiveSupplier] = price;
        document.getElementById('fpmCurrentPrice').textContent = '₱' + price.toFixed(2);
        document.getElementById('fpmCurrentMeta').textContent  =
            (data.note ? data.note + ' · ' : '') + (data.set_at ? 'Set ' + data.set_at : '');
        document.getElementById('fpm-price').value = price.toFixed(2);
    } catch (e) { /* silently fail */ }

    fpmHistoryLoaded = false;
    fpmHistoryRows   = [];
    fpmCurrentPage   = 1;
    document.getElementById('fpm-history-wrap').style.display  = 'none';
    document.getElementById('fpm-history-label').textContent   = 'Show Price History';
    document.getElementById('fpm-history-body').innerHTML      = '<div class="loading"><span class="spinner"></span>Loading…</div>';
    document.getElementById('fpm-history-pagination').style.display = 'none';
}

/** Live preview while typing in the price input. */
function fpmPreview() {
    const val = parseFloat(document.getElementById('fpm-price').value);
    const el  = document.getElementById('fpm-preview');
    const cur = getPriceFor(fpmActiveSupplier);
    if (!isNaN(val) && val > 0) {
        const diff = val - cur;
        const sign = diff >= 0 ? '+' : '';
        el.innerHTML = `New price: <strong style="color:var(--accent)">\u20b1${val.toFixed(2)}</strong>
            &nbsp;\u00b7&nbsp; Change: <span style="color:${diff>=0?'#f87171':'#3fb950'}">${sign}\u20b1${Math.abs(diff).toFixed(2)}</span>`;
    } else {
        el.textContent = '';
    }
}

/** Submit the new price for the currently active supplier. */
async function submitFuelPrice() {
    const price   = parseFloat(document.getElementById('fpm-price').value);
    const note    = document.getElementById('fpm-note').value.trim();
    const alertEl = document.getElementById('fpm-alert');

    if (isNaN(price) || price <= 0) {
        alertEl.innerHTML = '<div class="alert alert-error">Please enter a valid price greater than \u20b10.</div>';
        return;
    }

    const fd = new FormData();
    fd.append('action',   'set_fuel_price');
    fd.append('price',    price);
    fd.append('note',     note);
    fd.append('supplier', fpmActiveSupplier);

    try {
        const res  = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            fuelPrices[fpmActiveSupplier] = price;

            // Refresh tank badge if editing the currently shown tank
            if (fpmActiveSupplier === ((activeTankSupplier || 'TRADEWELL') + '').toUpperCase()) {
                const tankBadge = document.getElementById('tankFuelPriceVal');
                if (tankBadge) tankBadge.textContent = '\u20b1' + price.toFixed(2);
            }

            // Refresh Add Fuel price field if the add-supplier matches
            const addSupplierVal = ((document.getElementById('add-supplier') || {}).value || '').trim().toUpperCase();
            if (addSupplierVal === fpmActiveSupplier) {
                const addPriceInput = document.getElementById('add-price');
                if (addPriceInput) { addPriceInput.value = price.toFixed(2); calcAmount(); }
            }

            alertEl.innerHTML =
                `<div class="alert alert-success">✓ ${fpmActiveSupplier} fuel price updated to \u20b1${price.toFixed(2)}</div>`;
            document.getElementById('fpmCurrentPrice').textContent = '\u20b1' + price.toFixed(2);
            document.getElementById('fpmCurrentMeta').textContent  = (note ? note + ' \u00b7 ' : '') + 'Just now';

            fpmHistoryLoaded = false;
            fpmHistoryRows   = [];
            fpmCurrentPage   = 1;
            document.getElementById('fpm-history-body').innerHTML = '<div class="loading"><span class="spinner"></span>Loading…</div>';
            document.getElementById('fpm-history-pagination').style.display = 'none';

            setTimeout(() => closeModal('fuelPriceModal'), 1200);
        } else {
            alertEl.innerHTML = `<div class="alert alert-error">Error: ${data.message}</div>`;
        }
    } catch (e) {
        alertEl.innerHTML = '<div class="alert alert-error">Network error — please try again.</div>';
    }
}

// Toggle price history accordion — with 5-rows-per-page pagination
const FPM_PAGE_SIZE = 5;
let fpmHistoryLoaded = false;
let fpmHistoryRows   = [];
let fpmCurrentPage   = 1;

async function toggleFpmHistory() {
    const wrap  = document.getElementById('fpm-history-wrap');
    const label = document.getElementById('fpm-history-label');
    const isOpen = wrap.style.display !== 'none';

    if (isOpen) {
        wrap.style.display = 'none';
        label.textContent  = 'Show Price History';
        return;
    }

    wrap.style.display = 'block';
    label.textContent  = 'Hide Price History';

    if (!fpmHistoryLoaded) {
        try {
            const res  = await fetch(`${API}?action=fuel_price_history&supplier=${encodeURIComponent(fpmActiveSupplier)}`);
            const data = await res.json();
            fpmHistoryRows   = data.history || [];
            fpmHistoryLoaded = true;
            fpmCurrentPage   = 1;

            if (!fpmHistoryRows.length) {
                document.getElementById('fpm-history-body').innerHTML =
                    '<div style="padding:10px;color:var(--muted);font-size:12px">No history yet.</div>';
                document.getElementById('fpm-history-pagination').style.display = 'none';
                return;
            }
            renderFpmHistoryPage(fpmCurrentPage);
        } catch (e) {
            document.getElementById('fpm-history-body').innerHTML =
                '<div style="padding:10px;color:#f87171;font-size:12px">Failed to load history.</div>';
        }
    }
}

function renderFpmHistoryPage(page) {
    const rows       = fpmHistoryRows;
    const totalPages = Math.ceil(rows.length / FPM_PAGE_SIZE);
    page = Math.max(1, Math.min(page, totalPages));
    fpmCurrentPage   = page;

    const start = (page - 1) * FPM_PAGE_SIZE;
    const slice = rows.slice(start, start + FPM_PAGE_SIZE);

    let html = `<table class="fpm-history-table">
        <thead><tr>
            <th>Price</th><th>Note</th><th>Set By</th><th>Date</th>
        </tr></thead><tbody>`;
    slice.forEach(r => {
        html += `<tr>
            <td style="font-weight:700;color:var(--accent)">\u20b1${parseFloat(r.Price).toFixed(2)}</td>
            <td style="color:var(--muted)">${escHtml(r.Note || '—')}</td>
            <td style="font-size:11px">${escHtml(r.SetBy || '—')}</td>
            <td style="font-size:11px;color:var(--muted);white-space:nowrap">${escHtml(r.SetAt || '—')}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('fpm-history-body').innerHTML = html;

    const pgEl = document.getElementById('fpm-history-pagination');
    if (totalPages <= 1) { pgEl.style.display = 'none'; return; }

    pgEl.style.display = 'flex';
    let pgHtml = `<button class="fpm-page-btn" onclick="renderFpmHistoryPage(${page-1})" ${page===1?'disabled':''}>‹</button>`;
    pagRange(page, totalPages).forEach(p => {
        if (p === '…') {
            pgHtml += `<span style="color:var(--muted);font-size:12px;padding:0 2px">…</span>`;
        } else {
            pgHtml += `<button class="fpm-page-btn ${p===page?'active':''}" onclick="renderFpmHistoryPage(${p})">${p}</button>`;
        }
    });
    pgHtml += `<button class="fpm-page-btn" onclick="renderFpmHistoryPage(${page+1})" ${page===totalPages?'disabled':''}>›</button>`;
    pgEl.innerHTML = pgHtml;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Shared pagination range helper (also used here)
function pagRange(cur, total) {
    if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
    if (cur <= 4) return [1,2,3,4,5,'…',total];
    if (cur >= total - 3) return [1,'…',total-4,total-3,total-2,total-1,total];
    return [1,'…',cur-1,cur,cur+1,'…',total];
}

/* ══════════════════════════════════════════════════
   LIVE UPDATE ENGINE
   ══════════════════════════════════════════════════ */
(function LiveUpdater() {

    // ── Config ────────────────────────────────────────────
    const POLL_INTERVAL_MS  = 5000;    // poll every 5 s
    const PAUSE_ON_MODAL    = true;    // pause polling while any modal is open
    const MAX_ERRORS        = 4;       // switch to error state after N consecutive failures
    const TOAST_DURATION_MS = 5000;    // auto-dismiss toasts after 5 s

    // ── State ────────────────────────────────────────────
    let lastSnap      = {};            // fingerprints from last successful poll
    let pollTimer     = null;
    let errorStreak   = 0;
    let isPaused      = false;
    let isRunning     = false;

    // ── DOM refs ─────────────────────────────────────────
    const allIndicators = () => [
        document.getElementById('live-indicator'),         // navbar (desktop)
        document.getElementById('live-indicator-sidebar'), // sidebar (mobile)
    ].filter(Boolean);

    // ── Indicator helpers — update ALL indicator elements ─
    function setIndicatorState(state, label) {
        allIndicators().forEach(el => {
            el.classList.remove('live-paused', 'live-error', 'live-flash');
            if (state === 'paused') el.classList.add('live-paused');
            if (state === 'error')  el.classList.add('live-error');
        });
        // Navbar label
        const navLabel = document.getElementById('live-label');
        if (navLabel) navLabel.textContent = label || 'LIVE';
        // Sidebar label
        const sideLabel = document.querySelector('.live-sidebar-label');
        if (sideLabel) sideLabel.textContent = label || 'LIVE';
    }

    function flashIndicator() {
        allIndicators().forEach(el => {
            el.classList.remove('live-flash');
            void el.offsetWidth;           // reflow to restart animation
            el.classList.add('live-flash');
        });
    }

    // ── Toast notifications ───────────────────────────────
    function showToast(icon, title, msg, type = 'info') {
        const stack = document.getElementById('live-toast-stack');
        if (!stack) return;
        const t = document.createElement('div');
        t.className = `live-toast toast-${type}`;
        t.innerHTML = `<span class="toast-icon">${icon}</span>
            <div class="toast-body">
                <div class="toast-title">${title}</div>
                <div class="toast-msg">${msg}</div>
            </div>
            <span class="toast-close" onclick="this.closest('.live-toast').remove()">✕</span>`;
        stack.appendChild(t);

        const dismiss = () => {
            t.style.animation = 'toastOut .2s ease-in forwards';
            setTimeout(() => t.remove(), 220);
        };
        t.addEventListener('click', e => { if (!e.target.classList.contains('toast-close')) dismiss(); });
        setTimeout(dismiss, TOAST_DURATION_MS);
    }
    window.showToast = showToast;

    // ── Which page is currently active ───────────────────
    function activePage() {
        const pages = ['dashboard','fuel-records','gas-card','tank','administration'];
        for (const p of pages) {
            const el = document.getElementById('page-' + p);
            if (el && el.classList.contains('active')) return p;
        }
        return 'dashboard';
    }

    // ── Is any modal open? ────────────────────────────────
    function isModalOpen() {
        return !!document.querySelector('.modal-overlay.show');
    }

    // ── Detect changed sections and refresh them ──────────
    function handleChanges(snap) {
        const prev  = lastSnap;
        lastSnap    = snap;
        const page  = activePage();
        let refreshed = false;

        // Dashboard
        if (prev.dashboard !== undefined && snap.dashboard !== prev.dashboard) {
            refreshed = true;
            if (page === 'dashboard') {
                loadDashboard();
                showToast('📊', 'Dashboard Updated', 'New fuel activity detected today.', 'info');
            }
        }

        // Fuel Records page
        if (prev.fuel_records !== undefined && snap.fuel_records !== prev.fuel_records) {
            refreshed = true;
            if (page === 'fuel-records') {
                loadFuelRecords(frCurrentPage);
                showToast('⛽', 'Fuel Records Updated', 'Fuel records have been updated.', 'success');
            }
        }

        // Tank — figure out which supplier is active
        const tankSupplier = (typeof activeTankSupplier !== 'undefined' ? activeTankSupplier : 'TRADEWELL').toUpperCase();
        const tankKey = tankSupplier === 'TRADEWELL GUMACA' ? 'tank_gumaca' : 'tank_tradewell';
        if (prev[tankKey] !== undefined && snap[tankKey] !== prev[tankKey]) {
            refreshed = true;
            if (page === 'tank') {
                loadTankDashboard();
                showToast('🛢', 'Tank Updated', tankSupplier + ' tank data has changed.', 'info');
            }
        }

        // Fuel price changed for either supplier
        if (prev.fuel_price !== undefined && snap.fuel_price !== prev.fuel_price) {
            refreshed = true;
            // Reload both prices silently
            loadFuelPrice();
            if (page === 'tank') {
                showToast('💰', 'Fuel Price Updated', 'A fuel price has been changed.', 'warning');
            }
        }

        if (refreshed) flashIndicator();
    }

    // ── Core poll function ────────────────────────────────
    async function poll() {
        if (isPaused || (PAUSE_ON_MODAL && isModalOpen())) {
            schedule();
            return;
        }

        try {
            const dept   = typeof activeDept !== 'undefined' ? activeDept : '';
            const date   = typeof todayStr   !== 'undefined' ? todayStr   : new Date().toISOString().split('T')[0];
            const params = new URLSearchParams({ action: 'poll_snapshot', department: dept, date });
            const res    = await fetch(`${API}?${params}`, { cache: 'no-store' });

            if (!res.ok) throw new Error('HTTP ' + res.status);
            const snap = await res.json();

            errorStreak = 0;
            setIndicatorState('live', 'LIVE');

            if (Object.keys(lastSnap).length === 0) {
                // First poll — just store baseline, don't trigger refreshes
                lastSnap = snap;
            } else {
                handleChanges(snap);
            }
        } catch (e) {
            errorStreak++;
            console.warn('[LiveUpdater] poll failed:', e.message);
            if (errorStreak >= MAX_ERRORS) {
                setIndicatorState('error', 'OFFLINE');
            }
        }
        schedule();
    }

    function schedule() {
        clearTimeout(pollTimer);
        pollTimer = setTimeout(poll, POLL_INTERVAL_MS);
    }

    // ── Public controls ───────────────────────────────────
    window.liveUpdater = {
        pause()  { isPaused = true;  setIndicatorState('paused', 'PAUSED'); },
        resume() { isPaused = false; setIndicatorState('live',   'LIVE');   poll(); },
        // Call after manually saving/editing/deleting — immediately updates the
        // baseline snapshot so other accounts' pollers detect the change within 5s.
        markDirty() {
            // Do a silent poll to update lastSnap to the new state.
            // We don't fire handleChanges on this account since it already refreshed manually.
            clearTimeout(pollTimer);
            (async () => {
                try {
                    const dept   = typeof activeDept !== 'undefined' ? activeDept : '';
                    const date   = typeof todayStr   !== 'undefined' ? todayStr   : new Date().toISOString().split('T')[0];
                    const params = new URLSearchParams({ action: 'poll_snapshot', department: dept, date });
                    const res    = await fetch(`${API}?${params}`, { cache: 'no-store' });
                    if (res.ok) lastSnap = await res.json(); // update baseline silently
                } catch(e) {}
                schedule();
            })();
        },
    };

    // ── Kick off after page load ──────────────────────────
    window.addEventListener('load', () => {
        // Small delay so the initial page data loads first
        setTimeout(poll, 3000);
    });

    // ── Pause when tab is hidden, resume when visible ─────
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(pollTimer);
            setIndicatorState('paused', 'PAUSED');
        } else {
            // Force an immediate re-baseline so we don't false-fire
            lastSnap = {};
            setIndicatorState('live', 'LIVE');
            poll();
        }
    });

})();

/* ══════════════════════════════════════════════════
   THEME TOGGLE — Dark (Moon) / Light (Sun)
   ══════════════════════════════════════════════════ */
(function initTheme() {
    // Apply saved theme immediately on load (before paint)
    const saved = localStorage.getItem('fuelTheme') || 'dark';
    if (saved === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();

function toggleTheme() {
    const html    = document.documentElement;
    const current = html.getAttribute('data-theme') || 'dark';
    const next    = current === 'dark' ? 'light' : 'dark';

    // Animate the button with a little spin
    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
        btn.style.transform = 'scale(.85) rotate(20deg)';
        setTimeout(() => { btn.style.transform = ''; }, 250);
    }

    if (next === 'light') {
        html.setAttribute('data-theme', 'light');
    } else {
        html.removeAttribute('data-theme');
    }
    localStorage.setItem('fuelTheme', next);
}

// ══════════════════════════════════════════════════════════
// DEPARTMENT MANAGEMENT PAGE
// ══════════════════════════════════════════════════════════
let deptMgmtData = [];

async function loadDeptMgmt() {
    const wrap = document.getElementById('dept-mgmt-table');
    if (!wrap) return;
    wrap.innerHTML = '<div class="loading"><span class="spinner"></span>Loading…</div>';
    try {
        const res  = await fetch(`${API}?action=get_departments&active_only=0`);
        deptMgmtData = await res.json();
        renderDeptMgmtTable();
    } catch (e) {
        wrap.innerHTML = '<div class="loading">Failed to load departments.</div>';
    }
}

function renderDeptMgmtTable() {
    const wrap = document.getElementById('dept-mgmt-table');
    if (!deptMgmtData.length) {
        wrap.innerHTML = '<div class="loading">No departments found.</div>';
        return;
    }
    let rows = deptMgmtData.map(d => {
        const color  = d.Color || '#888';
        const active = parseInt(d.Status) === 1;
        return `<tr>
            <td><span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:${color};margin-right:8px;vertical-align:middle"></span>${d.DepartmentName}</td>
            <td><code>${color}</code></td>
            <td><span style="color:${active ? '#3fb950' : 'var(--muted)'};">${active ? '● Active' : '○ Inactive'}</span></td>
            <td>${d.CreatedAt || ''}</td>
            <td>
                <button class="btn btn-secondary" style="padding:4px 10px;font-size:12px" onclick="openEditDeptModal(${d.DepartmentID})">Edit</button>
                <button class="btn" style="padding:4px 10px;font-size:12px;background:${active ? 'rgba(248,81,73,.15)' : 'rgba(63,185,80,.15)'};color:${active ? '#f85149' : '#3fb950'}"
                    onclick="toggleDept(${d.DepartmentID}, ${active ? 0 : 1})">${active ? 'Deactivate' : 'Activate'}</button>
            </td>
        </tr>`;
    }).join('');
    wrap.innerHTML = `<table>
        <thead><tr>
            <th>Department Name</th><th>Color</th><th>Status</th><th>Created</th><th>Actions</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;
}

function openAddDeptModal() {
    document.getElementById('deptModalTitle').textContent  = 'Add Department';
    document.getElementById('dept-edit-id').value          = '';
    document.getElementById('dept-name-input').value       = '';
    document.getElementById('dept-color-input').value      = '#4f8ef7';
    document.getElementById('dept-color-hex').textContent  = '#4f8ef7';
    document.getElementById('dept-color-preview').style.background = '#4f8ef7';
    document.getElementById('dept-status-group').style.display = 'none';
    document.getElementById('dept-modal-alert').innerHTML  = '';
    document.getElementById('deptModal').classList.add('show');
    wireDeptColorPicker();
}

function openEditDeptModal(id) {
    const d = deptMgmtData.find(x => x.DepartmentID == id);
    if (!d) return;
    document.getElementById('deptModalTitle').textContent  = 'Edit Department';
    document.getElementById('dept-edit-id').value          = d.DepartmentID;
    document.getElementById('dept-name-input').value       = d.DepartmentName;
    document.getElementById('dept-color-input').value      = d.Color || '#4f8ef7';
    document.getElementById('dept-color-hex').textContent  = d.Color || '#4f8ef7';
    document.getElementById('dept-color-preview').style.background = d.Color || '#4f8ef7';
    document.getElementById('dept-status-input').value     = d.Status;
    document.getElementById('dept-status-group').style.display = '';
    document.getElementById('dept-modal-alert').innerHTML  = '';
    document.getElementById('deptModal').classList.add('show');
    wireDeptColorPicker();
}

function wireDeptColorPicker() {
    const colorInput = document.getElementById('dept-color-input');
    if (!colorInput || colorInput._wired) return;
    colorInput._wired = true;
    colorInput.addEventListener('input', e => {
        const v = e.target.value;
        document.getElementById('dept-color-hex').textContent = v;
        document.getElementById('dept-color-preview').style.background = v;
    });
}

async function saveDepartment() {
    const id      = document.getElementById('dept-edit-id').value;
    const name    = document.getElementById('dept-name-input').value.trim().toUpperCase();
    const color   = document.getElementById('dept-color-input').value;
    const status  = document.getElementById('dept-status-input')?.value || '1';
    const alertEl = document.getElementById('dept-modal-alert');

    if (!name) {
        alertEl.innerHTML = '<div class="alert alert-danger">Department name is required.</div>';
        return;
    }

    const btn = document.getElementById('dept-save-btn');
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('name', name);
    fd.append('color', color);

    if (id) {
        fd.append('action', 'update_department');
        fd.append('id', id);
        fd.append('status', status);
    } else {
        fd.append('action', 'add_department');
    }

    try {
        const res  = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('deptModal');
            await loadDepartments();  // refresh global dept arrays
            await loadDeptMgmt();     // refresh table immediately — no manual refresh needed
            showDeptToast(id ? `✓ "${name}" updated successfully` : `✓ "${name}" department created`);
        } else {
            alertEl.innerHTML = `<div class="alert alert-danger">${data.message || 'Error saving department.'}</div>`;
        }
    } catch (e) {
        alertEl.innerHTML = '<div class="alert alert-danger">Network error. Check that you are logged in and try again.</div>';
        console.error('saveDepartment error:', e);
    } finally {
        btn.disabled = false; btn.textContent = 'Save';
    }
}

async function toggleDept(id, newStatus) {
    const d = deptMgmtData.find(x => x.DepartmentID == id);
    if (!d) return;
    const action = newStatus ? 'Activate' : 'Deactivate';
    if (!confirm(`${action} department "${d.DepartmentName}"?`)) return;

    const fd = new FormData();
    fd.append('action', 'update_department');
    fd.append('id', id);
    fd.append('name', d.DepartmentName);
    fd.append('color', d.Color);
    fd.append('status', newStatus);

    try {
        const res  = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            await loadDepartments();
            await loadDeptMgmt();
            showDeptToast(`✓ "${d.DepartmentName}" ${newStatus ? 'activated' : 'deactivated'}`);
        } else {
            alert(data.message || 'Failed to update department.');
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

</script>

</body>
</html>
