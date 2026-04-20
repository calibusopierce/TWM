📦 TWM – Tradewell Management System

TWM (Tradewell Management System) is an internal business web application designed to manage and streamline operations across multiple departments including Careers, Logistics Dasboard, and Uniform Inventory.

Built using PHP and SQL Server, the system supports real-world workflows such as job application processing, vehicle monitoring, and inventory tracking.

🚀 System Overview
🔧 Backend: PHP
🗄️ Database: Microsoft SQL Server (sqlsrv)
🌐 Environment: XAMPP / Apache
📱 Extension: Android mobile app (offline sales order system with sync)

🧩 Core Modules

👥 Human Resources (HR)
   Job application system
   Interview scheduling
   Applicant status tracking (Pending → Hired / Rejected)
   Employee management

🧾 Sales
   Sales order processing
   Dynamic pricing and discounts
   Mobile integration (offline-first Android app)

🚚 Logistics
   Trucking and delivery coordination
   Fuel monitoring dashboard
   Checklist and tracking system

📦 Inventory
   Real-time stock balance calculation
   Receiving (+)
   Release (-)
   Transfers
   Adjustments
   Printing


⚙️ Getting Started
1. Setup Environment
   Install XAMPP

   Place project in:

   C:/xampp/htdocs/TWM
   2. Start Server
   Run Apache from XAMPP Control Panel
   3. Open in Browser
   http://localhost/TWM
   🗄️ Database Setup

⚠️ Note: This project uses SQL Server (sqlsrv) — not MySQL
   Open SQL Server Management Studio (SSMS) or equivalent
   Locate SQL files inside:
   /TABLES/
   Import required .sql files
   Configure database connection in your PHP files if needed

🧑‍💻 Development Workflow
   cd /c/xampp/htdocs/TWM
   git add .
   git commit -m "Update Description"
   git push

📁 Project Structure (Simplified)
TWM/
   │
   ├── assets/        # CSS, JS, images
   ├── TABLES/        # SQL files (database schema/data)
   ├── uploads/       # User-uploaded files
   ├── HR/            # HR module
   ├── logistics/     # Logistics module
   ├── sales/         # Sales module
   ├── includes/      # Shared components (nav, auth, etc.)
   └── *.php          # Core application pages


📌 Notes
Do NOT commit:
   Local config files
   .env files
   Uploaded files (/uploads/)
   This system is designed for:
   Internal company use
   Multi-user environment
   Real business workflows (not just academic demo)

🔮 Future Improvements
   Role-based access control enhancements
   Dashboard analytics improvements
   API integration (mobile + external systems)
   UI/UX modernization (Shopee-style components 👀)

👨‍💻 Author
Pierce Crisver Calibuso
Tradewell System Developer

📄 License
Private / Internal Use Only
