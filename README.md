# 🚀 TWM — Technology Workplace Manager

> **Empowering Modern Business Operations.**

TWM (Technology Workplace Manager) is a modular enterprise management platform designed to streamline, automate, and centralize business operations across organizations of any size.

Built with **PHP** and **Microsoft SQL Server**, TWM provides a scalable foundation for Human Resources, Attendance, Payroll, Logistics, Inventory, Finance, Sales, and administrative workflows through a unified web-based system.

Designed with extensibility in mind, each module operates independently while sharing a centralized authentication, permissions, and database architecture.

---

# ✨ Key Features

- 👥 Human Resource Management
- ⏰ Attendance Management
- 📅 Leave Management
- 💰 Payroll & Loans
- 🚚 Logistics & Fleet Monitoring
- ⛽ Fuel Monitoring & Analytics
- 📦 Inventory Management
- 🛒 Purchasing & Receiving
- 📈 Sales & Business Operations
- 🔐 Role-Based Access Control (RBAC)
- 📊 Executive Dashboards & Reports
- 📱 Offline Mobile Synchronization
- 🏢 Multi-department Enterprise Architecture

---

# 🏗 Technology Stack

| Category | Technology |
|----------|------------|
| Backend | PHP |
| Database | Microsoft SQL Server (`sqlsrv`) |
| Frontend | HTML, CSS, JavaScript, Bootstrap |
| Server | Apache (XAMPP) |
| Authentication | Session-based Authentication |
| Authorization | Role-Based Access Control (RBAC) |
| Architecture | Modular Enterprise Platform |

---

# 📦 Platform Modules

## 👥 Human Resources

Designed to simplify workforce management from recruitment to employee lifecycle.

### Features

- Recruitment & Applicant Tracking
- Interview Management
- Employee Records
- Employee Profiles
- Document Management
- Digital Personnel Files
- Employee Photo Management
- Organizational Administration

### Recruitment Workflow

```text
Pending
   ↓
Evaluating
   ↓
For Interview
   ↓
Final Interview
   ↓
Hired / Rejected
```

---

## ⏰ Attendance Management

- Daily Time Records
- Attendance Monitoring
- Attendance Dashboard
- Overtime Tracking
- Leave Integration
- Attendance Analytics

---

## 📅 Leave Management

- Leave Applications
- Supervisor Approval
- HR Approval
- Leave History
- Leave Balance Monitoring
- Attendance Synchronization

---

## 💰 Payroll & Finance

- Payroll Management
- Employee Loans
- Salary Deductions
- Remittance Monitoring
- Accounts Receivable
- Financial Reporting

---

## 🚚 Logistics

Monitor fleet operations, fuel efficiency, and delivery performance.

### Features

- Vehicle Monitoring
- Fuel Consumption Analytics
- Fuel Anomaly Detection
- Area Consumption Reports
- Fleet Performance
- Delivery Monitoring
- Fuel Checklists
- Executive Reports

---

## 📦 Inventory Management

Manage company assets and inventory with complete stock visibility.

### Features

- Stock Monitoring
- Purchase Orders
- Receiving Transactions
- Stock Releases
- Returns Management
- Inventory Movement History
- Real-Time Inventory Balances
- Printable Reports

---

## 🔐 Role-Based Access Control

Enterprise-grade access management across all modules.

### Features

- Role-Based Permissions
- Dynamic Navigation
- Page-Level Security
- Module Authorization
- Session Management
- Shared Authentication

---

# 🏛 Architecture

TWM follows a centralized modular architecture.

```text
                Users
                  │
                  ▼
      Authentication / RBAC
                  │
      ─────────────────────────
      │      │      │      │
      ▼      ▼      ▼      ▼
     HR  Attendance Inventory Logistics
      │      │      │      │
      ─────────────────────────
              Database
      Microsoft SQL Server
```

---

# 🗄 Database

The platform utilizes a centralized SQL Server connection shared across all modules.

### Connection

```text
test_sqlsrv.php
```

### Benefits

- Shared PDO Connection
- Environment Switching
- Reduced Code Duplication
- Easier Deployment
- Improved Maintainability

---

# ⚙ Installation
## Requirements

- XAMPP
- Microsoft SQL Server
- SQLSRV PHP Driver
- PHP 8+

---

## Project Location

```text
C:\xampp\htdocs\TWM
```

---

## Database Setup

1. Restore or create the SQL Server database.
2. Execute SQL scripts inside:
```text
TABLES/
```
3. Configure:
```text
test_sqlsrv.php
```
4. Start Apache.
5. Open
```text
http://localhost/TWM
```

---

# 📂 Project Structure

```text
TWM/
│
├── assets/
├── includes/
├── uploads/
├── Android/
├── TABLES/
│
├── HR/
├── ATTENDANCE/
├── PAYROLL/
├── LEAVE/
├── LOGISTICS/
├── INVENTORY/
├── SALES/
├── FINANCE/
├── RBAC/
│
├── test_sqlsrv.php
└── index.php
```

---

# 🚀 Platform Highlights

- Modular Architecture
- Enterprise RBAC
- SQL Server Integration
- Responsive Dashboard
- Shared Components
- Dynamic Navigation
- Analytics & Reporting
- Production Ready
- Scalable Design

---

# 🔮 Roadmap

- REST API
- Mobile Application
- Real-Time Notifications
- Business Intelligence Dashboards
- Multi-Branch Support
- Multi-Tenant Deployment
- Audit Logs
- Workflow Automation
- AI-powered Insights

---

# 🔒 License

**Private Software**

Technology Workplace Manager (TWM) is proprietary software intended for licensed organizational and enterprise use.
