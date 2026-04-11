<?php
session_start();
include '../test_sqlsrv.php';

// read-and-clear any success message set by job-application.php
$successMessage = "";
if (!empty($_SESSION['successMessage'])) {
    $successMessage = $_SESSION['successMessage'];
    unset($_SESSION['successMessage']);
}

// fetch active careers
$sql = "SELECT CareerID, JobTitle, Location, IsActive FROM Careers WHERE IsActive = 1";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$careers = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $careers[] = $row;
}
sqlsrv_free_stmt($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>Careers</title>
  <meta name="description" content="">
  <meta name="keywords" content="">

  <!-- Favicons -->
  <link href="../assets/img/logo.png" rel="icon">
  <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="../assets/vendor/fonts/fonts.css" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="../assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="../assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">

  <!-- Main CSS File -->
  <link href="../assets/css/main.css" rel="stylesheet">

  <!-- Careers CSS -->
  <link href="../assets/css/styles.css" rel="stylesheet">

</head>

<body class="index-page">

  <!-- ══ HEADER (matching careers-details & job-application) ══ -->
  <header id="header" class="header">
    <div class="topbar d-flex align-items-center">
      <div class="container d-flex justify-content-center justify-content-md-between">
        <div class="contact-info d-flex align-items-center">
          <i class="bi bi-envelope d-flex align-items-center ms-4"><span>hr_tradewell@yahoo.com | hr.tradewell@gmail.com</span></i>
          <i class="bi bi-telephone-fill d-flex align-items-center ms-4"><span>(042)719-1306</span></i>
          <i class="bi bi-messenger d-flex align-items-center ms-4"><span>UrbanTradewellCorp</span></i>
        </div>
      </div>
    </div>
  </header>

<main class="main">

<!-- ══ HERO ════════════════════════════════════════ -->
<section class="careers-hero">
  <div class="hero-inner">
    <div class="hero-eyebrow">
      <i class="bi bi-briefcase-fill"></i>
      We're Hiring
    </div>
    <h1 class="hero-title">
      Build Your Career<br>with <span>Urban Tradewell</span>
    </h1>
    <p class="hero-desc">
      Join a growing team dedicated to excellence in distribution and trade.
      Explore open positions and take the next step in your career journey.
    </p>
  </div>
</section>

<!-- ══ MAIN ═════════════════════════════════════════ -->
<section class="careers-section">
  <div class="careers-main">

  <!-- Success Alert -->
  <?php if (!empty($successMessage)): ?>
    <div class="alert-success-custom" role="alert">
      <i class="bi bi-check-circle-fill" style="font-size:1.1rem; color:#10b981; flex-shrink:0;"></i>
      <span><?= htmlspecialchars($successMessage) ?></span>
    </div>
  <?php endif; ?>

  <!-- Section Header -->
  <div class="section-head">
    <div class="section-head-left">
      <div class="section-label">Open Positions</div>
      <div class="section-title">Current Job Listings</div>
    </div>
    <?php if (!empty($careers)): ?>
      <div class="count-badge">
        <i class="bi bi-circle-fill" style="font-size:.45rem; color:#10b981;"></i>
        <?= count($careers) ?> Active listing<?= count($careers) !== 1 ? 's' : '' ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Careers Grid — untouched -->
  <div class="careers-grid">
    <?php if (empty($careers)): ?>
      <div class="empty-state">
        <i class="bi bi-briefcase empty-icon"></i>
        <p>No open positions at the moment.<br>Check back soon — we're always growing!</p>
      </div>
    <?php else: ?>
      <?php foreach ($careers as $row): ?>
        <a href="careers-details.php?id=<?= (int)$row['CareerID'] ?>" class="career-card">
          <div style="display:flex; align-items:flex-start; gap:.9rem;">
            <div class="card-icon-wrap">
              <i class="bi bi-briefcase-fill"></i>
            </div>
            <div class="card-body-content">
              <div class="card-title"><?= htmlspecialchars($row['JobTitle']) ?></div>
              <?php if (!empty($row['Location'])): ?>
                <div class="card-meta">
                  <i class="bi bi-geo-alt-fill"></i>
                  <?= htmlspecialchars($row['Location']) ?>
                </div>
              <?php endif; ?>
              <div class="card-active-badge">
                <span class="dot"></span> Actively Hiring
              </div>
            </div>
          </div>
          <div class="card-footer-row">
            <span style="font-size:.78rem; color:var(--text-3);">
              <i class="bi bi-clock" style="margin-right:.25rem;"></i>Full-time
            </span>
            <span class="btn-view-details">
              View Details <i class="bi bi-arrow-right"></i>
            </span>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  </div>
</section>

</main>

  <!-- ══ FOOTER (matching careers-details & job-application) ══ -->
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
            <p><strong>Email:</strong> <span>hr_tradewell@yahoo.com | hr.tradewell@gmail.com</span></p>
          </div>
        </div>

        <div class="col-lg-2 col-md-3 footer-links">
          <h4>Useful Links</h4>
          <ul>
            <li><i class="bi bi-chevron-right"></i> <a href="https://122.52.195.3/">Home</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="https://122.52.195.3/#about">About us</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="https://122.52.195.3/#portfolio">Brand</a></li>
            <li><i class="bi bi-chevron-right"></i> <a href="https://122.52.195.3/#services">Coverage</a></li>
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
            <a href="https://122.52.195.3/#contact"><i class="bi bi-envelope-fill"></i></a>
          </div>
        </div>
      </div>
    </div>

    <div class="container copyright text-center mt-4">
      <p>© <strong>Copyright</strong> <strong class="px-1 sitename">Urban Tradewell Corporation</strong> <span>All Rights Reserved.</span></p>
      <div class="credits"></div>
    </div>
  </footer>

  <!-- Scroll Top -->
  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Preloader -->
  <div id="preloader">
    <div></div>
    <div></div>
    <div></div>
    <div></div>
  </div>

  <!-- Vendor JS Files -->
  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/vendor/php-email-form/validate.js"></script>
  <script src="../assets/vendor/aos/aos.js"></script>
  <script src="../assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="../assets/vendor/waypoints/noframework.waypoints.js"></script>
  <script src="../assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="../assets/vendor/swiper/swiper-bundle.min.js"></script>
  <script src="../assets/vendor/imagesloaded/imagesloaded.pkgd.min.js"></script>
  <script src="../assets/vendor/isotope-layout/isotope.pkgd.min.js"></script>

  <!-- Main JS File -->
  <script src="../assets/js/main.js"></script>

</body>
</html>