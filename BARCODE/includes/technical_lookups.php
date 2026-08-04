<?php
/**
 * technical_lookups.php
 * Shared helpers for populating the Supplier and Category dropdowns
 * on technical/items.php. Requires an already-open sqlsrv connection
 * (see includes/config.php).
 */

// The auto-generated asset barcode series: TWM888-01, TWM888-02, ...
// Kept as a constant so get_next_barcode.php (live preview) and
// save_item.php (authoritative, at insert time) always agree on the
// same prefix.
define('TECH_ASSET_BARCODE_PREFIX', 'TWM888-');

/**
 * Returns the next barcode in the TWM888-XX series (e.g. "TWM888-06"),
 * based on the highest existing number under that prefix. Zero-padded
 * to 2 digits up to 99, then grows naturally (TWM888-100, -101, ...).
 *
 * This is a plain read for UI preview purposes only -- it is NOT
 * concurrency-safe on its own. save_item.php recomputes this same
 * value itself, inside a locked transaction, right before insert, so
 * two people opening the form at the same moment can never be handed
 * the same barcode.
 */
function getNextTechnicalAssetBarcode($conn) {
    $prefixLen = strlen(TECH_ASSET_BARCODE_PREFIX);
    $sql = "SELECT MAX(TRY_CAST(SUBSTRING(Barcode, ?, 50) AS INT)) AS MaxNum
            FROM TBL_Technical_Items
            WHERE Barcode LIKE ?";
    $stmt = sqlsrv_query($conn, $sql, [$prefixLen + 1, TECH_ASSET_BARCODE_PREFIX . '%']);

    $maxNum = 0;
    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $maxNum = (int)($row['MaxNum'] ?? 0);
    }

    $next = $maxNum + 1;
    return TECH_ASSET_BARCODE_PREFIX . str_pad($next, 2, '0', STR_PAD_LEFT);
}

function getActiveTechnicalSuppliers($conn) {
    $sql = "SELECT ID, SupplierCode, SupplierName
            FROM TBL_Technical_Supplier
            WHERE Status = 1
            ORDER BY SupplierName ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    $suppliers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $suppliers[] = $row;
    }

    return $suppliers;
}

function getActiveTechnicalCategories($conn) {
    $sql = "SELECT ID, CategoryCode, CategoryName
            FROM TBL_Technical_Category
            WHERE Status = 1
            ORDER BY CategoryName ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    $categories = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $categories[] = $row;
    }

    return $categories;
}

function getItemCatalog($conn) {
    // One row per distinct item name that's ever been registered,
    // using whichever registration is most recent for its
    // Category/Brand/Model/Cost — this is what lets the PO screen's
    // Item dropdown offer "reorder this" instead of retyping details.
    $sql = "SELECT ItemName, Category, Brand, Model, Cost
            FROM (
                SELECT ItemName, Category, Brand, Model, Cost,
                       ROW_NUMBER() OVER (PARTITION BY ItemName ORDER BY DateTimeInput DESC) AS rn
                FROM TBL_Technical_Items
                WHERE ItemName IS NOT NULL AND ItemName <> ''
            ) t
            WHERE rn = 1
            ORDER BY ItemName ASC";

    $stmt = sqlsrv_query($conn, $sql);

    if ($stmt === false) {
        return [];
    }

    $catalog = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $catalog[] = $row;
    }

    return $catalog;
}