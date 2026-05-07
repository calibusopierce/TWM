# 📦 TWM — Tradewell Management System

TWM (Tradewell Management System) is an internal enterprise web application developed to streamline and centralize operations across multiple departments including Human Resources, Logistics, Sales, Uniform Inventory, and User Access Control.

The system is built using PHP and Microsoft SQL Server and is designed for real-world business workflows such as applicant processing, trucking coordination, fuel monitoring, inventory tracking, purchasing, and offline mobile sales synchronization.

---

# 🚀 System Overview

| Category            | Technology                      |
| ------------------- | ------------------------------- |
| 🔧 Backend          | PHP                             |
| 🗄️ Database        | Microsoft SQL Server (`sqlsrv`) |
| 🌐 Environment      | XAMPP / Apache                  |
| 🔐 Authentication   | Session-based Auth + RBAC       |
| 📦 Architecture     | Modular PHP System              |

---

# 🧩 Core Modules

## 👥 Human Resources (HR)

Human resource management and applicant processing tools.

### Features

* Job application management
* Applicant status tracking
* Interview scheduling
* Applicant evaluation workflow
* Employee management
* Employee profile picture uploads
* Resume and attachment handling
* Fuel Dashboard Monitoring
* Fuel Graph Visualization
* Uniform Inventory
* RBAC-enabled admin management

### Job Applicant Workflow

```Job Application
Pending → Evaluating → For Interview → For Final Interview → Hired / Rejected
```

---

# 🚚 Logistics Dashboard

Fleet and delivery management system for monitoring vehicle operations and fuel usage.

### Features

* Vehicle Consumption Summary
* Fuel monitoring dashboard
* Fuel anomaly detection
* Area-based fuel reporting
* Monthly fuel checklist system
* Delivery scheduling support
* Vehicle consumption monitoring
* Graph analytics and reports

### Logistics Dashboard Pages

| Page                   | Description                              |
| ---------------------- | ---------------------------------------- |
| `fuel_dashboard.php`   | Fleet fuel summary dashboard             |
| `Fuel-30Day.php`       | 30-day monitoring dashboard              |
| `Fuel-Anomaly.php`     | Suspicious fuel transaction detection    |
| `Fuel-Area.php`        | Area fuel consumption summary            |
| `Fuel-Checklist.php`   | Refuel schedule monitoring               |
| `Fuel-Comparison.php`  | Truck fuel efficiency comparison         |
| `Fuel-Consumption.php` | Weekly/monthly fuel consumption tracking |
| `Fuel-Report.php`      | Raw fuel transaction reporting           |
| `graphs.php`           | Charts and analytics                     |

---


# 📦 Uniform Inventory System

Inventory and purchasing system for company uniform management.

### Features

* Uniform stock tracking
* Receiving transactions
* Purchase order report
* Release of uniforms
* Return of uniforms
* Stock movement history
* Real-time stock balance monitoring
* Printable reports

### Database Tables

| Table                   |
| ----------------------- |
| `UniformStock`          |
| `UniformRequests`       |
| `UniformReleased`       |
| `UniformPO`             |
| `UniformPOItems`        |
| `UniformReceiving`      |
| `UniformReceivingItems` |
| `UniformReturns`        |

---

# ⚙️ Shared System Features

## 🔐 RBAC (Role-Based Access Control)

The system includes centralized RBAC handling.

### Features

* Shared PDO connection architecture
* Session-based authentication
* Dynamic page permissions
* Shared navigation and topbar handling
* Centralized auth checks

---

# 🖼️ Employee Profile Picture System

### Features

* Employee avatar uploads
* Fullscreen photo preview modal
* Automatic initials fallback
* Dynamic image rendering
* Admin-only photo change permissions

### Upload Directory

```
/uploads/
/uploads/employee_pics/
```

---

# 🗄️ Database Architecture

## Shared Connection System

Database connections are centralized using:

```Sql Server Conn
test_sqlsrv.php
```

### Improvements

* Shared `$pdo` and `$conn`
* Production/local environment switching
* Reduced duplicate SQL connection logic
* Easier deployment configuration

---

# ⚙️ Getting Started

## 1️⃣ Install Environment

Install:

* XAMPP
* Microsoft SQL Server
* SQLSRV PHP Driver

---

# 📂 Project Placement

Place the project inside:

```text
C:/xampp/htdocs/TWM
```

---

# ▶️ Start Server

Open XAMPP Control Panel and start:

* Apache

---

# 🌐 Open in Browser

```text
http://localhost/TWM
```

---

# 🗄️ Database Setup

> ⚠️ This project uses Microsoft SQL Server (`sqlsrv`) — NOT MySQL.

## Setup Steps

1. Open SQL Server Management Studio (SSMS)
2. Create or restore the database
3. Locate SQL files inside:

```text
/TABLES/
```

4. Run required `.sql` files
5. Configure:

```text
test_sqlsrv.php
```

for local or production environments

---

# 🧑‍💻 Development Workflow

```bash
cd /c/xampp/htdocs/TWM

git add .
git commit -m "Update Description"
git push
```

---

# 📁 Project Structure

```text
TWM/
│
├── assets/                 # CSS, JS, images
├── uploads/                # Uploaded files
├── TABLES/                 # SQL schema and migration files
├── includes/               # Shared includes and components
├── HR/                     # HR module
├── LOGISTICS/              # Logistics dashboard
├── SALES/                  # Sales module
├── RBAC/                   # Role-based access control
├── Android/                # Android-related resources
├── test_sqlsrv.php         # Shared database connection
└── *.php                   # Core application pages
```

---

# 🚀 Recent Improvements

## Latest Refactors

### Database

* Centralized PDO architecture
* Shared SQL connection handling
* Production-ready connection switching

### RBAC

* Removed hardcoded PDO connections
* Shared auth handling
* Cleaner permission management

### Employee Module

* Fullscreen avatar preview
* Better image fallback handling
* Improved upload directory structure

### Uniform Inventory

* Production database migration completed
* Inventory tables standardized
* Stock views recreated

---

# 📌 Development Notes

## Do NOT Commit

* Local environment configs
* Temporary files
* Uploaded user files
* Backup SQL dumps
* Sensitive credentials

---

# 🏢 Intended Usage

This system is designed for:

* Internal company operations
* Multi-user environments
* Real-world enterprise workflows
* Logistics and inventory management
* HR and applicant processing

---

# 🔮 Future Improvements

Planned future enhancements include:

* Advanced RBAC permissions
* Dashboard analytics improvements
* API integrations
* Mobile app enhancements
* UI/UX modernization
* Real-time notifications
* Centralized reporting dashboards
* Multi-warehouse inventory support

---

# 👨‍💻 Author

## Pierce Crisver Calibuso

Tradewell System Developer

---

# 📄 License

Private / Internal Company Use Only
