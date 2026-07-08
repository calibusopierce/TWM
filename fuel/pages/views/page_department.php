<?php
/**
 * page_department.php
 * Department management page — superadmin only.
 */
?>
<!-- ═══════════════════ DEPARTMENT PAGE ═══════════════════ -->
<div class="page" id="page-department">
    <div class="page-title">Department <span>Management</span></div>
    <p class="page-sub">Add and manage departments stored in the database</p>

    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">Departments</span>
            <button class="btn btn-primary" onclick="openAddDeptModal()">+ Add Department</button>
        </div>
        <div class="panel-body" style="padding:0">
            <div id="dept-mgmt-table" class="tbl-wrap">
                <div class="loading"><span class="spinner"></span>Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Add/Edit Department Modal — uses the same .modal-overlay pattern as the rest of the app ── -->
<div class="modal-overlay" id="deptModal">
    <div class="modal" style="max-width:420px;width:100%">
        <div class="modal-header">
            <span class="modal-title" id="deptModalTitle">Add Department</span>
            <button class="modal-close" onclick="closeModal('deptModal')">✕</button>
        </div>
        <div class="modal-body">
            <div id="dept-modal-alert"></div>
            <input type="hidden" id="dept-edit-id">

            <div class="form-group">
                <label>Department Name *</label>
                <input type="text" id="dept-name-input" placeholder="e.g. LOGISTICS" style="text-transform:uppercase">
            </div>

            <div class="form-group">
                <label>Color *</label>
                <div style="display:flex;gap:10px;align-items:center">
                    <input type="color" id="dept-color-input" value="#4f8ef7"
                           style="width:48px;height:36px;padding:2px;border-radius:6px;cursor:pointer;border:1px solid var(--border);background:transparent">
                    <span id="dept-color-hex" style="font-size:13px;color:var(--muted);min-width:70px">#4f8ef7</span>
                    <div id="dept-color-preview" style="flex:1;height:36px;border-radius:6px;background:#4f8ef7;border:1px solid var(--border);transition:background .15s"></div>
                </div>
            </div>

            <div class="form-group" id="dept-status-group" style="display:none">
                <label>Status</label>
                <select id="dept-status-input">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>
        </div>
        <div class="modal-footer" style="padding:16px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px">
            <button class="btn btn-secondary" onclick="closeModal('deptModal')">Cancel</button>
            <button class="btn btn-primary" id="dept-save-btn" onclick="saveDepartment()">Save</button>
        </div>
    </div>
</div>
