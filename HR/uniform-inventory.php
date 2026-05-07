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
$validTabs   = ['stocks','released','requests','po','receiving','returns'];
if (!in_array($tab, $validTabs)) $tab = 'stocks';
$sizes       = ['XS','S','M','L','XL','XXL','XXXL','4XL'];
$depts       = ['Century','Monde','Multilines','NutriAsia'];
$uTypes      = ['TSHIRT','POLOSHIRT'];

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

// ── POST: Update Stock ─────────────────────────────────────────
if (isset($_POST['save_stock'])) {
    $type=$_POST['UniformType']??''; $size=$_POST['Size']??'';
    $prev=intval($_POST['PreviousStock']??0);
    $add =intval($_POST['AdditionalStock']??0);
    $less=intval($_POST['LessStock']??0);
    $stmt=@sqlsrv_query($conn,
        "UPDATE [dbo].[UniformStock] SET PreviousStock=?,AdditionalStock=?,LessStock=?,UpdatedAt=GETDATE(),UpdatedBy=? WHERE UniformType=? AND Size=?",
        [$prev,$add,$less,$currentUser,$type,$size]);
    $messages[]=$stmt===false?['type'=>'danger','text'=>'Failed to update stock.']:['type'=>'success','text'=>"Stock updated: {$type} {$size}."];
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
if (isset($_POST['save_return'])) {
    $emp    = trim($_POST['ReturnEmployeeName'] ?? '');
    $ut     = trim($_POST['ReturnUniformType']  ?? '');
    $us     = trim($_POST['ReturnUniformSize']  ?? '');
    $qty    = intval($_POST['ReturnQuantity']   ?? 1);
    $dept   = trim($_POST['ReturnDepartment']   ?? '');
    $dr     = trim($_POST['DateReturned']       ?? date('Y-m-d'));
    $cond   = in_array($_POST['Condition'] ?? '', ['Good','Damaged']) ? $_POST['Condition'] : 'Good';
    $rto    = trim($_POST['ReturnedTo']         ?? '');
    $rem    = trim($_POST['ReturnRemarks']      ?? '');
    $relId  = intval($_POST['ReturnReleasedID'] ?? 0);

    if (!$emp || !$ut || !$us) {
        $messages[] = ['type'=>'danger','text'=>'Employee name, type and size are required.'];
    } else {
        $stmt = @sqlsrv_query($conn,
            "INSERT INTO [dbo].[UniformReturns]
                (ReleasedID,EmployeeName,UniformType,UniformSize,Quantity,Department,DateReturned,Condition,ReturnedTo,Remarks,CreatedBy)
             VALUES(?,?,?,?,?,?,?,?,?,?,?)",
            [$relId ?: null, $emp, $ut, $us, $qty, $dept, $dr, $cond, $rto, $rem, $currentUser]);
        if ($stmt !== false) {
            @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformStock]
                 SET ReturnStock=ReturnStock+?, UpdatedAt=GETDATE(), UpdatedBy=?
                 WHERE UniformType=? AND Size=?",
                [$qty, $currentUser, $ut, $us]);
            $messages[] = ['type'=>'success','text'=>"Return recorded: {$qty}x {$ut} ({$us}) from {$emp}. Stock updated."];
        } else {
            $messages[] = ['type'=>'danger','text'=>'Failed to save return.'];
        }
    }
    $tab = 'returns';
}

// ── POST: Delete Return ───────────────────────────────────────
if (isset($_POST['delete_return'])) {
    $id = intval($_POST['ReturnID'] ?? 0);
    if ($id > 0) {
        $row = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
        if (!empty($row)) {
            $r0  = $row[0];
            $qty = intval($r0['Quantity']);
            $stmt = @sqlsrv_query($conn, "DELETE FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
            if ($stmt !== false) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformStock]
                     SET ReturnStock=ReturnStock-?, UpdatedAt=GETDATE(), UpdatedBy=?
                     WHERE UniformType=? AND Size=?",
                    [$qty, $currentUser, $r0['UniformType'], $r0['UniformSize']]);
                $messages[] = ['type'=>'success','text'=>'Return deleted and stock reversed.'];
            } else {
                $messages[] = ['type'=>'danger','text'=>'Failed to delete return.'];
            }
        }
    }
    $tab = 'returns';
}

// ── POST: Edit Return ─────────────────────────────────────────
if (isset($_POST['edit_return'])) {
    $id   = intval($_POST['ReturnID']          ?? 0);
    $emp  = trim($_POST['ReturnEmployeeName']  ?? '');
    $ut   = trim($_POST['ReturnUniformType']   ?? '');
    $us   = trim($_POST['ReturnUniformSize']   ?? '');
    $qty  = intval($_POST['ReturnQuantity']    ?? 1);
    $dept = trim($_POST['ReturnDepartment']    ?? '');
    $dr   = trim($_POST['DateReturned']        ?? date('Y-m-d'));
    $cond = in_array($_POST['Condition'] ?? '', ['Good','Damaged']) ? $_POST['Condition'] : 'Good';
    $rto  = trim($_POST['ReturnedTo']          ?? '');
    $rem  = trim($_POST['ReturnRemarks']       ?? '');
    if (!$id || !$emp || !$ut || !$us) {
        $messages[] = ['type'=>'danger','text'=>'Name, type and size are required.'];
    } else {
        $old = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$id]);
        $stmt = @sqlsrv_query($conn,
            "UPDATE [dbo].[UniformReturns]
             SET EmployeeName=?,UniformType=?,UniformSize=?,Quantity=?,Department=?,
                 DateReturned=?,Condition=?,ReturnedTo=?,Remarks=?
             WHERE ReturnID=?",
            [$emp, $ut, $us, $qty, $dept, $dr, $cond, $rto, $rem, $id]);
        if ($stmt !== false) {
            if (!empty($old)) {
                @sqlsrv_query($conn,
                    "UPDATE [dbo].[UniformStock]
                     SET ReturnStock=ReturnStock-?, UpdatedAt=GETDATE(), UpdatedBy=?
                     WHERE UniformType=? AND Size=?",
                    [$old[0]['Quantity'], $currentUser, $old[0]['UniformType'], $old[0]['UniformSize']]);
            }
            @sqlsrv_query($conn,
                "UPDATE [dbo].[UniformStock]
                 SET ReturnStock=ReturnStock+?, UpdatedAt=GETDATE(), UpdatedBy=?
                 WHERE UniformType=? AND Size=?",
                [$qty, $currentUser, $ut, $us]);
            $messages[] = ['type'=>'success','text'=>"Return updated for {$emp}."];
        } else {
            $messages[] = ['type'=>'danger','text'=>'Failed to update return.'];
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
$relWhere  = $relSearch!=='' ? "WHERE (EmployeeName LIKE '%".str_replace("'","''",$relSearch)."%' OR RequestedBy LIKE '%".str_replace("'","''",$relSearch)."%')" : '';
$relAll    = rq($conn,"SELECT * FROM [dbo].[UniformReleased] {$relWhere} ORDER BY DateGiven DESC, CreatedAt DESC");
$relTotal  = count($relAll);
$relPages  = max(1,(int)ceil($relTotal/20));
$relPage   = max(1,min((int)($_GET['relpage']??1),$relPages));
$released  = array_slice($relAll,($relPage-1)*20,20);

$totalGiven = rq($conn,"SELECT ISNULL(SUM(Quantity),0) AS Total FROM [dbo].[UniformReleased]");
$totalGivenCount = intval($totalGiven[0]['Total']??0);

// ── Requests ──────────────────────────────────────────────────
// Status tab: 'pending' (default) or 'given'
$reqStatus  = ($_GET['rstatus'] ?? 'pending') === 'given' ? 'given' : 'pending';
$reqUType   = trim($_GET['rutype'] ?? '');
$reqDept    = trim($_GET['rdept']  ?? '');

// Validate uniform type and department against known values
if (!in_array($reqUType, ['TSHIRT','POLOSHIRT'])) $reqUType = '';
if (!in_array($reqDept,  $depts))                  $reqDept  = '';

$reqConditions = ["r.IsGiven = " . ($reqStatus === 'given' ? '1' : '0')];
if ($reqUType !== '') $reqConditions[] = "r.UniformType = '" . str_replace("'","''",$reqUType) . "'";
if ($reqDept  !== '') $reqConditions[] = "r.Department  = '" . str_replace("'","''",$reqDept)  . "'";
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

// Counts for tab badges
$reqPendingTotal = rq($conn,"SELECT COUNT(*) AS N FROM [dbo].[UniformRequests] WHERE IsGiven=0");
$reqGivenTotal   = rq($conn,"SELECT COUNT(*) AS N FROM [dbo].[UniformRequests] WHERE IsGiven=1");
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
$retWhere  = $retSearch !== ''
    ? "WHERE (EmployeeName LIKE '%" . str_replace("'","''",$retSearch) . "%' OR ReturnedTo LIKE '%" . str_replace("'","''",$retSearch) . "%')"
    : '';
$retAll    = rq($conn, "SELECT * FROM [dbo].[UniformReturns] {$retWhere} ORDER BY DateReturned DESC, CreatedAt DESC");
$retTotal  = count($retAll);
$retPages  = max(1,(int)ceil($retTotal/20));
$retPage   = max(1,min((int)($_GET['retpage']??1),$retPages));
$retList   = array_slice($retAll,($retPage-1)*20,20);
$totalReturnCount = array_sum(array_column($retAll,'Quantity'));

// ── Edit mode (Returns) ───────────────────────────────────────
$editRetId  = intval($_GET['editretid'] ?? 0);
$editRetRow = [];
if ($editRetId > 0 && $tab === 'returns') {
    $tmp = rq($conn, "SELECT * FROM [dbo].[UniformReturns] WHERE ReturnID=?", [$editRetId]);
    $editRetRow = $tmp[0] ?? [];
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
    <div class="page-badge">🧥 <?= date('Y') ?> · <?= safe($_SESSION['Department']??'All Departments') ?></div>
  </div>
  <button class="btn-add" data-bs-toggle="modal" data-bs-target="#releasedModal">
    <i class="bi bi-plus-lg"></i> Release Uniform
  </button>
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
  <div class="stat-card"><div class="stat-icon">📦</div><div class="stat-label">Total Uniform Given</div><div class="stat-value sv-amber"><?= number_format($totalGivenCount) ?></div></div>
  <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-label">Pending Requests</div><div class="stat-value sv-red"><?= count(array_filter($reqAll,fn($r)=>!$r['IsGiven'])) ?></div></div>
</div>

<div class="tab-bar">
  <a href="?tab=stocks"    class="tab-btn <?= $tab==='stocks'   ?'active':'' ?>"><i class="bi bi-boxes"></i> Stocks</a>
  <a href="?tab=released"  class="tab-btn <?= $tab==='released' ?'active':'' ?>"><i class="bi bi-send-fill"></i> Uniforms Released</a>
  <a href="?tab=requests"  class="tab-btn <?= $tab==='requests' ?'active':'' ?>"><i class="bi bi-clipboard-check"></i> Requested List</a>
  <a href="?tab=po"        class="tab-btn <?= $tab==='po'       ?'active':'' ?>"><i class="bi bi-file-earmark-text-fill"></i> PO Form</a>
  <a href="?tab=receiving" class="tab-btn <?= $tab==='receiving'?'active':'' ?>"><i class="bi bi-box-seam-fill"></i> Receiving Form</a>
  <a href="?tab=returns"   class="tab-btn <?= $tab==='returns'  ?'active':'' ?>"><i class="bi bi-arrow-return-left"></i> Returns</a>
</div>

<?php
// ═══ TAB: STOCKS ═══════════════════════════════════════════════
if ($tab==='stocks'):
$typeTotals=[];
foreach(['TSHIRT','POLOSHIRT'] as $t){
    $sum=0;
    foreach($sizes as $sz) $sum+=max(0,intval(($stockMap[$t][$sz]??['CurrentStock'=>0])['CurrentStock']));
    $typeTotals[$t]=$sum;
}
?>
<div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;background:var(--surface);border:1.5px solid var(--border);border-radius:12px;padding:.65rem 1.1rem;margin-bottom:1.25rem;font-size:.76rem;">
  <span style="font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;font-size:.68rem;">How to read</span>
  <span style="display:flex;align-items:center;gap:.35rem;color:var(--text-secondary);"><span style="width:10px;height:10px;border-radius:50%;background:#64748b;display:inline-block;"></span> Previous — stock carried over</span>
  <span style="display:flex;align-items:center;gap:.35rem;color:var(--text-secondary);"><span style="width:10px;height:10px;border-radius:50%;background:#0891b2;display:inline-block;"></span> Additional — new stock received</span>
  <span style="display:flex;align-items:center;gap:.35rem;color:var(--text-secondary);"><span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block;"></span> Less — released / used</span>
  <span style="display:flex;align-items:center;gap:.35rem;color:var(--text-secondary);"><span style="width:10px;height:10px;border-radius:50%;background:#7c3aed;display:inline-block;"></span> Returns — uniforms returned</span>
  <span style="display:flex;align-items:center;gap:.35rem;color:var(--text-secondary);"><span style="width:22px;height:10px;border-radius:3px;background:linear-gradient(90deg,#1e40af,#3b82f6);display:inline-block;"></span> Current = Previous + Additional + Returns − Less</span>
  <span style="margin-left:auto;display:flex;align-items:center;gap:.35rem;color:var(--text-muted);font-style:italic;"><i class="bi bi-pencil-square"></i> Edit any number and hit Save</span>
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
  <div style="display:grid;grid-template-columns:44px 1fr 1fr 1fr 1fr 90px 70px;background:var(--surface-2);border-bottom:1px solid var(--border);padding:0 .85rem;">
    <div style="padding:.45rem 0;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Size</div>
    <div style="padding:.45rem .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#64748b;text-align:center;"><i class="bi bi-arrow-counterclockwise"></i> Previous</div>
    <div style="padding:.45rem .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#0891b2;text-align:center;"><i class="bi bi-plus-circle-fill"></i> Additional</div>
    <div style="padding:.45rem .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#dc2626;text-align:center;"><i class="bi bi-dash-circle-fill"></i> Less</div>
    <div style="padding:.45rem .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#7c3aed;text-align:center;"><i class="bi bi-arrow-return-left"></i> Returns</div>
    <div style="padding:.45rem .3rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:<?= $meta['accent'] ?>;text-align:center;"><i class="bi bi-check-circle-fill"></i> Current</div>
    <div></div>
  </div>
  <div>
  <?php foreach($sizes as $sz):
    $row=$stockMap[$type][$sz]??['PreviousStock'=>0,'AdditionalStock'=>0,'LessStock'=>0,'CurrentStock'=>0];
    $cur=max(0,intval($row['CurrentStock']??0));
    if($cur===0){$dot='#dc2626';$tip='Out of stock';}
    elseif($cur<=5){$dot='#ca8a04';$tip='Low stock';}
    else{$dot='#10b981';$tip='In stock';}
  ?>
  <form method="POST" style="display:grid;grid-template-columns:44px 1fr 1fr 1fr 1fr 90px 70px;padding:0 .85rem;border-bottom:1px solid var(--border);align-items:center;" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
    <input type="hidden" name="save_stock" value="1">
    <input type="hidden" name="UniformType" value="<?= $type ?>">
    <input type="hidden" name="Size" value="<?= $sz ?>">
    <div style="padding:.55rem 0;"><span style="background:<?= $meta['light'] ?>;color:<?= $meta['accent'] ?>;border:1px solid <?= $meta['border'] ?>;border-radius:6px;padding:.15rem .45rem;font-family:'DM Mono',monospace;font-size:.78rem;font-weight:800;"><?= $sz ?></span></div>
    <div style="padding:.45rem .3rem;text-align:center;"><input type="number" name="PreviousStock"   class="stock-input" value="<?= intval($row['PreviousStock']??0) ?>"   min="0"></div>
    <div style="padding:.45rem .3rem;text-align:center;"><input type="number" name="AdditionalStock" class="stock-input" value="<?= intval($row['AdditionalStock']??0) ?>" min="0" style="border-color:rgba(8,145,178,.3);background:rgba(8,145,178,.04);"></div>
    <div style="padding:.45rem .3rem;text-align:center;"><input type="number" name="LessStock"       class="stock-input" value="<?= intval($row['LessStock']??0) ?>"       min="0" style="border-color:rgba(220,38,38,.25);background:rgba(220,38,38,.04);"></div>
    <div style="padding:.45rem .3rem;text-align:center;">
      <span style="font-family:'DM Mono',monospace;font-size:.82rem;font-weight:700;color:#7c3aed;background:rgba(124,58,237,.07);border:1px solid rgba(124,58,237,.2);border-radius:6px;padding:.2rem .45rem;display:inline-block;"><?= intval($row['ReturnStock']??0) ?></span>
    </div>
    <div style="padding:.45rem .3rem;text-align:center;">
      <div style="display:flex;align-items:center;justify-content:center;gap:.3rem;">
        <span style="width:7px;height:7px;border-radius:50%;background:<?= $dot ?>;display:inline-block;" title="<?= $tip ?>"></span>
        <span style="font-family:'DM Mono',monospace;font-weight:800;font-size:.88rem;color:<?= $dot ?>;"><?= $cur ?></span>
      </div>
    </div>
    <div style="padding:.45rem 0;text-align:center;">
      <button type="submit" style="background:var(--primary);color:#fff;border:none;cursor:pointer;font-size:.72rem;font-weight:700;padding:.3rem .55rem;border-radius:7px;font-family:'DM Sans',sans-serif;display:inline-flex;align-items:center;gap:.25rem;white-space:nowrap;" onmouseover="this.style.background='#1d3fa3'" onmouseout="this.style.background='var(--primary)'">
        <i class="bi bi-floppy-fill"></i> Save
      </button>
    </div>
  </form>
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
      <div style="background:var(--primary-glow);color:var(--primary);border:1px solid rgba(59,130,246,.25);border-radius:20px;padding:.2rem .75rem;font-size:.75rem;font-weight:700;">Total Given: <?= number_format($totalGivenCount) ?> pcs</div>
      <form method="GET" style="display:flex;gap:.4rem;align-items:center;">
        <input type="hidden" name="tab" value="released">
        <div class="sbar"><i class="bi bi-search"></i><input type="text" name="rsearch" placeholder="Employee or HR name…" value="<?= safe($relSearch) ?>"></div>
        <button type="submit" class="btn-add" style="padding:.38rem .8rem;"><i class="bi bi-search"></i></button>
        <?php if($relSearch!==''): ?><a href="?tab=released" class="btn-sm-action btn-del" style="padding:.38rem .65rem;"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </form>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#releasedModal"><i class="bi bi-plus-lg"></i> Add</button>
    </div>
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
        <a href="?tab=released&editid=<?= $r['ReleasedID'] ?>&relpage=<?= $relPage ?>" class="btn-sm-action btn-edit">
          <i class="bi bi-pencil-fill"></i> Edit
        </a>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Return to Pending?','This release will be deleted, stock restored, and request reverted to Pending.','#dc2626')">
          <input type="hidden" name="delete_released" value="1">
          <input type="hidden" name="ReleasedID" value="<?= $r['ReleasedID'] ?>">
          <button type="submit" class="btn-sm-action btn-del" title="Delete & revert to pending"><i class="bi bi-arrow-return-left"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('relpage',$relPage,$relPages,$relTotal,['tab'=>'released','rsearch'=>$relSearch]) ?>
  <?php endif; ?>
</div>

<?php
// ═══ TAB: REQUESTS ═════════════════════════════════════════════
elseif($tab==='requests'):
// Build base URL params for filter links (preserves filters across page/tab switches)
$reqBaseParams = ['tab'=>'requests','rstatus'=>$reqStatus];
if($reqUType!=='') $reqBaseParams['rutype']=$reqUType;
if($reqDept !=='') $reqBaseParams['rdept'] =$reqDept;
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

      <select name="rdept" class="form-select" style="width:165px;font-size:.78rem;padding:.3rem .55rem;" onchange="this.form.submit()">
        <option value="" <?= $reqDept===''?'selected':'' ?>>All Departments</option>
        <?php foreach($depts as $d): ?>
        <option value="<?= $d ?>" <?= $reqDept===$d?'selected':'' ?>><?= safe($d) ?></option>
        <?php endforeach; ?>
      </select>

      <?php if($reqUType!=='' || $reqDept!==''): ?>
      <a href="?tab=requests&rstatus=<?= $reqStatus ?>" class="btn-sm-action btn-del" style="padding:.38rem .65rem;" title="Clear filters"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
    </form>
  </div>

  <?php if(empty($requests)): ?>
  <div class="empty-st"><i class="bi bi-clipboard"></i><p>No <?= $reqStatus === 'pending' ? 'pending' : 'given' ?> requests<?= ($reqUType!==''||$reqDept!=='') ? ' matching the selected filters' : '' ?>.</p></div>
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
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('reqpage',$reqPage,$reqPages,$reqTotal,
        array_merge($reqBaseParams,['rutype'=>$reqUType,'rdept'=>$reqDept])) ?>
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
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete PO?','This will delete the PO and all its items. Continue?','#dc2626')">
          <input type="hidden" name="delete_po" value="1">
          <input type="hidden" name="POID" value="<?= $po['POID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('popage',$poPage,$poPages,$poTotal,['tab'=>'po']) ?>
</div>
<?php endif; ?>

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
?>
<div class="panel" style="border:2px solid var(--primary-light);">
  <div class="panel-hdr" style="background:var(--primary-glow);">
    <div class="panel-title" style="color:var(--primary);"><i class="bi bi-pencil-fill"></i> Editing Return — <?= safe($er['EmployeeName']) ?></div>
    <a href="?tab=returns" class="btn-sm-action btn-del"><i class="bi bi-x-lg"></i> Cancel</a>
  </div>
  <div style="padding:1.25rem;">
    <form method="POST">
      <input type="hidden" name="edit_return" value="1">
      <input type="hidden" name="ReturnID"    value="<?= $er['ReturnID'] ?>">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Employee Name <span style="color:#dc2626">*</span></label><input type="text" name="ReturnEmployeeName" class="form-control" value="<?= safe($er['EmployeeName']) ?>" required></div>
        <div class="col-md-3">
          <label class="form-label">Uniform Type <span style="color:#dc2626">*</span></label>
          <select name="ReturnUniformType" class="form-select" required>
            <option value="TSHIRT"    <?= $er['UniformType']==='TSHIRT'   ?'selected':'' ?>>👕 T-Shirt (Logistics)</option>
            <option value="POLOSHIRT" <?= $er['UniformType']==='POLOSHIRT'?'selected':'' ?>>👔 Polo Shirt (Office/Sales)</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Size <span style="color:#dc2626">*</span></label>
          <select name="ReturnUniformSize" class="form-select" required>
            <?php foreach($sizes as $sz): ?><option value="<?= $sz ?>" <?= $er['UniformSize']===$sz?'selected':'' ?>><?= $sz ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1"><label class="form-label">Qty</label><input type="number" name="ReturnQuantity" class="form-control" value="<?= intval($er['Quantity']) ?>" min="1"></div>
        <div class="col-md-2">
          <label class="form-label">Condition</label>
          <select name="Condition" class="form-select">
            <option value="Good"    <?= ($er['Condition']??'')==='Good'   ?'selected':'' ?>>✅ Good</option>
            <option value="Damaged" <?= ($er['Condition']??'')==='Damaged'?'selected':'' ?>>⚠️ Damaged</option>
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

<div class="panel">
  <div class="panel-hdr">
    <div class="panel-title"><i class="bi bi-arrow-return-left" style="color:var(--primary-light)"></i> Uniform Returns</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
      <div style="background:var(--primary-glow);color:var(--primary);border:1px solid rgba(59,130,246,.25);border-radius:20px;padding:.2rem .75rem;font-size:.75rem;font-weight:700;">Total Returned: <?= number_format($totalReturnCount) ?> pcs</div>
      <form method="GET" style="display:flex;gap:.4rem;align-items:center;">
        <input type="hidden" name="tab" value="returns">
        <div class="sbar"><i class="bi bi-search"></i><input type="text" name="retsearch" placeholder="Employee or staff name…" value="<?= safe($retSearch) ?>"></div>
        <button type="submit" class="btn-add" style="padding:.38rem .8rem;"><i class="bi bi-search"></i></button>
        <?php if($retSearch!==''): ?><a href="?tab=returns" class="btn-sm-action btn-del" style="padding:.38rem .65rem;"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </form>
      <button class="btn-add" data-bs-toggle="modal" data-bs-target="#returnModal"><i class="bi bi-plus-lg"></i> Add Return</button>
    </div>
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
        <th>Condition</th>
        <th>Department</th>
        <th>Date Returned</th>
        <th>Returned To</th>
        <th>Remarks</th>
        <th style="text-align:center;">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($retList as $i=>$r):
      $rowNum   = ($retPage-1)*20 + $i + 1;
      $isGood   = ($r['Condition'] ?? 'Good') === 'Good';
      $condBg   = $isGood ? 'rgba(16,185,129,.1)' : 'rgba(234,179,8,.1)';
      $condClr  = $isGood ? '#059669' : '#ca8a04';
      $condBdr  = $isGood ? '#6ee7b7' : '#fde047';
      $condIcon = $isGood ? '✅' : '⚠️';
      $condTxt  = $isGood ? 'Good' : 'Damaged';
    ?>
    <tr>
      <td style="color:var(--text-muted);font-family:'DM Mono',monospace;"><?= $rowNum ?></td>
      <td style="font-weight:700;color:var(--text-primary);"><?= safe($r['EmployeeName']) ?></td>
      <td><span class="bdg <?= $r['UniformType']==='TSHIRT'?'bdg-tshirt':'bdg-polo' ?>"><?= $r['UniformType'] ?></span></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= safe($r['UniformSize']) ?></td>
      <td style="font-family:'DM Mono',monospace;font-weight:700;"><?= intval($r['Quantity']) ?></td>
      <td>
        <span style="background:<?= $condBg ?>;color:<?= $condClr ?>;border:1px solid <?= $condBdr ?>;border-radius:20px;padding:.18rem .55rem;font-size:.68rem;font-weight:700;white-space:nowrap;">
          <?= $condIcon ?> <?= $condTxt ?>
        </span>
      </td>
      <td><?php if($r['Department']): ?><span class="bdg dept-<?= $r['Department'] ?>"><?= safe($r['Department']) ?></span><?php else: ?>—<?php endif; ?></td>
      <td style="font-family:'DM Mono',monospace;font-size:.76rem;white-space:nowrap;"><?= fmtDate($r['DateReturned']) ?></td>
      <td style="font-size:.78rem;"><?= safe($r['ReturnedTo']??'—') ?></td>
      <td style="font-size:.76rem;color:var(--text-muted);"><?= safe($r['Remarks']??'—') ?></td>
      <td style="text-align:center;white-space:nowrap;">
        <a href="?tab=returns&editretid=<?= $r['ReturnID'] ?>&retpage=<?= $retPage ?>" class="btn-sm-action btn-edit">
          <i class="bi bi-pencil-fill"></i> Edit
        </a>
        <form method="POST" style="display:inline;" onsubmit="return confirmAction(event,'Delete Return?','This will delete the return record and reverse the stock. Continue?','#dc2626')">
          <input type="hidden" name="delete_return" value="1">
          <input type="hidden" name="ReturnID"      value="<?= $r['ReturnID'] ?>">
          <button type="submit" class="btn-sm-action btn-del"><i class="bi bi-trash3-fill"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= paginationBar('retpage',$retPage,$retPages,$retTotal,['tab'=>'returns','retsearch'=>$retSearch]) ?>
  <?php endif; ?>
</div>

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
              <label class="form-label">Condition</label>
              <select name="Condition" class="form-select">
                <option value="Good">✅ Good</option>
                <option value="Damaged">⚠️ Damaged</option>
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