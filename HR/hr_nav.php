<?php
/**
 * hr_nav.php — HR Module Sidebar
 * Include this AFTER topbar.php on every HR page.
 * Usage:
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/TWM/includes/topbar.php';
 *   $hr_active = 'attendance'; // set before including
 *   require_once __DIR__ . '/../hr_nav.php';
 *
 * NOTE: topbar.php renders the hamburger toggle button (#hrSidebarToggleBtn)
 * beside the logo, but only when it detects an HR page. That button and
 * this sidebar communicate purely through the shared toggleHRSidebar()
 * function and element IDs below.
 */
// $hr_active must be set by the including page BEFORE require-ing this file.
// e.g. $hr_active = 'attendance';
$hr_active = $hr_active ?? '';
$hr_menu = [
    'payroll_dashboard'    => ['icon' => 'bi-cash-stack',          'label' => 'Payroll Dashboard'],
    'employee-list'        => ['icon' => 'bi-people-fill',         'label' => 'Employees'],
    'attendance'           => ['icon' => 'bi-clock-fill',          'label' => 'Attendance'],
    'visual-attendance'    => ['icon' => 'bi-camera-fill',         'label' => 'Visual Attendance'],
    // Leave lives in its own module folder (TWM/LEAVE), not TWM/HR — so it
    // needs an explicit href override instead of the default HR/{slug}.php.
    'leave'                => ['icon' => 'bi-calendar2-check-fill','label' => 'Leave', 'href' => 'LEAVE/leave-application-list.php'],
    // 'uniform-inventory'    => ['icon' => 'bi-bag-fill',            'label' => 'Uniforms'],
    'careers-admin'        => ['icon' => 'bi-briefcase-fill',      'label' => 'Careers'],
    // 'employee-blacklist'   => ['icon' => 'bi-slash-circle-fill',   'label' => 'Blacklist'],
    'help-manual'          => ['icon' => 'bi-book-fill',           'label' => 'Help Manual'],
];
?>
<style>
/* ── HR sidebar shell ─────────────────────────────────────────── */
/* The sidebar is position:fixed at every breakpoint so it always sits
   on top of the page content and never gets squeezed, wrapped, or hidden
   by flex reflow while the window is resized. It's offset by the actual
   topbar height (measured at runtime via --hr-topbar-height below) so it
   never covers the topbar itself — including the hamburger button that
   controls it. .hr-main is offset with a matching margin-left instead of
   being a flex sibling. */
:root{ --hr-topbar-height: 64px; } /* fallback until JS measures the real topbar */

.hr-shell{position:relative;min-height:100vh;}

.hr-sidebar{
  position:fixed;
  top:var(--hr-topbar-height, 64px);
  left:0;
  height:calc(100vh - var(--hr-topbar-height, 64px));
  width:260px;
  background:#fff;
  border-right:1px solid #eef0f3;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  transform:translateX(0);
  transition:transform .25s ease, top .15s ease, height .15s ease;
  z-index:900;
}
/* Collapsed = fully hidden (slid off-screen), not an icon-only rail.
   Labels and icons stay as-is; the whole sidebar just tucks away. */
.hr-sidebar.collapsed{transform:translateX(-100%);}

.hr-sidebar-header{
  display:flex;align-items:center;gap:.6rem;
  padding:1.1rem 1rem;border-bottom:1px solid #eef0f3;flex-shrink:0;
  background:#fff;
}
.hr-sidebar-icon{font-size:1.3rem;line-height:1;}
.hr-sidebar-title{font-weight:800;font-size:.95rem;color:#111827;white-space:nowrap;}
.hr-sidebar-title span{color:#6366f1;}

.hr-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:.6rem;}
.hr-nav-item{
  display:flex;align-items:center;gap:.7rem;padding:.62rem .7rem;border-radius:9px;
  color:#4b5563;text-decoration:none;font-size:.82rem;font-weight:600;position:relative;
  transition:background .15s,color .15s;margin-bottom:.15rem;white-space:nowrap;
}
.hr-nav-item:hover{background:rgba(99,102,241,.07);color:#6366f1;}
.hr-nav-item.active{background:rgba(99,102,241,.1);color:#6366f1;}
.hr-nav-item i{font-size:1rem;width:20px;text-align:center;flex-shrink:0;}
.hr-nav-pip{position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:60%;background:#6366f1;border-radius:0 4px 4px 0;}

.hr-sidebar-footer{padding:.6rem;border-top:1px solid #eef0f3;flex-shrink:0;}

/* Content is offset by the sidebar's width via margin-left since the
   sidebar itself is fixed and out of normal flow. */
.hr-main{
  min-width:0;
  padding:1.5rem;
  margin-left:260px;
  transition:margin-left .25s ease;
}
.hr-shell.is-collapsed .hr-main{margin-left:0;}

/* Accessible focus states */
.hr-nav-item:focus-visible{outline:2px solid #6366f1;outline-offset:2px;}

/* Overlay (mobile/tablet only) */
.hr-sidebar-overlay{display:none;}

/* ── Tablet & mobile: hidden by default, slides in on top of content ── */
@media (max-width:1024px){
  .hr-sidebar{
    width:280px;
    max-width:82vw;
    transform:translateX(-100%);
    box-shadow:0 12px 40px rgba(0,0,0,.18);
  }
  .hr-sidebar.mobile-open{transform:translateX(0);}

  .hr-sidebar-overlay{
    display:block;
    position:fixed;
    top:var(--hr-topbar-height, 64px);
    left:0;
    right:0;
    bottom:0;
    background:rgba(15,23,42,.45);
    opacity:0;
    pointer-events:none;
    transition:opacity .25s ease;
    z-index:850;
  }
  .hr-sidebar-overlay.active{opacity:1;pointer-events:all;}

  /* On small screens the sidebar overlays content — never push/offset it. */
  .hr-main,
  .hr-shell.is-collapsed .hr-main{
    margin-left:0;
    padding:1rem;
  }
}

@media (max-width:480px){
  .hr-sidebar{width:86vw;}
  .hr-main{padding:.75rem;}
}
</style>

<div class="hr-shell" id="hrShell">
  <!-- ── Mobile/tablet overlay ────────────────────────────── -->
  <div class="hr-sidebar-overlay" id="hrOverlay"></div>

  <!-- ── Sidebar ──────────────────────────────────────────── -->
  <aside class="hr-sidebar" id="hrSidebar" aria-label="HR module navigation" aria-hidden="false">
    <div class="hr-sidebar-header">
      <span class="hr-sidebar-icon" aria-hidden="true">👥</span>
      <span class="hr-sidebar-title">HR <span>Module</span></span>
    </div>
    <nav class="hr-nav" role="navigation" aria-label="HR sections">
      <?php foreach ($hr_menu as $slug => $item):
        $isActive = ($hr_active === $slug);
        $href     = base_url($item['href'] ?? "HR/{$slug}.php");
      ?>
      <a href="<?= $href ?>"
         class="hr-nav-item<?= $isActive ? ' active' : '' ?>"
         title="<?= htmlspecialchars($item['label']) ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>>
        <i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i>
        <span class="hr-nav-label"><?= htmlspecialchars($item['label']) ?></span>
        <?php if ($isActive): ?>
          <span class="hr-nav-pip"></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="hr-sidebar-footer">
      <a href="<?= base_url('index.php') ?>" class="hr-nav-item" title="Back to TWM">
        <i class="bi bi-arrow-left-circle-fill" aria-hidden="true"></i>
        <span class="hr-nav-label">Back to TWM</span>
      </a>
    </div>
  </aside>

  <!-- ── Main Content Area ────────────────────────────────── -->
  <main class="hr-main" id="hrMain">

<script>
(function () {
  var MOBILE_BREAKPOINT = 1024;
  var shell     = document.getElementById('hrShell');
  var sidebar   = document.getElementById('hrSidebar');
  var overlay   = document.getElementById('hrOverlay');
  var topbarBtn = document.getElementById('hrSidebarToggleBtn');

  if (!sidebar) return;

  // ── Keep the sidebar/overlay pinned exactly below the topbar ────
  // Measured at runtime since topbar.php's real height isn't known
  // ahead of time (and can change with content/wrapping/zoom).
  var topbarEl = document.querySelector('header.topbar') || document.querySelector('.topbar');
  function updateTopbarOffset() {
    var h = topbarEl ? Math.round(topbarEl.getBoundingClientRect().height) : 64;
    if (h > 0) document.documentElement.style.setProperty('--hr-topbar-height', h + 'px');
  }
  updateTopbarOffset();
  window.addEventListener('load', updateTopbarOffset);
  window.addEventListener('resize', updateTopbarOffset);
  if (topbarEl && typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(updateTopbarOffset).observe(topbarEl);
  }

  function syncShellOffset() {
    if (shell) shell.classList.toggle('is-collapsed', sidebar.classList.contains('collapsed'));
  }

  function isMobile() {
    return window.innerWidth <= MOBILE_BREAKPOINT;
  }

  function isExpanded() {
    return isMobile()
      ? sidebar.classList.contains('mobile-open')
      : !sidebar.classList.contains('collapsed');
  }

  function syncAria() {
    var expanded = isExpanded();
    if (topbarBtn) topbarBtn.setAttribute('aria-expanded', String(expanded));
    sidebar.setAttribute('aria-hidden', String(!expanded));
  }

  function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    if (overlay) overlay.classList.add('active');
    syncAria();
    var firstLink = sidebar.querySelector('.hr-nav-item');
    if (firstLink) firstLink.focus();
  }

  function closeMobileSidebar(returnFocus) {
    sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    syncAria();
    if (returnFocus && topbarBtn) topbarBtn.focus();
  }

  window.toggleHRSidebar = function () {
    if (isMobile()) {
      if (sidebar.classList.contains('mobile-open')) {
        closeMobileSidebar(true);
      } else {
        openMobileSidebar();
      }
    } else {
      var collapsed = sidebar.classList.toggle('collapsed');
      try { localStorage.setItem('hrSidebarCollapsed', collapsed); } catch (e) {}
      syncShellOffset();
      syncAria();
    }
  };

  if (overlay) {
    overlay.addEventListener('click', function () { closeMobileSidebar(false); });
  }

  document.addEventListener('click', function (e) {
    if (!isMobile() || !sidebar.classList.contains('mobile-open')) return;
    var clickedToggle = topbarBtn && topbarBtn.contains(e.target);
    if (!sidebar.contains(e.target) && !clickedToggle) {
      closeMobileSidebar(false);
    }
  });

  sidebar.querySelectorAll('.hr-nav-item').forEach(function (item) {
    item.addEventListener('click', function () {
      if (isMobile()) closeMobileSidebar(false);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isMobile() && sidebar.classList.contains('mobile-open')) {
      closeMobileSidebar(true);
    }
  });

  window.addEventListener('resize', function () {
    if (!isMobile()) {
      if (overlay) overlay.classList.remove('active');
      sidebar.classList.remove('mobile-open');
    } else {
      sidebar.classList.remove('collapsed');
    }
    syncShellOffset();
    syncAria();
  });

  try {
    if (!isMobile() && localStorage.getItem('hrSidebarCollapsed') === 'true') {
      sidebar.classList.add('collapsed');
    }
  } catch (e) {}

  syncShellOffset();
  syncAria();
})();
</script>