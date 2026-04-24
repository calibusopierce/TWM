-- ============================================================
--  TWM Purchase Order Module - SQL Server Table Creation
--  Server:   PIERCE
--  Database: TradewellDatabase
--  Place this in: TWM/TABLES/po_tables.sql
--  Run once to set up the PO schema
-- ============================================================

USE [TradewellDatabase];
GO

-- PO Categories (Computers, Vehicles, Printers, etc.)
CREATE TABLE po_categories (
    category_id   INT IDENTITY(1,1) PRIMARY KEY,
    category_name NVARCHAR(100) NOT NULL,
    description   NVARCHAR(255),
    created_at    DATETIME DEFAULT GETDATE()
);

-- Seed default categories
INSERT INTO po_categories (category_name, description) VALUES
('Computer / Devices', 'Laptops, desktops, peripherals, IT equipment'),
('Vehicles',                   'Motorcycles, cars, trucks, company vehicles'),
('Printers',                   'Printers, scanners, copiers, fax machines'),
('Office Supplies',            'Stationery, furniture, office consumables'),
('Others',                     'Miscellaneous items not covered above');

-- Main Purchase Orders table
CREATE TABLE purchase_orders (
    po_id           INT IDENTITY(1,1) PRIMARY KEY,
    po_number       NVARCHAR(20) NOT NULL UNIQUE,   -- e.g. PO-2026-0001
    category_id     INT NOT NULL REFERENCES po_categories(category_id),
    po_date         DATE NOT NULL DEFAULT CAST(GETDATE() AS DATE),

    -- Vendor
    vendor_company  NVARCHAR(150) NOT NULL,
    vendor_contact  NVARCHAR(100),
    vendor_address  NVARCHAR(255),
    vendor_phone    NVARCHAR(50),

    -- Ship To
    ship_to_name    NVARCHAR(100) NOT NULL,
    ship_to_company NVARCHAR(150),
    ship_to_address NVARCHAR(255),
    ship_to_phone   NVARCHAR(50),

    -- Totals
    subtotal        DECIMAL(18,2) DEFAULT 0,
    tax_amount      DECIMAL(18,2) DEFAULT 0,
    shipping_amount DECIMAL(18,2) DEFAULT 0,
    other_amount    DECIMAL(18,2) DEFAULT 0,
    total_amount    DECIMAL(18,2) DEFAULT 0,

    -- Status & Signatories
    status          NVARCHAR(20) DEFAULT 'Draft'  -- Draft, Approved, Cancelled
                    CHECK (status IN ('Draft','Approved','Cancelled')),
    prepared_by     NVARCHAR(100),
    prepared_title  NVARCHAR(100),
    approved_by     NVARCHAR(100),
    approved_title  NVARCHAR(100),

    -- Audit
    created_by      INT,            -- maps to your users table
    created_at      DATETIME DEFAULT GETDATE(),
    updated_at      DATETIME DEFAULT GETDATE()
);

-- Line items for each PO
CREATE TABLE po_items (
    item_id         INT IDENTITY(1,1) PRIMARY KEY,
    po_id           INT NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    line_no         INT NOT NULL,           -- 1,2,3…
    description     NVARCHAR(255) NOT NULL,
    cash_price      DECIMAL(18,2) DEFAULT 0,
    percent_price   DECIMAL(18,2) DEFAULT 0,  -- discounted / deal price
    quantity        INT DEFAULT 1,
    total_price     DECIMAL(18,2) DEFAULT 0
);

-- Index for fast lookups by category and date
CREATE INDEX IX_po_category  ON purchase_orders(category_id);
CREATE INDEX IX_po_date      ON purchase_orders(po_date);
CREATE INDEX IX_po_status    ON purchase_orders(status);
CREATE INDEX IX_poitems_poid ON po_items(po_id);