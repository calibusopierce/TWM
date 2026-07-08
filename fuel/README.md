# Tradewell Fuel Monitoring System
## Setup Instructions

### Requirements
- PHP 7.4+ with SQL Server driver (sqlsrv)
- Microsoft SQL Server (EYRON instance)
- Web server (Apache/IIS/XAMPP)

### Installation Steps

1. **Copy files** to your web server root (e.g., `C:/xampp/htdocs/fuel-monitoring/`)

2. **Install PHP SQL Server driver** if not yet installed:
   - Download from: https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server
   - Add to php.ini:
     ```
     extension=php_sqlsrv_74_ts_x64.dll
     extension=php_pdo_sqlsrv_74_ts_x64.dll
     ```

3. **Update database credentials** in `includes/db.php`:
   ```php
   define('DB_SERVER', 'EYRON');           // Your SQL Server name
   define('DB_DATABASE', 'TradewellDatabase');
   define('DB_USERNAME', '');            // Your SQL login
   define('DB_PASSWORD', ''); // Your password
   ```

4. **Access the system** at: `http://localhost/fuel-monitoring/`

---

### File Structure
```
fuel-monitoring/
├── index.php              ← Main dashboard (open this)
├── includes/
│   ├── db.php             ← Database connection config
│   └── functions.php      ← All database query functions
├── pages/
│   └── api.php            ← AJAX API endpoint
└── README.md
```

### Features
- **Dashboard** – Daily fuel summary by department (MONDE, CENTURY, UFC, MULTILINES)
- **Fuel Log** – Full history with filters (department, plate, date range, area)
- **Truck Schedules** – View daily routing tied to TruckSchedule table
- **Monthly Report** – Per-truck fuel consumption summary
- **Add Fuel Record** – Modal form to log new refills

### Database Tables Used
| Table | Purpose |
|-------|---------|
| `Tbl_fuel` | Fuel refill records |
| `Vehicle` | Truck/vehicle master list |
| `TruckSchedule` | Daily routing schedules |
| `Schedule` | Employee schedule reference |
