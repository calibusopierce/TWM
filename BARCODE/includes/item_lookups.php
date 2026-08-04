<?php
/**
 * item_lookups.php
 * Shared helpers for populating supplier/brand/category dropdowns
 * that feed Tbl_Item_Products. Requires an already-open sqlsrv
 * connection (see includes/config.php).
 */

function getActiveSuppliers($conn) {
    $sql = "SELECT ID, SupplierCode, SupplierName
            FROM TBL_Item_Supplier
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

function getBrandsForSupplier($conn, $supplierCode) {
    $sql = "SELECT BrandCode, BrandName
            FROM TBL_Item_Brand
            WHERE SupplierCode = ? AND Status = 1
            ORDER BY BrandName ASC";

    $stmt = sqlsrv_query($conn, $sql, [$supplierCode]);

    if ($stmt === false) {
        return [];
    }

    $brands = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $brands[] = $row;
    }

    return $brands;
}

function getCategoriesForSupplier($conn, $supplierCode) {
    $sql = "SELECT CategoryCode, CategoryName
            FROM TBL_Item_Category
            WHERE SupplierCode = ? AND Status = 1
            ORDER BY CategoryName ASC";

    $stmt = sqlsrv_query($conn, $sql, [$supplierCode]);

    if ($stmt === false) {
        return [];
    }

    $categories = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $categories[] = $row;
    }

    return $categories;
}
