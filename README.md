# TWM

This is a PHP web application project for an internal business system that includes HR, sales, logistics, forms, and other modules.

## Overview

- PHP-based application
- Designed to run on XAMPP / Apache with MySQL
- Contains HR, LOGISTICS, SALES, and forms functionality
- Uses client-side assets in `assets/` and application pages in the project root

## Getting started

1. Install XAMPP if you do not already have it.
2. Copy the project folder to `c:/xampp/htdocs/TWM` or use the existing location.
3. Start Apache and MySQL from the XAMPP control panel.
4. Open your browser and visit:
   `http://localhost/TWM`

## Database setup

There is no single installer script in this repo, but SQL export files are available in `TABLES/`.

1. Open phpMyAdmin or another MySQL tool.
2. Import the needed SQL files from `TABLES/`.
3. Update any database connection settings in the project if required.

## Recommended workflow

```bash
cd /c/xampp/htdocs/TWM
git add .
git commit -m "Describe your changes"
git push
```

## Notes

- Keep local uploads and environment-specific files out of version control.
- If you add a `.env` or local config file, do not commit it.
- Use GitHub for repo history and collaboration.

## Useful files

- `Readme.txt` — project notes and feature ideas
- `TABLES/` — SQL files for database schema and application data
- `uploads/` — user-uploaded files and documents
