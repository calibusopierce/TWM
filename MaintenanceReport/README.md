# Tradewell Expense Ledger (PHP version)

Uses your existing `config.php` / `sqlsrv` connection. No Node.js needed.

## Files
```
config.php          <- your existing DB connection (unchanged)
api/expenses.php     <- combines View_Maintenance_Service + View_Maintenance_Parts, returns JSON
api/health.php        <- simple "is the DB reachable" check
index.html            <- the dashboard page (view + filter + sort + export)
```

## Setup
1. Copy this whole folder into your PHP site's folder, e.g.:
   - XAMPP: `C:\xampp\htdocs\tradewell-dashboard\`
   - IIS: your site's root or a subfolder
2. Confirm `config.php` still has the right `DB_SERVER` value (currently `EYRON`) — it's the same file you already had, untouched.
3. Make sure the `sqlsrv` and `pdo_sqlsrv` PHP extensions are enabled (you already need these for your existing system, so this should already be set up).
4. Confirm the SQL login has `SELECT` permission on `View_Maintenance_Service` and `View_Maintenance_Parts`.

## Running it
Just visit it in a browser, same as the rest of your PHP system, e.g.:
```
http://localhost/tradewell-dashboard/index.html
```
or wherever you placed the folder.

If it says "connection issue" or won't load rows:
- Check `config.php` has the right server name/credentials
- Confirm the account PHP runs as (or the SQL login) has `SELECT` permission on `View_Maintenance_Service` and `View_Maintenance_Parts`
- Look at the browser console / the on-page error box — it will show the raw `sqlsrv` error if the query itself fails

## What it does
- One combined table of all Service + Parts expenses, each row tagged Service or Parts
- Search box (searches every column), per-column filter boxes, click headers to sort
- "Columns" button to show/hide which columns are visible
- "Export CSV" to download the current filtered view
- Read-only — it only runs `SELECT`, never modifies data, so it's safe to hand out for browsing
