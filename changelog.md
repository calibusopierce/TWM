# LOGISTICS — Fuel Dashboard

Internal fuel monitoring dashboard for tracking refuels, anomalies, consumption, and scheduling across the fleet.

All pages live under `LOGISTICS/` and share a common config in `fuel_shared.php`.

---

## File Overview

| File | Description |
|---|---|
| `fuel_dashboard.php` | Overall summary with Low→High and High→Low ranking tabs |
| `Fuel-30Day.php` | 30-Day Monitor — refuel coverage per truck |
| `Fuel-Area.php` | Area Summary — fuel grouped by delivery area |
| `Fuel-Comparison.php` | Fuel Comparison — benchmarks trucks against fleet peers |
| `Fuel-Anomaly.php` | Anomaly Flags — auto-detects suspicious refuel transactions |
| `Fuel-Checklist.php` | Monthly Checklist — daily schedule vs. actual refuel status |
| `Fuel-Consumption.php` | Fuel Consumption — monthly/weekly breakdown per truck |
| `Fuel-Report.php` | Usage Report — raw transaction log |
| `fuel_shared.php` | Shared filters, queries, tab nav, filter bar, and JS helpers |
| `fuel_dashboard-Current.php` | Legacy/reference snapshot |
| `graphs.php` | Analytics graphs — consumption, trend, area, top 10, status |
| `index.php` | Entry redirect |

---

## What's New

### April 2026

#### ✨ Fuel-Consumption.php — Monthly breakdown tab

A new dedicated page (`fuel_monthly` tab) has been added for tracking fuel consumption per truck broken down by week.

- Weeks are computed dynamically: Week 1 = days 1–7, Week 2 = 8–14, Week 3 = 15–21, Week 4 = 22–28, Week 5 = days 29–end of month
- Table is grouped by **Department → Vehicle Type** with department subtotals and a grand total row
- Includes a **month/year picker** with prev/next navigation, independent of the main date-range filter
- Columns: Plate #, Department, Vehicle Type, Total Refuels, Total Liters, Total Amount, and per-week Liters + Amount
- CSV, Excel, and Print exports reflect the full filtered dataset

---

#### ✨ Fuel-Checklist.php — New columns and pagination

- **Fuel Time** — new column showing the exact timestamp a refuel was recorded (blank if not refueled)
- **Driver** — new column showing the driver who actually requested fuel (`f.Requested`), separate from the scheduled driver
- **Sched. Driver** now pulled from `teamschedule` (matched by plate + date + `Position LIKE '%DRIVER%'`), not the fuel record
- Results are now **paginated** — 20 rows per page with Previous/Next controls and a page counter
- Query capped at **TOP 500 rows** to prevent overloading
- Summary line above the table now shows total **Refueled** and **Not Refueled** counts for the filtered result
- Search box placeholder updated to reflect "driver" as a searchable field

---

#### 🔧 fuel_shared.php — Filter and routing updates

- **Driver filter** (`?driver=`) — text input, partial match against `f.Requested` and `teamschedule.Employee_Name`
- **Area filter** (`?area=`) — text input, partial match against `f.Area` and `ts.Area`, applied across all tabs simultaneously
- Both new filters render as **active filter chips** in the filter bar when set
- `pageUrl()` helper now carries `driver` and `area` parameters through pagination links
- `renderTabNav()` now accepts `$fcYear` and `$fcMonth` for the Fuel Consumption tab link
- `fuel_monthly` tab route added to the navigation array, pointing to `Fuel-Consumption.php`
- `fc_year` and `fc_month` URL params (`?fc_year=`, `?fc_month=`) parsed and validated in shared init

---

> For questions or issues, open an issue or ping the dev team.