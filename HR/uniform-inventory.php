<?php
// ══════════════════════════════════════════════════════════════
//  uniform-inventory.php  —  Uniform Inventory System
//  Access: Admin, Administrator, HR
// ══════════════════════════════════════════════════════════════
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/auth_check.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/RBAC/rbac_helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/test_sqlsrv.php';
auth_check();

rbac_gate($pdo, 'uniform_inventory');

$currentUser = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'System';
$messages    = [];
$tab         = $_GET['tab'] ?? 'stocks';
$validTabs   = ['stocks','stockview','released','requests','po','receiving','returns','pending_inspection','report'];
if (!in_array($tab, $validTabs)) $tab = 'stocks';
// Editable Stocks tab is restricted to full-access users; view-only users
// get bounced to the read-only Stock Overview tab further down, once
// $canManageStock is resolved (right after department scope detection).
$sizes       = ['XS','S','M','L','XL','XXL','XXXL','4XL'];
$depts       = ['Century','Monde','Multilines','NutriAsia'];
$uTypes      = ['TSHIRT','POLOSHIRT'];

// ── Department scope detection ─────────────────────────────────
// Admins/HR see all; department users see only their department's data
$sessionDept   = trim($_SESSION['Department'] ?? '');
$isAdminView   = in_array($_SESSION['Role'] ?? '', ['Admin','Administrator','HR']) || !in_array($sessionDept, $depts);
$deptScope     = $isAdminView ? '' : $sessionDept; // '' means no restriction

// ── Stock-edit restriction (RBAC-driven) ────────────────────────
// Editing raw stock numbers and inspecting returns requires FULL
// access to the uniform_inventory module. View-only accounts still
// get the module and the read-only Stock Overview / Returns history —
// they just don't get the editable Stocks tab or the inspect/dispose/
// cleaning action buttons. This reuses the same permission_level
// ('full' vs 'view_only') that already gates Employee List, Blacklist,
// Inactive, and the PO module — nothing new to maintain per-module.
$canManageStock  = !rbac_is_view_only('uniform_inventory');
if ($tab === 'stocks' && !$canManageStock) $tab = 'stockview';

// ── Global write gate for view-only users ───────────────────────
// View-only accounts get exactly ONE write privilege in this whole
// module: creating a new entry on the Requested List (save_request).
// Every other mutating action — releasing, editing, deleting,
// inspecting returns, POs, receiving, stock edits, everything — is
// blocked outright here, in one place, rather than trusting each
// handler below to remember to check. Viewing every tab is untouched.
if (!$canManageStock && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_request'])) {
    $blockedActions = [
        'save_stock','save_released','delete_released','edit_released',
        'mark_given','delete_request','save_po','delete_po',
        'save_receiving','delete_receiving','post_to_stocks','unpost_from_stocks',
        'save_return','inspect_return','complete_cleaning','add_to_stock',
        'delete_return','edit_return',
    ];
    foreach ($blockedActions as $ba) {
        if (isset($_POST[$ba])) {
            $messages[] = ['type'=>'danger','text'=>'Your account has view-only access — you can only submit new entries on the Requested List. This action was not saved.'];
            unset($_POST[$ba]);
            break;
        }
    }
}

function rq($conn,$sql,$p=[]) {
    $stmt = empty($p) ? sqlsrv_query($conn,$sql) : sqlsrv_query($conn,$sql,$p);
    if (!$stmt) return [];
    $rows=[];
    while ($r=sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) $rows[]=$r;
    sqlsrv_free_stmt($stmt);
    return $rows;
}
function fmtDate($v) {
    if (!$v) return '—';
    if ($v instanceof DateTime) return $v->format('M d, Y');
    return is_string($v) ? date('M d, Y',strtotime($v)) : '—';
}
function safe($s) { return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8'); }

// ── Build a WHERE/AND clause fragment for dept scoping ─────────
// $prefix: table alias prefix e.g. '' or 'r.'
function deptWhere(string $deptScope, string $prefix='', bool $asAnd=false): string {
    if ($deptScope === '') return '';
    $escaped = str_replace("'","''",$deptScope);
    $clause  = "{$prefix}Department = '{$escaped}'";
    return $asAnd ? " AND {$clause}" : "WHERE {$clause}";
}

// ── Pagination helper ──────────────────────────────────────────
function paginationBar(string $pageParam, int $currentPage, int $totalPages, int $total, array $extra=[]): string {
    if ($totalPages <= 1) return '';
    $params = array_merge($_GET, $extra, [$pageParam => '__P__']);
    $base   = '?' . http_build_query($params);
    $prev   = $currentPage > 1         ? str_replace('__P__', $currentPage-1, $base) : null;
    $next   = $currentPage < $totalPages ? str_replace('__P__', $currentPage+1, $base) : null;
    $start  = ($currentPage-1)*20+1;
    $end    = min($currentPage*20, $total);
    $btnBase= 'display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .75rem;border-radius:7px;font-size:.78rem;font-weight:600;text-decoration:none;transition:all .13s;';
    $active = $btnBase.'border:1.5px solid var(--border);background:var(--surface);color:var(--text-secondary);';
    $disabled=$btnBase.'border:1.5px solid var(--border);background:var(--surface-3);color:var(--text-muted);cursor:not-allowed;';
    $hover  = "onmouseover=\"this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'\" onmouseout=\"this.style.background='var(--surface)';this.style.color='var(--text-secondary)';this.style.borderColor='var(--border)'\"";
    $h  = '<div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;background:var(--surface-2);border-top:1px solid var(--border);font-size:.78rem;gap:.5rem;flex-wrap:wrap;">';
    $h .= '<span style="color:var(--text-muted);">Showing <strong style="color:var(--text-primary);">'.$start.'–'.$end.'</strong> of <strong style="color:var(--text-primary);">'.$total.'</strong></span>';
    $h .= '<div style="display:flex;gap:.35rem;">';
    $h .= $prev ? '<a href="'.htmlspecialchars($prev).'" style="'.$active.'" '.$hover.'><i class="bi bi-chevron-left"></i> Prev</a>' : '<span style="'.$disabled.'"><i class="bi bi-chevron-left"></i> Prev</span>';
    $h .= '<span style="display:inline-flex;align-items:center;padding:.3rem .75rem;border-radius:7px;border:1.5px solid var(--primary);background:var(--primary-glow);color:var(--primary);font-size:.78rem;font-weight:700;">'.$currentPage.' / '.$totalPages.'</span>';
    $h .= $next ? '<a href="'.htmlspecialchars($next).'" style="'.$active.'" '.$hover.'>Next <i class="bi bi-chevron-right"></i></a>' : '<span style="'.$disabled.'">Next <i class="bi bi-chevron-right"></i></span>';
    $h .= '</div></div>';
    return $h;
}

// ── POST: Update Stock (stock managers only) ────────────────────
if (isset($_POST['save_stock'])) {
    if (!$canManageStock) {
        $messages[]=['type'=>'danger','text'=>'You do not have permission to edit stock numbers.'];
    } else {
        $type=$_POST['UniformType']??''; $size=$_POST['Size']??'';
        $prev=intval($_POST['PreviousStock']??0);
        $add =intval($_POST['AdditionalStock']??0);
        $less=intval($_POST['LessStock']??0);
        $stmt=@sqlsrv_query($conn,
            "UPDATE [dbo].[UniformStock] SET PreviousStock=?,AdditionalStock=?,LessStock=?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
            [$prev,$add,$less,$currentUser,$type,$size]);
        $messages[]=$stmt===false?['type'=>'danger','text'=>'Failed to update stock.']:['type'=>'success','text'=>"Stock updated: {$type} {$size}."];
    }
    $tab='stocks';
}

// ── POST: Save Released ────────────────────────────────────────
if (isset($_POST['save_released'])) {
    $emp =trim($_POST['EmployeeName']??''); $ut=trim($_POST['UniformType']??'');
    $us  =trim($_POST['UniformSize']??'');  $qty=intval($_POST['Quantity']??3);
    $dept=trim($_POST['Department']??'');   $dg=trim($_POST['DateGiven']??date('Y-m-d'));
    $rb  =trim($_POST['RequestedBy']??'');  $rem=trim($_POST['Remarks']??'');
    if (!$emp||!$ut||!$us) { $messages[]=['type'=>'danger','text'=>'Name, type and size are required.']; }
    else {
        $stmt=@sqlsrv_query($conn,
            "INSERT INTO [dbo].[UniformReleased](EmployeeName,UniformType,UniformSize,Quantity,Department,DateGiven,RequestedBy,Remarks,CreatedBy) VALUES(?,?,?,?,?,?,?,?,?)",
            [$emp,$ut,$us,$qty,$dept,$dg,$rb,$rem,$currentUser]);
        if($stmt!==false){
            @sqlsrv_query($conn,"UPDATE [dbo].[UniformStock] SET LessStock=LessStock+?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",[$qty,$currentUser,$ut,$us]);
            $messages[]=['type'=>'success','text'=>"Released {$qty}x {$ut} ({$us}) to {$emp}."];
        } else { $messages[]=['type'=>'danger','text'=>'Failed to save release.']; }
    }
    $tab='released';
}

// ── POST: Delete Released ──────────────────────────────────────
if (isset($_POST['delete_released'])) {
    $id=intval($_POST['ReleasedID']??0);
    $row=rq($conn,"SELECT UniformType,UniformSize,Quantity,RequestID FROM [dbo].[UniformReleased] WHERE ReleasedID=?",[$id]);
    if($id>0&&!empty($row)){
        $r0  = $row[0];
        $qty = intval($r0['Quantity']);
        $stmt=@sqlsrv_query($conn,"DELETE FROM [dbo].[UniformReleased] WHERE ReleasedID=?",[$id]);
        if($stmt!==false){
            @sqlsrv_query($conn,"UPDATE [dbo].[UniformStock] SET LessStock=LessStock-?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
                [$qty,$currentUser,$r0['UniformType'],$r0['UniformSize']]);
            $reqId = intval($r0['RequestID'] ?? 0);
            if($reqId > 0){
                @sqlsrv_query($conn,"UPDATE [dbo].[UniformRequests] SET IsGiven=0,DateGiven=NULL,GivenBy=NULL WHERE RequestID=?",[$reqId]);
                $messages[]=['type'=>'success','text'=>'Release deleted, stock restored, and request reverted to Pending.'];
            } else {
                $messages[]=['type'=>'success','text'=>'Release deleted and stock restored.'];
            }
        } else { $messages[]=['type'=>'danger','text'=>'Failed to delete.']; }
    }
    $tab='released';
}

// ── POST: Edit Released ────────────────────────────────────────
if (isset($_POST['edit_released'])) {
    $id   = intval($_POST['ReleasedID']  ?? 0);
    $emp  = trim($_POST['EmployeeName']  ?? '');
    $ut   = trim($_POST['UniformType']   ?? '');
    $us   = trim($_POST['UniformSize']   ?? '');
    $qty  = intval($_POST['Quantity']    ?? 3);
    $dept = trim($_POST['Department']    ?? '');
    $dg   = trim($_POST['DateGiven']     ?? date('Y-m-d'));
    $rb   = trim($_POST['RequestedBy']   ?? '');
    $rem  = trim($_POST['Remarks']       ?? '');
    if (!$id || !$emp || !$ut || !$us) {
        $messages[]=['type'=>'danger','text'=>'Name, type and size are required.'];
    } else {
        $old = rq($conn,"SELECT UniformType,UniformSize,Quantity FROM [dbo].[UniformReleased] WHERE ReleasedID=?",[$id]);
        $stmt = @sqlsrv_query($conn,
            "UPDATE [dbo].[UniformReleased] SET EmployeeName=?,UniformType=?,UniformSize=?,Quantity=?,Department=?,DateGiven=?,RequestedBy=?,Remarks=? WHERE ReleasedID=?",
            [$emp,$ut,$us,$qty,$dept,$dg,$rb,$rem,$id]);
        if ($stmt !== false) {
            if (!empty($old)) {
                @sqlsrv_query($conn,"UPDATE [dbo].[UniformStock] SET LessStock=LessStock-?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
                    [$old[0]['Quantity'],$currentUser,$old[0]['UniformType'],$old[0]['UniformSize']]);
            }
            @sqlsrv_query($conn,"UPDATE [dbo].[UniformStock] SET LessStock=LessStock+?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
                [$qty,$currentUser,$ut,$us]);
            $messages[]=['type'=>'success','text'=>"Record updated for {$emp}."];
        } else {
            $messages[]=['type'=>'danger','text'=>'Failed to update record.'];
        }
    }
    $tab = 'released';
}

// ── POST: Requests ─────────────────────────────────────────────
if (isset($_POST['save_request'])) {
    $rb  = trim($_POST['RequestedBy']   ?? '');
    $ut  = trim($_POST['UniformType']   ?? '');
    $us  = trim($_POST['UniformSize']   ?? '');
    $qty = intval($_POST['Quantity']    ?? 3);
    $emp = trim($_POST['EmployeeName']  ?? '');
    $dept= trim($_POST['Department']    ?? '');
    $rem = trim($_POST['Remarks']       ?? '');

    // ── Fix: pass date as a proper sqlsrv typed param ──
    $drRaw = trim($_POST['DateRequested'] ?? date('Y-m-d'));
    $dr    = !empty($drRaw) ? $drRaw : date('Y-m-d');

    if (!$rb || !$ut || !$us) {
        $messages[] = ['type'=>'danger','text'=>'Requested by, type and size are required.'];
    } else {
        $params = [
            [$emp,  SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(255)],
            [$rb,   SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(255)],
            [$ut,   SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(50)],
            [$us,   SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(20)],
            [$qty,  SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_INT],
            [$dept, SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(100)],
            [$dr,   SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_DATE],
            [$rem,  SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(500)],
            [$currentUser, SQLSRV_PARAM_IN, null, SQLSRV_SQLTYPE_NVARCHAR(255)],
        ];

        $stmt = sqlsrv_query($conn,
            "INSERT INTO [dbo].[UniformRequests]
                (EmployeeName, RequestedBy, UniformType, UniformSize, Quantity,
                 Department, DateRequested, Remarks, CreatedBy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            $params);

        if ($stmt === false) {
            $errors  = sqlsrv_errors();
            $errText = '';
            if ($errors) foreach ($errors as $e) $errText .= '[' . $e['code'] . '] ' . $e['message'] . ' ';
            $messages[] = ['type'=>'danger','text'=>'Failed to save request: ' . trim($errText)];
        } else {
            $messages[] = ['type'=>'success','text'=>"Request added: {$qty}x {$ut} ({$us}) for {$emp}."];
        }
    }
    $tab = 'requests';
}

if (isset($_POST['mark_given'])) {
    $id=intval($_POST['RequestID']??0);
    if($id>0){
        $req=rq($conn,"SELECT * FROM [dbo].[UniformRequests] WHERE RequestID=?",[$id]);
        if(!empty($req)){
            $req=$req[0];
            $qty     = intval($req['Quantity']);
            $empName = trim($req['EmployeeName'] ?? '');
            if($empName === '') $empName = 'From Request #'.$id;
            $stockRow = rq($conn,
                "SELECT (PreviousStock + AdditionalStock - LessStock) AS CurrentStock
                 FROM [dbo].[UniformStock] WHERE UniformType=? AND Size=?",
                [$req['UniformType'], $req['UniformSize']]);
            $currentStock = intval($stockRow[0]['CurrentStock'] ?? 0);
            if ($currentStock < $qty) {
                $messages[]=['type'=>'danger','text'=>
                    "⚠️ Insufficient stock! Requested: {$qty} pcs of {$req['UniformType']} ({$req['UniformSize']}), ".
                    "but only {$currentStock} pcs available. Request NOT processed."];
            } else {
                @sqlsrv_query($conn,"UPDATE [dbo].[UniformRequests] SET IsGiven=1,DateGiven=CAST(GETDATE() AS DATE),GivenBy=? WHERE RequestID=?",[$currentUser,$id]);
                $relStmt=@sqlsrv_query($conn,
                    "INSERT INTO [dbo].[UniformReleased](EmployeeName,UniformType,UniformSize,Quantity,Department,DateGiven,RequestedBy,Remarks,CreatedBy,RequestID)
                     VALUES(?,?,?,?,?,CAST(GETDATE() AS DATE),?,?,?,?)",
                    [$empName,$req['UniformType'],$req['UniformSize'],$qty,$req['Department']??'',$req['RequestedBy']??'',$req['Remarks']??'',$currentUser,$id]);
                if($relStmt!==false){
                    @sqlsrv_query($conn,"UPDATE [dbo].[UniformStock] SET LessStock=LessStock+?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
                        [$qty,$currentUser,$req['UniformType'],$req['UniformSize']]);
                    $messages[]=['type'=>'success','text'=>"✅ Marked as Given — {$qty}x {$req['UniformType']} ({$req['UniformSize']}) released to {$empName}."];
                } else {
                    @sqlsrv_query($conn,"UPDATE [dbo].[UniformRequests] SET IsGiven=0,DateGiven=NULL,GivenBy=NULL WHERE RequestID=?",[$id]);
                    $messages[]=['type'=>'danger','text'=>'Failed to create release record. Request reverted to Pending.'];
                }
            }
        } else {
            $messages[]=['type'=>'danger','text'=>'Request not found.'];
        }
    }
    $tab='requests';
}

if (isset($_POST['delete_request'])) {
    $id=intval($_POST['RequestID']??0);
    if($id>0){ $stmt=@sqlsrv_query($conn,"DELETE FROM [dbo].[UniformRequests] WHERE RequestID=?",[$id]);
        $messages[]=$stmt===false?['type'=>'danger','text'=>'Failed to delete.']:['type'=>'success','text'=>'Request deleted.']; }
    $tab='requests';
}

// ── POST: PO ───────────────────────────────────────────────────
if (isset($_POST['save_po'])) {
    $poNum  = trim($_POST['PONumber'] ?? '');
    $poDate = trim($_POST['PODate']   ?? date('Y-m-d'));
    $rem    = trim($_POST['Remarks']  ?? '');

    if (!$poNum) {
        $messages[] = ['type'=>'danger','text'=>'PO Number is required.'];
    } else {
        $sql  = "INSERT INTO [dbo].[UniformPO](PONumber,PODate,Supplier,Remarks,CreatedBy)
                 OUTPUT INSERTED.POID
                 VALUES(?,?,?,?,?)";
        $stmt = @sqlsrv_query($conn, $sql, [$poNum, $poDate, '', $rem, $currentUser]);

        if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
            $newPID = intval($row['POID']);
            sqlsrv_free_stmt($stmt);

            foreach ($uTypes as $ut) {
                foreach ($sizes as $sz) {
                    $r = intval($_POST["req_{$ut}_{$sz}"] ?? 0);
                    $a = intval($_POST["add_{$ut}_{$sz}"] ?? 0);
                    if ($r > 0 || $a > 0) {
                        @sqlsrv_query($conn,
                            "INSERT INTO [dbo].[UniformPOItems](POID,UniformType,Size,Requested,Additional)
                             VALUES(?,?,?,?,?)",
                            [$newPID, $ut, $sz, $r, $a]);
                    }
                }
            }
            $messages[] = ['type'=>'success','text'=>"PO {$poNum} saved."];
        } else {
            $err = sqlsrv_errors();
            $messages[] = ['type'=>'danger','text'=>'Failed to save PO. ' . ($err[0]['message'] ?? '')];
        }
    }
    $tab = 'po';
}

// ── POST: Delete PO ───────────────────────────────────────────
if (isset($_POST['delete_po'])) {
    $id = intval($_POST['POID'] ?? 0);
    if ($id > 0) {
        @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformPOItems] WHERE POID=?", [$id]);
        $recRows = rq($conn, "SELECT RFID FROM [dbo].[UniformReceiving] WHERE POID=?", [$id]);
        foreach ($recRows as $rr) {
            @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformReceivingItems] WHERE RFID=?", [intval($rr['RFID'])]);
        }
        @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformReceiving] WHERE POID=?", [$id]);
        $stmt = @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformPO] WHERE POID=?", [$id]);
        $messages[] = $stmt !== false
            ? ['type' => 'success', 'text' => 'PO deleted successfully.']
            : ['type' => 'danger',  'text' => 'Failed to delete PO. ' . (sqlsrv_errors()[0]['message'] ?? '')];
    }
    $tab = 'po';
}

// ── POST: Save Receiving ───────────────────────────────────────
if (isset($_POST['save_receiving'])) {
    $poid      = intval($_POST['POID_REC']??0);
    $dateRec   = trim($_POST['DateReceived']??date('Y-m-d'));
    $printShop = trim($_POST['PrintingShop']??'');
    $printRep  = trim($_POST['PrintingShopRep']??'');
    $utcRep    = trim($_POST['UTCRep']??'');
    $recType   = trim($_POST['ReceivingUniformType']??'TSHIRT');

    if(!$poid){ $messages[]=['type'=>'danger','text'=>'Please select a PO.']; }
    else {
        $existRec = rq($conn,
            "SELECT RFID FROM [dbo].[UniformReceiving] WHERE POID=? AND UniformType=?",
            [$poid,$recType]);

        if(!empty($existRec)){
            // ── UPDATE existing record ──────────────────────────────
            $recId = intval($existRec[0]['RFID']);
            @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformReceiving]
                 SET RFDate=?,DateReceived=?,PrintingShop=?,PrintShop=?,RepresentativeThem=?,RepresentativeUs=?,UniformType=?
                 WHERE RFID=?",
                [$dateRec,$dateRec,$printShop,$printShop,$printRep,$utcRep,$recType,$recId]);
        } else {
            // ── INSERT new record ───────────────────────────────────
            // Generate RFNumber from PO Number
          $poNumRow = rq($conn, "SELECT PONumber FROM [dbo].[UniformPO] WHERE POID=?", [$poid]);
          $poNumStr = $poNumRow[0]['PONumber'] ?? 'PO';
          $rfNumber = 'RF-' . preg_replace('/[^A-Z0-9]/i', '', $poNumStr) . '-' . date('YmdHis');

          $insStmt = sqlsrv_query($conn,
              "INSERT INTO [dbo].[UniformReceiving]
                  (POID,RFNumber,RFDate,DateReceived,PrintingShop,PrintShop,RepresentativeThem,RepresentativeUs,UniformType,CreatedBy,CreatedAt)
              OUTPUT INSERTED.RFID
              VALUES(?,?,?,?,?,?,?,?,?,?,GETDATE())",
              [$poid,$rfNumber,$dateRec,$dateRec,$printShop,$printShop,$printRep,$utcRep,$recType,$currentUser]);

            $recId = 0;
            if($insStmt !== false && ($ridRow = sqlsrv_fetch_array($insStmt, SQLSRV_FETCH_ASSOC))){
                $recId = intval($ridRow['RFID']);
                sqlsrv_free_stmt($insStmt);
            } else {
                $errors = sqlsrv_errors();
                $errMsg = '';
                if($errors) foreach($errors as $e) $errMsg .= $e['message'].' ';
                $messages[]=['type'=>'danger','text'=>'Insert failed: '.trim($errMsg)];
            }
        }

        if($recId>0){
            foreach($sizes as $sz){
                $qtyRec = intval($_POST["rec_{$recType}_{$sz}"]??0);
                $exist  = rq($conn,
                    "SELECT RFItemID FROM [dbo].[UniformReceivingItems]
                     WHERE RFID=? AND UniformType=? AND Size=?",
                    [$recId,$recType,$sz]);
                if(!empty($exist)){
                    @sqlsrv_query($conn,
                        "UPDATE [dbo].[UniformReceivingItems] SET Quantity=? WHERE RFItemID=?",
                        [$qtyRec,intval($exist[0]['RFItemID'])]);
                } else {
                    @sqlsrv_query($conn,
                        "INSERT INTO [dbo].[UniformReceivingItems](RFID,UniformType,Size,Quantity)
                         VALUES(?,?,?,?)",
                        [$recId,$recType,$sz,$qtyRec]);
                }
            }
            $messages[]=['type'=>'success','text'=>'Receiving record saved successfully.'];
        }
    }
    $tab='receiving';
}

// ── POST: Delete Receiving ─────────────────────────────────────
if (isset($_POST['delete_receiving'])) {
    $id=intval($_POST['ReceivingID']??0);
    if($id>0){
        // Safety check: prevent deletion of a posted record
        $chk = rq($conn,"SELECT IsPosted FROM [dbo].[UniformReceiving] WHERE RFID=?",[$id]);
        if(!empty($chk) && intval($chk[0]['IsPosted']??0)===1){
            $messages[]=['type'=>'danger','text'=>'Cannot delete a posted receiving record. Un-post it first before deleting.'];
        } else {
            @sqlsrv_query($conn,"DELETE FROM [dbo].[UniformReceivingItems] WHERE RFID=?",[$id]);
            $stmt=@sqlsrv_query($conn,"DELETE FROM [dbo].[UniformReceiving] WHERE RFID=?",[$id]);
            $messages[]=$stmt!==false
                ?['type'=>'success','text'=>'Receiving record deleted.']
                :['type'=>'danger','text'=>'Failed to delete receiving record.'];
        }
    }
    $tab='receiving';
}

// ── POST: Post Receiving to Stocks ────────────────────────────
if (isset($_POST['post_to_stocks'])) {
    $id = intval($_POST['ReceivingID'] ?? 0);
    if ($id > 0) {
        // Verify record exists and is not already posted
        $recRow = rq($conn,
            "SELECT r.RFID, r.UniformType, r.IsPosted
             FROM [dbo].[UniformReceiving] r WHERE r.RFID=?", [$id]);
        if (empty($recRow)) {
            $messages[] = ['type'=>'danger','text'=>'Receiving record not found.'];
        } elseif (intval($recRow[0]['IsPosted'] ?? 0) === 1) {
            $messages[] = ['type'=>'danger','text'=>'This record has already been posted to stocks.'];
        } else {
            $recItems = rq($conn,
                "SELECT UniformType, Size, Quantity FROM [dbo].[UniformReceivingItems] WHERE RFID=?",
                [$id]);
            $allOk = true;
            foreach ($recItems as $item) {
                $ut  = $item['UniformType'];
                $sz  = $item['Size'];
                $qty = intval($item['Quantity']);
                if ($qty <= 0) continue;
                $upd = @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformStock]
                     SET AdditionalStock = AdditionalStock + ?,
                         UpdatedAt = GETDATE(),
                         UpdatedBy = ?
                     WHERE UniformType = ? AND Size = ?",
                    [$qty, $currentUser, $ut, $sz]);
                if ($upd === false) { $allOk = false; break; }
            }
            if ($allOk) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformReceiving]
                     SET IsPosted=1, PostedAt=GETDATE(), PostedBy=?
                     WHERE RFID=?",
                    [$currentUser, $id]);
                $messages[] = ['type'=>'success','text'=>'Receiving record successfully posted to stocks.'];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to update stock for one or more sizes. No changes were committed.'];
            }
        }
    }
    $tab = 'receiving';
}

// ── POST: Un-post Receiving from Stocks ───────────────────────
if (isset($_POST['unpost_from_stocks'])) {
    $id = intval($_POST['ReceivingID'] ?? 0);
    if ($id > 0) {
        $recRow = rq($conn,
            "SELECT RFID, UniformType, IsPosted
             FROM [dbo].[UniformReceiving] WHERE RFID=?", [$id]);
        if (empty($recRow)) {
            $messages[] = ['type'=>'danger','text'=>'Receiving record not found.'];
        } elseif (intval($recRow[0]['IsPosted'] ?? 0) === 0) {
            $messages[] = ['type'=>'danger','text'=>'This record has not been posted yet.'];
        } else {
            $recItems = rq($conn,
                "SELECT UniformType, Size, Quantity FROM [dbo].[UniformReceivingItems] WHERE RFID=?",
                [$id]);
            $allOk = true;
            foreach ($recItems as $item) {
                $ut  = $item['UniformType'];
                $sz  = $item['Size'];
                $qty = intval($item['Quantity']);
                if ($qty <= 0) continue;
                $upd = @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformStock]
                     SET AdditionalStock = AdditionalStock - ?,
                         UpdatedAt = GETDATE(),
                         UpdatedBy = ?
                     WHERE UniformType = ? AND Size = ?",
                    [$qty, $currentUser, $ut, $sz]);
                if ($upd === false) { $allOk = false; break; }
            }
            if ($allOk) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformReceiving]
                     SET IsPosted=0, PostedAt=NULL, PostedBy=NULL
                     WHERE RFID=?",
                    [$id]);
                $messages[] = ['type'=>'success','text'=>'Receiving record un-posted. Stock has been reversed.'];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to reverse stock. No changes were committed.'];
            }
        }
    }
    $tab = 'receiving';
}

// ── POST: Save Return ─────────────────────────────────────────
// NOTE: Returns no longer touch stock at submission time. Every
// return lands as "Pending Inspection" — a uniform only becomes
// available again (or moves to cleaning/repair or gets written off)
// once a stock manager inspects it via inspect_return below.
if (isset($_POST['save_return'])) {
    $emp    = trim($_POST['ReturnEmployeeName'] ?? '');
    $ut     = trim($_POST['ReturnUniformType']  ?? '');
    $us     = trim($_POST['ReturnUniformSize']  ?? '');
    $qty    = intval($_POST['ReturnQuantity']   ?? 1);
    $dept   = trim($_POST['ReturnDepartment']   ?? '');
    $dr     = trim($_POST['DateReturned']       ?? date('Y-m-d'));
    // Reported condition is descriptive only — it does NOT decide stock
    // placement. The inspector decides that separately after looking
    // at the actual item.
    $condOptions = ['Good','Faded','Stained','Torn','Other'];
    $cond   = in_array($_POST['Condition'] ?? '', $condOptions) ? $_POST['Condition'] : 'Good';
    $rto    = trim($_POST['ReturnedTo']         ?? '');
    $rem    = trim($_POST['ReturnRemarks']      ?? '');
    $relId  = intval($_POST['ReturnReleasedID'] ?? 0);

    if (!$emp || !$ut || !$us) {
        $messages[] = ['type'=>'danger','text'=>'Employee name, type and size are required.'];
    } else {
        $stmt = @sqlsrv_query($conn,
            "INSERT INTO [dbo].[UniformReturns]
                (ReleasedID,EmployeeName,UniformType,UniformSize,Quantity,Department,DateReturned,Condition,InspectionStatus,ReturnedTo,Remarks,CreatedBy)
             VALUES(?,?,?,?,?,?,?,?,'Pending Inspection',?,?,?)",
            [$relId ?: null, $emp, $ut, $us, $qty, $dept, $dr, $cond, $rto, $rem, $currentUser]);
        if ($stmt !== false) {
            $messages[] = ['type'=>'success','text'=>"Return recorded: {$qty}x {$ut} ({$us}) from {$emp}. Awaiting inspection — stock not yet updated."];
        } else {
            $messages[] = ['type'=>'danger','text'=>'Failed to save return.'];
        }
    }
    $tab = 'returns';
}

// ── POST: Inspect Return (stock managers only) ─────────────────
// Moves a "Pending Inspection" return into exactly one of:
//   Returned          → UniformStock.ReturnedStock (HELD — confirmed
//                        good, but NOT counted as Available yet)
//   Cleaning/Repair    → UniformStock.CleaningStock (held, not available)
//   Disposed           → UniformStock.DisposedStock (permanently removed)
// Note: "Returned" still needs a separate "Add to Stock" action before
// it counts toward Available — see add_to_stock below.
if (isset($_POST['inspect_return'])) {
    $id       = intval($_POST['ReturnID'] ?? 0);
    $decision = $_POST['Decision'] ?? '';
    $validDecisions = ['Returned'=>'ReturnedStock','Cleaning/Repair'=>'CleaningStock','Disposed'=>'DisposedStock'];
    if (!$canManageStock) {
        $messages[] = ['type'=>'danger','text'=>'You do not have permission to inspect returns.'];
    } elseif ($id<=0 || !isset($validDecisions[$decision])) {
        $messages[] = ['type'=>'danger','text'=>'Invalid inspection request.'];
    } else {
        $row = rq($conn,"SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?",[$id]);
        if (empty($row)) {
            $messages[] = ['type'=>'danger','text'=>'Return record not found.'];
        } elseif (($row[0]['InspectionStatus'] ?? '') !== 'Pending Inspection') {
            $messages[] = ['type'=>'danger','text'=>'This return has already been inspected.'];
        } else {
            $r0 = $row[0];
            $col = $validDecisions[$decision];
            $upd = @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformStock] SET {$col}={$col}+?, UpdatedAt=GETDATE(), UpdatedBy=? WHERE UniformType=? AND Size=?",
                [intval($r0['Quantity']), $currentUser, $r0['UniformType'], $r0['UniformSize']]);
            if ($upd !== false) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformReturns] SET InspectionStatus=?, InspectedBy=?, InspectedAt=GETDATE() WHERE ReturnID=?",
                    [$decision, $currentUser, $id]);
                $inspectMsg = $decision === 'Returned'
                    ? "Inspected: {$r0['Quantity']}x {$r0['UniformType']} ({$r0['UniformSize']}) confirmed Returned — still needs Add to Stock to count as Available."
                    : "Inspected: {$r0['Quantity']}x {$r0['UniformType']} ({$r0['UniformSize']}) marked {$decision}.";
                $messages[] = ['type'=>'success','text'=>$inspectMsg];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to update stock during inspection.'];
            }
        }
    }
    $tab = 'returns';
}

// ── POST: Complete Cleaning/Repair (stock managers only) ────────
// Moves a previously "Cleaning/Repair" return into the SAME "Returned"
// holding bucket as a fresh good return — it still needs a separate
// Add to Stock action before it counts as Available. This keeps one
// consistent final gate no matter which path an item took.
if (isset($_POST['complete_cleaning'])) {
    $id = intval($_POST['ReturnID'] ?? 0);
    if (!$canManageStock) {
        $messages[] = ['type'=>'danger','text'=>'You do not have permission to update this record.'];
    } elseif ($id<=0) {
        $messages[] = ['type'=>'danger','text'=>'Invalid request.'];
    } else {
        $row = rq($conn,"SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?",[$id]);
        if (empty($row)) {
            $messages[] = ['type'=>'danger','text'=>'Return record not found.'];
        } elseif (($row[0]['InspectionStatus'] ?? '') !== 'Cleaning/Repair') {
            $messages[] = ['type'=>'danger','text'=>'This item is not currently in cleaning/repair.'];
        } else {
            $r0  = $row[0];
            $qty = intval($r0['Quantity']);
            $upd = @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformStock]
                 SET CleaningStock=CleaningStock-?, ReturnedStock=ReturnedStock+?, UpdatedAt=GETDATE(), UpdatedBy=?
                 WHERE UniformType=? AND Size=?",
                [$qty, $qty, $currentUser, $r0['UniformType'], $r0['UniformSize']]);
            if ($upd !== false) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformReturns] SET InspectionStatus='Returned', InspectedBy=?, InspectedAt=GETDATE() WHERE ReturnID=?",
                    [$currentUser, $id]);
                $messages[] = ['type'=>'success','text'=>"{$qty}x {$r0['UniformType']} ({$r0['UniformSize']}) repaired — moved to Returned, still needs Add to Stock to count as Available."];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to update stock.'];
            }
        }
    }
    $tab = 'returns';
}

// ── POST: Add to Stock (stock managers only) ────────────────────
// The final gate: moves a "Returned" (held) item into actual Available
// stock. This is the ONLY action that increments ReturnStock, which is
// the only return-related column counted in the CurrentStock formula.
if (isset($_POST['add_to_stock'])) {
    $id = intval($_POST['ReturnID'] ?? 0);
    if (!$canManageStock) {
        $messages[] = ['type'=>'danger','text'=>'You do not have permission to update stock.'];
    } elseif ($id<=0) {
        $messages[] = ['type'=>'danger','text'=>'Invalid request.'];
    } else {
        $row = rq($conn,"SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?",[$id]);
        if (empty($row)) {
            $messages[] = ['type'=>'danger','text'=>'Return record not found.'];
        } elseif (($row[0]['InspectionStatus'] ?? '') !== 'Returned') {
            $messages[] = ['type'=>'danger','text'=>'This item is not in the Returned holding bucket.'];
        } else {
            $r0  = $row[0];
            $qty = intval($r0['Quantity']);
            $upd = @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformStock]
                 SET ReturnedStock=ReturnedStock-?, ReturnStock=ReturnStock+?, UpdatedAt=GETDATE(), UpdatedBy=?
                 WHERE UniformType=? AND Size=?",
                [$qty, $qty, $currentUser, $r0['UniformType'], $r0['UniformSize']]);
            if ($upd !== false) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformReturns] SET InspectionStatus='Stocked', InspectedBy=?, InspectedAt=GETDATE() WHERE ReturnID=?",
                    [$currentUser, $id]);
                $messages[] = ['type'=>'success','text'=>"{$qty}x {$r0['UniformType']} ({$r0['UniformSize']}) added to stock — now counted as Available."];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to update stock.'];
            }
        }
    }
    $tab = 'returns';
}

// ── POST: Delete Return ───────────────────────────────────────
if (isset($_POST['delete_return'])) {
    $id = intval($_POST['ReturnID'] ?? 0);
    if (!$canManageStock) {
        $messages[] = ['type'=>'danger','text'=>'You do not have permission to delete return records.'];
    } elseif ($id > 0) {
        $row = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
        if (!empty($row)) {
            $r0     = $row[0];
            $qty    = intval($r0['Quantity']);
            $status = $r0['InspectionStatus'] ?? 'Pending Inspection';
            // Only reverse stock if this return had already moved into a bucket.
            $bucketCol = ['Returned'=>'ReturnedStock','Cleaning/Repair'=>'CleaningStock','Disposed'=>'DisposedStock','Stocked'=>'ReturnStock'][$status] ?? null;
            $stmt = @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
            if ($stmt !== false) {
                if ($bucketCol !== null) {
                    @sqlsrv_query($conn,
                        "UPDATE [dbo].[UniformStock] SET {$bucketCol}={$bucketCol}-?, UpdatedAt=GETDATE(), UpdatedBy=? WHERE UniformType=? AND Size=?",
                        [$qty, $currentUser, $r0['UniformType'], $r0['UniformSize']]);
                }
                $messages[] = ['type'=>'success','text'=>'Return deleted' . ($bucketCol!==null ? ' and stock reversed.' : ' (was still pending inspection — no stock change needed).')];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to delete return.'];
            }
        }
    }
    $tab = 'returns';
}

// ── POST: Edit Return ─────────────────────────────────────────
// While a return is still "Pending Inspection", every field is
// editable — nothing is in stock yet so there's nothing to reverse.
// Once it has been inspected (Returned/Cleaning/Repair/Disposed/Stocked),
// only descriptive fields can change — type/size/qty are locked
// because editing them would require unwinding whichever stock
// bucket it already moved into. Use Delete + re-add for that instead.
if (isset($_POST['edit_return'])) {
    $id   = intval($_POST['ReturnID']          ?? 0);
    $emp  = trim($_POST['ReturnEmployeeName']  ?? '');
    $ut   = trim($_POST['ReturnUniformType']   ?? '');
    $us   = trim($_POST['ReturnUniformSize']   ?? '');
    $qty  = intval($_POST['ReturnQuantity']    ?? 1);
    $dept = trim($_POST['ReturnDepartment']    ?? '');
    $dr   = trim($_POST['DateReturned']        ?? date('Y-m-d'));
    $condOptions = ['Good','Faded','Stained','Torn','Other'];
    $cond = in_array($_POST['Condition'] ?? '', $condOptions) ? $_POST['Condition'] : 'Good';
    $rto  = trim($_POST['ReturnedTo']          ?? '');
    $rem  = trim($_POST['ReturnRemarks']       ?? '');
    if (!$id || !$emp || !$ut || !$us) {
        $messages[] = ['type'=>'danger','text'=>'Name, type and size are required.'];
    } else {
        $old = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
        if (empty($old)) {
            $messages[] = ['type'=>'danger','text'=>'Return record not found.'];
        } else {
            $isPending = ($old[0]['InspectionStatus'] ?? 'Pending Inspection') === 'Pending Inspection';
            if (!$isPending) {
                // Lock type/size/qty to whatever was originally inspected.
                $ut  = $old[0]['UniformType'];
                $us  = $old[0]['UniformSize'];
                $qty = intval($old[0]['Quantity']);
            }
            $stmt = @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformReturns]
                 SET EmployeeName=?,UniformType=?,UniformSize=?,Quantity=?,Department=?,
                     DateReturned=?,Condition=?,ReturnedTo=?,Remarks=?
                 WHERE ReturnID=?",
                [$emp, $ut, $us, $qty, $dept, $dr, $cond, $rto, $rem, $id]);
            if ($stmt !== false) {
                $messages[] = ['type'=>'success','text'=>"Return updated for {$emp}." . (!$isPending ? ' (type/size/qty locked — already inspected)' : '')];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to update return.'];
            }
        }
    }
    $tab = 'returns';
}

// ── FETCH ──────────────────────────────────────────────────────
$sizeOrder = "CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4 WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END";
$stocks = rq($conn,"SELECT * FROM [dbo].[vw_UniformStock] ORDER BY UniformType DESC, {$sizeOrder}");
$stockMap  = [];
foreach ($stocks as $s) $stockMap[$s['UniformType']][$s['Size']] = $s;
$totalStock = ['TSHIRT'=>0,'POLOSHIRT'=>0];
foreach ($stocks as $s) $totalStock[$s['UniformType']] += max(0,intval($s['CurrentStock']));

// ── Released ──────────────────────────────────────────────────
$relSearch = trim($_GET['rsearch']??'');
$relUType  = trim($_GET['reltype']??'');
$relDept   = trim($_GET['reldept']??'');
$relReqBy  = trim($_GET['relreqby']??'');
$relSize   = trim($_GET['relsize']??'');
$relDateFrom = trim($_GET['reldatefrom']??'');
$relDateTo   = trim($_GET['reldateto']??'');

// Distinct "Requested By" values already on record, for the filter dropdown
$reqByRows = rq($conn,"SELECT DISTINCT RequestedBy FROM [dbo].[UniformReleased] WHERE RequestedBy IS NOT NULL AND RequestedBy <> '' ORDER BY RequestedBy ASC");
$reqByList = array_map(fn($r)=>$r['RequestedBy'], $reqByRows);

if (!in_array($relUType, ['TSHIRT','POLOSHIRT'])) $relUType = '';
if (!in_array($relDept, $depts))                   $relDept  = '';
if (!in_array($relSize, $sizes))                    $relSize  = '';
if (!in_array($relReqBy, $reqByList, true))          $relReqBy = '';
if ($relDateFrom !== '' && !strtotime($relDateFrom)) $relDateFrom = '';
if ($relDateTo   !== '' && !strtotime($relDateTo))   $relDateTo   = '';

// If dept-scoped user, force their department
$effectiveRelDept = $deptScope !== '' ? $deptScope : $relDept;

$relConditions = [];
if ($relSearch !== '') {
    $s = str_replace("'","''",$relSearch);
    $relConditions[] = "(EmployeeName LIKE '%{$s}%' OR RequestedBy LIKE '%{$s}%')";
}
if ($relUType !== '') $relConditions[] = "UniformType = '" . str_replace("'","''",$relUType) . "'";
if ($effectiveRelDept !== '') $relConditions[] = "Department = '" . str_replace("'","''",$effectiveRelDept) . "'";
if ($relReqBy !== '') $relConditions[] = "RequestedBy = '" . str_replace("'","''",$relReqBy) . "'";
if ($relSize !== '') $relConditions[] = "UniformSize = '" . str_replace("'","''",$relSize) . "'";
if ($relDateFrom !== '') $relConditions[] = "DateGiven >= '" . date('Y-m-d',strtotime($relDateFrom)) . "'";
if ($relDateTo   !== '') $relConditions[] = "DateGiven <= '" . date('Y-m-d',strtotime($relDateTo)) . "'";
$relWhere = !empty($relConditions) ? 'WHERE ' . implode(' AND ', $relConditions) : '';

$relAll    = rq($conn,"SELECT * FROM [dbo].[UniformReleased] {$relWhere} ORDER BY DateGiven DESC, CreatedAt DESC");
$relTotal  = count($relAll);
$relPages  = max(1,(int)ceil($relTotal/20));
$relPage   = max(1,min((int)($_GET['relpage']??1),$relPages));
$released  = array_slice($relAll,($relPage-1)*20,20);

// Total given count respects dept scope
$totalGivenWhere = $deptScope !== '' ? "WHERE Department = '" . str_replace("'","''",$deptScope) . "'" : '';
$totalGiven = rq($conn,"SELECT ISNULL(SUM(Quantity),0) AS Total FROM [dbo].[UniformReleased] {$totalGivenWhere}");
$totalGivenCount = intval($totalGiven[0]['Total']??0);

// ── Requests ──────────────────────────────────────────────────
// Status tab: 'pending' (default) or 'given'
$reqStatus  = ($_GET['rstatus'] ?? 'pending') === 'given' ? 'given' : 'pending';
$reqUType   = trim($_GET['rutype'] ?? '');
$reqDept    = trim($_GET['rdept']  ?? '');

// Validate uniform type and department against known values
if (!in_array($reqUType, ['TSHIRT','POLOSHIRT'])) $reqUType = '';
if (!in_array($reqDept,  $depts))                  $reqDept  = '';

// If dept-scoped, force their dept (overrides any filter selection)
$effectiveReqDept = $deptScope !== '' ? $deptScope : $reqDept;

$reqConditions = ["r.IsGiven = " . ($reqStatus === 'given' ? '1' : '0')];
if ($reqUType !== '') $reqConditions[] = "r.UniformType = '" . str_replace("'","''",$reqUType) . "'";
if ($effectiveReqDept !== '') $reqConditions[] = "r.Department = '" . str_replace("'","''",$effectiveReqDept) . "'";
$reqWhere = 'WHERE ' . implode(' AND ', $reqConditions);

$reqAll    = rq($conn,
    "SELECT r.*, ISNULL(s.PreviousStock+s.AdditionalStock+s.ReturnStock-s.LessStock,0) AS CurrentStock
     FROM [dbo].[UniformRequests] r
     LEFT JOIN [dbo].[UniformStock] s ON s.UniformType=r.UniformType AND s.Size=r.UniformSize
     {$reqWhere} ORDER BY r.DateRequested DESC");
$reqTotal  = count($reqAll);
$reqPages  = max(1,(int)ceil($reqTotal/20));
$reqPage   = max(1,min((int)($_GET['reqpage']??1),$reqPages));
$requests  = array_slice($reqAll,($reqPage-1)*20,20);

// Counts for tab badges — respect dept scope
$badgeDeptAnd = $deptScope !== '' ? " AND Department = '" . str_replace("'","''",$deptScope) . "'" : '';
$reqPendingTotal = rq($conn,"SELECT COUNT(*) AS N FROM [dbo].[UniformRequests] WHERE IsGiven=0{$badgeDeptAnd}");
$reqGivenTotal   = rq($conn,"SELECT COUNT(*) AS N FROM [dbo].[UniformRequests] WHERE IsGiven=1{$badgeDeptAnd}");
$reqPendingCount = intval($reqPendingTotal[0]['N'] ?? 0);
$reqGivenCount   = intval($reqGivenTotal[0]['N']   ?? 0);

// ── PO ────────────────────────────────────────────────────────
$poAll   = rq($conn,"SELECT p.*,(SELECT COUNT(*) FROM [dbo].[UniformPOItems] i WHERE i.POID=p.POID) AS ItemCount FROM [dbo].[UniformPO] p ORDER BY PODate DESC");
$poTotal = count($poAll);
$poPages = max(1,(int)ceil($poTotal/20));
$poPage  = max(1,min((int)($_GET['popage']??1),$poPages));
$poList  = array_slice($poAll,($poPage-1)*20,20);

// ── Auto-increment PO Number ───────────────────────────────────
$lastPO  = rq($conn,"SELECT TOP 1 PONumber FROM [dbo].[UniformPO] ORDER BY POID DESC");
$nextPONum = 'PO-'.date('Y').'-001';
if(!empty($lastPO)){
    preg_match('/(\d+)$/',$lastPO[0]['PONumber'],$m);
    if(!empty($m[1])) $nextPONum='PO-'.date('Y').'-'.str_pad(intval($m[1])+1,3,'0',STR_PAD_LEFT);
}

// ── Aggregate pending requests by type+size ────────────────────
$pendingReqRaw = rq($conn,"SELECT UniformType,UniformSize,SUM(Quantity) AS TotalQty FROM [dbo].[UniformRequests] WHERE IsGiven=0 GROUP BY UniformType,UniformSize");
$pendingReqMap = [];
foreach($pendingReqRaw as $pr) $pendingReqMap[$pr['UniformType']][$pr['UniformSize']] = intval($pr['TotalQty']);

// ── Receiving list ─────────────────────────────────────────────
// FIX: Use correct column names — RFDate, RepresentativeThem, RepresentativeUs
$recAll  = rq($conn,
    "SELECT r.RFID, r.POID, r.RFDate, r.DateReceived, r.PrintingShop,
            r.RepresentativeThem, r.RepresentativeUs, r.UniformType,
            r.CreatedBy, r.CreatedAt, p.PONumber,
            r.IsPosted, r.PostedAt, r.PostedBy
     FROM [dbo].[UniformReceiving] r
     LEFT JOIN [dbo].[UniformPO] p ON p.POID=r.POID
     ORDER BY r.RFDate DESC, r.CreatedAt DESC");
$recTotal= count($recAll);
$recPages= max(1,(int)ceil($recTotal/20));
$recPage = max(1,min((int)($_GET['recpage']??1),$recPages));
$recList = array_slice($recAll,($recPage-1)*20,20);

// POs for receiving form dropdown
$poForReceiving = rq($conn,"SELECT p.POID,p.PONumber,p.PODate FROM [dbo].[UniformPO] p ORDER BY p.PODate DESC");

// ── If editing a receiving record ──────────────────────────────
$editRecId  = intval($_GET['editrecid']??0);
$editRecRow = [];
$editRecItems = [];
if($editRecId>0 && $tab==='receiving'){
    $tmp=rq($conn,"SELECT * FROM [dbo].[UniformReceiving] WHERE RFID=?",[$editRecId]);
    if(!empty($tmp)){
        $editRecRow=$tmp[0];
        $items=rq($conn,"SELECT * FROM [dbo].[UniformReceivingItems] WHERE RFID=?",[$editRecId]);
        foreach($items as $it) $editRecItems[$it['UniformType']][$it['Size']]=intval($it['Quantity']);
    }
}

// ── Edit mode (Released) ───────────────────────────────────────
$editId  = intval($_GET['editid']??0);
$editRow = [];
if ($editId>0 && $tab==='released') {
    $tmp = rq($conn,"SELECT * FROM [dbo].[UniformReleased] WHERE ReleasedID=?",[$editId]);
    $editRow=$tmp[0]??[];
}

// ── Returns ───────────────────────────────────────────────────
$retSearch = trim($_GET['retsearch'] ?? '');
$retUType  = trim($_GET['rettype']  ?? '');
$retDept   = trim($_GET['retdept']  ?? '');
$retSize   = trim($_GET['retsize']  ?? '');
$retReturnedTo = trim($_GET['retreturnedto'] ?? '');
$retDateFrom   = trim($_GET['retdatefrom']   ?? '');
$retDateTo     = trim($_GET['retdateto']     ?? '');

// Distinct "Returned To" values already on record, for the filter dropdown
$returnedToRows = rq($conn,"SELECT DISTINCT ReturnedTo FROM [dbo].[UniformReturns] WHERE ReturnedTo IS NOT NULL AND ReturnedTo <> '' ORDER BY ReturnedTo ASC");
$returnedToList = array_map(fn($r)=>$r['ReturnedTo'], $returnedToRows);

if (!in_array($retUType, ['TSHIRT','POLOSHIRT'])) $retUType = '';
if (!in_array($retDept, $depts))                   $retDept  = '';
if (!in_array($retSize, $sizes))                    $retSize  = '';
if (!in_array($retReturnedTo, $returnedToList, true)) $retReturnedTo = '';
if ($retDateFrom !== '' && !strtotime($retDateFrom)) $retDateFrom = '';
if ($retDateTo   !== '' && !strtotime($retDateTo))   $retDateTo   = '';

$effectiveRetDept = $deptScope !== '' ? $deptScope : $retDept;

$retConditions = [];
if ($retSearch !== '') {
    $s = str_replace("'","''",$retSearch);
    $retConditions[] = "(EmployeeName LIKE '%{$s}%' OR ReturnedTo LIKE '%{$s}%')";
}
if ($retUType !== '') $retConditions[] = "UniformType = '" . str_replace("'","''",$retUType) . "'";
if ($effectiveRetDept !== '') $retConditions[] = "Department = '" . str_replace("'","''",$effectiveRetDept) . "'";
if ($retSize !== '') $retConditions[] = "UniformSize = '" . str_replace("'","''",$retSize) . "'";
if ($retReturnedTo !== '') $retConditions[] = "ReturnedTo = '" . str_replace("'","''",$retReturnedTo) . "'";
if ($retDateFrom !== '') $retConditions[] = "DateReturned >= '" . date('Y-m-d',strtotime($retDateFrom)) . "'";
if ($retDateTo   !== '') $retConditions[] = "DateReturned <= '" . date('Y-m-d',strtotime($retDateTo)) . "'";
$retWhere = !empty($retConditions) ? 'WHERE ' . implode(' AND ', $retConditions) : '';

$retAll    = rq($conn, "SELECT * FROM [dbo].[UniformReturns] {$retWhere} ORDER BY DateReturned DESC, CreatedAt DESC");
$retTotal  = count($retAll);
$retPages  = max(1,(int)ceil($retTotal/20));
$retPage   = max(1,min((int)($_GET['retpage']??1),$retPages));
$retList   = array_slice($retAll,($retPage-1)*20,20);
$totalReturnCount = array_sum(array_column($retAll,'Quantity'));

// Pending-inspection queue and items currently out for cleaning/repair
// (dept-scoped the same way as the main returns list, but not paginated —
// this is meant to be a short actionable queue, not a full history).
$pendingInspectionWhere = $retWhere !== '' ? $retWhere." AND InspectionStatus='Pending Inspection'" : "WHERE InspectionStatus='Pending Inspection'";
$cleaningWhere          = $retWhere !== '' ? $retWhere." AND InspectionStatus='Cleaning/Repair'"     : "WHERE InspectionStatus='Cleaning/Repair'";
$returnedReadyWhere     = $retWhere !== '' ? $retWhere." AND InspectionStatus='Returned'"            : "WHERE InspectionStatus='Returned'";
$pendingInspectionList  = rq($conn, "SELECT * FROM [dbo].[UniformReturns] {$pendingInspectionWhere} ORDER BY DateReturned ASC, CreatedAt ASC");
$cleaningList           = rq($conn, "SELECT * FROM [dbo].[UniformReturns] {$cleaningWhere} ORDER BY DateReturned ASC, CreatedAt ASC");
$returnedReadyList      = rq($conn, "SELECT * FROM [dbo].[UniformReturns] {$returnedReadyWhere} ORDER BY InspectedAt ASC");

// Dept-scoped-only pending count, for the nav tab badge — independent of
// whatever ad-hoc search/type/size/date filters are active on the Returns tab.
$pendingInspectionCountWhere = $deptScope !== ''
    ? "WHERE Department = '" . str_replace("'","''",$deptScope) . "' AND InspectionStatus='Pending Inspection'"
    : "WHERE InspectionStatus='Pending Inspection'";
$pendingInspectionCountAll = count(rq($conn, "SELECT ReturnID FROM [dbo].[UniformReturns] {$pendingInspectionCountWhere}"));

// ── Edit mode (Returns) ───────────────────────────────────────
$editRetId  = intval($_GET['editretid'] ?? 0);
$editRetRow = [];
if ($editRetId > 0 && $tab === 'returns') {
    $tmp = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$editRetId]);
    $editRetRow = $tmp[0] ?? [];
}

// ── Report data ───────────────────────────────────────────────
if ($tab === 'report') {
    // Date range filter
    $rptFrom  = trim($_GET['rpt_from'] ?? date('Y-01-01'));
    $rptTo    = trim($_GET['rpt_to']   ?? date('Y-m-d'));
    $rptDept  = trim($_GET['rpt_dept'] ?? ($deptScope !== '' ? $deptScope : ''));
    $rptUType = trim($_GET['rpt_type'] ?? '');
    if (!in_array($rptUType, ['TSHIRT','POLOSHIRT'])) $rptUType = '';
    if (!in_array($rptDept, $depts) && $rptDept !== '') $rptDept = '';
    // If dept-scoped user, force their dept
    if ($deptScope !== '') $rptDept = $deptScope;

    // Build WHERE conditions helper
    $rptCondRel = ["DateGiven >= '{$rptFrom}' AND DateGiven <= '{$rptTo}'"];
    $rptCondReq = ["DateRequested >= '{$rptFrom}' AND DateRequested <= '{$rptTo}'"];
    $rptCondRet = ["DateReturned >= '{$rptFrom}' AND DateReturned <= '{$rptTo}'"];
    if ($rptDept  !== '') {
        $rd = str_replace("'","''",$rptDept);
        $rptCondRel[] = "Department = '{$rd}'";
        $rptCondReq[] = "Department = '{$rd}'";
        $rptCondRet[] = "Department = '{$rd}'";
    }
    if ($rptUType !== '') {
        $ru = str_replace("'","''",$rptUType);
        $rptCondRel[] = "UniformType = '{$ru}'";
        $rptCondReq[] = "UniformType = '{$ru}'";
        $rptCondRet[] = "UniformType = '{$ru}'";
    }
    $wrRel = 'WHERE ' . implode(' AND ', $rptCondRel);
    $wrReq = 'WHERE ' . implode(' AND ', $rptCondReq);
    $wrRet = 'WHERE ' . implode(' AND ', $rptCondRet);

    // ── Summary totals
    $rptRelTotals = rq($conn,
        "SELECT UniformType, SUM(Quantity) AS TotalQty, COUNT(*) AS Records
         FROM [dbo].[UniformReleased] {$wrRel}
         GROUP BY UniformType");

    $rptReqTotals = rq($conn,
        "SELECT UniformType, SUM(Quantity) AS TotalQty,
                SUM(CASE WHEN IsGiven=1 THEN 1 ELSE 0 END) AS GivenCount,
                SUM(CASE WHEN IsGiven=0 THEN 1 ELSE 0 END) AS PendingCount
         FROM [dbo].[UniformRequests] {$wrReq}
         GROUP BY UniformType");

    $rptRetTotals = rq($conn,
        "SELECT UniformType, SUM(Quantity) AS TotalQty, COUNT(*) AS Records
         FROM [dbo].[UniformReturns] {$wrRet}
         GROUP BY UniformType");

    // ── Released by dept breakdown
    $rptRelByDept = rq($conn,
        "SELECT Department, UniformType, SUM(Quantity) AS TotalQty
         FROM [dbo].[UniformReleased] {$wrRel}
         GROUP BY Department, UniformType
         ORDER BY Department, UniformType");

    // ── Released by size breakdown
    $rptRelBySize = rq($conn,
        "SELECT UniformType, UniformSize AS Size, SUM(Quantity) AS TotalQty
         FROM [dbo].[UniformReleased] {$wrRel}
         GROUP BY UniformType, UniformSize
         ORDER BY UniformType,
           CASE UniformSize WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4
                            WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END");

    // ── Top 10 employees (released)
    $rptTopEmp = rq($conn,
        "SELECT TOP 10 EmployeeName, Department, UniformType, SUM(Quantity) AS TotalQty
         FROM [dbo].[UniformReleased] {$wrRel}
         GROUP BY EmployeeName, Department, UniformType
         ORDER BY SUM(Quantity) DESC");

    // ── Current stock snapshot
    $rptStockSnap = rq($conn,
        "SELECT UniformType, Size,
                PreviousStock, AdditionalStock, LessStock, ReturnedStock, ReturnStock, CleaningStock, DisposedStock,
                (PreviousStock + AdditionalStock + ReturnStock - LessStock) AS CurrentStock
         FROM [dbo].[UniformStock]
         ORDER BY UniformType DESC,
           CASE Size WHEN 'XS' THEN 1 WHEN 'S' THEN 2 WHEN 'M' THEN 3 WHEN 'L' THEN 4
                     WHEN 'XL' THEN 5 WHEN 'XXL' THEN 6 WHEN 'XXXL' THEN 7 WHEN '4XL' THEN 8 END");

    // ── Monthly trend (released, last 12 months)
    $rptMonthly = rq($conn,
        "SELECT FORMAT(DateGiven,'yyyy-MM') AS Mo, UniformType, SUM(Quantity) AS TotalQty
         FROM [dbo].[UniformReleased]
         WHERE DateGiven >= DATEADD(MONTH,-11,CAST(GETDATE() AS DATE))
         " . ($rptDept!==''  ? "AND Department = '" . str_replace("'","''",$rptDept)  . "'" : '')
           . ($rptUType!=='' ? "AND UniformType = '" . str_replace("'","''",$rptUType) . "'" : '') . "
         GROUP BY FORMAT(DateGiven,'yyyy-MM'), UniformType
         ORDER BY Mo");

    // ── Returns by inspection status
    $rptRetCond = rq($conn,
        "SELECT InspectionStatus, SUM(Quantity) AS TotalQty, COUNT(*) AS Records
         FROM [dbo].[UniformReturns] {$wrRet}
         GROUP BY InspectionStatus");

    // Helper maps
    $rptRelMap  = []; foreach ($rptRelTotals as $r) $rptRelMap[$r['UniformType']]  = $r;
    $rptReqMap  = []; foreach ($rptReqTotals as $r) $rptReqMap[$r['UniformType']]  = $r;
    $rptRetMap  = []; foreach ($rptRetTotals as $r) $rptRetMap[$r['UniformType']]  = $r;
    $rptStockMap= []; foreach ($rptStockSnap  as $r) $rptStockMap[$r['UniformType']][$r['Size']] = $r;
    $rptMonthMap= []; foreach ($rptMonthly    as $r) $rptMonthMap[$r['Mo']][$r['UniformType']] = intval($r['TotalQty']);
    $rptMonths  = array_unique(array_column($rptMonthly,'Mo'));

    $rptGrandRelQty = array_sum(array_column($rptRelTotals,'TotalQty'));
    $rptGrandRetQty = array_sum(array_column($rptRetTotals,'TotalQty'));
    $rptGrandReqQty = array_sum(array_column($rptReqTotals,'TotalQty'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Uniform Inventory — Tradewell</title>
  <link href="<?= base_url('assets/img/logo.png') ?>" rel="icon">
  <link href="<?= base_url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/admin.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/css/topbar.css') ?>" rel="stylesheet">
  
<style>
.tab-bar{display:flex;gap:.3rem;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.3rem;margin-bottom:1.5rem;flex-wrap:wrap;}
.tab-btn{display:flex;align-items:center;gap:.4rem;padding:.42rem 1rem;border-radius:8px;font-size:.8rem;font-weight:600;border:none;cursor:pointer;background:transparent;color:var(--text-secondary);font-family:'DM Sans',sans-serif;transition:background .14s,color .14s;text-decoration:none;white-space:nowrap;}
.tab-btn:hover{background:var(--surface-3);color:var(--text-primary);}
.tab-btn.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(30,64,175,.2);}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;padding:1rem 1.2rem;box-shadow:var(--shadow-sm);}
.stat-icon{font-size:1.3rem;margin-bottom:.2rem;}
.stat-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);}
.stat-value{font-family:'Sora',sans-serif;font-size:1.55rem;font-weight:800;color:var(--text-primary);line-height:1.1;}
.sv-blue{color:var(--primary-light);}.sv-teal{color:#0891b2;}.sv-amber{color:#ca8a04;}.sv-red{color:#dc2626;}
.panel{background:var(--surface);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:1.5rem;}
.panel-hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.65rem;padding:.85rem 1.2rem;background:var(--surface-3);border-bottom:1px solid var(--border);}
.panel-title{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.4rem;}
.utbl{width:100%;border-collapse:collapse;font-size:.8rem;}
.utbl thead th{padding:.5rem .85rem;text-align:left;color:var(--text-muted);font-weight:700;font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;background:var(--surface-2);border-bottom:1px solid var(--border);white-space:nowrap;}
.utbl tbody td{padding:.55rem .85rem;border-bottom:1px solid var(--border);vertical-align:middle;color:var(--text-secondary);}
.utbl tbody tr:last-child td{border-bottom:none;}
.utbl tbody tr:hover td{background:var(--surface-2);}
.bdg{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;border:1px solid;white-space:nowrap;}
.bdg-tshirt{background:rgba(59,130,246,.1);color:#1e40af;border-color:#93c5fd;}
.bdg-polo{background:rgba(16,185,129,.1);color:#059669;border-color:#6ee7b7;}
.bdg-given{background:rgba(16,185,129,.1);color:#059669;border-color:#6ee7b7;}
.bdg-pending{background:rgba(234,179,8,.1);color:#ca8a04;border-color:#fde047;}
.dept-Century{background:rgba(59,130,246,.1);color:#1e40af;border-color:#93c5fd;}
.dept-Monde{background:rgba(239,68,68,.1);color:#dc2626;border-color:#fca5a5;}
.dept-Multilines{background:rgba(234,179,8,.1);color:#ca8a04;border-color:#fde047;}
.dept-NutriAsia{background:rgba(16,185,129,.1);color:#059669;border-color:#6ee7b7;}
.btn-add{display:inline-flex;align-items:center;gap:.4rem;background:var(--primary);color:#fff;border:none;border-radius:9px;padding:.48rem 1.05rem;font-size:.8rem;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;box-shadow:0 2px 8px rgba(30,64,175,.18);transition:background .14s;}
.btn-add:hover{background:#1d3fa3;}
.btn-sm-action{display:inline-flex;align-items:center;gap:.25rem;padding:.25rem .62rem;border-radius:7px;font-size:.72rem;font-weight:600;border:1.5px solid;cursor:pointer;text-decoration:none;font-family:'DM Sans',sans-serif;transition:all .12s;background:none;}
.btn-edit{color:var(--primary);border-color:rgba(59,130,246,.3);background:var(--primary-glow);}
.btn-edit:hover{background:var(--primary);color:#fff;}
.btn-del{color:#dc2626;border-color:#fca5a5;background:rgba(220,38,38,.06);}
.btn-del:hover{background:#dc2626;color:#fff;}
.btn-green{color:#059669;border-color:#6ee7b7;background:rgba(16,185,129,.07);}
.btn-green:hover{background:#059669;color:#fff;}
.stock-input{width:72px;border:1.5px solid var(--border);border-radius:7px;padding:.28rem .45rem;font-size:.8rem;font-family:'DM Mono',monospace;text-align:center;background:var(--surface-2);color:var(--text-primary);transition:border-color .13s;}
.stock-input:focus{outline:none;border-color:var(--primary-light);}
.flash{display:flex;align-items:center;gap:.45rem;padding:.6rem .95rem;border-radius:9px;font-size:.8rem;font-weight:600;margin-bottom:.85rem;}
.flash-ok{background:rgba(16,185,129,.09);color:#059669;border:1px solid #6ee7b7;}
.flash-err{background:rgba(220,38,38,.07);color:#dc2626;border:1px solid #fca5a5;}
.modal-content{border-radius:14px;border:1.5px solid var(--border);}
.modal-header{background:var(--surface-3);border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
.modal-title{font-family:'Sora',sans-serif;font-weight:700;font-size:.92rem;}
.form-label{font-size:.76rem;font-weight:700;color:var(--text-secondary);margin-bottom:.22rem;}
.form-control,.form-select{font-size:.8rem;border-color:var(--border);border-radius:8px;padding:.42rem .7rem;font-family:'DM Sans',sans-serif;}
.form-control:focus,.form-select:focus{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(59,130,246,.1);}
.po-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.25rem;}
.po-type-card{background:var(--surface);border:1.5px solid var(--border);border-radius:12px;overflow:hidden;}
.po-type-hdr{padding:.6rem 1rem;font-family:'Sora',sans-serif;font-size:.82rem;font-weight:700;display:flex;align-items:center;gap:.4rem;}
.po-type-hdr.tshirt{background:rgba(59,130,246,.1);color:#1e40af;}
.po-type-hdr.polo{background:rgba(16,185,129,.1);color:#059669;}
.sbar{display:flex;align-items:center;gap:.35rem;background:var(--surface-2);border:1.5px solid var(--border);border-radius:9px;padding:.28rem .7rem;}
.sbar input{border:none;background:transparent;outline:none;font-size:.8rem;font-family:'DM Sans',sans-serif;color:var(--text-primary);min-width:160px;}
.sbar i{color:var(--text-muted);}
@media(max-width:900px){.stock-side-grid{grid-template-columns:1fr !important;}}
.empty-st{text-align:center;padding:2.5rem 1rem;color:var(--text-muted);}
.empty-st i{font-size:2rem;display:block;margin-bottom:.6rem;}
.empty-st p{font-size:.82rem;margin:0;}
</style>
</head>
<body>

<?php $topbar_page = 'careers'; require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php'; ?>

<div class="container">
<div class="page-header">
  <div><br>
    <div class="page-title">Uniform <span>Inventory</span> System</div>
    <div class="page-badge">🧥 <?= date('Y') ?> · 
      <?php if($deptScope!==''): ?>
        <span style="background:rgba(59,130,246,.12);color:var(--primary);border-radius:6px;padding:.08rem .45rem;font-weight:700;"><?= safe($deptScope) ?></span> Department View
      <?php else: ?>
        <?= safe($sessionDept ?: 'All Departments') ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php foreach($messages as $m): ?>
<div class="flash <?= $m['type']==='success'?'flash-ok':'flash-err' ?>">
  <i class="bi <?= $m['type']==='success'?'bi-check-circle-fill':'bi-exclamation-triangle-fill' ?>"></i>
  <?= safe($m['text']) ?>
</div>
<?php endforeach; ?>

<div class="stats-row">
  <div class="stat-card"><div class="stat-icon">👕</div><div class="stat-label">T-Shirt Stock</div><div class="stat-value sv-blue"><?= number_format($totalStock['TSHIRT']) ?></div></div>
  <div class="stat-card"><div class="stat-icon">👔</div><div class="stat-label">Polo Shirt Stock</div><div class="stat-value sv-teal"><?= number_format($totalStock['POLOSHIRT']) ?></div></div>
  <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-label">Total Uniform Given<?= $deptScope!==''?' ('.$deptScope.')':'' ?></div><div class="stat-value sv-amber"><?= number_format($totalGivenCount) ?></div></div>
  <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-label">Pending Requests<?= $deptScope!==''?' ('.$deptScope.')':'' ?></div><div class="stat-value sv-red"><?= $reqPendingCount ?></div></div>
</div>

<div class="tab-bar">
  <?php if($canManageStock): ?>
  <a href="?tab=stocks"    class="tab-btn <?= $tab==='stocks'   ?'active':'' ?>"><i class="bi bi-boxes"></i> Stocks</a>
  <?php endif; ?>
  <a href="?tab=stockview" class="tab-btn <?= $tab==='stockview'?'active':'' ?>"><i class="bi bi-eye-fill"></i> Stock Overview</a>
  <a href="?tab=released"  class="tab-btn <?= $tab==='released' ?'active':'' ?>"><i class="bi bi-send-fill"></i> Uniforms Released</a>
  <a href="?tab=requests"  class="tab-btn <?= $tab==='requests' ?'active':'' ?>"><i class="bi bi-clipboard-check"></i> Requested List</a>
  <a href="?tab=po"        class="tab-btn <?= $tab==='po'       ?'active':'' ?>"><i class="bi bi-file-earmark-text-fill"></i> PO Form</a>
  <a href="?tab=receiving" class="tab-btn <?= $tab==='receiving'?'active':'' ?>"><i class="bi bi-box-seam-fill"></i> Receiving Form</a>
  <a href="?tab=returns"   class="tab-btn <?= $tab==='returns'  ?'active':'' ?>"><i class="bi bi-arrow-return-left"></i> Returns</a>
  <a href="?tab=pending_inspection" class="tab-btn <?= $tab==='pending_inspection' ?'active':'' ?>"><i class="bi bi-hourglass-split"></i> Pending Inspection<?php if($pendingInspectionCountAll>0): ?> <span style="background:#fde047;color:#854d0e;border-radius:10px;padding:0 .45rem;font-size:.68rem;font-weight:800;margin-left:.15rem;"><?= $pendingInspectionCountAll ?></span><?php endif; ?></a>
  <a href="?tab=report"    class="tab-btn <?= $tab==='report'   ?'active':'' ?>"><i class="bi bi-bar-chart-fill"></i> Reports</a>
</div>

<?php if($deptScope!==''): ?>
<div style="display:flex;align-items:center;gap:.55rem;background:rgba(59,130,246,.06);border:1.5px solid rgba(59,130,246,.2);border-radius:10px;padding:.55rem 1rem;margin-bottom:.85rem;font-size:.78rem;color:var(--primary);font-weight:600;">
  <i class="bi bi-building-fill" style="font-size:.95rem;"></i>
  <span>Showing data for <strong><?= safe($deptScope) ?></strong> department only. Stocks tab shows all inventory.</span>
</div>
<?php endif; ?>

<?php
// ═══ TAB: STOCKS (stock managers only) ═══════════════════════════
if ($tab==='stocks'):
if (!$canManageStock):
?>
<div class="empty-st"><i class="bi bi-lock-fill"></i><p>You don't have permission to edit stock numbers. Use the <strong>Stock Overview</strong> tab to view current levels.</p></div>
<?php
else:
$typeTotals=[];
foreach(['TSHIRT','POLOSHIRT'] as $t){
    $sum=0;
    foreach($sizes as $sz) $sum+=max(0,intval(($stockMap[$t][$sz]??['CurrentStock'=>0])['CurrentStock']));
    $typeTotals[$t]=$sum;
}
?>
<!-- ── At-a-glance pipeline: mirrors the Returns tab lifecycle, so the ── -->
<!-- ── numbers below make sense without anyone explaining them.       ── -->
<div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.25rem;">
  <div style="font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.65rem;">How a returned uniform becomes Available again</div>
  <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:.78rem;">
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(234,179,8,.08);border:1px solid #fde047;color:#854d0e;border-radius:20px;padding:.3rem .7rem;font-weight:700;">⏳ Pending Inspection</span>
    <i class="bi bi-arrow-right" style="color:var(--text-muted);"></i>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(67,56,202,.08);border:1px solid #a5b4fc;color:#4338ca;border-radius:20px;padding:.3rem .7rem;font-weight:700;">📦 Returned <span style="font-weight:400;opacity:.8;">(held)</span></span>
    <i class="bi bi-arrow-right" style="color:var(--text-muted);"></i>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(16,185,129,.08);border:1px solid #6ee7b7;color:#059669;border-radius:20px;padding:.3rem .7rem;font-weight:700;">✅ Stocked <span style="font-weight:400;opacity:.8;">→ counts as Available</span></span>
    <span style="margin-left:.4rem;color:var(--text-muted);">or</span>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(13,148,136,.08);border:1px solid #5eead4;color:#0d9488;border-radius:20px;padding:.3rem .7rem;font-weight:700;">💧 Cleaning/Repair <span style="font-weight:400;opacity:.8;">(held, then → Returned)</span></span>
    <span style="color:var(--text-muted);">or</span>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(220,38,38,.08);border:1px solid #fca5a5;color:#dc2626;border-radius:20px;padding:.3rem .7rem;font-weight:700;">🗑️ Disposed <span style="font-weight:400;opacity:.8;">(never counted)</span></span>
  </div>
  <div style="margin-top:.6rem;font-size:.73rem;color:var(--text-muted);display:flex;align-items:center;gap:.35rem;">
    <i class="bi bi-info-circle-fill"></i> Only what's <strong style="color:#059669;">Stocked</strong> below counts toward the big number for each size. Manage returns on the <strong>Returns</strong> tab — click <strong>Edit</strong> here only to adjust raw stock counts (new purchases, corrections).
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;" class="stock-side-grid">
<?php foreach([
    'TSHIRT'   =>['label'=>'T-Shirt',   'emoji'=>'👕','accent'=>'#1e40af','light'=>'rgba(59,130,246,.08)','border'=>'rgba(59,130,246,.25)','role'=>'Logistics employees'],
    'POLOSHIRT'=>['label'=>'Polo Shirt','emoji'=>'👔','accent'=>'#0891b2','light'=>'rgba(8,145,178,.08)', 'border'=>'rgba(8,145,178,.25)', 'role'=>'Office / Sales employees'],
] as $type=>$meta):
    $typeTotal=$typeTotals[$type];
    $outCount=0; $lowCount=0;
    foreach($sizes as $sz){
        $cur=max(0,intval(($stockMap[$type][$sz]??['CurrentStock'=>0])['CurrentStock']));
        if($cur===0) $outCount++; elseif($cur<=5) $lowCount++;
    }
?>
<div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);">
  <div style="background:<?= $meta['light'] ?>;border-bottom:1.5px solid <?= $meta['border'] ?>;padding:.85rem 1.1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
      <div style="display:flex;align-items:center;gap:.55rem;">
        <span style="font-size:1.4rem;line-height:1;"><?= $meta['emoji'] ?></span>
        <div>
          <div style="font-family:'Sora',sans-serif;font-size:.95rem;font-weight:800;color:<?= $meta['accent'] ?>;line-height:1.2;"><?= $meta['label'] ?></div>
          <div style="font-size:.7rem;color:var(--text-muted);margin-top:.1rem;"><?= $meta['role'] ?></div>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <div style="background:var(--surface);border:1px solid <?= $meta['border'] ?>;border-radius:20px;padding:.2rem .65rem;font-size:.72rem;font-weight:700;color:<?= $meta['accent'] ?>;"><i class="bi bi-stack"></i> <?= number_format($typeTotal) ?> pcs</div>
        <?php if($outCount>0): ?><div style="background:rgba(220,38,38,.08);border:1px solid #fca5a5;border-radius:20px;padding:.2rem .65rem;font-size:.72rem;font-weight:700;color:#dc2626;"><i class="bi bi-x-circle-fill"></i> <?= $outCount ?> out</div><?php endif; ?>
        <?php if($lowCount>0): ?><div style="background:rgba(234,179,8,.08);border:1px solid #fde047;border-radius:20px;padding:.2rem .65rem;font-size:.72rem;font-weight:700;color:#ca8a04;"><i class="bi bi-exclamation-triangle-fill"></i> <?= $lowCount ?> low</div><?php endif; ?>
      </div>
    </div>
  </div>
  <div>
  <?php foreach($sizes as $sz):
    $row=$stockMap[$type][$sz]??['PreviousStock'=>0,'AdditionalStock'=>0,'LessStock'=>0,'ReturnedStock'=>0,'ReturnStock'=>0,'CleaningStock'=>0,'DisposedStock'=>0,'CurrentStock'=>0];
    $cur=max(0,intval($row['CurrentStock']??0));
    if($cur===0){$dot='#dc2626';$tip='Out of stock';$statusLbl='Out of stock';}
    elseif($cur<=5){$dot='#ca8a04';$tip='Low stock';$statusLbl='Low stock';}
    else{$dot='#10b981';$tip='In stock';$statusLbl='available';}
    $returnedQ=intval($row['ReturnedStock']??0);
    $cleaningQ=intval($row['CleaningStock']??0);
    $disposedQ=intval($row['DisposedStock']??0);
    $hasPipeline = ($returnedQ>0 || $cleaningQ>0 || $disposedQ>0);
    $prevS=intval($row['PreviousStock']??0); $addS=intval($row['AdditionalStock']??0); $lessS=intval($row['LessStock']??0); $stockedS=intval($row['ReturnStock']??0);
  ?>
  <div style="padding:.75rem 1.1rem;border-bottom:1px solid var(--border);">
    <div style="display:flex;align-items:center;gap:.85rem;flex-wrap:wrap;">
      <span style="background:<?= $meta['light'] ?>;color:<?= $meta['accent'] ?>;border:1px solid <?= $meta['border'] ?>;border-radius:6px;padding:.25rem .55rem;font-family:'DM Mono',monospace;font-size:.8rem;font-weight:800;min-width:40px;text-align:center;"><?= $sz ?></span>

      <div style="display:flex;align-items:baseline;gap:.4rem;min-width:110px;">
        <span style="font-family:'DM Mono',monospace;font-weight:800;font-size:1.35rem;color:<?= $dot ?>;line-height:1;"><?= $cur ?></span>
        <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;"><?= $statusLbl ?></span>
      </div>

      <div style="display:flex;gap:.4rem;flex-wrap:wrap;flex:1;">
        <?php if($returnedQ>0): ?><span style="background:rgba(67,56,202,.08);color:#4338ca;border:1px solid rgba(67,56,202,.2);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Confirmed good, waiting to be added to stock">📦 <?= $returnedQ ?> returned</span><?php endif; ?>
        <?php if($cleaningQ>0): ?><span style="background:rgba(13,148,136,.08);color:#0d9488;border:1px solid rgba(13,148,136,.2);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Out for cleaning/repair">💧 <?= $cleaningQ ?> cleaning</span><?php endif; ?>
        <?php if($disposedQ>0): ?><span style="background:rgba(153,27,27,.06);color:#991b1b;border:1px solid rgba(153,27,27,.15);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Lifetime written off">🗑️ <?= $disposedQ ?> disposed (lifetime)</span><?php endif; ?>
        <?php if(!$hasPipeline): ?><span style="color:var(--text-muted);font-size:.72rem;font-style:italic;">✓ nothing pending</span><?php endif; ?>
      </div>

      <?php if($canManageStock): ?>
      <details style="margin-left:auto;">
        <summary style="cursor:pointer;list-style:none;background:var(--surface-2);border:1px solid var(--border);border-radius:7px;padding:.3rem .6rem;font-size:.72rem;font-weight:700;color:var(--text-secondary);display:inline-flex;align-items:center;gap:.3rem;user-select:none;"><i class="bi bi-pencil-square"></i> Edit</summary>
        <form method="POST" style="margin-top:.65rem;padding:.85rem;background:var(--surface-2);border:1px dashed var(--border);border-radius:10px;">
          <input type="hidden" name="save_stock" value="1">
          <input type="hidden" name="UniformType" value="<?= $type ?>">
          <input type="hidden" name="Size" value="<?= $sz ?>">
          <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
            <div>
              <label style="display:block;font-size:.65rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Previous</label>
              <input type="number" name="PreviousStock" class="stock-input" value="<?= $prevS ?>" min="0" style="width:80px;">
            </div>
            <div>
              <label style="display:block;font-size:.65rem;font-weight:700;color:#0891b2;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Additional</label>
              <input type="number" name="AdditionalStock" class="stock-input" value="<?= $addS ?>" min="0" style="width:80px;border-color:rgba(8,145,178,.3);background:rgba(8,145,178,.04);">
            </div>
            <div>
              <label style="display:block;font-size:.65rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Less</label>
              <input type="number" name="LessStock" class="stock-input" value="<?= $lessS ?>" min="0" style="width:80px;border-color:rgba(220,38,38,.25);background:rgba(220,38,38,.04);">
            </div>
            <div>
              <label style="display:block;font-size:.65rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem;">Stocked <i class="bi bi-lock-fill" style="font-size:.6rem;" title="Only changes via Add to Stock on the Returns tab"></i></label>
              <div style="width:80px;text-align:center;padding:.4rem 0;background:rgba(124,58,237,.06);border:1px solid rgba(124,58,237,.2);border-radius:8px;font-family:'DM Mono',monospace;font-weight:700;color:#7c3aed;"><?= $stockedS ?></div>
            </div>
            <button type="submit" style="background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:.75rem;font-weight:700;padding:.5rem .8rem;border-radius:8px;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:.3rem;white-space:nowrap;" onmouseover="this.style.background='#1d3fa3'" onmouseout="this.style.background='var(--primary)'">
              <i class="bi bi-floppy-fill"></i> Save
            </button>
          </div>
          <div style="margin-top:.55rem;font-size:.72rem;color:var(--text-muted);font-family:'DM Mono',monospace;">
            <?= $cur ?> available = <?= $prevS ?> previous + <?= $addS ?> additional + <?= $stockedS ?> stocked − <?= $lessS ?> less
          </div>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1.1rem;background:<?= $meta['light'] ?>;border-top:1.5px solid <?= $meta['border'] ?>;">
    <span style="font-size:.75rem;font-weight:700;color:<?= $meta['accent'] ?>;display:flex;align-items:center;gap:.35rem;"><i class="bi bi-calculator-fill"></i> Total <?= $meta['label'] ?> Available</span>
    <span style="font-family:'DM Mono',monospace;font-size:1rem;font-weight:800;color:<?= $meta['accent'] ?>;"><?= number_format($typeTotal) ?> <span style="font-size:.72rem;font-weight:600;">pcs</span></span>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; /* canManageStock */ ?>

<?php
// ═══ TAB: STOCK OVERVIEW (read-only, everyone with module access) ═
elseif($tab==='stockview'):
$typeTotalsRO=[];
foreach(['TSHIRT','POLOSHIRT'] as $t){
    $sum=0;
    foreach($sizes as $sz) $sum+=max(0,intval(($stockMap[$t][$sz]??['CurrentStock'=>0])['CurrentStock']));
    $typeTotalsRO[$t]=$sum;
}
?>
<div style="display:flex;align-items:center;gap:.55rem;background:rgba(59,130,246,.06);border:1.5px solid rgba(59,130,246,.2);border-radius:10px;padding:.55rem 1rem;margin-bottom:1rem;font-size:.78rem;color:var(--primary);font-weight:600;">
  <i class="bi bi-eye-fill" style="font-size:.95rem;"></i>
  <span>Read-only view. Your account has view-only access to Uniform Inventory — editing stock numbers and inspecting returns requires full access.</span>
</div>

<div style="background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.9rem 1.1rem;margin-bottom:1.25rem;">
  <div style="font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.65rem;">How a returned uniform becomes Available again</div>
  <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;font-size:.78rem;">
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(234,179,8,.08);border:1px solid #fde047;color:#854d0e;border-radius:20px;padding:.3rem .7rem;font-weight:700;">⏳ Pending Inspection</span>
    <i class="bi bi-arrow-right" style="color:var(--text-muted);"></i>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(67,56,202,.08);border:1px solid #a5b4fc;color:#4338ca;border-radius:20px;padding:.3rem .7rem;font-weight:700;">📦 Returned <span style="font-weight:400;opacity:.8;">(held)</span></span>
    <i class="bi bi-arrow-right" style="color:var(--text-muted);"></i>
    <span style="display:flex;align-items:center;gap:.4rem;background:rgba(16,185,129,.08);border:1px solid #6ee7b7;color:#059669;border-radius:20px;padding:.3rem .7rem;font-weight:700;">✅ Stocked <span style="font-weight:400;opacity:.8;">→ counts as Available</span></span>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;" class="stock-side-grid">
<?php foreach([
    'TSHIRT'   =>['label'=>'T-Shirt',   'emoji'=>'👕','accent'=>'#1e40af','light'=>'rgba(59,130,246,.08)','border'=>'rgba(59,130,246,.25)','role'=>'Logistics employees'],
    'POLOSHIRT'=>['label'=>'Polo Shirt','emoji'=>'👔','accent'=>'#0891b2','light'=>'rgba(8,145,178,.08)', 'border'=>'rgba(8,145,178,.25)', 'role'=>'Office / Sales employees'],
] as $type=>$meta):
    $typeTotal=$typeTotalsRO[$type];
?>
<div style="background:var(--surface);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:var(--shadow-sm);">
  <div style="background:<?= $meta['light'] ?>;border-bottom:1.5px solid <?= $meta['border'] ?>;padding:.85rem 1.1rem;">
    <div style="display:flex;align-items:center;gap:.55rem;">
      <span style="font-size:1.4rem;line-height:1;"><?= $meta['emoji'] ?></span>
      <div>
        <div style="font-family:'Sora',sans-serif;font-size:.95rem;font-weight:800;color:<?= $meta['accent'] ?>;line-height:1.2;"><?= $meta['label'] ?></div>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:.1rem;"><?= $meta['role'] ?></div>
      </div>
    </div>
  </div>
  <div>
  <?php foreach($sizes as $sz):
    $row=$stockMap[$type][$sz]??['ReturnedStock'=>0,'CleaningStock'=>0,'DisposedStock'=>0,'CurrentStock'=>0];
    $cur=max(0,intval($row['CurrentStock']??0));
    if($cur===0){$dot='#dc2626';$statusLbl='Out of stock';}
    elseif($cur<=5){$dot='#ca8a04';$statusLbl='Low stock';}
    else{$dot='#10b981';$statusLbl='available';}
    $returnedQ=intval($row['ReturnedStock']??0);
    $cleaningQ=intval($row['CleaningStock']??0);
    $disposedQ=intval($row['DisposedStock']??0);
    $hasPipeline = ($returnedQ>0 || $cleaningQ>0 || $disposedQ>0);
  ?>
  <div style="padding:.75rem 1.1rem;border-bottom:1px solid var(--border);">
    <div style="display:flex;align-items:center;gap:.85rem;flex-wrap:wrap;">
      <span style="background:<?= $meta['light'] ?>;color:<?= $meta['accent'] ?>;border:1px solid <?= $meta['border'] ?>;border-radius:6px;padding:.25rem .55rem;font-family:'DM Mono',monospace;font-size:.8rem;font-weight:800;min-width:40px;text-align:center;"><?= $sz ?></span>
      <div style="display:flex;align-items:baseline;gap:.4rem;min-width:110px;">
        <span style="font-family:'DM Mono',monospace;font-weight:800;font-size:1.35rem;color:<?= $dot ?>;line-height:1;"><?= $cur ?></span>
        <span style="font-size:.72rem;color:var(--text-muted);font-weight:600;"><?= $statusLbl ?></span>
      </div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;flex:1;">
        <?php if($returnedQ>0): ?><span style="background:rgba(67,56,202,.08);color:#4338ca;border:1px solid rgba(67,56,202,.2);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Confirmed good, waiting to be added to stock">📦 <?= $returnedQ ?> returned</span><?php endif; ?>
        <?php if($cleaningQ>0): ?><span style="background:rgba(13,148,136,.08);color:#0d9488;border:1px solid rgba(13,148,136,.2);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Out for cleaning/repair">💧 <?= $cleaningQ ?> cleaning</span><?php endif; ?>
        <?php if($disposedQ>0): ?><span style="background:rgba(153,27,27,.06);color:#991b1b;border:1px solid rgba(153,27,27,.15);border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;" title="Lifetime written off">🗑️ <?= $disposedQ ?> disposed (lifetime)</span><?php endif; ?>
        <?php if(!$hasPipeline): ?><span style="color:var(--text-muted);font-size:.72rem;font-style:italic;">✓ nothing pending</span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1.1rem;background:<?= $meta['light'] ?>;border-top:1.5px solid <?= $meta['border'] ?>;">
    <span style="font-size:.75rem;font-weight:700;color:<?= $meta['accent'] ?>;display:flex;align-items:center;gap:.35rem;"><i class="bi bi-calculator-fill"></i> Total <?= $meta['label'] ?> Available</span>
    <span style="font-family:'DM Mono',monospace;font-size:1rem;font-weight:800;color:<?= $meta['accent'] ?>;"><?= number_format($typeTotal) ?> <span style="font-size:.72rem;font-weight:600;">pcs</span></span>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php
// ═══ TAB: RELEASED ═════════════════════════════════════════════
elseif($tab==='released'): ?>

<?php if (!empty($editRow)):
  $er=$editRow;
  $erDate=$er['DateGiven'] instanceof DateTime
    ? $er['DateGiven']->format('Y-m-d')
    : (is_string($er['DateGiven']) ? date('Y-m-d',strtotime($er['DateGiven'])) : date('Y-m-d'));
?>
<div class="panel" style="border:2px solid var(--primary-light);">
  <div class="panel-hdr" style="background:var(--primary-glow);">
    <div class="panel-title" style="color:var(--primary);"><i class="bi bi-pencil-fill"></i> Editing — <?= safe($er['EmployeeName']) ?></div>
    <a href="?tab=released" class="btn-sm-action btn-del"><i class="bi bi-x-lg"></i> Cancel</a>
  </div>
  <div style="padding:1.25rem;">
    <form method="POST">
      <input type="hidden" name="edit_released" value="1">
      <input type="hidden" name="ReleasedID" value="<?= $er['ReleasedID'] ?>">
      <div class="row g-3">
        <div class="col-md-5"><label class="form-label">Employee Name <span style="color:#dc2626">*</span></label><input type="text" name="EmployeeName" class="form-control" value="<?= safe($er['EmployeeName']) ?>" required></div>
        <div class="col-md-3">
          <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
          <select name="UniformType" class="form-select" required>
            <option value="TSHIRT"    <?= $er['UniformType']==='TSHIRT'   ?'selected':'' ?>>T-Shirt (Logistics)</option>
            <option value="POLOSHIRT" <?= $er['UniformType']==='POLOSHIRT'?'selected':'' ?>>Polo Shirt (Office/Sales)</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Size <span style="color:#dc2626">*</span></label>
          <select name="UniformSize" class="form-select" required>
            <?php foreach($sizes as $sz): ?><option value="<?= $sz ?>" <?= $er['UniformSize']===$sz?'selected':'' ?>><?= $sz ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><label class="form-label">Quantity</label><input type="number" name="Quantity" class="form-control" value="<?= intval($er['Quantity']) ?>" min="1"></div>
        <div class="col-md-3">
          <label class="form-label">Department</label>
          <select name="Department" class="form-select">
            <option value="">— Select —</option>
            <?php foreach($depts as $d): ?><option value="<?= $d ?>" <?= ($er['Department']??'')===$d?'selected':'' ?>><?= $d ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label">Date Given</label><input type="date" name="DateGiven" class="form-control" value="<?= $erDate ?>"></div>
        <div class="col-md-4"><label class="form-label">Requested By (HR)</label><input type="text" name="RequestedBy" class="form-control" value="<?= safe($er['RequestedBy']??'') ?>" placeholder="e.g. Ma'am Niera"></div>
        <div class="col-md-2" style="display:flex;align-items:flex-end;"><button type="submit" class="btn-add w-100"><i class="bi bi-floppy-fill"></i> Save</button></div>
        <div class="col-12"><label class="form-label">Remarks</label><textarea name="Remarks" class="form-control" rows="2"><?= safe($er['Remarks']??'') ?></textarea></div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-hdr">
    <div class="panel-title"><i class="bi bi-send-fill" style="color:var(--primary-light)"></i> Uniforms Released / Sent</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <div style="background:var(--primary-glow);color:var(--primary);border:1px solid rgba(59,130,246,.25);border-radius:20px;padding:.2rem .75rem;font-size:.75rem;font-weight:700;">Total Given: <?= number_format($totalGivenCount) ?> pcs<?= $deptScope!==''?' ('.$deptScope.')':'' ?></div>
      <?php if($canManageStock): ?>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#releasedModal"><i class="bi bi-plus-lg"></i> Add</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter bar -->
  <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;width:100%;">
      <input type="hidden" name="tab" value="released">
      <input type="hidden" name="relpage" value="1">

      <div class="sbar" style="flex:1;min-width:160px;"><i class="bi bi-search"></i><input type="text" name="rsearch" placeholder="Employee or HR name…" value="<?= safe($relSearch) ?>"></div>

      <select name="reltype" class="form-select" style="width:175px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $relUType===''?'selected':'' ?>>All Uniform Types</option>
        <option value="TSHIRT"    <?= $relUType==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt</option>
        <option value="POLOSHIRT" <?= $relUType==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt</option>
      </select>

      <select name="relsize" class="form-select" style="width:110px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $relSize===''?'selected':'' ?>>All Sizes</option>
        <?php foreach($sizes as $sz): ?>
        <option value="<?= $sz ?>" <?= $relSize===$sz?'selected':'' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>

      <select name="relreqby" class="form-select" style="width:170px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $relReqBy===''?'selected':'' ?>>All Requested By</option>
        <?php foreach($reqByList as $rb): ?>
        <option value="<?= safe($rb) ?>" <?= $relReqBy===$rb?'selected':'' ?>><?= safe($rb) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="date" name="reldatefrom" class="form-control" style="width:145px;font-size:.78rem;padding:.3rem .55rem;" value="<?= safe($relDateFrom) ?>" title="Date Given — from">
      <input type="date" name="reldateto" class="form-control" style="width:145px;font-size:.78rem;padding:.3rem .55rem;" value="<?= safe($relDateTo) ?>" title="Date Given — to">

      <?php if($deptScope===''): ?>
      <select name="reldept" class="form-select" style="width:165px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $relDept===''?'selected':'' ?>>All Departments</option>
        <?php foreach($depts as $d): ?>
        <option value="<?= $d ?>" <?= $relDept===$d?'selected':'' ?>><?= safe($d) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <span style="background:rgba(59,130,246,.09);color:var(--primary);border:1px solid rgba(59,130,246,.2);border-radius:7px;padding:.3rem .65rem;font-size:.75rem;font-weight:700;white-space:nowrap;"><i class="bi bi-building"></i> <?= safe($deptScope) ?></span>
      <?php endif; ?>

      <button type="submit" class="btn-add" style="padding:.38rem .8rem;"><i class="bi bi-search"></i></button>
      <?php if($relSearch!==''||$relUType!==''||$relDept!==''||$relReqBy!==''||$relSize!==''||$relDateFrom!==''||$relDateTo!==''): ?>
      <a href="?tab=released" class="btn-sm-action btn-del" style="padding:.38rem .65rem;" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
  </div>
  <?php if(empty($released)): ?>
  <div class="empty-st"><i class="bi bi-send"></i><p>No release records found.</p></div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>#</th><th>Employee Name</th><th>Uniform Type</th><th>Size</th><th>Qty</th><th>Department</th><th>Date Given</th><th>Requested By</th><th>Remarks</th><th style="text-align:center;">Action</th></tr></thead>
    <tbody>
    <?php foreach($released as $i=>$r):
      $rowNum = ($relPage-1)*20 + $i + 1;
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;color:var(--text-primary);"><?= safe($r['EmployeeName']) ?></td>
      <td><span class="bdg <?= $r['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $r['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($r['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($r['Quantity']) ?></td>
      <td><?php if($r['Department']): ?><span class="bdg dept-<?= $r['Department'] ?>"><?= safe($r['Department']) ?></span><?php else: ?>—<?php endif; ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($r['DateGiven']) ?></td>
      <td style="font-size:.78rem;"><?= safe($r['RequestedBy']??'—') ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($r['Remarks']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <?php if($canManageStock): ?>
        <a href="?tab=released&editid=<?= $r['ReleasedID'] ?>&relpage=<?= $relPage ?>" class="btn-sm-action btn-edit">
          <i class="bi bi-pencil-fill"></i> Edit
        </a>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Return to Pending?','This release will be deleted, stock restored, and request reverted to Pending.','#dc2626')">
          <input type="hidden" name="delete_released" value="1">
          <input type="hidden" name="ReleasedID" value="<?= $r['ReleasedID'] ?>">
          <button type="submit" class="btn-sm-action btn-del" title="Delete & revert to pending"><i class="bi bi-arrow-return-left"></i></button>
        </form>
        <?php else: ?>
        <span style="color:var(--text-muted);font-size:.72rem;">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('relpage',$relPage,$relPages,$relTotal,['tab'=>'released','rsearch'=>$relSearch,'reltype'=>$relUType,'reldept'=>$relDept,'relreqby'=>$relReqBy,'relsize'=>$relSize,'reldatefrom'=>$relDateFrom,'reldateto'=>$relDateTo]) ?>
  <?php endif; ?>
</div>

<?php
// ═══ TAB: REQUESTS ═════════════════════════════════════════════
elseif($tab==='requests'):
// Build base URL params for filter links (preserves filters across page/tab switches)
$reqBaseParams = ['tab'=>'requests','rstatus'=>$reqStatus];
if($reqUType!=='') $reqBaseParams['rutype']=$reqUType;
if($deptScope==='' && $reqDept!=='') $reqBaseParams['rdept']=$reqDept;
?>
<div class="panel">
  <div class="panel-hdr" style="flex-wrap:wrap;gap:.6rem;">
    <div class="panel-title"><i class="bi bi-clipboard-check" style="color:var(--primary-light)"></i> Requested Uniform List</div>
    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#requestModal"><i class="bi bi-plus-lg"></i> Add Request</button>
  </div>

  <!-- ── Pending / Given tabs + filters ─────────────────────── -->
  <div style="padding:.75rem 1rem .5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.65rem;">

    <!-- Status tabs -->
    <div style="display:flex;gap:.3rem;background:var(--surface-2);border:1.5px solid var(--border);border-radius:10px;padding:.25rem;">
      <?php
        $pendingUrl = '?' . http_build_query(array_merge($reqBaseParams, ['rstatus'=>'pending','reqpage'=>1]));
        $givenUrl   = '?' . http_build_query(array_merge($reqBaseParams, ['rstatus'=>'given',  'reqpage'=>1]));
        $pendingActive = $reqStatus === 'pending';
        $activeStyle   = 'background:var(--primary);color:#fff;border-radius:7px;padding:.32rem .85rem;font-size:.78rem;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;';
        $inactiveStyle = 'background:transparent;color:var(--text-secondary);border-radius:7px;padding:.32rem .85rem;font-size:.78rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;';
      ?>
      <a href="<?= htmlspecialchars($pendingUrl) ?>" style="<?= $pendingActive ? $activeStyle : $inactiveStyle ?>">
        <i class="bi bi-hourglass-split"></i> Pending
        <span style="background:<?= $pendingActive?'rgba(255,255,255,.25)':'rgba(59,130,246,.12)' ?>;color:<?= $pendingActive?'#fff':'var(--primary)' ?>;border-radius:20px;padding:.05rem .45rem;font-size:.7rem;font-weight:800;">
          <?= $reqPendingCount ?>
        </span>
      </a>
      <a href="<?= htmlspecialchars($givenUrl) ?>" style="<?= !$pendingActive ? $activeStyle : $inactiveStyle ?>">
        <i class="bi bi-check-circle-fill"></i> Given
        <span style="background:<?= !$pendingActive?'rgba(255,255,255,.25)':'rgba(59,130,246,.12)' ?>;color:<?= !$pendingActive?'#fff':'var(--primary)' ?>;border-radius:20px;padding:.05rem .45rem;font-size:.7rem;font-weight:800;">
          <?= $reqGivenCount ?>
        </span>
      </a>
    </div>

    <!-- Dropdown filters -->
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="tab"     value="requests">
      <input type="hidden" name="rstatus" value="<?= safe($reqStatus) ?>">
      <input type="hidden" name="reqpage" value="1">

      <select name="rutype" class="form-select" style="width:175px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $reqUType===''?'selected':'' ?>>All Uniform Types</option>
        <option value="TSHIRT"    <?= $reqUType==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt (Logistics)</option>
        <option value="POLOSHIRT" <?= $reqUType==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt (Office/Sales)</option>
      </select>

      <?php if($deptScope===''): ?>
      <select name="rdept" class="form-select" style="width:165px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $reqDept===''?'selected':'' ?>>All Departments</option>
        <?php foreach($depts as $d): ?>
        <option value="<?= $d ?>" <?= $reqDept===$d?'selected':'' ?>><?= safe($d) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <span style="background:rgba(59,130,246,.09);color:var(--primary);border:1px solid rgba(59,130,246,.2);border-radius:7px;padding:.3rem .65rem;font-size:.75rem;font-weight:700;white-space:nowrap;"><i class="bi bi-building"></i> <?= safe($deptScope) ?></span>
      <?php endif; ?>

      <?php if($reqUType!=='' || ($deptScope==='' && $reqDept!=='')): ?>
      <a href="?tab=requests&rstatus=<?= $reqStatus ?>" class="btn-sm-action btn-del" style="padding:.38rem .65rem;" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
  </div>

  <?php if(empty($requests)): ?>
  <div class="empty-st"><i class="bi bi-clipboard"></i><p>No <?= $reqStatus === 'pending' ? 'pending' : 'given' ?> requests<?= ($reqUType!==''||$effectiveReqDept!=='') ? ' matching the selected filters' : '' ?><?= $deptScope!==''?' for '.$deptScope.'' : '' ?>.</p></div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Employee Name</th>
        <th>Requested By</th>
        <th>Uniform Type</th>
        <th>Size</th>
        <th>Qty</th>
        <?php if($reqStatus==='pending'): ?><th>Stock</th><?php endif; ?>
        <th>Department</th>
        <th>Date Requested</th>
        <?php if($reqStatus==='given'): ?><th>Date Given</th><?php endif; ?>
        <th>Remarks</th>
        <th style="text-align:center;">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($requests as $i=>$r):
      $rowNum  = ($reqPage-1)*20 + $i + 1;
      $stockOk = intval($r['CurrentStock']??0) >= intval($r['Quantity']);
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;color:var(--text-primary);"><?= safe($r['EmployeeName']??'—') ?></td>
      <td style="font-size:.78rem;"><?= safe($r['RequestedBy']) ?></td>
      <td><span class="bdg <?= $r['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $r['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($r['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($r['Quantity']) ?></td>
      <?php if($reqStatus==='pending'): ?>
      <td style="text-align:center;">
        <?php
          $avail = intval($r['CurrentStock']??0);
          $need  = intval($r['Quantity']);
          if ($avail<=0)    echo '<span style="background:rgba(220,38,38,.1);color:#dc2626;border:1px solid #fca5a5;border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;">Out of stock</span>';
          elseif ($avail<$need) echo '<span style="background:rgba(234,179,8,.1);color:#ca8a04;border:1px solid #fde047;border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;">Only '.$avail.' left</span>';
          else echo '<span style="background:rgba(16,185,129,.1);color:#059669;border:1px solid #6ee7b7;border-radius:20px;padding:.15rem .55rem;font-size:.7rem;font-weight:700;white-space:nowrap;">'.$avail.' in stock</span>';
        ?>
      </td>
      <?php endif; ?>
      <td><?php if($r['Department']): ?><span class="bdg dept-<?= $r['Department'] ?>"><?= safe($r['Department']) ?></span><?php else: ?>—<?php endif; ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($r['DateRequested']) ?></td>
      <?php if($reqStatus==='given'): ?>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($r['DateGiven']) ?></td>
      <?php endif; ?>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($r['Remarks']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <?php if($canManageStock): ?>
        <?php if(!$r['IsGiven']): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="mark_given" value="1">
          <input type="hidden" name="RequestID"  value="<?= $r['RequestID'] ?>">
          <?php if($stockOk): ?>
          <button type="submit" class="btn-sm-action btn-green"
            onclick="return confirmAction(event,'Mark as Given?','This will release the uniform and deduct from stock. Continue?','#059669')">
            <i class="bi bi-check-lg"></i> Given
          </button>
          <?php else: ?>
          <button type="button" class="btn-sm-action" disabled style="color:#94a3b8;border-color:#e2e8f0;cursor:not-allowed;" title="Insufficient stock — update stock first">
            <i class="bi bi-x-circle"></i> No Stock
          </button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete Request?','This will permanently delete this request. Continue?','#dc2626')">
          <input type="hidden" name="delete_request" value="1">
          <input type="hidden" name="RequestID"      value="<?= $r['RequestID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
        <?php else: ?>
        <span style="color:var(--text-muted);font-size:.72rem;"><?= $r['IsGiven'] ? 'Given' : 'Pending' ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('reqpage',$reqPage,$reqPages,$reqTotal,
        array_merge($reqBaseParams,['rutype'=>$reqUType,'rdept'=>($deptScope!==''?'':$reqDept)])) ?>
  <?php endif; ?>
</div>

<?php
// ═══ TAB: PO ═══════════════════════════════════════════════════
elseif($tab==='po'): ?>

<?php if(!empty($poList)): ?>
<div class="panel" style="margin-bottom:1.5rem;">
  <div class="panel-hdr"><div class="panel-title"><i class="bi bi-collection-fill" style="color:var(--primary-light)"></i> Purchase Orders</div></div>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>#</th><th>PO Number</th><th>PO Date</th><th>Items</th><th>Remarks</th><th>Created By</th><th style="text-align:center;">Action</th></tr></thead>
    <tbody>
    <?php foreach($poList as $i=>$po): // FIX: was using $rec/$uTypeRec — now correctly uses $po
      $rowNum = ($poPage-1)*20 + $i + 1;
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;font-family:'DM Mono',monospace;color:var(--primary);"><?= safe($po['PONumber']??'—') ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;"><?= fmtDate($po['PODate']) ?></td>
      <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= intval($po['ItemCount']) ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($po['Remarks']??'—') ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($po['CreatedBy']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <button class="btn-sm-action btn-edit" onclick="viewPOItems(<?= $po['POID'] ?>,'<?= addslashes($po['PONumber']??'') ?>')">
          <i class="bi bi-eye-fill"></i> View
        </button>
        <button class="btn-sm-action" onclick="printPO(<?= $po['POID'] ?>,'<?= addslashes($po['PONumber']??'') ?>')" style="color:#0891b2;border-color:rgba(8,145,178,.3);background:rgba(8,145,178,.05);">
          <i class="bi bi-printer-fill"></i> Print
        </button>
        <?php if($canManageStock): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete PO?','This will delete the PO and all its items. Continue?','#dc2626')">
          <input type="hidden" name="delete_po" value="1">
          <input type="hidden" name="POID" value="<?= $po['POID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('popage',$poPage,$poPages,$poTotal,['tab'=>'po']) ?>
</div>
<?php endif; ?>

<?php if($canManageStock): ?>
<div class="panel">
  <div class="panel-hdr" style="cursor:pointer;" onclick="togglePanel('poFormBody','poFormChevron')">
    <div class="panel-title"><i class="bi bi-file-earmark-plus-fill" style="color:var(--primary-light)"></i> Create Purchase Order</div>
    <button type="button" class="btn-add" style="pointer-events:none;">
      <i class="bi bi-plus-lg" id="poFormChevron"></i> New PO
    </button>
  </div>
  <div id="poFormBody" style="display:none;">
  <div style="padding:1.25rem;">
  <form method="POST">
    <input type="hidden" name="save_po" value="1">
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <label class="form-label">PO Number <span style="color:#dc2626">*</span></label>
        <input type="text" name="PONumber" class="form-control" value="<?= safe($nextPONum) ?>" required>
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:.2rem;"><i class="bi bi-info-circle"></i> Auto-generated. You may edit if needed.</div>
      </div>
      <div class="col-md-3"><label class="form-label">PO Date <span style="color:#dc2626">*</span></label><input type="date" name="PODate" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-3"><label class="form-label">Remarks</label><input type="text" name="Remarks" class="form-control" placeholder="Optional notes"></div>
    </div>
    <div style="background:var(--primary-glow);border:1px solid rgba(59,130,246,.2);border-radius:9px;padding:.55rem .9rem;margin-bottom:1rem;font-size:.76rem;color:var(--primary);font-weight:600;">
      <i class="bi bi-info-circle-fill"></i>
      <strong>How totals work:</strong> Requested Pieces = items from pending requests list. Additional Pieces = extra stock buffer (default 15 pcs/size). Total Pieces = Requested + Additional.
    </div>
    <div class="po-grid">
    <?php foreach(['TSHIRT'=>['label'=>'T-Shirt','cls'=>'tshirt'],'POLOSHIRT'=>['label'=>'Polo Shirt','cls'=>'polo']] as $type=>$meta): ?>
    <div class="po-type-card">
      <div class="po-type-hdr <?= $meta['cls'] ?>"><i class="bi bi-grid-3x3-gap-fill"></i> <?= $meta['label'] ?></div>
      <div style="padding:.75rem;">
      <table class="utbl" style="font-size:.78rem;">
        <thead><tr>
          <th style="text-align:left;">Size</th>
          <th style="text-align:center;">Requested<br><span style="font-weight:400;text-transform:none;font-size:.68rem;">(from pending list)</span></th>
          <th style="text-align:center;">Additional<br><span style="font-weight:400;text-transform:none;font-size:.68rem;">(ideal stock buffer)</span></th>
          <th style="text-align:center;">Total Pieces</th>
        </tr></thead>
        <tbody>
        <?php foreach($sizes as $sz):
          $reqPcs = intval($pendingReqMap[$type][$sz] ?? 0);
        ?>
        <tr>
          <td style="font-weight:700;font-family:'DM Mono',monospace;"><?= $sz ?></td>
          <td style="text-align:center;">
            <input type="number" name="req_<?= $type ?>_<?= $sz ?>" class="stock-input po-req"
              data-type="<?= $type ?>" data-size="<?= $sz ?>"
              value="<?= $reqPcs ?>" min="0">
          </td>
          <td style="text-align:center;">
            <input type="number" name="add_<?= $type ?>_<?= $sz ?>" class="stock-input po-add"
              data-type="<?= $type ?>" data-size="<?= $sz ?>"
              value="15" min="0">
          </td>
          <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;" id="total_<?= $type ?>_<?= $sz ?>">0</td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--surface-3);">
          <td colspan="2" style="font-weight:700;color:var(--primary);">Grand Total</td>
          <td></td>
          <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);" id="grandtotal_<?= $type ?>">0</td>
        </tr>
        </tbody>
      </table>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:1.25rem;text-align:right;">
      <button type="submit" class="btn-add"><i class="bi bi-floppy-fill"></i> Save Purchase Order</button>
    </div>
  </form>
  </div>
  </div><!-- /poFormBody -->
</div>
<?php endif; ?>

<?php
// ═══ TAB: RECEIVING ════════════════════════════════════════════
elseif($tab==='receiving'):

$poItemsAll = rq($conn,"SELECT POID,UniformType,Size,Requested,Additional FROM [dbo].[UniformPOItems] ORDER BY POID");
$poItemsMap = [];
foreach($poItemsAll as $pi) {
    $poItemsMap[$pi['POID']][$pi['UniformType']][$pi['Size']] = [
        'requested' => intval($pi['Requested']),
        'additional'=> intval($pi['Additional']),
    ];
}
?>

<?php if(!empty($recList)): ?>
<div class="panel" style="margin-bottom:1.5rem;">
  <div class="panel-hdr">
    <div class="panel-title"><i class="bi bi-box-seam-fill" style="color:var(--primary-light)"></i> Receiving Records</div>
  </div>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>#</th><th>PO Number</th><th>Uniform Type</th><th>Date Received</th><th>Printing Shop</th><th>Printing Shop Rep</th><th>UTC Rep</th><th style="text-align:center;">Stock Status</th><th>Created By</th><th style="text-align:center;">Action</th></tr></thead>
    <tbody>
    <?php foreach($recList as $i=>$rec):
      $rowNum=($recPage-1)*20+$i+1;
      $uTypeRec  = $rec['UniformType'] ?? '';
      $isPosted  = intval($rec['IsPosted'] ?? 0);
      $postedAt  = $rec['PostedAt'] ?? null;
      $postedBy  = $rec['PostedBy'] ?? '';
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;font-family:'DM Mono',monospace;color:var(--primary);"><?= safe($rec['PONumber']??'—') ?></td>
      <td>
        <?php if($uTypeRec): ?>
        <span class="bdg <?= $uTypeRec==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $uTypeRec ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <!-- FIX: was $rec['DateReceived'] — use RFDate with fallback to DateReceived -->
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;"><?= fmtDate($rec['DateReceived'] ?? $rec['RFDate']) ?></td>
      <!-- FIX: PrintingShop is correct -->
      <td style="font-size:.78rem;font-weight:600;"><?= safe($rec['PrintingShop']??'—') ?></td>
      <!-- FIX: was $rec['PrintingShopRep'] — correct column is RepresentativeThem -->
      <td style="font-size:.78rem;"><?= safe($rec['RepresentativeThem']??'—') ?></td>
      <!-- FIX: was $rec['UTCRep'] — correct column is RepresentativeUs -->
      <td style="font-size:.78rem;"><?= safe($rec['RepresentativeUs']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <?php if($isPosted): ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;background:#dcfce7;color:#15803d;border:1px solid #86efac;border-radius:20px;padding:.2rem .65rem;font-size:.72rem;font-weight:700;">
            <i class="bi bi-check-circle-fill"></i> Posted
          </span>
          <?php if($postedAt): ?>
          <div style="font-size:.67rem;color:var(--text-muted);margin-top:.15rem;"><?= fmtDate($postedAt) ?><?= $postedBy ? ' · '.safe($postedBy) : '' ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;border-radius:20px;padding:.2rem .65rem;font-size:.72rem;font-weight:700;">
            <i class="bi bi-dash-circle"></i> Unposted
          </span>
        <?php endif; ?>
      </td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($rec['CreatedBy']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <!-- FIX: was $rec['ReceivingID'] — correct PK is RFID -->
        <button class="btn-sm-action btn-edit" onclick="viewRecItems(<?= $rec['RFID'] ?>,'<?= addslashes($rec['PONumber']??'') ?>','<?= addslashes($rec['UniformType']??'') ?>')">
          <i class="bi bi-eye-fill"></i> View
        </button>
        <?php if($canManageStock): ?>
        <?php if(!$isPosted): ?>
        <a href="?tab=receiving&editrecid=<?= $rec['RFID'] ?>&recpage=<?= $recPage ?>" class="btn-sm-action btn-edit">
          <i class="bi bi-pencil-fill"></i> Edit
        </a>
        <?php endif; ?>
        <?php if(!$isPosted): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Post to Stocks?','This will add the received quantities into AdditionalStock. Continue?','#15803d')">
          <input type="hidden" name="post_to_stocks" value="1">
          <input type="hidden" name="ReceivingID" value="<?= $rec['RFID'] ?>">
          <button type="submit" class="btn-sm-action" style="color:#15803d;border-color:rgba(21,128,61,.3);background:rgba(21,128,61,.06);">
            <i class="bi bi-box-arrow-in-down-right"></i> Post to Stocks
          </button>
        </form>
        <?php else: ?>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Un-post from Stocks?','This will reverse the quantities from AdditionalStock. This cannot be undone automatically if stock was consumed after posting.','#ca8a04')">
          <input type="hidden" name="unpost_from_stocks" value="1">
          <input type="hidden" name="ReceivingID" value="<?= $rec['RFID'] ?>">
          <button type="submit" class="btn-sm-action" style="color:#ca8a04;border-color:rgba(202,138,4,.3);background:rgba(202,138,4,.06);">
            <i class="bi bi-arrow-counterclockwise"></i> Un-post
          </button>
        </form>
        <?php endif; ?>
        <?php if(!$isPosted): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete Receiving Record?','This will permanently delete this receiving record. Continue?','#dc2626')">
          <input type="hidden" name="delete_receiving" value="1">
          <!-- FIX: was $rec['ReceivingID'] — correct PK is RFID -->
          <input type="hidden" name="ReceivingID" value="<?= $rec['RFID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <!-- FIX: was $rec['ReceivingID'] — correct PK is RFID -->
        <button class="btn-sm-action" onclick="printReceiving(<?= $rec['RFID'] ?>)" style="color:#0891b2;border-color:rgba(8,145,178,.3);background:rgba(8,145,178,.05);">
          <i class="bi bi-printer-fill"></i> Print
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('recpage',$recPage,$recPages,$recTotal,['tab'=>'receiving']) ?>
</div>
<?php endif; ?>

<!-- ── Receiving Form ──────────────────────────────────────────── -->
<?php if($canManageStock): ?>
<div class="panel">
  <div class="panel-hdr" <?php if($editRecId<=0): ?>style="cursor:pointer;" onclick="togglePanel('recFormBody','recFormChevron')"<?php endif; ?>>
    <div class="panel-title">
      <?php if($editRecId>0): ?>
        <i class="bi bi-pencil-fill" style="color:var(--primary)"></i> Edit Receiving Record
        <?php
          $editPONum = '';
          foreach($poForReceiving as $pp){
            if(intval($pp['POID'])===intval($editRecRow['POID']??0)) { $editPONum=$pp['PONumber']; break; }
          }
          if($editPONum) echo ' — <span style="font-family:\'DM Mono\',monospace;color:var(--primary);">'.safe($editPONum).'</span>';
        ?>
      <?php else: ?>
        <i class="bi bi-box-seam-fill" style="color:var(--primary-light)"></i> New Receiving Form
      <?php endif; ?>
    </div>
    <?php if($editRecId>0): ?>
      <a href="?tab=receiving" class="btn-sm-action btn-del"><i class="bi bi-x-lg"></i> Cancel</a>
    <?php else: ?>
      <button type="button" class="btn-add" style="pointer-events:none;">
        <i class="bi bi-plus-lg" id="recFormChevron"></i> New Receiving
      </button>
    <?php endif; ?>
  </div>

  <div id="recFormBody" style="<?= $editRecId>0 ? '' : 'display:none;' ?>">
  <div style="padding:1.25rem;">
  <form method="POST">
    <input type="hidden" name="save_receiving" value="1">
    <?php if($editRecId>0): ?><input type="hidden" name="ReceivingID" value="<?= $editRecId ?>"><?php endif; ?>

    <div style="font-size:.78rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.4rem;padding-bottom:.55rem;border-bottom:1px solid var(--border);margin-bottom:.9rem;">
      <i class="bi bi-truck" style="color:var(--primary-light)"></i> Delivery Details
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Purchase Order <span style="color:#dc2626">*</span></label>
        <select name="POID_REC" id="recPOSelect" class="form-select" required onchange="recFillPO(this.value)">
          <option value="">— Select PO —</option>
          <?php foreach($poForReceiving as $po): ?>
          <option value="<?= $po['POID'] ?>" <?= intval($editRecRow['POID']??0)===$po['POID']?'selected':'' ?>>
            <?= safe($po['PONumber']) ?> — <?= fmtDate($po['PODate']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <?php
          $recDateVal = $editRecId>0
            ? (($editRecRow['RFDate'] instanceof DateTime)
                ? $editRecRow['RFDate']->format('Y-m-d')
                : date('Y-m-d',strtotime($editRecRow['RFDate']??date('Y-m-d'))))
            : date('Y-m-d');
        ?>
        <label class="form-label">Date Received <span style="color:#dc2626">*</span></label>
        <input type="date" name="DateReceived" class="form-control" value="<?= $recDateVal ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Printing Shop Name</label>
        <input type="text" name="PrintingShop" class="form-control" value="<?= safe($editRecRow['PrintingShop']??'') ?>" placeholder="e.g. ABC Printing, Stitch Express…">
      </div>
      <div class="col-md-3" style="display:flex;align-items:flex-end;">
        <div style="background:var(--primary-glow);border:1px solid rgba(59,130,246,.2);border-radius:9px;padding:.5rem .8rem;font-size:.75rem;color:var(--primary);font-weight:600;width:100%;">
          <i class="bi bi-info-circle-fill"></i> PO selection auto-fills the size &amp; quantity grid below.
        </div>
      </div>
    </div>

    <div style="font-size:.78rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.4rem;padding-bottom:.55rem;border-bottom:1px solid var(--border);margin-bottom:.9rem;margin-top:1.1rem;">
      <i class="bi bi-toggles" style="color:var(--primary-light)"></i> Uniform Type &amp; Received Quantities
    </div>

    <?php
      $editRecUniformType = $editRecRow['UniformType'] ?? 'TSHIRT';
      if(!in_array($editRecUniformType,['TSHIRT','POLOSHIRT'])) $editRecUniformType='TSHIRT';
    ?>
    <input type="hidden" name="ReceivingUniformType" id="recUniformTypeInput" value="<?= $editRecUniformType ?>">

    <div style="display:flex;gap:.5rem;margin-bottom:1rem;">
      <?php foreach(['TSHIRT'=>['label'=>'T-Shirt (Logistics)','icon'=>'bi-person-standing','accent'=>'#1e40af','light'=>'rgba(59,130,246,.1)','border'=>'rgba(59,130,246,.3)'],
                     'POLOSHIRT'=>['label'=>'Polo Shirt (Office / Sales)','icon'=>'bi-person-badge','accent'=>'#0891b2','light'=>'rgba(8,145,178,.1)','border'=>'rgba(8,145,178,.3)']] as $bt=>$bm): ?>
      <button type="button"
        id="typeToggle_<?= $bt ?>"
        onclick="recSetType('<?= $bt ?>')"
        style="flex:1;padding:.52rem 1rem;border-radius:9px;font-size:.8rem;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .13s;display:flex;align-items:center;justify-content:center;gap:.5rem;
        <?= $editRecUniformType===$bt ? "background:{$bm['light']};color:{$bm['accent']};border:2px solid {$bm['accent']};" : "background:var(--surface);color:var(--text-secondary);border:1.5px solid var(--border);" ?>">
        <i class="bi <?= $bm['icon'] ?>"></i> <?= $bm['label'] ?>
      </button>
      <?php endforeach; ?>
    </div>

    <?php
      $recSizes=['XS','S','M','L','XL','XXL','XXXL','4XL'];
      $editPOID = intval($editRecRow['POID']??0);
    ?>
    <?php foreach(['TSHIRT'=>['label'=>'T-Shirt','cls'=>'tshirt','accent'=>'#1e40af','light'=>'rgba(59,130,246,.08)','border'=>'rgba(59,130,246,.2)'],
                   'POLOSHIRT'=>['label'=>'Polo Shirt','cls'=>'polo','accent'=>'#0891b2','light'=>'rgba(8,145,178,.08)','border'=>'rgba(8,145,178,.2)']] as $type=>$meta): ?>
    <div id="recSection_<?= $type ?>" style="<?= $editRecUniformType===$type?'':'display:none;' ?>">
      <div class="po-type-card" style="margin-bottom:.75rem;">
        <div class="po-type-hdr <?= $meta['cls'] ?>">
          <i class="bi bi-grid-3x3-gap-fill"></i> <?= $meta['label'] ?> — Received Quantities
        </div>
        <div style="padding:.75rem;overflow-x:auto;">
        <table class="utbl" style="font-size:.79rem;min-width:500px;">
          <thead><tr>
            <th style="text-align:left;width:60px;">Size</th>
            <th style="text-align:center;">PO Ordered (pcs)</th>
            <th style="text-align:center;">Qty Received *</th>
            <th style="text-align:center;">Variance</th>
          </tr></thead>
          <tbody>
          <?php
            $typeTotal = 0;
            foreach($recSizes as $sz):
              $qtyRec  = intval($editRecItems[$type][$sz] ?? 0);
              $poQtyOrdered = 0;
              if($editPOID>0 && isset($poItemsMap[$editPOID][$type][$sz])){
                  $pi = $poItemsMap[$editPOID][$type][$sz];
                  $poQtyOrdered = $pi['requested'] + $pi['additional'];
              }
              $typeTotal += $qtyRec;
          ?>
          <tr>
            <td><span class="<?= $meta['cls']==='tshirt'?'bdg bdg-tshirt':'bdg bdg-polo' ?>" style="font-family:'DM Mono',monospace;font-size:.75rem;"><?= $sz ?></span></td>
            <td style="text-align:center;font-family:'DM Mono',monospace;color:var(--text-muted);" id="poOrd_<?= $type ?>_<?= $sz ?>"><?= $poQtyOrdered>0?$poQtyOrdered:'—' ?></td>
            <td style="text-align:center;">
              <input type="number"
                name="rec_<?= $type ?>_<?= $sz ?>"
                class="stock-input rec-qty-new"
                data-rectype="<?= $type ?>"
                data-size="<?= $sz ?>"
                value="<?= $qtyRec ?>"
                min="0"
                oninput="recalcNew('<?= $type ?>')">
            </td>
            <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.78rem;" id="recvar_<?= $type ?>_<?= $sz ?>">
              <?php
                if($poQtyOrdered>0 || $qtyRec>0){
                    $diff=$qtyRec-$poQtyOrdered;
                    $col=$diff===0?'#059669':($diff>0?'#ca8a04':'#dc2626');
                    echo "<span style='color:{$col};font-weight:700;'>".($diff>=0?'+'.$diff:$diff)."</span>";
                } else { echo '—'; }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:var(--surface-3);">
              <td style="padding:.5rem .85rem;font-weight:700;color:<?= $meta['accent'] ?>;" colspan="2">Total <?= $meta['label'] ?></td>
              <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:<?= $meta['accent'] ?>;padding:.5rem .85rem;" id="newrectotal_<?= $type ?>"><?= $typeTotal ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;background:var(--primary-glow);border:1px solid rgba(59,130,246,.2);border-radius:9px;padding:.6rem 1rem;margin-bottom:1.25rem;">
      <span style="font-size:.78rem;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:.35rem;"><i class="bi bi-calculator-fill"></i> Grand Total Pieces Received (this session)</span>
      <span style="font-family:'DM Mono',monospace;font-size:1rem;font-weight:800;color:var(--primary);" id="newrecgrand">0</span>
    </div>

    <div style="font-size:.78rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:.4rem;padding-bottom:.55rem;border-bottom:1px solid var(--border);margin-bottom:.9rem;">
      <i class="bi bi-pen-fill" style="color:var(--primary-light)"></i> Representative Information &amp; Signatures
    </div>

    <div class="row g-3" style="margin-bottom:1.25rem;">
      <div class="col-md-6">
        <div style="border:1.5px solid var(--border);border-radius:10px;padding:.9rem;background:var(--surface);">
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.6rem;"><i class="bi bi-shop"></i> Printing Shop Representative</div>
          <div class="mb-2">
            <label class="form-label">Name <span style="color:#dc2626">*</span></label>
            <!-- FIX: field maps to RepresentativeThem in DB -->
            <input type="text" name="PrintingShopRep" class="form-control" value="<?= safe($editRecRow['RepresentativeThem']??'') ?>" placeholder="Full name of printing shop rep" required>
          </div>
          <div style="border-top:1px solid var(--border);padding-top:.6rem;margin-top:.5rem;">
            <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:.4rem;font-weight:600;">Signature</div>
            <div style="border:1.5px dashed var(--border);border-radius:8px;height:64px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;">
              <span style="font-size:.72rem;color:var(--text-muted);font-style:italic;">Print and sign manually</span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div style="border:1.5px solid var(--border);border-radius:10px;padding:.9rem;background:var(--surface);">
          <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.6rem;"><i class="bi bi-building"></i> Urban Tradewell Corp. Representative</div>
          <div class="mb-2">
            <label class="form-label">Name <span style="color:#dc2626">*</span></label>
            <!-- FIX: field maps to RepresentativeUs in DB -->
            <input type="text" name="UTCRep" class="form-control" value="<?= safe($editRecRow['RepresentativeUs']??'') ?>" placeholder="Full name of UTC representative" required>
          </div>
          <div style="border-top:1px solid var(--border);padding-top:.6rem;margin-top:.5rem;">
            <div style="font-size:.7rem;color:var(--text-muted);margin-bottom:.4rem;font-weight:600;">Signature</div>
            <div style="border:1.5px dashed var(--border);border-radius:8px;height:64px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;">
              <span style="font-size:.72rem;color:var(--text-muted);font-style:italic;">Print and sign manually</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:.75rem;justify-content:flex-end;align-items:center;">
      <span style="font-size:.75rem;color:var(--text-muted);"><i class="bi bi-printer"></i> After saving, use the Print button to generate the receiving document.</span>
      <button type="submit" class="btn-add"><i class="bi bi-floppy-fill"></i> Save Receiving Record</button>
    </div>
  </form>
  </div><!-- /padding -->
  </div><!-- /recFormBody -->
</div>
<?php endif; ?>

<script>
const recPOItems = <?= json_encode($poItemsMap) ?>;
const recSizes   = <?= json_encode($sizes) ?>;
const recUTypes  = ['TSHIRT','POLOSHIRT'];

function recFillPO(poidStr){
  const poid = parseInt(poidStr)||0;
  const data = poid ? (recPOItems[poid]||{}) : {};
  recUTypes.forEach(type => {
    recSizes.forEach(sz => {
      const ordEl = document.getElementById('poOrd_'+type+'_'+sz);
      const recInp = document.querySelector('[name="rec_'+type+'_'+sz+'"]');
      if(ordEl){
        const item = (data[type]||{})[sz];
        const total = item ? (parseInt(item.requested||0)+parseInt(item.additional||0)) : 0;
        ordEl.textContent = total > 0 ? total : '—';
        if(recInp) recInp.value = total > 0 ? total : 0;
      }
    });
    recalcNew(type);
  });
}

function recSetType(type){
  document.getElementById('recUniformTypeInput').value = type;
  recUTypes.forEach(t => {
    const sec = document.getElementById('recSection_'+t);
    const btn = document.getElementById('typeToggle_'+t);
    if(sec) sec.style.display = t===type ? '' : 'none';
    if(btn){
      if(t===type){
        if(type==='TSHIRT'){
          btn.style.background='rgba(59,130,246,.1)';btn.style.color='#1e40af';btn.style.border='2px solid #1e40af';
        } else {
          btn.style.background='rgba(8,145,178,.1)';btn.style.color='#0891b2';btn.style.border='2px solid #0891b2';
        }
      } else {
        btn.style.background='var(--surface)';btn.style.color='var(--text-secondary)';btn.style.border='1.5px solid var(--border)';
      }
    }
  });
  recalcNew(type);
  updateRecGrand();
}

function recalcNew(type){
  let total=0;
  recSizes.forEach(sz=>{
    const inp = document.querySelector('[name="rec_'+type+'_'+sz+'"]');
    const varEl = document.getElementById('recvar_'+type+'_'+sz);
    const ordEl = document.getElementById('poOrd_'+type+'_'+sz);
    const rec = inp ? (parseInt(inp.value)||0) : 0;
    total += rec;
    if(varEl && ordEl){
      const ordTxt = ordEl.textContent.trim();
      const ord = ordTxt==='—' ? 0 : (parseInt(ordTxt)||0);
      if(ord>0||rec>0){
        const diff=rec-ord;
        const col=diff===0?'#059669':(diff>0?'#ca8a04':'#dc2626');
        varEl.innerHTML='<span style="color:'+col+';font-weight:700;">'+(diff>=0?'+':'')+diff+'</span>';
      } else { varEl.textContent='—'; }
    }
  });
  const totEl=document.getElementById('newrectotal_'+type);
  if(totEl) totEl.textContent=total;
  updateRecGrand();
}

function updateRecGrand(){
  const activeType = document.getElementById('recUniformTypeInput').value;
  const el = document.getElementById('newrectotal_'+activeType);
  const g = el ? (parseInt(el.textContent)||0) : 0;
  const gEl = document.getElementById('newrecgrand');
  if(gEl) gEl.textContent=g;
}

document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.rec-qty-new').forEach(el=>el.addEventListener('input',function(){recalcNew(this.dataset.rectype);}));

  const poSel=document.getElementById('recPOSelect');
  const isEditing=<?= $editRecId>0 ? 'true' : 'false' ?>;

  if(poSel&&poSel.value){
    if(isEditing){
      const poid=parseInt(poSel.value)||0;
      const data=poid?(recPOItems[poid]||{}):{};
      recUTypes.forEach(type=>{
        recSizes.forEach(sz=>{
          const ordEl=document.getElementById('poOrd_'+type+'_'+sz);
          if(ordEl){
            const item=(data[type]||{})[sz];
            const total=item?(parseInt(item.requested||0)+parseInt(item.additional||0)):0;
            ordEl.textContent=total>0?total:'—';
          }
        });
      });
    } else {
      recFillPO(poSel.value);
    }
  }

  recalcNew('TSHIRT');
  recalcNew('POLOSHIRT');
  updateRecGrand();
});
</script>

<?php
// ═══ TAB: RETURNS ══════════════════════════════════════════════
elseif($tab==='returns'):
?>

<?php if (!empty($editRetRow)):
  $er  = $editRetRow;
  $erDate = $er['DateReturned'] instanceof DateTime
    ? $er['DateReturned']->format('Y-m-d')
    : (is_string($er['DateReturned']) ? date('Y-m-d',strtotime($er['DateReturned'])) : date('Y-m-d'));
  $erPending = ($er['InspectionStatus'] ?? 'Pending Inspection') === 'Pending Inspection';
  $condOptionsEdit = ['Good'=>'✅ Good','Faded'=>'🎨 Faded','Stained'=>'💧 Stained','Torn'=>'✂️ Torn','Other'=>'❓ Other'];
?>
<div class="panel" style="border:2px solid var(--primary-light);">
  <div class="panel-hdr" style="background:var(--primary-glow);">
    <div class="panel-title" style="color:var(--primary);"><i class="bi bi-pencil-fill"></i> Editing Return — <?= safe($er['EmployeeName']) ?></div>
    <a href="?tab=returns" class="btn-sm-action btn-del"><i class="bi bi-x-lg"></i> Cancel</a>
  </div>
  <div style="padding:1.25rem;">
    <?php if(!$erPending): ?>
    <div style="display:flex;align-items:center;gap:.5rem;background:rgba(234,179,8,.08);border:1px solid #fde047;border-radius:8px;padding:.5rem .75rem;margin-bottom:1rem;font-size:.78rem;color:#854d0e;">
      <i class="bi bi-lock-fill"></i> Already inspected (<?= safe($er['InspectionStatus']) ?>) — type, size and quantity are locked. Delete and re-add the return if those need to change.
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="edit_return" value="1">
      <input type="hidden" name="ReturnID"    value="<?= $er['ReturnID'] ?>">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Employee Name <span style="color:#dc2626">*</span></label><input type="text" name="ReturnEmployeeName" class="form-control" value="<?= safe($er['EmployeeName']) ?>" required></div>
        <div class="col-md-3">
          <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
          <select name="ReturnUniformType" class="form-select" required <?= $erPending?'':'disabled' ?>>
            <option value="TSHIRT"    <?= $er['UniformType']==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt (Logistics)</option>
            <option value="POLOSHIRT" <?= $er['UniformType']==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt (Office/Sales)</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Size <span style="color:#dc2626">*</span></label>
          <select name="ReturnUniformSize" class="form-select" required <?= $erPending?'':'disabled' ?>>
            <?php foreach($sizes as $sz): ?><option value="<?= $sz ?>" <?= $er['UniformSize']===$sz?'selected':'' ?>><?= $sz ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1"><label class="form-label">Qty</label><input type="number" name="ReturnQuantity" class="form-control" value="<?= intval($er['Quantity']) ?>" min="1" <?= $erPending?'':'disabled' ?>></div>
        <div class="col-md-2">
          <label class="form-label">Condition (reported)</label>
          <select name="Condition" class="form-select">
            <?php foreach($condOptionsEdit as $val=>$lbl): ?><option value="<?= $val ?>" <?= ($er['Condition']??'')===$val?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Department</label>
          <select name="ReturnDepartment" class="form-select">
            <option value="">— Select —</option>
            <?php foreach($depts as $d): ?><option value="<?= $d ?>" <?= ($er['Department']??'')===$d?'selected':'' ?>><?= safe($d) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label">Date Returned</label><input type="date" name="DateReturned" class="form-control" value="<?= $erDate ?>"></div>
        <div class="col-md-4"><label class="form-label">Returned To (UTC Staff)</label><input type="text" name="ReturnedTo" class="form-control" value="<?= safe($er['ReturnedTo']??'') ?>" placeholder="e.g. Ma'am Niera"></div>
        <div class="col-md-2" style="display:flex;align-items:flex-end;"><button type="submit" class="btn-add w-100"><i class="bi bi-floppy-fill"></i> Save</button></div>
        <div class="col-12"><label class="form-label">Remarks</label><textarea name="ReturnRemarks" class="form-control" rows="2"><?= safe($er['Remarks']??'') ?></textarea></div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php if(!empty($cleaningList)): ?>
<div class="panel" style="border:1.5px solid #5eead4;">
  <div class="panel-hdr" style="background:rgba(13,148,136,.08);">
    <div class="panel-title" style="color:#0d9488;"><i class="bi bi-droplet-fill"></i> In Cleaning / Repair (<?= count($cleaningList) ?>)</div>
  </div>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>Employee</th><th>Type</th><th>Size</th><th>Qty</th><th>Sent</th><?php if($canManageStock): ?><th style="text-align:center;">Action</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach($cleaningList as $c): ?>
    <tr>
      <td style="font-weight:700;"><?= safe($c['EmployeeName']) ?></td>
      <td><span class="bdg <?= $c['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $c['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($c['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($c['Quantity']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($c['InspectedAt'] ?? $c['DateReturned']) ?></td>
      <?php if($canManageStock): ?>
      <td style="text-align:center;">
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Mark as repaired?','It will move to the Returned holding bucket. Use Add to Stock afterward to make it available.','#4338ca')">
          <input type="hidden" name="complete_cleaning" value="1"><input type="hidden" name="ReturnID" value="<?= $c['ReturnID'] ?>">
          <button type="submit" class="btn-sm-action" style="background:rgba(67,56,202,.1);color:#4338ca;border:1px solid #a5b4fc;"><i class="bi bi-check-circle-fill"></i> Mark Repaired</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if(!empty($returnedReadyList)): ?>
<div class="panel" style="border:1.5px solid #a5b4fc;">
  <div class="panel-hdr" style="background:rgba(67,56,202,.08);">
    <div class="panel-title" style="color:#4338ca;"><i class="bi bi-box-seam-fill"></i> Returned — Ready to Stock In (<?= count($returnedReadyList) ?>)</div>
  </div>
  <div style="padding:.5rem 1rem;font-size:.75rem;color:var(--text-muted);border-bottom:1px solid var(--border);">Confirmed good and physically back, but not yet counted as Available. Click <strong>Add to Stock</strong> once it's actually placed back in inventory.</div>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>Employee</th><th>Type</th><th>Size</th><th>Qty</th><th>Confirmed</th><?php if($canManageStock): ?><th style="text-align:center;">Action</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach($returnedReadyList as $rr): ?>
    <tr>
      <td style="font-weight:700;"><?= safe($rr['EmployeeName']) ?></td>
      <td><span class="bdg <?= $rr['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $rr['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($rr['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($rr['Quantity']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($rr['InspectedAt'] ?? $rr['DateReturned']) ?></td>
      <?php if($canManageStock): ?>
      <td style="text-align:center;">
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Add to Stock?','This will finally count it toward Available stock.','#059669')">
          <input type="hidden" name="add_to_stock" value="1"><input type="hidden" name="ReturnID" value="<?= $rr['ReturnID'] ?>">
          <button type="submit" class="btn-sm-action" style="background:rgba(16,185,129,.1);color:#059669;border:1px solid #6ee7b7;"><i class="bi bi-box-arrow-in-down"></i> Add to Stock</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-hdr">
    <div class="panel-title"><i class="bi bi-arrow-return-left" style="color:var(--primary-light)"></i> Uniform Returns — Full History</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <div style="background:var(--primary-glow);color:var(--primary);border:1px solid rgba(59,130,246,.25);border-radius:20px;padding:.2rem .75rem;font-size:.75rem;font-weight:700;">Total Returned: <?= number_format($totalReturnCount) ?> pcs<?= $deptScope!==''?' ('.$deptScope.')':'' ?></div>
      <?php if($canManageStock): ?>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#returnModal"><i class="bi bi-plus-lg"></i> Add Return</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Filter bar -->
  <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
    <form method="GET" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;width:100%;">
      <input type="hidden" name="tab" value="returns">
      <input type="hidden" name="retpage" value="1">

      <div class="sbar" style="flex:1;min-width:160px;"><i class="bi bi-search"></i><input type="text" name="retsearch" placeholder="Employee or staff name…" value="<?= safe($retSearch) ?>"></div>

      <select name="rettype" class="form-select" style="width:175px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $retUType===''?'selected':'' ?>>All Uniform Types</option>
        <option value="TSHIRT"    <?= $retUType==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt</option>
        <option value="POLOSHIRT" <?= $retUType==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt</option>
      </select>

      <select name="retsize" class="form-select" style="width:110px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $retSize===''?'selected':'' ?>>All Sizes</option>
        <?php foreach($sizes as $sz): ?>
        <option value="<?= $sz ?>" <?= $retSize===$sz?'selected':'' ?>><?= $sz ?></option>
        <?php endforeach; ?>
      </select>

      <select name="retreturnedto" class="form-select" style="width:170px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $retReturnedTo===''?'selected':'' ?>>All Returned To</option>
        <?php foreach($returnedToList as $rt): ?>
        <option value="<?= safe($rt) ?>" <?= $retReturnedTo===$rt?'selected':'' ?>><?= safe($rt) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="date" name="retdatefrom" class="form-control" style="width:145px;font-size:.78rem;padding:.3rem .55rem;" value="<?= safe($retDateFrom) ?>" title="Date Returned — from">
      <input type="date" name="retdateto" class="form-control" style="width:145px;font-size:.78rem;padding:.3rem .55rem;" value="<?= safe($retDateTo) ?>" title="Date Returned — to">

      <?php if($deptScope===''): ?>
      <select name="retdept" class="form-select" style="width:165px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $retDept===''?'selected':'' ?>>All Departments</option>
        <?php foreach($depts as $d): ?>
        <option value="<?= $d ?>" <?= $retDept===$d?'selected':'' ?>><?= safe($d) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <span style="background:rgba(59,130,246,.09);color:var(--primary);border:1px solid rgba(59,130,246,.2);border-radius:7px;padding:.3rem .65rem;font-size:.75rem;font-weight:700;white-space:nowrap;"><i class="bi bi-building"></i> <?= safe($deptScope) ?></span>
      <?php endif; ?>

      <button type="submit" class="btn-add" style="padding:.38rem .8rem;"><i class="bi bi-search"></i></button>
      <?php if($retSearch!==''||$retUType!==''||$retDept!==''||$retSize!==''||$retReturnedTo!==''||$retDateFrom!==''||$retDateTo!==''): ?>
      <a href="?tab=returns" class="btn-sm-action btn-del" style="padding:.38rem .65rem;" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
  </div>

  <?php if(empty($retList)): ?>
  <div class="empty-st"><i class="bi bi-arrow-return-left"></i><p>No return records<?= $retSearch!==''?' matching your search':'' ?>.</p></div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Employee Name</th>
        <th>Uniform Type</th>
        <th>Size</th>
        <th>Qty</th>
        <th>Reported Condition</th>
        <th>Status</th>
        <th>Department</th>
        <th>Date Returned</th>
        <th>Returned To</th>
        <th>Remarks</th>
        <th style="text-align:center;">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $statusStyles = [
      'Pending Inspection' => ['bg'=>'rgba(234,179,8,.1)', 'clr'=>'#ca8a04','bdr'=>'#fde047','icon'=>'⏳'],
      'Returned'           => ['bg'=>'rgba(67,56,202,.1)', 'clr'=>'#4338ca','bdr'=>'#a5b4fc','icon'=>'📦'],
      'Cleaning/Repair'    => ['bg'=>'rgba(13,148,136,.1)','clr'=>'#0d9488','bdr'=>'#5eead4','icon'=>'💧'],
      'Disposed'           => ['bg'=>'rgba(220,38,38,.1)', 'clr'=>'#dc2626','bdr'=>'#fca5a5','icon'=>'🗑️'],
      'Stocked'            => ['bg'=>'rgba(16,185,129,.1)','clr'=>'#059669','bdr'=>'#6ee7b7','icon'=>'✅'],
    ];
    foreach($retList as $i=>$r):
      $rowNum = ($retPage-1)*20 + $i + 1;
      $status = $r['InspectionStatus'] ?? 'Pending Inspection';
      $ss     = $statusStyles[$status] ?? $statusStyles['Pending Inspection'];
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;color:var(--text-primary);"><?= safe($r['EmployeeName']) ?></td>
      <td><span class="bdg <?= $r['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $r['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($r['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($r['Quantity']) ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($r['Condition']??'—') ?></td>
      <td>
        <span style="background:<?= $ss['bg'] ?>;color:<?= $ss['clr'] ?>;border:1px solid <?= $ss['bdr'] ?>;border-radius:20px;padding:.18rem .55rem;font-size:.68rem;font-weight:700;white-space:nowrap;">
          <?= $ss['icon'] ?> <?= safe($status) ?>
        </span>
      </td>
      <td><?php if($r['Department']): ?><span class="bdg dept-<?= $r['Department'] ?>"><?= safe($r['Department']) ?></span><?php else: ?>—<?php endif; ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($r['DateReturned']) ?></td>
      <td style="font-size:.78rem;"><?= safe($r['ReturnedTo']??'—') ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($r['Remarks']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <?php if($canManageStock): ?>
        <a href="?tab=returns&editretid=<?= $r['ReturnID'] ?>&retpage=<?= $retPage ?>" class="btn-sm-action btn-edit">
          <i class="bi bi-pencil-fill"></i> Edit
        </a>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete Return?','This will delete the return record and reverse any stock it had moved into. Continue?','#dc2626')">
          <input type="hidden" name="delete_return" value="1">
          <input type="hidden" name="ReturnID"      value="<?= $r['ReturnID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
        <?php else: ?>
        <span style="color:var(--text-muted);font-size:.72rem;">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('retpage',$retPage,$retPages,$retTotal,['tab'=>'returns','retsearch'=>$retSearch,'rettype'=>$retUType,'retdept'=>($deptScope!==''?'':$retDept),'retsize'=>$retSize,'retreturnedto'=>$retReturnedTo,'retdatefrom'=>$retDateFrom,'retdateto'=>$retDateTo]) ?>
  <?php endif; ?>
</div>

<?php
// ═══ TAB: PENDING INSPECTION ═══════════════════════════════════
elseif($tab==='pending_inspection'):
?>

<div class="panel" style="border:1.5px solid #fde047;">
  <div class="panel-hdr" style="background:rgba(234,179,8,.08);">
    <div class="panel-title" style="color:#854d0e;"><i class="bi bi-hourglass-split"></i> Pending Inspection (<?= count($pendingInspectionList) ?>)</div>
  </div>
  <?php if(empty($pendingInspectionList)): ?>
  <div class="empty-st"><i class="bi bi-hourglass-split"></i><p>No returns awaiting inspection.</p></div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead><tr><th>Employee</th><th>Type</th><th>Size</th><th>Qty</th><th>Reported</th><th>Date</th><?php if($canManageStock): ?><th style="text-align:center;">Inspect</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach($pendingInspectionList as $p): ?>
    <tr>
      <td style="font-weight:700;"><?= safe($p['EmployeeName']) ?></td>
      <td><span class="bdg <?= $p['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $p['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($p['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($p['Quantity']) ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($p['Condition']??'—') ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($p['DateReturned']) ?></td>
      <?php if($canManageStock): ?>
      <td style="text-align:center;white-space:nowrap;">
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Confirm this item is Returned?','It will be HELD as confirmed-good, but not yet counted as Available. Use Add to Stock afterward to make it available.','#4338ca')">
          <input type="hidden" name="inspect_return" value="1"><input type="hidden" name="ReturnID" value="<?= $p['ReturnID'] ?>"><input type="hidden" name="Decision" value="Returned">
          <button type="submit" class="btn-sm-action" style="background:rgba(67,56,202,.1);color:#4338ca;border:1px solid #a5b4fc;"><i class="bi bi-check-circle-fill"></i> Mark Returned</button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Send for cleaning/repair?','It will be held out of available stock until marked repaired.','#0d9488')">
          <input type="hidden" name="inspect_return" value="1"><input type="hidden" name="ReturnID" value="<?= $p['ReturnID'] ?>"><input type="hidden" name="Decision" value="Cleaning/Repair">
          <button type="submit" class="btn-sm-action" style="background:rgba(13,148,136,.1);color:#0d9488;border:1px solid #5eead4;"><i class="bi bi-droplet-fill"></i> Cleaning</button>
        </form>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Dispose this item?','It will be permanently removed from stock.','#dc2626')">
          <input type="hidden" name="inspect_return" value="1"><input type="hidden" name="ReturnID" value="<?= $p['ReturnID'] ?>"><input type="hidden" name="Decision" value="Disposed">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i> Dispose</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php
// ═══ TAB: REPORT ═══════════════════════════════════════════════
elseif($tab==='report'):
?>


<!-- Report filter bar -->
<div class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-hdr">
    <div class="panel-title"><i class="bi bi-funnel-fill" style="color:var(--primary-light)"></i> Report Filters</div>
    <div style="display:flex;gap:.5rem;align-items:center;">
      <button onclick="printReport()" class="btn-add" style="background:#0891b2;border-color:#0891b2;">
        <i class="bi bi-printer-fill"></i> Print Report
      </button>
    </div>
  </div>
  <div style="padding:.85rem 1.1rem;">
    <form method="GET" style="display:flex;gap:.65rem;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="tab" value="report">
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.06em;">Date From</label>
        <input type="date" name="rpt_from" class="form-control" style="width:155px;font-size:.8rem;padding:.32rem .6rem;" value="<?= safe($rptFrom) ?>">
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.06em;">Date To</label>
        <input type="date" name="rpt_to" class="form-control" style="width:155px;font-size:.8rem;padding:.32rem .6rem;" value="<?= safe($rptTo) ?>">
      </div>
      <?php if($deptScope===''): ?>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.06em;">Department</label>
        <select name="rpt_dept" class="form-select" style="width:165px;font-size:.8rem;padding:.32rem .6rem;">
          <option value="">All Departments</option>
          <?php foreach($depts as $d): ?><option value="<?= $d ?>" <?= $rptDept===$d?'selected':'' ?>><?= safe($d) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.06em;">Uniform Type</label>
        <select name="rpt_type" class="form-select" style="width:175px;font-size:.8rem;padding:.32rem .6rem;">
          <option value="">All Types</option>
          <option value="TSHIRT"    <?= $rptUType==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt</option>
          <option value="POLOSHIRT" <?= $rptUType==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt</option>
        </select>
      </div>
      <button type="submit" class="btn-add"><i class="bi bi-search"></i> Generate</button>
      <a href="?tab=report" class="btn-sm-action" style="padding:.38rem .75rem;align-self:flex-end;"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
    </form>
  </div>
</div>

<!-- ══ Printable Report ══════════════════════════════════════════ -->
<div id="reportPrintArea">

<!-- Print-only header (hidden on screen) -->
<div class="rpt-print-header" style="display:none;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;padding-bottom:.75rem;border-bottom:2px solid #1e40af;">
    <div>
      <div style="font-size:1.2rem;font-weight:800;color:#1e40af;">Urban Tradewell Corp.</div>
      <div style="font-size:.85rem;font-weight:600;color:#64748b;">Uniform Inventory — Summary Report</div>
    </div>
    <div style="text-align:right;font-size:.75rem;color:#64748b;">
      <div><strong>Period:</strong> <?= date('M d, Y', strtotime($rptFrom)) ?> – <?= date('M d, Y', strtotime($rptTo)) ?></div>
      <div><strong>Department:</strong> <?= $rptDept !== '' ? safe($rptDept) : 'All Departments' ?></div>
      <div><strong>Uniform Type:</strong> <?= $rptUType !== '' ? safe($rptUType) : 'All Types' ?></div>
      <div><strong>Generated:</strong> <?= date('M d, Y h:i A') ?> by <?= safe($currentUser) ?></div>
    </div>
  </div>
</div>

<!-- Summary cards row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;">
  <?php
    $tshirtRel  = intval($rptRelMap['TSHIRT']['TotalQty']   ?? 0);
    $poloRel    = intval($rptRelMap['POLOSHIRT']['TotalQty'] ?? 0);
    $tshirtRet  = intval($rptRetMap['TSHIRT']['TotalQty']   ?? 0);
    $poloRet    = intval($rptRetMap['POLOSHIRT']['TotalQty'] ?? 0);
    $tshirtCur  = 0; $poloCur = 0;
    foreach($rptStockSnap as $s){
        if($s['UniformType']==='TSHIRT')    $tshirtCur += max(0,intval($s['CurrentStock']));
        if($s['UniformType']==='POLOSHIRT') $poloCur   += max(0,intval($s['CurrentStock']));
    }
  ?>
  <div class="stat-card" style="border-color:rgba(59,130,246,.3);">
    <div class="stat-icon">📦</div>
    <div class="stat-label">Total Released</div>
    <div class="stat-value sv-blue"><?= number_format($rptGrandRelQty) ?></div>
    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;">in selected period</div>
  </div>
  <div class="stat-card" style="border-color:rgba(8,145,178,.3);">
    <div class="stat-icon">👕</div>
    <div class="stat-label">T-Shirt Released</div>
    <div class="stat-value sv-blue"><?= number_format($tshirtRel) ?></div>
    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;"><?= number_format($tshirtCur) ?> pcs in stock</div>
  </div>
  <div class="stat-card" style="border-color:rgba(5,150,105,.3);">
    <div class="stat-icon">👔</div>
    <div class="stat-label">Polo Released</div>
    <div class="stat-value sv-teal"><?= number_format($poloRel) ?></div>
    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;"><?= number_format($poloCur) ?> pcs in stock</div>
  </div>
  <div class="stat-card" style="border-color:rgba(124,58,237,.3);">
    <div class="stat-icon">↩️</div>
    <div class="stat-label">Total Returns</div>
    <div class="stat-value" style="color:#7c3aed;"><?= number_format($rptGrandRetQty) ?></div>
    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;">in selected period</div>
  </div>
  <div class="stat-card" style="border-color:rgba(202,138,4,.3);">
    <div class="stat-icon">📋</div>
    <div class="stat-label">Total Requests</div>
    <div class="stat-value sv-amber"><?= number_format($rptGrandReqQty) ?></div>
    <div style="font-size:.68rem;color:var(--text-muted);margin-top:.2rem;">in selected period</div>
  </div>
</div>

<!-- Row 1: Stock Snapshot + Released by Size -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;" class="rpt-2col">

  <!-- Stock Snapshot -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-boxes" style="color:var(--primary-light)"></i> Current Stock Snapshot</div></div>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead>
        <tr>
          <th>Type</th>
          <th>Size</th>
          <th style="text-align:center;">Prev</th>
          <th style="text-align:center;">Added</th>
          <th style="text-align:center;">Less</th>
          <th style="text-align:center;">Returned</th>
          <th style="text-align:center;">Stocked</th>
          <th style="text-align:center;">Cleaning</th>
          <th style="text-align:center;">Disposed</th>
          <th style="text-align:center;color:var(--primary);">Current</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach(['TSHIRT','POLOSHIRT'] as $ut):
        $typeTotal = 0;
        foreach($sizes as $sz):
          $sr = $rptStockMap[$ut][$sz] ?? null;
          if(!$sr) continue;
          $cur = max(0,intval($sr['CurrentStock']));
          $typeTotal += $cur;
          if($cur===0){$dot='#dc2626';}elseif($cur<=5){$dot='#ca8a04';}else{$dot='#10b981';}
      ?>
      <tr>
        <td><span class="bdg <?= $ut==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>" style="font-size:.65rem;"><?= $ut==='TSHIRT'?'TSHIRT':'POLO' ?></span></td>
        <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= $sz ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;"><?= intval($sr['PreviousStock']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#0891b2;"><?= intval($sr['AdditionalStock']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#dc2626;"><?= intval($sr['LessStock']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#4338ca;"><?= intval($sr['ReturnedStock']??0) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#7c3aed;"><?= intval($sr['ReturnStock']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#0d9488;"><?= intval($sr['CleaningStock']??0) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-size:.76rem;color:#991b1b;"><?= intval($sr['DisposedStock']??0) ?></td>
        <td style="text-align:center;">
          <span style="font-family:'DM Mono',monospace;font-weight:800;color:<?= $dot ?>;font-size:.85rem;"><?= $cur ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:var(--surface-2);">
        <td colspan="9" style="font-weight:700;color:<?= $ut==='TSHIRT'?'#1e40af':'#0891b2' ?>;font-size:.75rem;">
          <?= $ut==='TSHIRT'?'T-Shirt':'Polo Shirt' ?> Subtotal
        </td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:<?= $ut==='TSHIRT'?'#1e40af':'#0891b2' ?>;"><?= number_format($typeTotal) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Released by Size -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-send-fill" style="color:var(--primary-light)"></i> Released by Uniform Type &amp; Size</div></div>
    <?php if(empty($rptRelBySize)): ?>
    <div class="empty-st"><i class="bi bi-send"></i><p>No release data for the selected period.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead><tr><th>Type</th><th>Size</th><th style="text-align:center;">Qty Released</th></tr></thead>
      <tbody>
      <?php
        $prevType='';$typeSubtotal=0;
        foreach($rptRelBySize as $row):
          if($prevType!=='' && $prevType!==$row['UniformType']):
      ?>
      <tr style="background:var(--surface-2);">
        <td colspan="2" style="font-weight:700;color:#1e40af;font-size:.75rem;"><?= $prevType==='TSHIRT'?'T-Shirt':'Polo Shirt' ?> Subtotal</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:#1e40af;"><?= number_format($typeSubtotal) ?></td>
      </tr>
      <?php $typeSubtotal=0; endif;
        $prevType=$row['UniformType'];
        $typeSubtotal+=intval($row['TotalQty']);
      ?>
      <tr>
        <td><span class="bdg <?= $row['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>" style="font-size:.65rem;"><?= $row['UniformType']==='TSHIRT'?'TSHIRT':'POLO' ?></span></td>
        <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($row['Size']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= number_format(intval($row['TotalQty'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if($prevType!==''): ?>
      <tr style="background:var(--surface-2);">
        <td colspan="2" style="font-weight:700;color:#0891b2;font-size:.75rem;"><?= $prevType==='TSHIRT'?'T-Shirt':'Polo Shirt' ?> Subtotal</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:#0891b2;"><?= number_format($typeSubtotal) ?></td>
      </tr>
      <?php endif; ?>
      <tr style="background:var(--primary-glow);">
        <td colspan="2" style="font-weight:800;color:var(--primary);">Grand Total</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($rptGrandRelQty) ?></td>
      </tr>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 2: Released by Department + Top Employees -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;" class="rpt-2col">

  <!-- Released by Department -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-building" style="color:var(--primary-light)"></i> Released by Department</div></div>
    <?php if(empty($rptRelByDept)): ?>
    <div class="empty-st"><i class="bi bi-building"></i><p>No department data for the selected period.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead><tr><th>Department</th><th>Type</th><th style="text-align:center;">Qty</th></tr></thead>
      <tbody>
      <?php
        $prevDept=''; $deptSub=0;
        foreach($rptRelByDept as $row):
          if($prevDept!=='' && $prevDept!==$row['Department']):
      ?>
      <tr style="background:var(--surface-2);">
        <td colspan="2" style="font-weight:700;color:var(--primary);font-size:.74rem;"><?= safe($prevDept) ?> Subtotal</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($deptSub) ?></td>
      </tr>
      <?php $deptSub=0; endif;
        $prevDept=$row['Department'];
        $deptSub+=intval($row['TotalQty']);
      ?>
      <tr>
        <td style="font-weight:600;"><?= $row['Department']!==''?safe($row['Department']):'<span style="color:var(--text-muted)">—</span>' ?></td>
        <td><span class="bdg <?= $row['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>" style="font-size:.65rem;"><?= $row['UniformType']==='TSHIRT'?'TSHIRT':'POLO' ?></span></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= number_format(intval($row['TotalQty'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if($prevDept!==''): ?>
      <tr style="background:var(--surface-2);">
        <td colspan="2" style="font-weight:700;color:var(--primary);font-size:.74rem;"><?= safe($prevDept) ?> Subtotal</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($deptSub) ?></td>
      </tr>
      <?php endif; ?>
      <tr style="background:var(--primary-glow);">
        <td colspan="2" style="font-weight:800;color:var(--primary);">Grand Total</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($rptGrandRelQty) ?></td>
      </tr>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Top Employees -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-people-fill" style="color:var(--primary-light)"></i> Top 10 Employees (by qty released)</div></div>
    <?php if(empty($rptTopEmp)): ?>
    <div class="empty-st"><i class="bi bi-person"></i><p>No employee release data for the selected period.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead><tr><th>#</th><th>Employee</th><th>Dept</th><th>Type</th><th style="text-align:center;">Qty</th></tr></thead>
      <tbody>
      <?php foreach($rptTopEmp as $i=>$row): ?>
      <tr>
        <td style="color:var(--text-muted);font-family:'DM Mono',monospace;font-weight:700;"><?= $i+1 ?></td>
        <td style="font-weight:700;color:var(--text-primary);"><?= safe($row['EmployeeName']) ?></td>
        <td><?= $row['Department']?'<span class="bdg dept-'.safe($row['Department']).'">'.safe($row['Department']).'</span>':'—' ?></td>
        <td><span class="bdg <?= $row['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>" style="font-size:.65rem;"><?= $row['UniformType']==='TSHIRT'?'TSHIRT':'POLO' ?></span></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format(intval($row['TotalQty'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 3: Returns Summary + Request Summary -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;" class="rpt-2col">

  <!-- Returns summary -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-arrow-return-left" style="color:var(--primary-light)"></i> Returns Summary</div></div>
    <?php if(empty($rptRetTotals) && empty($rptRetCond)): ?>
    <div class="empty-st"><i class="bi bi-arrow-return-left"></i><p>No return records for the selected period.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead><tr><th>Metric</th><th>TSHIRT</th><th>POLOSHIRT</th><th style="text-align:center;">Total</th></tr></thead>
      <tbody>
      <tr>
        <td style="font-weight:600;">Total Returned (pcs)</td>
        <td style="font-family:'DM Mono',monospace;"><?= number_format(intval($rptRetMap['TSHIRT']['TotalQty']??0)) ?></td>
        <td style="font-family:'DM Mono',monospace;"><?= number_format(intval($rptRetMap['POLOSHIRT']['TotalQty']??0)) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= number_format($rptGrandRetQty) ?></td>
      </tr>
      <tr>
        <td style="font-weight:600;">Records</td>
        <td style="font-family:'DM Mono',monospace;"><?= intval($rptRetMap['TSHIRT']['Records']??0) ?></td>
        <td style="font-family:'DM Mono',monospace;"><?= intval($rptRetMap['POLOSHIRT']['Records']??0) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= intval($rptRetMap['TSHIRT']['Records']??0)+intval($rptRetMap['POLOSHIRT']['Records']??0) ?></td>
      </tr>
      </tbody>
    </table>
    <?php if(!empty($rptRetCond)): ?>
    <div style="padding:.6rem .85rem;border-top:1px solid var(--border);font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">By Status</div>
    <table class="utbl">
      <thead><tr><th>Status</th><th style="text-align:center;">Records</th><th style="text-align:center;">Qty</th></tr></thead>
      <tbody>
      <?php
      $rcStyles = [
        'Pending Inspection' => ['clr'=>'#ca8a04','icon'=>'⏳'],
        'Returned'           => ['clr'=>'#4338ca','icon'=>'📦'],
        'Cleaning/Repair'    => ['clr'=>'#0d9488','icon'=>'💧'],
        'Disposed'           => ['clr'=>'#dc2626','icon'=>'🗑️'],
        'Stocked'            => ['clr'=>'#059669','icon'=>'✅'],
      ];
      foreach($rptRetCond as $rc):
        $st = $rc['InspectionStatus'] ?? 'Pending Inspection';
        $rs = $rcStyles[$st] ?? $rcStyles['Pending Inspection'];
      ?>
      <tr>
        <td><span style="color:<?= $rs['clr'] ?>;font-weight:700;"><?= $rs['icon'] ?> <?= safe($st) ?></span></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;"><?= intval($rc['Records']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= number_format(intval($rc['TotalQty'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Requests summary -->
  <div class="panel" style="margin-bottom:0;">
    <div class="panel-hdr"><div class="panel-title"><i class="bi bi-clipboard-check" style="color:var(--primary-light)"></i> Requests Summary</div></div>
    <?php if(empty($rptReqTotals)): ?>
    <div class="empty-st"><i class="bi bi-clipboard"></i><p>No request records for the selected period.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="utbl">
      <thead><tr><th>Type</th><th style="text-align:center;">Total Qty</th><th style="text-align:center;">Given</th><th style="text-align:center;">Pending</th><th style="text-align:center;">Fulfillment %</th></tr></thead>
      <tbody>
      <?php foreach($rptReqTotals as $rr):
        $totalRec = intval($rr['GivenCount'])+intval($rr['PendingCount']);
        $pct = $totalRec>0 ? round(intval($rr['GivenCount'])/$totalRec*100) : 0;
      ?>
      <tr>
        <td><span class="bdg <?= $rr['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $rr['UniformType'] ?></span></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;"><?= number_format(intval($rr['TotalQty'])) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;color:#059669;font-weight:700;"><?= intval($rr['GivenCount']) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;color:#dc2626;font-weight:700;"><?= intval($rr['PendingCount']) ?></td>
        <td style="text-align:center;">
          <div style="display:flex;align-items:center;gap:.4rem;">
            <div style="flex:1;background:#e2e8f0;border-radius:20px;height:6px;overflow:hidden;">
              <div style="width:<?= $pct ?>%;background:<?= $pct>=80?'#059669':($pct>=50?'#ca8a04':'#dc2626') ?>;height:100%;border-radius:20px;"></div>
            </div>
            <span style="font-family:'DM Mono',monospace;font-size:.73rem;font-weight:700;min-width:32px;"><?= $pct ?>%</span>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <tr style="background:var(--primary-glow);">
        <td style="font-weight:800;color:var(--primary);">Total</td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($rptGrandReqQty) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:#059669;"><?= array_sum(array_column($rptReqTotals,'GivenCount')) ?></td>
        <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:700;color:#dc2626;"><?= array_sum(array_column($rptReqTotals,'PendingCount')) ?></td>
        <td></td>
      </tr>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Monthly Trend -->
<?php if(!empty($rptMonths)): ?>
<div class="panel" style="margin-bottom:1.25rem;">
  <div class="panel-hdr"><div class="panel-title"><i class="bi bi-graph-up" style="color:var(--primary-light)"></i> Monthly Release Trend (Last 12 Months)</div></div>
  <div style="overflow-x:auto;">
  <table class="utbl">
    <thead>
      <tr>
        <th>Month</th>
        <?php foreach(['TSHIRT','POLOSHIRT'] as $ut): ?>
        <th style="text-align:center;"><?= $ut==='TSHIRT'?'👕 T-Shirt':'👔 Polo Shirt' ?></th>
        <?php endforeach; ?>
        <th style="text-align:center;">Monthly Total</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $colMax = 1;
      foreach($rptMonths as $mo) {
          $tot = 0;
          foreach(['TSHIRT','POLOSHIRT'] as $ut) $tot += intval($rptMonthMap[$mo][$ut]??0);
          if($tot>$colMax) $colMax=$tot;
      }
      $grandMonthTotal = 0;
      $monthTotByType  = ['TSHIRT'=>0,'POLOSHIRT'=>0];
      foreach($rptMonths as $mo):
        $moTotal = 0;
        foreach(['TSHIRT','POLOSHIRT'] as $ut) $moTotal += intval($rptMonthMap[$mo][$ut]??0);
        $grandMonthTotal += $moTotal;
    ?>
    <tr>
      <td style="font-family:'DM Mono',monospace;font-weight:700;white-space:nowrap;"><?= date('M Y', strtotime($mo.'-01')) ?></td>
      <?php foreach(['TSHIRT','POLOSHIRT'] as $ut):
        $v = intval($rptMonthMap[$mo][$ut]??0);
        $monthTotByType[$ut] += $v;
        $barW = $colMax>0 ? round($v/$colMax*80) : 0;
      ?>
      <td style="text-align:center;min-width:120px;">
        <?php if($v>0): ?>
        <div style="display:flex;align-items:center;justify-content:center;gap:.4rem;">
          <div style="width:<?= $barW ?>px;min-width:4px;max-width:80px;height:10px;border-radius:3px;background:<?= $ut==='TSHIRT'?'#3b82f6':'#0891b2' ?>;"></div>
          <span style="font-family:'DM Mono',monospace;font-weight:700;font-size:.78rem;"><?= number_format($v) ?></span>
        </div>
        <?php else: ?><span style="color:var(--text-muted);font-size:.75rem;">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
      <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($moTotal) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:var(--primary-glow);font-weight:700;">
      <td style="color:var(--primary);font-weight:800;">Total</td>
      <?php foreach(['TSHIRT','POLOSHIRT'] as $ut): ?>
      <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($monthTotByType[$ut]) ?></td>
      <?php endforeach; ?>
      <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:800;color:var(--primary);"><?= number_format($grandMonthTotal) ?></td>
    </tr>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Print footer -->
<div class="rpt-print-footer" style="display:none;margin-top:1.5rem;padding-top:.75rem;border-top:1.5px solid #e2e8f0;display:none;">
  <div style="display:flex;justify-content:space-between;font-size:.72rem;color:#94a3b8;">
    <span>Urban Tradewell Corp. — Uniform Inventory System</span>
    <span>Printed: <?= date('M d, Y h:i A') ?></span>
  </div>
</div>

</div><!-- /#reportPrintArea -->

<style>
@media print {
  body * { visibility: hidden; }
  #reportPrintArea, #reportPrintArea * { visibility: visible; }
  #reportPrintArea { position: fixed; left: 0; top: 0; width: 100%; padding: 1.5rem 2rem; }
  .rpt-print-header, .rpt-print-footer { display: block !important; }
  .rpt-2col { grid-template-columns: 1fr 1fr !important; }
  .panel { break-inside: avoid; box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
  .panel-hdr { background: #f8fafc !important; }
  .utbl thead th { background: #f1f5f9 !important; }
  .btn-add, form, .panel-hdr > div:last-child { display: none !important; }
  a { color: inherit !important; text-decoration: none !important; }
}
</style>

<?php endif; // ═══ end if/elseif tab chain ?>

</div><!-- /container -->

<!-- ══ MODAL: Release Uniform ══════════════════════════════════ -->
<div class="modal fade" id="releasedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="save_released" value="1">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-send-fill" style="color:var(--primary)"></i> Release Uniform to Employee</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem;">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Employee Name <span style="color:#dc2626">*</span></label><input type="text" name="EmployeeName" class="form-control" placeholder="Full name" required></div>
            <div class="col-md-3">
              <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
              <select name="UniformType" id="relType" class="form-select" required onchange="autoType(this.value)">
                <option value="">— Select —</option>
                <option value="TSHIRT">T-Shirt (Logistics)</option>
                <option value="POLOSHIRT">Polo Shirt (Office/Sales)</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Size <span style="color:#dc2626">*</span></label>
              <select name="UniformSize" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach($sizes as $sz): ?><option><?= $sz ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label">Quantity</label><input type="number" name="Quantity" class="form-control" value="3" min="1"></div>
            <div class="col-md-3">
              <label class="form-label">Department</label>
              <select name="Department" class="form-select">
                <option value="">— Select —</option>
                <?php foreach($depts as $d): ?><option><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Date Given</label><input type="date" name="DateGiven" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label">Requested By (HR)</label><input type="text" name="RequestedBy" class="form-control" placeholder="e.g. Ma'am Niera"></div>
            <div class="col-12"><label class="form-label">Remarks</label><textarea name="Remarks" class="form-control" rows="2" placeholder="Optional notes…"></textarea></div>
          </div>
          <div id="typeHint" style="display:none;margin-top:.75rem;padding:.55rem .85rem;border-radius:8px;font-size:.78rem;font-weight:600;background:var(--primary-glow);color:var(--primary);border:1px solid rgba(59,130,246,.2);">
            <i class="bi bi-info-circle-fill"></i> <span id="typeHintText"></span>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border);">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add"><i class="bi bi-check-circle-fill"></i> Confirm Release</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: Add Request ════════════════════════════════════ -->
<div class="modal fade" id="requestModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="save_request" value="1">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-clipboard-plus-fill" style="color:var(--primary)"></i> Add Uniform Request</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:1.25rem;">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Employee Name <span style="color:#dc2626">*</span></label><input type="text" name="EmployeeName" class="form-control" placeholder="Full name of employee" required></div>
            <div class="col-md-6"><label class="form-label">Requested By (HR) <span style="color:#dc2626">*</span></label><input type="text" name="RequestedBy" class="form-control" placeholder="HR name e.g. Ma'am Niera" required></div>
            <div class="col-md-6">
              <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
              <select name="UniformType" class="form-select" required>
                <option value="">— Select —</option>
                <option value="TSHIRT">T-Shirt (Logistics)</option>
                <option value="POLOSHIRT">Polo Shirt (Office/Sales)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Size <span style="color:#dc2626">*</span></label>
              <select name="UniformSize" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach($sizes as $sz): ?><option><?= $sz ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Quantity</label><input type="number" name="Quantity" class="form-control" value="3" min="1"></div>
            <div class="col-md-4">
              <label class="form-label">Department</label>
              <select name="Department" class="form-select">
                <option value="">— Select —</option>
                <?php foreach($depts as $d): ?><option><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5"><label class="form-label">Date Requested</label><input type="date" name="DateRequested" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><label class="form-label">Remarks</label><textarea name="Remarks" class="form-control" rows="2" placeholder="Optional notes…"></textarea></div>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border);">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add"><i class="bi bi-floppy-fill"></i> Save Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: View PO Items ════════════════════════════════════ -->
<div class="modal fade" id="poItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="poItemsTitle"><i class="bi bi-eye-fill" style="color:var(--primary)"></i> PO Items</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="poItemsBody" style="padding:1.25rem;"></div>
      <div class="modal-footer" style="border-top:1px solid var(--border);">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: View Receiving Items ═════════════════════════════ -->
<div class="modal fade" id="recItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="recItemsTitle"><i class="bi bi-box-seam-fill" style="color:var(--primary)"></i> Receiving Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="recItemsBody" style="padding:1.25rem;"></div>
      <div class="modal-footer" style="border-top:1px solid var(--border);gap:.5rem;">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn-add" id="recItemsPrintBtn" style="display:none;" onclick="printReceivingFromModal()">
          <i class="bi bi-printer-fill"></i> Print
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: Add Return ════════════════════════════════════════ -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-return-left" style="color:var(--primary)"></i> Record Uniform Return</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="save_return" value="1">
        <div class="modal-body" style="padding:1.25rem;">
          <div style="display:flex;align-items:center;gap:.5rem;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:8px;padding:.5rem .75rem;margin-bottom:1rem;font-size:.78rem;color:var(--primary);">
            <i class="bi bi-info-circle-fill"></i> This goes to <strong>Pending Inspection</strong> — stock is only updated once it's inspected on the Returns tab.
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Employee Name <span style="color:#dc2626">*</span></label>
              <input type="text" name="ReturnEmployeeName" class="form-control" placeholder="e.g. Juan dela Cruz" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
              <select name="ReturnUniformType" class="form-select" required>
                <option value="">— Select —</option>
                <option value="TSHIRT">👕 T-Shirt (Logistics)</option>
                <option value="POLOSHIRT">👔 Polo Shirt (Office/Sales)</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Size <span style="color:#dc2626">*</span></label>
              <select name="ReturnUniformSize" class="form-select" required>
                <option value="">—</option>
                <?php foreach($sizes as $sz): ?><option value="<?= $sz ?>"><?= $sz ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-1">
              <label class="form-label">Qty</label>
              <input type="number" name="ReturnQuantity" class="form-control" value="1" min="1">
            </div>
            <div class="col-md-2">
              <label class="form-label">Reported Condition</label>
              <select name="Condition" class="form-select">
                <option value="Good">✅ Good</option>
                <option value="Faded">🎨 Faded</option>
                <option value="Stained">💧 Stained</option>
                <option value="Torn">✂️ Torn</option>
                <option value="Other">❓ Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Department</label>
              <select name="ReturnDepartment" class="form-select">
                <option value="">— Select —</option>
                <?php foreach($depts as $d): ?><option value="<?= $d ?>"><?= safe($d) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Date Returned</label>
              <input type="date" name="DateReturned" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Returned To (UTC Staff)</label>
              <input type="text" name="ReturnedTo" class="form-control" placeholder="e.g. Ma'am Niera">
            </div>
            <div class="col-md-2">
              <label class="form-label">Linked Release ID <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
              <input type="number" name="ReturnReleasedID" class="form-control" placeholder="0" min="0">
            </div>
            <div class="col-12">
              <label class="form-label">Remarks</label>
              <textarea name="ReturnRemarks" class="form-control" rows="2" placeholder="Optional notes…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="border-top:1px solid var(--border);">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-add"><i class="bi bi-floppy-fill"></i> Save Return</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
function togglePanel(bodyId, iconId) {
  const body = document.getElementById(bodyId);
  const icon = document.getElementById(iconId);
  const isOpen = body.style.display !== 'none';
  body.style.display = isOpen ? 'none' : '';
  if (icon) {
    icon.className = isOpen ? 'bi bi-plus-lg' : 'bi bi-dash-lg';
  }
}

function confirmAction(e,title,text,color){
  e.preventDefault();
  const form=e.target.closest('form');
  Swal.fire({title:title,text:text,icon:'question',showCancelButton:true,
    confirmButtonColor:color||'#1e40af',cancelButtonColor:'#64748b',
    confirmButtonText:'Yes, continue',cancelButtonText:'Cancel',
    background:'#fff',color:'#0f172a'})
    .then(r=>{if(r.isConfirmed)form.submit();});
  return false;
}

document.querySelectorAll('.flash').forEach(el=>{
  setTimeout(()=>{el.style.transition='opacity .5s';el.style.opacity='0';setTimeout(()=>el.remove(),500);},4000);
});

function autoType(val){
  const hint=document.getElementById('typeHint');
  const txt=document.getElementById('typeHintText');
  if(val==='TSHIRT'){hint.style.display='flex';txt.textContent='T-Shirts are for Logistics employees.';}
  else if(val==='POLOSHIRT'){hint.style.display='flex';txt.textContent='Polo Shirts are for Office / Sales employees.';}
  else{hint.style.display='none';}
}

function recalcPO(){
  <?php foreach($uTypes as $ut): ?>
  (function(){
    let gt=0;
    <?php foreach($sizes as $sz): ?>
    (function(){
      const r=parseInt(document.querySelector('[name="req_<?= $ut ?>_<?= $sz ?>"]')?.value)||0;
      const a=parseInt(document.querySelector('[name="add_<?= $ut ?>_<?= $sz ?>"]')?.value)||0;
      const t=r+a;
      const el=document.getElementById('total_<?= $ut ?>_<?= $sz ?>');
      if(el) el.textContent=t;
      gt+=t;
    })();
    <?php endforeach; ?>
    const gtEl=document.getElementById('grandtotal_<?= $ut ?>');
    if(gtEl) gtEl.textContent=gt;
  })();
  <?php endforeach; ?>
}

function viewPOItems(poid,poNum){
  document.getElementById('poItemsTitle').innerHTML=`<i class="bi bi-eye-fill" style="color:var(--primary-light)"></i> PO: ${poNum}`;
  document.getElementById('poItemsBody').innerHTML='<div style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="bi bi-hourglass-split"></i> Loading…</div>';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('poItemsModal')).show();
  fetch(`uniform-po-items.php?poid=${poid}`).then(r=>r.text()).then(html=>{document.getElementById('poItemsBody').innerHTML=html;}).catch(()=>{document.getElementById('poItemsBody').innerHTML='<p style="color:#dc2626">Failed to load items.</p>';});
}

let _currentRecPrintId = 0;

function viewRecItems(recId, poNum, uType) {
  _currentRecPrintId = recId;
  const typeBadge = uType === 'TSHIRT'
    ? '<span class="bdg bdg-tshirt">TSHIRT</span>'
    : '<span class="bdg bdg-polo">POLOSHIRT</span>';
  document.getElementById('recItemsTitle').innerHTML =
    `<i class="bi bi-box-seam-fill" style="color:var(--primary-light)"></i> Receiving — ${poNum} &nbsp;${typeBadge}`;
  document.getElementById('recItemsBody').innerHTML =
    '<div style="text-align:center;padding:2rem;color:var(--text-muted)"><i class="bi bi-hourglass-split"></i> Loading…</div>';
  document.getElementById('recItemsPrintBtn').style.display = 'none';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('recItemsModal')).show();
  fetch(`uniform-receiving-items.php?recid=${recId}`)
    .then(r => r.text())
    .then(html => {
      document.getElementById('recItemsBody').innerHTML = html;
      document.getElementById('recItemsPrintBtn').style.display = 'inline-flex';
    })
    .catch(() => {
      document.getElementById('recItemsBody').innerHTML = '<p style="color:#dc2626">Failed to load items.</p>';
    });
}

function printReceivingFromModal() { printReceiving(_currentRecPrintId); }

function printPO(poid,poNum){
  const win=window.open('uniform-po-print.php?poid='+poid,'_blank','width=900,height=700,scrollbars=yes');
  if(!win) Swal.fire('Popup blocked','Please allow popups for this site to print PO documents.','warning');
}

function printReceiving(recId){
  const win=window.open('uniform-receiving-print.php?recid='+recId,'_blank','width=900,height=700,scrollbars=yes');
  if(!win) Swal.fire('Popup blocked','Please allow popups for this site to print receiving documents.','warning');
}

function printReport(){
  window.print();
}

document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.po-req,.po-add').forEach(el=>el.addEventListener('input',recalcPO));
  recalcPO();

  // Auto-open the PO form if it was just saved (flash message present) or if URL has #po-form
  <?php if(!empty($messages) && $tab==='po'): ?>
  const poBody = document.getElementById('poFormBody');
  const poIcon = document.getElementById('poFormChevron');
  if(poBody){ poBody.style.display=''; if(poIcon) poIcon.className='bi bi-dash-lg'; }
  <?php endif; ?>

  // Auto-open the receiving form if it was just saved (flash message present)
  <?php if(!empty($messages) && $tab==='receiving'): ?>
  const recBody = document.getElementById('recFormBody');
  const recIcon = document.getElementById('recFormChevron');
  if(recBody){ recBody.style.display=''; if(recIcon) recIcon.className='bi bi-dash-lg'; }
  <?php endif; ?>
});
</script>
</body>
</html>