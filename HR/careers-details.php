<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/nav.php';
include("../test_sqlsrv.php");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Career Details</title>
  <meta name="description" content="">
  <meta name="keywords" content="">

  <link href="../assets/img/logo.png" rel="icon">
  <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="../assets/vendor/fonts/fonts.css" rel="stylesheet">

  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="../assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="../assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">
  <link href="../assets/css/main.css" rel="stylesheet">

  <!-- Careers CSS -->
  <link href="../assets/css/styles.css" rel="stylesheet">
</head>

<body class="service-details-page">

  <!-- ══ ORIGINAL HEADER ══ -->
  <header id="header" class="header">
    <div class="topbar d-flex align-items-center">
      <div class="container d-flex justify-content-center justify-content-md-between">
        <div class="contact-info d-flex align-items-center">
          <i class="bi bi-envelope d-flex align-items-center ms-4"><span>hr_tradewell@yahoo.com</span></i>
          <i class="bi bi-telephone-fill d-flex align-items-center ms-4"><span>(042)719-1306</span></i>
          <i class="bi bi-messenger d-flex align-items-center ms-4"><span>UrbanTradewellCorp</span></i>
        </div>
      </div>
    </div>
  </header>

  <main class="main">

<?php
$careerId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$sql = "SELECT CareerID, JobTitle, JobDescription, Department, Qualifications, Location, JobImage 
        FROM Careers WHERE CareerID = ?";
$params = [$careerId];
$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) { die(print_r(sqlsrv_errors(), true)); }
$job = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if (!$job) { die("Job not found."); }
?>

    <!-- Page Hero -->
    <div class="page-hero">
      <div class="hero-inner">
        <div>
          <div class="page-hero-title"><?= htmlspecialchars($job['JobTitle']) ?></div>
          <div class="page-hero-sub">
            <i class="bi bi-geo-alt-fill"></i>
            <?= !empty($job['Location']) ? htmlspecialchars($job['Location']) : 'Urban Tradewell Corporation' ?>
            <?php if (!empty($job['Department'])): ?>
              &nbsp;·&nbsp;<i class="bi bi-building"></i>
              <?= htmlspecialchars($job['Department']) ?>
            <?php endif; ?>
          </div>
        </div>
        <nav class="breadcrumb-nav">
          <a href="careers.php"><i class="bi bi-briefcase"></i> Careers</a>
          <span class="sep">/</span>
          <span class="current">Job Details</span>
        </nav>
      </div>
    </div>

    <!-- Content -->
    <div class="details-main">
      <div class="details-layout">

        <!-- Sidebar -->
        <aside>
          <div class="sidebar-card">
            <div class="sidebar-card-header">
              <h3><i class="bi bi-list-ul" style="margin-right:.4rem;"></i>Other Openings</h3>
            </div>
            <div class="job-list">
              <?php
              $sqlList = "SELECT CareerID, JobTitle FROM Careers WHERE IsActive = 1";
              $stmtList = sqlsrv_query($conn, $sqlList);
              while ($row = sqlsrv_fetch_array($stmtList, SQLSRV_FETCH_ASSOC)) {
                  $activeClass = ($row['CareerID'] == $careerId) ? 'active' : '';
                  $icon = ($row['CareerID'] == $careerId) ? 'bi-dot' : 'bi-chevron-right';
                  echo "<a href='careers-details.php?id={$row['CareerID']}' class='{$activeClass}'>"
                     . "<i class='bi {$icon}'></i>"
                     . htmlspecialchars($row['JobTitle'])
                     . "</a>";
              }
              ?>
            </div>
          </div>

          <div class="info-block">
            <div class="info-block-title"><i class="bi bi-patch-check-fill"></i> Qualifications</div>
            <ul class="qual-list">
              <?php
              $qualifications = explode("\n", $job['Qualifications']);
              foreach ($qualifications as $qual) {
                  $q = trim($qual);
                  if ($q !== '') echo "<li><i class='bi bi-check-circle-fill'></i><span>" . htmlspecialchars($q) . "</span></li>";
              }
              ?>
            </ul>
          </div>

          <div class="info-block">
            <div class="info-block-title"><i class="bi bi-file-text-fill"></i> Job Description</div>
            <p class="desc-text"><?= nl2br(htmlspecialchars($job['JobDescription'])) ?></p>
          </div>
        </aside>

        <!-- Main panel -->
        <div class="main-panel">
          <div class="job-image-card">
            <?php if (!empty($job['JobImage'])): ?>
              <img src="data:image/jpeg;base64,<?= base64_encode($job['JobImage']) ?>"
                   alt="<?= htmlspecialchars($job['JobTitle']) ?>">
            <?php else: ?>
              <div class="job-image-placeholder"><i class="bi bi-briefcase"></i></div>
            <?php endif; ?>
          </div>

          <div class="apply-card">
            <div class="apply-card-text">
              <div class="apply-label">Ready to join us?</div>
              <div class="apply-title">Apply for <?= htmlspecialchars($job['JobTitle']) ?></div>
              <div class="apply-sub">Full-time · <?= !empty($job['Location']) ? htmlspecialchars($job['Location']) : 'Lucena City' ?></div>
            </div>
            <a href="job-application.php?id=<?= $job['CareerID'] ?>" class="btn-apply">
              <i class="bi bi-send-fill"></i> Send Application
            </a>
          </div>
        </div>

      </div>
    </div>

  </main>

  <!-- ══ ORIGINAL FOOTER ══ -->
  <footer id="footer" class="footer">
    <div class="container footer-top">
      <div class="row gy-4">
        <div class="col-lg-4 col-md-6 footer-about">
          <a href="careers.php" class="d-flex align-items-center">
            <span class="sitename">Urban Tradewell Corporation</span>
          </a>
          <div class="footer-contact pt-3">
            <p>Sta. Monica Street Lourdes Subdivision</p>
            <p>Phase 2 Ibabang Iyam, Lucena City 4301</p>
            <p class="mt-3"><strong>Phone:</strong> <span>(042) 719-1306</span></p>
            <p><strong>Email:</strong> <span>hr_tradewell@yahoo.com</span></p>
          </div>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Useful Links</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="tradewell.php">Home</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">About us</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="careers.php">Careers</a></li>
          </ul>
        </div>
        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Departments</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Monde Nissin Corporation</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Century Pacific Food Inc.</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Nutriasia</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="#">Multilines</a></li>
          </ul>
        </div>
        <div class="col-lg-4 col-md-12">
          <h4>Contact Us</h4>
          <p>For more information, feel free to contact us through the following:</p>
          <div class="social-links d-flex">
            <a href="https://www.facebook.com/UrbanTradewellCorp"><i class="bi bi-facebook"></i></a>
          </div>
        </div>
      </div>
    </div>
    <div class="container copyright text-center mt-4">
      <p>© <strong>Copyright</strong> <strong class="px-1 sitename">Urban Tradewell Corporation</strong> <span>All Rights Reserved.</span></p>
      <div class="credits"></div>
    </div>
  </footer>

  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>
  <div id="preloader"><div></div><div></div><div></div><div></div></div>

  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/vendor/php-email-form/validate.js"></script>
  <script src="../assets/vendor/aos/aos.js"></script>
  <script src="../assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="../assets/vendor/waypoints/noframework.waypoints.js"></script>
  <script src="../assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="../assets/vendor/swiper/swiper-bundle.min.js"></script>
  <script src="../assets/vendor/imagesloaded/imagesloaded.pkgd.min.js"></script>
  <script src="../assets/vendor/isotope-layout/isotope.pkgd.min.js"></script>
  <script src="../assets/js/main.js"></script>

</body>
</html>