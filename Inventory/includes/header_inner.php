<?php
/**
 * header_inner.php
 * Just the topbar div — for pages that need to control their own <head>
 * (like items.php which loads additional page-specific JS).
 * The full header.php is still used by all other pages.
 */
?>
<header class="topbar">
  <div class="topbar__left">
    <button type="button" class="hamburger-btn" id="sidebarToggle" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">
      <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <div>
      <div class="topbar__crumb"><?php echo htmlspecialchars($pageCrumb ?? 'Inventory'); ?></div>
      <div class="topbar__title"><?php echo htmlspecialchars($pageTitle ?? 'Page'); ?></div>
    </div>
    <label class="dept-select" for="departmentSelect">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5-9 5.5-9-5.5Z"/><path d="M7 12.5V17c0 1.1 2.2 2 5 2s5-.9 5-2v-4.5"/></svg>
      <select id="departmentSelect" name="department">
        <option value="">All Departments</option>
        <?php if (!empty($departments)): ?>
          <?php foreach ($departments as $d):
            $deptValue = $d['Department'] ?: ($d['DepartmentCode'] ?? '');
            $deptLabel = $d['DepartmentName'] ?: $deptValue;
          ?>
          <option value="<?php echo htmlspecialchars($deptValue); ?>"><?php echo htmlspecialchars($deptLabel); ?></option>
          <?php endforeach; ?>
        <?php else: ?>
          <option value="Multilines">Multilines</option>
          <option value="Nutriasia">Nutriasia</option>
        <?php endif; ?>
      </select>
    </label>
  </div>
  <div class="topbar__right">
    <div class="userchip">
      <div class="userchip__avatar"><?php echo htmlspecialchars(function_exists('getUserInitials') ? getUserInitials() : '?'); ?></div>
      <span><?php echo htmlspecialchars($_SESSION['display_name'] ?? ''); ?></span>
    </div>
    <a href="../logout.php" class="iconbtn" title="Log out" style="margin-left:6px;">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
    </a>
  </div>
</header>
