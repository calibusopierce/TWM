<?php
include 'test_sqlsrv.php';

// --- HANDLE SAVE (INSERT / UPDATE) ---
if (isset($_POST['save'])) {
    $careerId      = isset($_POST['CareerID']) ? intval($_POST['CareerID']) : 0;
    $jobTitle      = $_POST['JobTitle'];
    $department    = $_POST['Department'];
    $location      = $_POST['Location'];
    $qualifications= $_POST['Qualifications'];
    $jobDescription= $_POST['JobDescription'];

    // Handle image upload
    $jobImage = null;
    if (!empty($_FILES['JobImage']['tmp_name'])) {
        $jobImage = fopen($_FILES['JobImage']['tmp_name'], 'rb'); // stream for varbinary
    }

    if ($careerId > 0) {
        // --- UPDATE ---
        if ($jobImage) {
            $sql = "UPDATE Careers 
                    SET JobTitle=?, Department=?, Location=?, Qualifications=?, JobDescription=?, JobImage=? 
                    WHERE CareerID=?";
            $params = [$jobTitle, $department, $location, $qualifications, $jobDescription, $jobImage, $careerId];
        } else {
            $sql = "UPDATE Careers 
                    SET JobTitle=?, Department=?, Location=?, Qualifications=?, JobDescription=? 
                    WHERE CareerID=?";
            $params = [$jobTitle, $department, $location, $qualifications, $jobDescription, $careerId];
        }
    } else {
        // --- INSERT ---
        $sql = "INSERT INTO Careers 
                (JobTitle, Department, Location, Qualifications, JobDescription, JobImage, IsActive) 
                VALUES (?, ?, ?, ?, ?, ?, 1)";

        $params = [$jobTitle, $department, $location, $qualifications, $jobDescription];
        if ($jobImage) {
            $params[] = [$jobImage, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STREAM(SQLSRV_ENC_BINARY)];
        } else {
            $params[] = null;
        }
    }

    // Execute query, suppress false errors for varbinary streams
    $stmt = @sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        $errors = sqlsrv_errors();
        if ($errors) {
            die(print_r($errors, true)); // only show real errors
        }
    } else {
        echo "<div class='alert alert-success'>Career saved successfully!</div>";
    }
}

// --- HANDLE DELETE ---
if (isset($_GET['delete'])) {
    $careerId = intval($_GET['delete']);
    $sql = "DELETE FROM Careers WHERE CareerID=?";
    $stmt = sqlsrv_query($conn, $sql, [$careerId]);
    if ($stmt) {
        echo "<div class='alert alert-success'>Career deleted successfully!</div>";
    }
}

// --- HANDLE EDIT FETCH ---
$editCareer = null;
if (isset($_GET['edit'])) {
    $careerId = intval($_GET['edit']);
    $sql = "SELECT CareerID, JobTitle, Department, Location, Qualifications, JobDescription FROM Careers WHERE CareerID=?";
    $stmt = sqlsrv_query($conn, $sql, [$careerId]);
    if ($stmt) {
        $editCareer = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    }
}

// --- FETCH ALL CAREERS ---
$sql = "SELECT CareerID, JobTitle, Department, Location FROM Careers ORDER BY CareerID DESC";
$stmtAll = sqlsrv_query($conn, $sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Careers Admin Panel</title>
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/main.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">

<h2 class="mb-4">Careers Admin Panel</h2>

<!-- Add / Edit Form -->
<div class="card mb-4">
<div class="card-header"><?= $editCareer ? "Edit Career" : "Add Career" ?></div>
<div class="card-body">
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="CareerID" value="<?= $editCareer['CareerID'] ?? '' ?>">

    <div class="row mb-3">
        <div class="col">
            <label class="form-label">Job Title</label>
            <input type="text" name="JobTitle" class="form-control" required value="<?= htmlspecialchars($editCareer['JobTitle'] ?? '') ?>">
        </div>
        <div class="col">
            <label class="form-label">Department</label>
            <input type="text" name="Department" class="form-control" required value="<?= htmlspecialchars($editCareer['Department'] ?? '') ?>">
        </div>
    </div>

    <div class="row mb-3">
        <div class="col">
            <label class="form-label">Location</label>
            <input type="text" name="Location" class="form-control" value="<?= htmlspecialchars($editCareer['Location'] ?? '') ?>">
        </div>
        <div class="col">
            <label class="form-label">Image</label>
            <input type="file" name="JobImage" class="form-control">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Qualifications</label>
        <textarea name="Qualifications" class="form-control" rows="3"><?= htmlspecialchars($editCareer['Qualifications'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
        <label class="form-label">Job Description</label>
        <textarea name="JobDescription" class="form-control" rows="3"><?= htmlspecialchars($editCareer['JobDescription'] ?? '') ?></textarea>
    </div>

    <button type="submit" name="save" class="btn btn-success">Save</button>
</form>
</div>
</div>

<!-- Careers Table -->
<div class="card">
<div class="card-header">All Careers</div>
<div class="card-body">
<table class="table table-bordered table-striped">
<thead>
<tr>
<th>ID</th>
<th>Job Title</th>
<th>Department</th>
<th>Location</th>
<th>Actions</th>
</tr>
</thead>
<tbody>
<?php while ($row = sqlsrv_fetch_array($stmtAll, SQLSRV_FETCH_ASSOC)) { ?>
<tr>
<td><?= $row['CareerID'] ?></td>
<td><?= htmlspecialchars($row['JobTitle']) ?></td>
<td><?= htmlspecialchars($row['Department']) ?></td>
<td><?= htmlspecialchars($row['Location']) ?></td>
<td>
<a href="?edit=<?= $row['CareerID'] ?>" class="btn btn-sm btn-primary">Edit</a>
<a href="?delete=<?= $row['CareerID'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
</td>
</tr>
<?php } ?>
</tbody>
</table>
</div>
</div>

</div>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
