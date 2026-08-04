/**
 * app.js
 * Shared UI interactions across pages:
 *  - open/close whichever .modal-overlay is on the current page
 *  - client-side search filter for any .searchbox input + its table
 *  - Add New Supplier -> save_supplier.php (real INSERT, both Warehouse and Technical)
 *  - Create New Item -> save_item.php (real INSERT, Warehouse only)
 *  - Items: Supplier Code -> Brand Name / Category cascading dropdowns (live)
 */

document.addEventListener('DOMContentLoaded', function () {

  /* ---- Modal open/close (overlay-aware -- pages can have more than one .modal-overlay) ---- */
  const overlays  = document.querySelectorAll('.modal-overlay');
  const openBtns  = document.querySelectorAll('[data-open-modal]');
  const closeBtns = document.querySelectorAll('[data-close-modal]');

  openBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      // [data-open-modal] buttons are for the page's primary create/edit
      // modal; other modals (like a confirmation dialog) are opened
      // directly by their own script instead of through this button.
      const overlay = overlays[0];
      if (!overlay) return;
      overlay.classList.add('is-open');
      const firstField = overlay.querySelector('input, select, textarea');
      if (firstField) firstField.focus();
    });
  });

  closeBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      const overlay = btn.closest('.modal-overlay');
      if (overlay) overlay.classList.remove('is-open');
    });
  });

  overlays.forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.classList.remove('is-open');
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    overlays.forEach(function (overlay) { overlay.classList.remove('is-open'); });
  });

  /* ---- Mobile sidebar toggle (Warehouse only — the button/backdrop are
     hidden entirely on Technical via CSS, this is just a matching guard) ---- */
  if (document.querySelector('.app--warehouse')) {
    const sidebar     = document.getElementById('sidebar');
    const toggleBtn   = document.getElementById('sidebarToggle');
    const backdrop    = document.getElementById('sidebarBackdrop');

    function openSidebar() {
      if (!sidebar) return;
      sidebar.classList.add('is-open');
      if (backdrop) backdrop.classList.add('is-open');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
      if (!sidebar) return;
      sidebar.classList.remove('is-open');
      if (backdrop) backdrop.classList.remove('is-open');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }

    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        if (sidebar && sidebar.classList.contains('is-open')) {
          closeSidebar();
        } else {
          openSidebar();
        }
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeSidebar();
    });

    // Closing on nav tap keeps the drawer from staying open after the
    // page navigates to a new inner page (full page load resets it anyway,
    // but this avoids a visual flash on slower connections).
    if (sidebar) {
      sidebar.querySelectorAll('.navlink, .sidebar__switch').forEach(function (link) {
        link.addEventListener('click', closeSidebar);
      });
    }
  }

  /* ---- Add New Supplier form -> save_supplier.php (INSERT into TBL_Item_Supplier) ---- */
  const form = document.getElementById('supplierForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = form.querySelector('button[type="submit"]');
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';

      fetch('save_supplier.php', {
        method: 'POST',
        body: new FormData(form)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not save this supplier.');
            return;
          }
          form.reset();
          if (overlays[0]) overlays[0].classList.remove('is-open');
          window.location.reload(); // simplest way to reflect the new row for now
        })
        .catch(function (err) {
          console.error(err);
          alert('Something went wrong saving the supplier. Check the connection to TradewellDatabase.');
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }

  /* ---- Note: Technical's supplier form now shares the same #supplierForm
     id/handler above, since it's DB-wired too — no separate preview-only
     path needed here anymore. ---- */

  /* ---- Add New Category form (Technical) -> save_category.php (real INSERT into TBL_Technical_Category) ---- */
  const categoryForm = document.getElementById('categoryForm');
  if (categoryForm) {
    categoryForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = categoryForm.querySelector('button[type="submit"]');
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';

      fetch('save_category.php', {
        method: 'POST',
        body: new FormData(categoryForm)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not save this category.');
            return;
          }
          categoryForm.reset();
          if (overlays[0]) overlays[0].classList.remove('is-open');
          window.location.reload(); // simplest way to reflect the new row for now
        })
        .catch(function (err) {
          console.error(err);
          alert('Something went wrong saving the category. Check the connection to TradewellDatabase.');
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }

  /* ---- Register New Item form (Technical) -> save_item.php (real INSERT into TBL_Technical_Items) ---- */
  const techItemForm = document.getElementById('techItemForm');
  if (techItemForm) {
    techItemForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = techItemForm.querySelector('button[type="submit"]');
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';

      fetch('save_item.php', {
        method: 'POST',
        body: new FormData(techItemForm)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not save this item.');
            return;
          }
          techItemForm.reset();
          if (overlays[0]) overlays[0].classList.remove('is-open');
          window.location.reload(); // simplest way to reflect the new row for now
        })
        .catch(function (err) {
          console.error(err);
          alert('Something went wrong saving the item. Check the connection to TradewellDatabase.');
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }

  /* ---- Create New Item form (Warehouse) -> save_item.php (real INSERT into Tbl_Item_Products) ---- */
  const itemForm = document.getElementById('itemForm');
  if (itemForm) {
    itemForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const submitBtn = itemForm.querySelector('button[type="submit"]');
      const originalLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving...';

      fetch('save_item.php', {
        method: 'POST',
        body: new FormData(itemForm)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.error) {
            alert(data.message || 'Could not save this item.');
            return;
          }
          itemForm.reset();
          if (overlays[0]) overlays[0].classList.remove('is-open');
          window.location.reload(); // simplest way to reflect the new row for now
        })
        .catch(function (err) {
          console.error(err);
          alert('Something went wrong saving the item. Check the connection to TradewellDatabase.');
        })
        .finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = originalLabel;
        });
    });
  }

  /* ---- Client-side search filter: any .searchbox input filters its own .card's table rows ---- */
  document.querySelectorAll('.searchbox input').forEach(function (input) {
    const card = input.closest('.card');
    if (!card) return;
    const rows = card.querySelectorAll('table tbody tr');

    input.addEventListener('input', function () {
      const term = input.value.trim().toLowerCase();
      rows.forEach(function (row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
      });
    });
  });

  /* ---- Items: Supplier Code -> Brand Name / Category cascading dropdowns ----
     Queries TBL_Item_Brand and TBL_Item_Category (via get_brand_category.php),
     both filtered by the selected SupplierCode. If a supplier has no brands
     or categories on file, the dropdown is left as-is (disabled, nothing to
     pick). */
  const supplierCodeSelect = document.getElementById('itemSupplierCode');
  const brandSelect        = document.getElementById('itemBrandName');
  const categorySelect     = document.getElementById('itemCategory');

  if (supplierCodeSelect && brandSelect && categorySelect) {

    function fillDependentSelect(select, options, emptyLabel) {
      select.innerHTML = '';
      if (!options || options.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = emptyLabel;
        select.appendChild(opt);
        select.disabled = true;
        return;
      }
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select';
      select.appendChild(placeholder);
      options.forEach(function (o) {
        const opt = document.createElement('option');
        opt.value = o.value;
        opt.textContent = o.label;
        select.appendChild(opt);
      });
      select.disabled = false;
    }

    supplierCodeSelect.addEventListener('change', function () {
      const code = supplierCodeSelect.value;

      if (!code) {
        fillDependentSelect(brandSelect, null, 'Select supplier first');
        fillDependentSelect(categorySelect, null, 'Select supplier first');
        return;
      }

      fillDependentSelect(brandSelect, null, 'Loading...');
      fillDependentSelect(categorySelect, null, 'Loading...');

      fetch('get_brand_category.php?supplier_code=' + encodeURIComponent(code))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          fillDependentSelect(brandSelect, data.brands, 'No brand on file for this supplier');
          fillDependentSelect(categorySelect, data.categories, 'No category on file for this supplier');
        })
        .catch(function (err) {
          console.error(err);
          fillDependentSelect(brandSelect, null, 'Could not load brands');
          fillDependentSelect(categorySelect, null, 'Could not load categories');
        });
    });
  }

  /* ---- Items: keep the full Item Code (prefix + suffix) in sync for submission ----
     Duplicate-code checking itself happens server-side in save_item.php,
     which rejects the save with a clear message if the code already exists. */
  const itemCodeSuffix = document.getElementById('itemCodeSuffix');
  const itemCodePrefix = document.getElementById('itemCodePrefix');
  const itemCodeFull   = document.getElementById('itemCodeFull');

  if (itemCodeSuffix && itemCodeFull) {
    function syncFullItemCode() {
      const prefix = itemCodePrefix ? itemCodePrefix.textContent : '';
      itemCodeFull.value = (prefix + itemCodeSuffix.value).toUpperCase();
    }
    itemCodeSuffix.addEventListener('input', syncFullItemCode);
    syncFullItemCode();
  }

});
