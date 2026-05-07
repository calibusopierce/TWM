# TWM — Unified System Changelog

Comprehensive internal changelog covering RBAC cleanup, database refactoring, employee profile system improvements, and Uniform Inventory migration.

---

# Overview

This update focused on:

* Centralizing SQL Server database connections
* Cleaning up RBAC authentication and shared includes
* Refactoring shared UI components
* Improving employee profile picture handling
* Migrating Uniform Inventory tables and records to production
* Preparing the system for easier deployment and maintenance

---

# File Overview

| File                         | Description                                                                   |
| ---------------------------- | ----------------------------------------------------------------------------- |
| `test_sqlsrv.php`            | Centralized SQL Server connection configuration and shared PDO initialization |
| `includes/nav.php`           | Shared navigation include with automatic database bootstrap                   |
| `includes/topbar.php`        | Shared topbar component with routing and RBAC cleanup                         |
| `UNIFORM_MIGRATION_FULL.sql` | Full migration script for Uniform Inventory schema and data                   |
| `employee-list.php`          | Employee management page with improved profile picture handling               |
| `RBAC/*.php`                 | Role-based access management pages now using shared PDO connection            |

---

# What's New

## May 2026

---

# 🔧 Database Connection Refactor

## `test_sqlsrv.php`

The database connection layer was centralized to simplify deployment and eliminate duplicate connection logic.

### Changes

* Added `DB_LOADED` guard to prevent accidental double includes
* Centralized `$serverName` configuration for easier production switching
* Added global `$pdo` connection alongside existing `$conn`
* Local and production credentials are now separated into dedicated config blocks
* Reduced duplicate SQL connection logic across the project

### Benefits

* Easier production deployment
* Cleaner includes structure
* Shared PDO access across all modules
* Reduced maintenance overhead

---

# 🔧 Shared Navigation Bootstrap

## `includes/nav.php`

### Changes

* Added automatic:

```php
require_once test_sqlsrv.php;
```

* `$conn` and `$pdo` are now globally available on every page that includes `nav.php`
* Removed the need for repetitive DB includes on child pages

---

# 🔐 RBAC & Authentication Cleanup

All RBAC pages were updated to use the centralized PDO connection.

## Changes Across RBAC Pages

### Removed hardcoded connections

Removed legacy blocks similar to:

```php
$pdo_rbac = new PDO("sqlsrv:Server=PIERCE...");
```

### Standardized PDO usage

* Replaced all `$pdo_rbac` references with shared global `$pdo`
* Removed redundant `require_once test_sqlsrv.php` calls from pages already loading `nav.php`
* Standardized authentication and database access flow

### Benefits

* Cleaner architecture
* Fewer connection conflicts
* Easier maintenance and debugging
* Better consistency across modules

---

# 🔧 Topbar Component Refactor

## `includes/topbar.php`

The shared topbar component was cleaned up to remove legacy issues and improve routing behavior.

### Changes

* Removed old hardcoded PDO connection block
* Removed circular include:

```php
require_once 'topbar.php';
```

* Removed hardcoded:

```php
$topbar_page = 'fuel';
```

* `$topbar_page` is now assigned individually by each page before loading topbar
* All references now safely use:

```php
($topbar_page ?? '')
```

* Brand/home link updated from `#` to:

```php
route('home')
```

### Benefits

* Prevents undefined variable warnings
* Eliminates circular include issues
* Makes the topbar reusable across modules
* Cleaner routing behavior

---

# 🖼️ Employee Profile Picture Improvements

The employee image system was fully refactored for uploads, rendering, previews, and fallback handling.

---

## Upload System Updates

### Directory changes

Changed upload directory from:

```text
TWM/tradewellportal/uploads/employee_pics/
```

To:

```text
TWM/uploads/employee_pics/
```

### Database path updates

Images are now stored in the database as:

```text
uploads/employee_pics/filename.ext
```

### Returned URL updates

Returned image URL now resolves to:

```text
/TWM/uploads/employee_pics/filename.ext
```

---

# 🛠️ Image Rendering Fixes

Corrected broken image paths in multiple rendering locations.

## Fixed in

* PHP table row rendering
* JS `populateModal()`
* JS `buildPrintArea()`

## Base path updated from

```text
/TWM/tradewellportal/
```

To:

```text
/TWM/
```

---

# 🛡️ Broken Image Fallback Handling

Improved avatar fallback behavior when employee photos fail to load.

## PHP table rendering

* Added `onerror` fallback to switch into colored initials avatar

## JS `populateModal()`

* Replaced inline `onerror` string handling
* Added safer `createElement()` fallback handling

## JS `buildPrintArea()`

* Added fallback initials div for missing images

---

# 🔍 Fullscreen Photo Preview Modal

Added a dedicated fullscreen employee photo preview experience.

## Features

* Added `#photoViewModal`
* Clicking employee avatar opens dark overlay preview
* Preview displays:

  * Current employee photo
  * Employee name
  * “Change Photo” button (admin only)
* “Change Photo” button automatically triggers hidden file input
* Clicking close dismisses overlay

---

# 🗄️ Uniform Inventory Migration

Uniform Inventory tables and records were migrated from local `PIRS` database into production `TradewellDatabase`.

---

# 📦 Tables Created

Created using:

```text
UNIFORM_MIGRATION_FULL.sql
```

## Tables

| Table                   |
| ----------------------- |
| `po_categories`         |
| `purchase_orders`       |
| `po_items`              |
| `UniformStock`          |
| `UniformRequests`       |
| `UniformReleased`       |
| `UniformPO`             |
| `UniformPOItems`        |
| `UniformReceiving`      |
| `UniformReceivingItems` |
| `UniformReturns`        |

---

# 👁️ Views Recreated

| View              |
| ----------------- |
| `vw_UniformStock` |

---

# 📊 Migrated Data Summary

| Table                   | Rows Migrated |
| ----------------------- | ------------- |
| `UniformStock`          | 16            |
| `UniformRequests`       | 20            |
| `UniformReleased`       | 60            |
| `UniformPO`             | 1             |
| `UniformPOItems`        | 10            |
| `UniformReceiving`      | 2             |
| `UniformReceivingItems` | 16            |
| `UniformReturns`        | 2             |

---

# 🚀 Production Deployment Notes

When deploying to production:

## Only update the environment block inside

```text
test_sqlsrv.php
```

This allows the entire system to switch environments without modifying individual pages or modules.

---

# ✅ Overall Result

This update significantly improves:

* Maintainability
* Deployment simplicity
* Shared DB architecture
* RBAC consistency
* Employee media handling
* Production readiness
* Long-term scalability

---
