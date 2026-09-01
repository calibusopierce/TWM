<?php
// TWM/BULLETIN/bulletin_manage.php
date_default_timezone_set('Asia/Manila');
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../RBAC/rbac_helper.php';
require_once __DIR__ . '/../test_sqlsrv.php';

auth_check();
rbac_gate($pdo, 'bulletin');
$viewOnly = rbac_is_view_only('bulletin');

$userID      = (int) ($_SESSION['UserID']    ?? 0);
$displayName = $_SESSION['DisplayName'] ?? $_SESSION['Username'] ?? 'User';

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if ($viewOnly) {
        $errors[] = "You don't have permission to post announcements.";
    } else {
        $title     = trim($_POST['title'] ?? '');
        $message   = trim($_POST['message'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate   = trim($_POST['end_date'] ?? '');

        if ($title === '')          $errors[] = 'Title is required.';
        if ($message === '')        $errors[] = 'Message is required.';
        if (!$startDate || !$endDate) $errors[] = 'Both a start and end viewing date are required.';
        if ($startDate && $endDate && $startDate > $endDate) $errors[] = 'Start date must be on or before end date.';

        if (!$errors) {
            $sql = "INSERT INTO TBL_Bulletin (Title, Message, StartDate, EndDate, CreatedByUserID, CreatedByName, IsActive)
                    VALUES (?, ?, ?, ?, ?, ?, 1)";
            $stmt = sqlsrv_query($conn, $sql, [$title, $message, $startDate, $endDate, $userID, $displayName]);
            if ($stmt === false) {
                $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
            } else {
                $success = 'Announcement posted.';
                $_POST = []; // clear form on success
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate' && !$viewOnly) {
    $id = (int) ($_POST['bulletin_id'] ?? 0);
    if ($id > 0) {
        $stmt = sqlsrv_query($conn, "UPDATE TBL_Bulletin SET IsActive = 0 WHERE BulletinID = ?", [$id]);
        if ($stmt === false) {
            $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
        } else {
            $success = 'Announcement removed.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit' && !$viewOnly) {
    $id        = (int) ($_POST['bulletin_id'] ?? 0);
    $title     = trim($_POST['title'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate   = trim($_POST['end_date'] ?? '');

    if ($id <= 0)                $errors[] = 'Invalid announcement.';
    if ($title === '')           $errors[] = 'Title is required.';
    if ($message === '')         $errors[] = 'Message is required.';
    if (!$startDate || !$endDate) $errors[] = 'Both a start and end viewing date are required.';
    if ($startDate && $endDate && $startDate > $endDate) $errors[] = 'Start date must be on or before end date.';

    if (!$errors) {
        // Ownership check lives in the WHERE clause — only the creator can edit their own post
        $sql  = "UPDATE TBL_Bulletin SET Title = ?, Message = ?, StartDate = ?, EndDate = ?
                 WHERE BulletinID = ? AND CreatedByUserID = ?";
        $stmt = sqlsrv_query($conn, $sql, [$title, $message, $startDate, $endDate, $id, $userID]);
        if ($stmt === false) {
            $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
        } elseif (sqlsrv_rows_affected($stmt) === 0) {
            $errors[] = "You can only edit announcements you created.";
        } else {
            $success = 'Announcement updated.';
        }
    }
}

$bulletins = [];
$stmt = sqlsrv_query($conn, "SELECT BulletinID, Title, Message, StartDate, EndDate, CreatedByUserID, CreatedByName, CreatedAt, IsActive
                              FROM TBL_Bulletin
                              WHERE IsActive = 1
                              ORDER BY CreatedAt DESC");
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $bulletins[] = $row;
    }
} else {
    $errors[] = 'Database error: ' . print_r(sqlsrv_errors(), true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bulletin Board · Tradewell Admin</title>
  <link href="<?= base_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
  <link href="<?= base_url('assets/vendor/fonts/fonts.css') ?>" rel="stylesheet">
  <style>
    :root { --blue-deep:#08173d; --blue-bright:#4380e2; --blue-light:#93c5fd; --white:#fff;
            --w10:rgba(255,255,255,.10); --w15:rgba(255,255,255,.15); --w25:rgba(255,255,255,.25);
            --w60:rgba(255,255,255,.60); --w80:rgba(255,255,255,.80); }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:linear-gradient(145deg,var(--blue-bright) 0%,var(--blue-deep) 100%);
         background-attachment:fixed;min-height:100vh;padding:2rem;color:var(--white);}
    .wrap{max-width:900px;margin:0 auto;}
    a.back{color:var(--blue-light);font-size:.85rem;font-weight:600;text-decoration:none;display:inline-block;margin-bottom:1.2rem;}
    a.back:hover{text-decoration:underline;}
    h1{font-family:'Sora',sans-serif;font-size:1.4rem;font-weight:800;margin-bottom:1.2rem;display:flex;align-items:center;gap:.5rem;}
    /* Darker, more opaque card so it reads as a distinct surface against the gradient */
    .card{background:rgba(4,10,28,.55);border:1px solid var(--w25);border-radius:14px;
          padding:1.3rem;margin-bottom:1.3rem;box-shadow:0 8px 24px rgba(0,0,0,.2);}
    label{display:block;font-size:.8rem;font-weight:600;margin:.8rem 0 .35rem;color:var(--white);}
    input[type=text],input[type=date],textarea{
      width:100%;padding:.65rem .8rem;border-radius:8px;border:1px solid var(--w25);
      background:rgba(255,255,255,.14);color:var(--white);font-family:inherit;font-size:.9rem;
    }
    input[type=text]::placeholder,textarea::placeholder{color:rgba(255,255,255,.55);}
    input[type=text]:focus,input[type=date]:focus,textarea:focus{
      outline:none;border-color:var(--blue-light);background:rgba(255,255,255,.18);
      box-shadow:0 0 0 3px rgba(147,197,253,.25);
    }
    /* Native date-picker text/icon are dark-on-dark by default in Chromium — force light */
    input[type=date]{color-scheme:dark;}
    textarea{min-height:220px;resize:vertical;line-height:1.5;}
    .row2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
    button{margin-top:1.1rem;padding:.65rem 1.2rem;border:none;border-radius:8px;
           background:var(--blue-bright);color:#fff;font-weight:700;font-size:.87rem;cursor:pointer;}
    button:hover{background:#5590f0;}
    .msg-ok{background:rgba(16,185,129,.22);border:1px solid rgba(16,185,129,.5);padding:.65rem .9rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600;}
    .msg-err{background:rgba(239,68,68,.22);border:1px solid rgba(239,68,68,.5);padding:.65rem .9rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;font-weight:600;}
    table{width:100%;border-collapse:collapse;font-size:.85rem;}
    th,td{text-align:left;padding:.7rem .5rem;border-bottom:1px solid var(--w15);vertical-align:top;}
    th{color:var(--w80);font-weight:700;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;}
    td{color:var(--white);}
    tr.inactive td{color:var(--w60);}
    .badge{display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.7rem;font-weight:700;}
    .badge-active{background:rgba(16,185,129,.25);color:#8ef0c5;}
    .badge-inactive{background:var(--w15);color:var(--w80);}
    .del-btn{background:transparent;border:1px solid rgba(239,68,68,.5);color:#fca5a5;padding:.32rem .7rem;font-size:.75rem;font-weight:600;margin:0;}
    .del-btn:hover{background:rgba(239,68,68,.2);}
    .view-toggle{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.6rem;}
    .view-toggle h1{margin-bottom:0;}
    .btn-toggle{color:var(--blue-light);font-size:.8rem;font-weight:700;text-decoration:none;border:1px solid var(--w25);padding:.45rem .8rem;border-radius:8px;display:inline-flex;align-items:center;gap:.4rem;}
    .btn-toggle:hover{background:var(--w10);}
    .filter-row{display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;}
    .filter-row label{margin:.8rem 0 .35rem;}
    .filter-row input[type=date]{width:auto;}
    .filter-row button{margin-top:0;}
    .clear-filter{color:var(--blue-light);font-size:.8rem;font-weight:600;text-decoration:none;margin-left:.2rem;}
    .clear-filter:hover{text-decoration:underline;}
    .edit-btn{background:transparent;border:1px solid rgba(147,197,253,.5);color:var(--blue-light);padding:.32rem .7rem;font-size:.75rem;font-weight:600;margin:0 .4rem 0 0;}
    .edit-btn:hover{background:rgba(147,197,253,.2);}
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;padding:1.5rem;}
    .modal-box{background:#0c1a3f;border:1px solid var(--w25);border-radius:14px;padding:1.5rem;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 50px rgba(0,0,0,.4);}
    .modal-box h2{font-family:'Sora',sans-serif;font-size:1.15rem;font-weight:800;margin-bottom:.3rem;display:flex;align-items:center;gap:.5rem;}
    .modal-actions{display:flex;gap:.7rem;justify-content:flex-end;margin-top:1.1rem;}
    .btn-cancel{background:transparent;border:1px solid var(--w25);color:var(--white);}
    .btn-cancel:hover{background:var(--w10);}
  </style>
</head>
<body>
<div class="wrap">
  <a href="../home.php" class="back">&larr; Back to Home</a>
  <div class="view-toggle">
    <h1><i class="bi bi-megaphone"></i> Bulletin Board</h1>
    <a href="bulletin_list.php" class="btn-toggle"><i class="bi bi-archive"></i> View Past / Removed</a>
  </div>

  <?php if ($success): ?><div class="msg-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php foreach ($errors as $e): ?><div class="msg-err"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

  <?php if (!$viewOnly): ?>
  <div class="card">
    <form method="post">
      <input type="hidden" name="action" value="create">
      <label>Title</label>
      <input type="text" name="title" maxlength="255" required value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" placeholder="e.g. Remittance Office Move to a New Office">
      <label>Message</label>
      <textarea name="message" rows="8" required placeholder="e.g. Remittance Office will be having a meeting today, August 28, 2026."><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
      <div class="row2">
        <div>
          <label>Viewing start date</label>
          <input type="date" name="start_date" required value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div>
          <label>Viewing end date</label>
          <input type="date" name="end_date" required value="<?= htmlspecialchars($_POST['end_date'] ?? date('Y-m-d')) ?>">
        </div>
      </div>
      <button type="submit"><i class="bi bi-send"></i> Post Announcement</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Title</th><th>Viewing Window</th><th>Posted By</th><th>Status</th>
          <?php if (!$viewOnly): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($bulletins as $b): ?>
        <tr>
          <td><?= htmlspecialchars($b['Title']) ?></td>
          <td><?= htmlspecialchars($b['StartDate']->format('M j, Y')) ?> &ndash; <?= htmlspecialchars($b['EndDate']->format('M j, Y')) ?></td>
          <td><?= htmlspecialchars($b['CreatedByName'] ?? 'Unknown') ?></td>
          <td><span class="badge badge-active">Active</span></td>
          <?php if (!$viewOnly): ?>
          <td>
            <?php if ((int) $b['CreatedByUserID'] === $userID): ?>
            <button type="button" class="edit-btn" onclick='openEditModal(<?= (int) $b["BulletinID"] ?>, <?= json_encode($b["Title"]) ?>, <?= json_encode($b["Message"]) ?>, "<?= $b["StartDate"]->format("Y-m-d") ?>", "<?= $b["EndDate"]->format("Y-m-d") ?>")'>
              <i class="bi bi-pencil"></i> Edit
            </button>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove this announcement?');">
              <input type="hidden" name="action" value="deactivate">
              <input type="hidden" name="bulletin_id" value="<?= (int) $b['BulletinID'] ?>">
              <button type="submit" class="del-btn">Remove</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$bulletins): ?>
        <tr><td colspan="5">No active announcements right now.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="editModalOverlay" class="modal-overlay" style="display:none;">
  <div class="modal-box">
    <h2><i class="bi bi-pencil-square"></i> Edit Announcement</h2>
    <form method="post">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="bulletin_id" id="edit_bulletin_id">
      <label>Title</label>
      <input type="text" name="title" id="edit_title" maxlength="255" required>
      <label>Message</label>
      <textarea name="message" id="edit_message" rows="8" required></textarea>
      <div class="row2">
        <div>
          <label>Viewing start date</label>
          <input type="date" name="start_date" id="edit_start_date" required>
        </div>
        <div>
          <label>Viewing end date</label>
          <input type="date" name="end_date" id="edit_end_date" required>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
        <button type="submit"><i class="bi bi-check-lg"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(id, title, message, startDate, endDate, categoryId, depts, branches) {
  document.getElementById('edit_bulletin_id').value = id;
  document.getElementById('edit_title').value = title;
  document.getElementById('edit_message').value = message;
  document.getElementById('edit_start_date').value = startDate;
  document.getElementById('edit_end_date').value = endDate;
  document.getElementById('edit_category_id').value = categoryId;
  setTargetGroup('edit_dept_all', 'edit-dept-checks', depts);
  setTargetGroup('edit_branch_all', 'edit-branch-checks', branches);
  document.getElementById('editModalOverlay').style.display = 'flex';
}
function closeEditModal() {
  document.getElementById('editModalOverlay').style.display = 'none';
}

// "All Departments" / "All Branches": checked = hide + clear + disable the specific list
document.querySelectorAll('.all-toggle').forEach(function (toggle) {
  toggle.addEventListener('change', function () {
    var group = document.getElementById(toggle.dataset.target);
    group.style.display = toggle.checked ? 'none' : 'grid';
    group.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
      if (toggle.checked) cb.checked = false;
      cb.disabled = toggle.checked;
    });
  });
});

function setTargetGroup(allToggleId, groupId, selectedValues) {
  var toggle = document.getElementById(allToggleId);
  var group  = document.getElementById(groupId);
  var isAll  = !selectedValues || selectedValues.length === 0;
  toggle.checked = isAll;
  group.style.display = isAll ? 'none' : 'grid';
  group.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
    cb.disabled = isAll;
    cb.checked  = !isAll && selectedValues.indexOf(cb.value) !== -1;
  });
}
</script>
</body>
</html>