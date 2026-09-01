<?php
require_once __DIR__ . '/includes/auth_guard.php';
/**
 * index.php (formerly landing.php)
 * The first page a user sees at the site root. They pick which
 * inventory to enter — Warehouse or Technical — each lives in its own
 * folder with its own set of pages, so the choice here is really just
 * which folder to enter.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tradewell Inventory</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="landing-body">

<div style="position:fixed; top:20px; right:24px; display:flex; align-items:center; gap:10px; font-size:12.5px; color:var(--ink-300); z-index:5;">
  <span>Signed in as <strong style="color:var(--ink-700);"><?php echo htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? ''); ?></strong></span>
  <a href="logout.php" style="color:var(--ink-300); text-decoration:underline;">Logout</a>
</div>

<main class="landing">
  <div class="landing__brand">
    <div class="landing__mark">TW</div>
    <span>Tradewell</span>
  </div>

  <div class="landing__intro">
    <span class="landing__eyebrow">Inventory Management System</span>
    <h1>Where are we working today?</h1>
    <p>Pick an inventory to continue. Everything after this — stocks, purchase orders, suppliers — will be scoped to your choice.</p>
  </div>

  <div class="landing__cards">
    <a href="warehouse/index.php" class="landing-card" data-inventory="warehouse">
      <div class="landing-card__icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5V20a1 1 0 0 1-1 1h-4v-6H8v6H4a1 1 0 0 1-1-1V9.5Z"/><path d="M9 21v-6h6v6"/></svg>
      </div>
      <div class="landing-card__body">
        <h2>Warehouse</h2>
        <p>Stock on hand, receiving, and purchase orders across storage locations.</p>
      </div>
      <div class="landing-card__go">
        Enter
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
      </div>
    </a>

    <a href="technical/index.php" class="landing-card" data-inventory="technical">
      <div class="landing-card__icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
      </div>
      <div class="landing-card__body">
        <h2>Technical</h2>
        <p>Parts, equipment, and specialized items tracked for technical operations.</p>
      </div>
      <div class="landing-card__go">
        Enter
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
      </div>
    </a>
  </div>
</main>

<div class="landing-transition" id="landingTransition"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var cards = document.querySelectorAll('.landing-card');
  var transition = document.getElementById('landingTransition');

  cards.forEach(function (card) {
    card.addEventListener('click', function (e) {
      e.preventDefault();
      if (card.classList.contains('is-launching')) return; // guard double-click

      var href = card.getAttribute('href');
      cards.forEach(function (c) { c.classList.add('is-settled'); });
      card.classList.add('is-launching');
      card.classList.remove('is-settled');
      transition.classList.add('is-active');

      window.setTimeout(function () {
        window.location.href = href;
      }, 420);
    });
  });
});
</script>

</body>
</html>
