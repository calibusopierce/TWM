<?php
$currentPage = basename($_SERVER['PHP_SELF']);

function navlink($href, $label, $iconPath, $currentPage) {
    $isActive = ($currentPage === $href) ? 'is-active' : '';
    echo '<a class="navlink ' . $isActive . '" href="' . $href . '">';
    echo '<svg class="navlink__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $iconPath . '</svg>';
    echo '<span>' . $label . '</span></a>';
}
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar__brand">
    <div class="sidebar__mark">TW</div>
    <div class="sidebar__brandtext">
      <strong>Tradewell</strong>
      <span><?php echo htmlspecialchars($inventoryLabel ?? 'Warehouse'); ?> Inventory</span>
    </div>
  </div>

  <nav class="sidebar__nav">
    <div class="navgroup">
      <div class="navgroup__label">Inventory</div>
      <?php
      navlink('index.php', 'Dashboard', '<rect x="3" y="3" width="7" height="7" rx="1.2"/><rect x="14" y="3" width="7" height="7" rx="1.2"/><rect x="3" y="14" width="7" height="7" rx="1.2"/><rect x="14" y="14" width="7" height="7" rx="1.2"/>', $currentPage);
      navlink('stocks.php', 'Stocks', '<path d="M3 7l9-4 9 4-9 4-9-4Z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>', $currentPage);
      if (($inventoryType ?? '') === 'technical') {
          navlink('transactions.php', 'Asset Transactions', '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>', $currentPage);
      }
      navlink('purchase_order.php', 'Purchase Order', '<path d="M6 3h12l1 5H5l1-5Z"/><path d="M5 8h14l-1.2 11a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 8Z"/><path d="M9 12h6"/>', $currentPage);
      navlink('receiving.php', 'Receiving', '<path d="M21 8v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8"/><path d="M12 3v13"/><path d="M8 12l4 4 4-4"/>', $currentPage);
      if (($inventoryType ?? '') === 'technical') {
          navlink('release.php', 'Release', '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>', $currentPage);
      }
      ?>
    </div>

    <div class="navgroup">
      <div class="navgroup__label">Maintenance</div>
      <?php
      navlink('supplier.php', 'Supplier', '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 4.2a3 3 0 0 1 0 5.6"/><path d="M18.5 14.5c1.8.6 3.1 2.3 3.5 5"/>', $currentPage);
      navlink('items.php', 'Items', '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 13h4"/>', $currentPage);
      if (($inventoryType ?? '') === 'technical') {
          navlink('category.php', 'Category', '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>', $currentPage);
      }
      ?>
    </div>
  </nav>

  <div class="sidebar__footer">
    <a href="../index.php" class="sidebar__switch">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
      Switch inventory
    </a>
    <span>No DB connected</span>
  </div>
</aside>
