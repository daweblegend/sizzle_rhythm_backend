<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/FileUploader.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify vendor & return vendor_id
// ========================
function verifyInventoryVendor() {
    global $conn;

    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return null;

    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);

    $stmt = mysqli_prepare($conn, "SELECT u.id AS user_id, u.role, v.id AS vendor_id FROM users u LEFT JOIN vendors v ON v.user_id = u.id WHERE u.id = ? AND u.is_active = 1");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row) {
        ResponseHandler::error('User not found or account is inactive.', null, 404);
        return null;
    }
    if ($row['role'] !== 'vendor') {
        ResponseHandler::error('Access denied. Vendor privileges required.', null, 403);
        return null;
    }
    if (!$row['vendor_id']) {
        ResponseHandler::error('Vendor store profile not found. Please create your store profile first.', null, 404);
        return null;
    }

    return (int)$row['vendor_id'];
}

// ========================
// HELPER: Format inventory row
// ========================
function formatInventoryItem($item) {
    $item['quantity']        = (float)$item['quantity'];
    $item['low_stock_level'] = (float)$item['low_stock_level'];
    $item['cost_price']      = (float)$item['cost_price'];
    $item['selling_price']   = $item['selling_price'] !== null ? (float)$item['selling_price'] : null;
    $item['is_perishable']   = (bool)$item['is_perishable'];
    $item['is_active']       = (bool)$item['is_active'];
    $item['is_low_stock']    = $item['quantity'] <= $item['low_stock_level'];
    return $item;
}


// =========================================
// ADD INVENTORY ITEM
// =========================================
function addInventoryItem() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    // Support both JSON body and multipart/form-data (when image is included)
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $body = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);

    if (empty($body['name'])) {
        ResponseHandler::error('Item name is required.', null, 400);
        return;
    }

    $uuid        = Uuid::uuid4()->toString();
    $name        = UtilHandler::sanitizeInput($conn, $body['name']);
    $sku         = isset($body['sku']) ? UtilHandler::sanitizeInput($conn, $body['sku']) : null;
    $description = isset($body['description']) ? UtilHandler::sanitizeInput($conn, $body['description']) : null;
    $unit        = isset($body['unit']) ? UtilHandler::sanitizeInput($conn, $body['unit']) : 'pcs';
    $quantity    = isset($body['quantity']) ? (float)$body['quantity'] : 0;
    $lowStock    = isset($body['low_stock_level']) ? (float)$body['low_stock_level'] : 0;
    $costPrice   = isset($body['cost_price']) ? (float)$body['cost_price'] : 0;
    $sellPrice   = isset($body['selling_price']) ? (float)$body['selling_price'] : null;
    $isPerishable = !empty($body['is_perishable']) ? 1 : 0;
    $expiryDate  = isset($body['expiry_date']) ? UtilHandler::sanitizeInput($conn, $body['expiry_date']) : null;
    $supplier    = isset($body['supplier']) ? UtilHandler::sanitizeInput($conn, $body['supplier']) : null;
    $notes       = isset($body['notes']) ? UtilHandler::sanitizeInput($conn, $body['notes']) : null;
    $categoryId  = !empty($body['category_id']) ? (int)$body['category_id'] : null;

    // Validate category belongs to this vendor (if provided)
    if ($categoryId) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ? AND (type = 'inventory' OR type = 'both')");
        mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            ResponseHandler::error('Invalid category. Category must belong to your store and be usable for inventory.', null, 400);
            return;
        }
    }

    // Check duplicate SKU for this vendor
    if ($sku) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_inventory WHERE vendor_id = ? AND sku = ?");
        mysqli_stmt_bind_param($stmt, "is", $vendorId, $sku);
        mysqli_stmt_execute($stmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            ResponseHandler::error('An item with this SKU already exists.', null, 409);
            return;
        }
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO vendor_inventory (uuid, vendor_id, category_id, name, sku, description, unit, quantity, low_stock_level, cost_price, selling_price, is_perishable, expiry_date, supplier, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "siissssddddisss",
        $uuid, $vendorId, $categoryId, $name, $sku, $description, $unit,
        $quantity, $lowStock, $costPrice, $sellPrice,
        $isPerishable, $expiryDate, $supplier, $notes
    );

    if (mysqli_stmt_execute($stmt)) {
        $itemId = mysqli_insert_id($conn);

        // Log initial stock if quantity > 0
        if ($quantity > 0) {
            logStockAdjustment($conn, $itemId, $vendorId, 'restock', $quantity, 0, $quantity, null, 'Initial stock');
        }

        // Handle optional image upload
        if (!empty($_FILES['image'])) {
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            $maxSize      = 2 * 1024 * 1024; // 2 MB
            $uploadResult = FileUploader::upload($_FILES['image'], 'uploads/inventory', 'inv', $allowedMimes, $maxSize);

            if ($uploadResult['success'] && !empty($uploadResult['files'][0])) {
                $imagePath = $uploadResult['files'][0];
                $imgStmt   = mysqli_prepare($conn, "UPDATE vendor_inventory SET image = ? WHERE id = ?");
                mysqli_stmt_bind_param($imgStmt, "si", $imagePath, $itemId);
                mysqli_stmt_execute($imgStmt);
            }
        }

        $item = fetchInventoryItemById($conn, $itemId);
        ResponseHandler::success('Inventory item added successfully.', $item, 201);
    } else {
        ResponseHandler::error('Failed to add inventory item.', null, 500);
    }
}


// =========================================
// LIST INVENTORY ITEMS
// =========================================
function listInventoryItems() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $page         = max(1, (int)($_GET['page'] ?? 1));
    $limit        = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset       = ($page - 1) * $limit;
    $categoryId   = $_GET['category_id'] ?? null;
    $search       = $_GET['search'] ?? null;
    $lowStock     = $_GET['low_stock'] ?? null;
    $outOfStock   = $_GET['out_of_stock'] ?? null;
    $isPerishable = $_GET['is_perishable'] ?? null;
    $expiringSoon = $_GET['expiring_soon'] ?? null;
    $supplier     = $_GET['supplier'] ?? null;
    $hasImage     = $_GET['has_image'] ?? null;
    $unit         = $_GET['unit'] ?? null;
    $isActive     = $_GET['is_active'] ?? null;
    $dateFrom     = $_GET['date_from'] ?? null;
    $dateTo       = $_GET['date_to'] ?? null;
    $sort         = $_GET['sort'] ?? 'name';

    // ---- Build shared WHERE clause (used by list, count, AND analytics) ----
    $where  = "i.vendor_id = ?";
    $params = [$vendorId];
    $types  = "i";

    // Active filter — default to active-only unless explicitly set
    if ($isActive === 'false') {
        $where .= " AND i.is_active = 0";
    } elseif ($isActive === 'all') {
        // no filter — show everything
    } else {
        $where .= " AND i.is_active = 1";
    }

    if ($categoryId) {
        $where   .= " AND i.category_id = ?";
        $params[] = (int)$categoryId;
        $types   .= "i";
    }

    if ($search) {
        $searchTerm = '%' . UtilHandler::sanitizeInput($conn, $search) . '%';
        $where   .= " AND (i.name LIKE ? OR i.sku LIKE ? OR i.description LIKE ? OR i.supplier LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types   .= "ssss";
    }

    if ($lowStock === 'true') {
        $where .= " AND i.quantity <= i.low_stock_level AND i.quantity > 0";
    }

    if ($outOfStock === 'true') {
        $where .= " AND i.quantity <= 0";
    }

    if ($isPerishable === 'true') {
        $where .= " AND i.is_perishable = 1";
    } elseif ($isPerishable === 'false') {
        $where .= " AND i.is_perishable = 0";
    }

    if ($expiringSoon === 'true') {
        $where .= " AND i.is_perishable = 1 AND i.expiry_date IS NOT NULL AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND i.expiry_date >= CURDATE()";
    }

    if ($supplier) {
        $supplierTerm = '%' . UtilHandler::sanitizeInput($conn, $supplier) . '%';
        $where   .= " AND i.supplier LIKE ?";
        $params[] = $supplierTerm;
        $types   .= "s";
    }

    if ($hasImage === 'true') {
        $where .= " AND i.image IS NOT NULL AND i.image != ''";
    } elseif ($hasImage === 'false') {
        $where .= " AND (i.image IS NULL OR i.image = '')";
    }

    if ($unit) {
        $where   .= " AND i.unit = ?";
        $params[] = UtilHandler::sanitizeInput($conn, $unit);
        $types   .= "s";
    }

    if ($dateFrom) {
        $where   .= " AND DATE(i.created_at) >= ?";
        $params[] = $dateFrom;
        $types   .= "s";
    }
    if ($dateTo) {
        $where   .= " AND DATE(i.created_at) <= ?";
        $params[] = $dateTo;
        $types   .= "s";
    }

    // ---- Count ----
    $countQ = "SELECT COUNT(*) AS total FROM vendor_inventory i WHERE {$where}";
    $cStmt  = mysqli_prepare($conn, $countQ);
    mysqli_stmt_bind_param($cStmt, $types, ...$params);
    mysqli_stmt_execute($cStmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($cStmt))['total'];

    // ---- Analytics (same filters, aggregated) ----
    $analyticsQ = "
        SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(i.quantity), 0) AS total_quantity,
            COALESCE(SUM(i.quantity * i.cost_price), 0) AS total_stock_value,
            COALESCE(SUM(CASE WHEN i.selling_price IS NOT NULL THEN i.quantity * i.selling_price ELSE 0 END), 0) AS total_selling_value,
            COALESCE(SUM(CASE WHEN i.selling_price IS NOT NULL THEN i.quantity * (i.selling_price - i.cost_price) ELSE 0 END), 0) AS potential_profit,
            SUM(CASE WHEN i.quantity <= i.low_stock_level AND i.quantity > 0 THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN i.quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
            SUM(CASE WHEN i.quantity > i.low_stock_level THEN 1 ELSE 0 END) AS healthy_stock_count,
            SUM(CASE WHEN i.is_perishable = 1 THEN 1 ELSE 0 END) AS perishable_count,
            SUM(CASE WHEN i.is_perishable = 1 AND i.expiry_date IS NOT NULL AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND i.expiry_date >= CURDATE() THEN 1 ELSE 0 END) AS expiring_soon_count,
            SUM(CASE WHEN i.is_perishable = 1 AND i.expiry_date IS NOT NULL AND i.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
            COALESCE(AVG(i.cost_price), 0) AS avg_cost_price,
            COALESCE(AVG(i.selling_price), 0) AS avg_selling_price,
            COUNT(DISTINCT i.category_id) AS category_count,
            COUNT(DISTINCT i.supplier) AS supplier_count
        FROM vendor_inventory i
        WHERE {$where}
    ";
    $aStmt = mysqli_prepare($conn, $analyticsQ);
    mysqli_stmt_bind_param($aStmt, $types, ...$params);
    mysqli_stmt_execute($aStmt);
    $analytics = mysqli_fetch_assoc(mysqli_stmt_get_result($aStmt));

    // ---- Category breakdown (within current filters) ----
    $catQ = "
        SELECT
            COALESCE(c.name, 'Uncategorised') AS category,
            i.category_id,
            COUNT(*) AS item_count,
            COALESCE(SUM(i.quantity), 0) AS total_quantity,
            COALESCE(SUM(i.quantity * i.cost_price), 0) AS stock_value
        FROM vendor_inventory i
        LEFT JOIN vendor_categories c ON i.category_id = c.id
        WHERE {$where}
        GROUP BY i.category_id
        ORDER BY stock_value DESC
    ";
    $catStmt = mysqli_prepare($conn, $catQ);
    mysqli_stmt_bind_param($catStmt, $types, ...$params);
    mysqli_stmt_execute($catStmt);
    $catResult = mysqli_stmt_get_result($catStmt);
    $catBreakdown = [];
    while ($cRow = mysqli_fetch_assoc($catResult)) {
        $catBreakdown[] = [
            'category'       => $cRow['category'],
            'category_id'    => $cRow['category_id'] !== null ? (int)$cRow['category_id'] : null,
            'item_count'     => (int)$cRow['item_count'],
            'total_quantity' => (float)$cRow['total_quantity'],
            'stock_value'    => (float)$cRow['stock_value'],
        ];
    }

    // ---- Fetch items ----
    $query = "SELECT i.*, c.name AS category_name FROM vendor_inventory i LEFT JOIN vendor_categories c ON i.category_id = c.id WHERE {$where}";

    switch ($sort) {
        case 'quantity_low':    $query .= " ORDER BY i.quantity ASC"; break;
        case 'quantity_high':   $query .= " ORDER BY i.quantity DESC"; break;
        case 'cost_high':       $query .= " ORDER BY i.cost_price DESC"; break;
        case 'cost_low':        $query .= " ORDER BY i.cost_price ASC"; break;
        case 'value_high':      $query .= " ORDER BY (i.quantity * i.cost_price) DESC"; break;
        case 'value_low':       $query .= " ORDER BY (i.quantity * i.cost_price) ASC"; break;
        case 'selling_price':   $query .= " ORDER BY i.selling_price DESC"; break;
        case 'expiry_date':     $query .= " ORDER BY i.expiry_date ASC"; break;
        case 'newest':          $query .= " ORDER BY i.created_at DESC"; break;
        case 'oldest':          $query .= " ORDER BY i.created_at ASC"; break;
        case 'updated':         $query .= " ORDER BY i.updated_at DESC"; break;
        default:                $query .= " ORDER BY i.name ASC"; break;
    }

    $query .= " LIMIT ? OFFSET ?";
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes  = $types . "ii";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = formatInventoryItem($row);
    }

    ResponseHandler::success('Inventory items retrieved successfully.', [
        'analytics' => [
            'total_items'         => (int)$analytics['total_items'],
            'total_quantity'      => (float)$analytics['total_quantity'],
            'total_stock_value'   => round((float)$analytics['total_stock_value'], 2),
            'total_selling_value' => round((float)$analytics['total_selling_value'], 2),
            'potential_profit'    => round((float)$analytics['potential_profit'], 2),
            'stock_health' => [
                'healthy'    => (int)$analytics['healthy_stock_count'],
                'low_stock'  => (int)$analytics['low_stock_count'],
                'out_of_stock' => (int)$analytics['out_of_stock_count'],
            ],
            'perishable_count'    => (int)$analytics['perishable_count'],
            'expiring_soon_count' => (int)$analytics['expiring_soon_count'],
            'expired_count'       => (int)$analytics['expired_count'],
            'avg_cost_price'      => round((float)$analytics['avg_cost_price'], 2),
            'avg_selling_price'   => round((float)$analytics['avg_selling_price'], 2),
            'category_count'      => (int)$analytics['category_count'],
            'supplier_count'      => (int)$analytics['supplier_count'],
            'category_breakdown'  => $catBreakdown,
        ],
        'items' => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit)
        ]
    ]);
}


// =========================================
// GET SINGLE INVENTORY ITEM
// =========================================
function getInventoryItem() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $itemId = $_GET['id'] ?? null;
    if (!$itemId) {
        ResponseHandler::error('Item ID is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT i.*, c.name AS category_name FROM vendor_inventory i LEFT JOIN vendor_categories c ON i.category_id = c.id WHERE i.id = ? AND i.vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$item) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    ResponseHandler::success('Inventory item retrieved.', formatInventoryItem($item));
}


// =========================================
// UPDATE INVENTORY ITEM
// =========================================
function updateInventoryItem() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    $updates = [];
    $params  = [];
    $types   = "";

    $textFields = ['name', 'sku', 'description', 'unit', 'supplier', 'notes', 'expiry_date'];
    foreach ($textFields as $f) {
        if (isset($body[$f])) {
            $updates[] = "$f = ?";
            $params[]  = UtilHandler::sanitizeInput($conn, $body[$f]);
            $types    .= "s";
        }
    }

    $decimalFields = ['low_stock_level', 'cost_price', 'selling_price'];
    foreach ($decimalFields as $f) {
        if (isset($body[$f])) {
            $updates[] = "$f = ?";
            $params[]  = (float)$body[$f];
            $types    .= "d";
        }
    }

    if (isset($body['is_perishable'])) {
        $updates[] = "is_perishable = ?";
        $params[]  = $body['is_perishable'] ? 1 : 0;
        $types    .= "i";
    }

    if (isset($body['is_active'])) {
        $updates[] = "is_active = ?";
        $params[]  = $body['is_active'] ? 1 : 0;
        $types    .= "i";
    }

    if (isset($body['category_id'])) {
        $catId = (int)$body['category_id'];
        if ($catId > 0) {
            $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ? AND (type = 'inventory' OR type = 'both')");
            mysqli_stmt_bind_param($stmt, "ii", $catId, $vendorId);
            mysqli_stmt_execute($stmt);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                ResponseHandler::error('Invalid category for inventory.', null, 400);
                return;
            }
        }
        $updates[] = "category_id = ?";
        $params[]  = $catId > 0 ? $catId : null;
        $types    .= "i";
    }

    if (empty($updates)) {
        ResponseHandler::error('No valid fields to update.', null, 400);
        return;
    }

    $params[] = $itemId;
    $types   .= "i";

    $sql  = "UPDATE vendor_inventory SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (mysqli_stmt_execute($stmt)) {
        $item = fetchInventoryItemById($conn, $itemId);
        ResponseHandler::success('Inventory item updated successfully.', formatInventoryItem($item));
    } else {
        ResponseHandler::error('Failed to update inventory item.', null, 500);
    }
}


// =========================================
// ADJUST STOCK (restock / sale / waste / return / correction)
// =========================================
function adjustStock() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    $itemId = $body['item_id'] ?? null;
    $type   = $body['adjustment_type'] ?? null;
    $qty    = isset($body['quantity']) ? (float)$body['quantity'] : null;

    if (!$itemId || !$type || $qty === null || $qty == 0) {
        ResponseHandler::error('item_id, adjustment_type, and a non-zero quantity are required.', null, 400);
        return;
    }

    $validTypes = ['restock', 'sale', 'waste', 'return', 'correction'];
    if (!in_array($type, $validTypes)) {
        ResponseHandler::error('Invalid adjustment_type. Allowed: ' . implode(', ', $validTypes), null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$item) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    $currentQty = (float)$item['quantity'];

    // For sale/waste the quantity is subtracted
    if (in_array($type, ['sale', 'waste'])) {
        $change = -abs($qty);
    } elseif (in_array($type, ['restock', 'return'])) {
        $change = abs($qty);
    } else {
        // correction: can be positive or negative
        $change = $qty;
    }

    $newQty = $currentQty + $change;
    if ($newQty < 0) {
        ResponseHandler::error("Insufficient stock. Current: {$currentQty}, attempted change: {$change}", null, 400);
        return;
    }

    // Update quantity
    $stmt = mysqli_prepare($conn, "UPDATE vendor_inventory SET quantity = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "di", $newQty, $itemId);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to adjust stock.', null, 500);
        return;
    }

    // Log the adjustment
    $reference = isset($body['reference']) ? UtilHandler::sanitizeInput($conn, $body['reference']) : null;
    $notes     = isset($body['notes']) ? UtilHandler::sanitizeInput($conn, $body['notes']) : null;
    logStockAdjustment($conn, $itemId, $vendorId, $type, $change, $currentQty, $newQty, $reference, $notes);

    $updated = fetchInventoryItemById($conn, $itemId);
    ResponseHandler::success('Stock adjusted successfully.', [
        'item' => formatInventoryItem($updated),
        'adjustment' => [
            'type'            => $type,
            'quantity_change'  => $change,
            'quantity_before'  => $currentQty,
            'quantity_after'   => $newQty
        ]
    ]);
}


// =========================================
// GET STOCK LOGS FOR AN ITEM
// =========================================
function getStockLogs() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $itemId = $_GET['item_id'] ?? null;
    if (!$itemId) {
        ResponseHandler::error('item_id query parameter is required.', null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Count
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM vendor_inventory_logs WHERE inventory_id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_inventory_logs WHERE inventory_id = ? AND vendor_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    mysqli_stmt_bind_param($stmt, "iiii", $itemId, $vendorId, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $logs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['quantity_change']  = (float)$row['quantity_change'];
        $row['quantity_before']  = (float)$row['quantity_before'];
        $row['quantity_after']   = (float)$row['quantity_after'];
        $logs[] = $row;
    }

    ResponseHandler::success('Stock logs retrieved.', [
        'logs' => $logs,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit)
        ]
    ]);
}


// =========================================
// DELETE INVENTORY ITEM
// =========================================
function deleteInventoryItem() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Inventory item deleted successfully.');
    } else {
        ResponseHandler::error('Failed to delete inventory item.', null, 500);
    }
}


// =========================================
// UPLOAD INVENTORY ITEM IMAGE
// =========================================
function uploadInventoryImage() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    $itemId = $_POST['item_id'] ?? $_GET['item_id'] ?? null;
    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_inventory WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Inventory item not found.', null, 404);
        return;
    }

    if (empty($_FILES['image'])) {
        ResponseHandler::error('No image file provided.', null, 400);
        return;
    }

    $uploader     = new FileUploader();
    $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
    $maxSize      = 2 * 1024 * 1024;
    $result       = $uploader->upload($_FILES['image'], 'uploads/inventory', 'inv', $allowedMimes, $maxSize);

    if (!$result['success']) {
        ResponseHandler::error('Image upload failed.', ['errors' => $result['errors']], 400);
        return;
    }

    $imagePath = $result['files'][0];

    $stmt = mysqli_prepare($conn, "UPDATE vendor_inventory SET image = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $imagePath, $itemId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Inventory image uploaded successfully.', ['image' => $imagePath]);
    } else {
        ResponseHandler::error('Failed to save image path.', null, 500);
    }
}


// =========================================
// INVENTORY SUMMARY / DASHBOARD
// =========================================
function getInventorySummary() {
    global $conn;

    $vendorId = verifyInventoryVendor();
    if (!$vendorId) return;

    // Total items, total value, low-stock count
    $stmt = mysqli_prepare($conn,
        "SELECT 
            COUNT(*) AS total_items,
            COALESCE(SUM(quantity * cost_price), 0) AS total_stock_value,
            SUM(CASE WHEN quantity <= low_stock_level THEN 1 ELSE 0 END) AS low_stock_count,
            SUM(CASE WHEN is_perishable = 1 AND expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring_soon_count
         FROM vendor_inventory WHERE vendor_id = ? AND is_active = 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $summary['total_items']         = (int)$summary['total_items'];
    $summary['total_stock_value']   = (float)$summary['total_stock_value'];
    $summary['low_stock_count']     = (int)$summary['low_stock_count'];
    $summary['expiring_soon_count'] = (int)$summary['expiring_soon_count'];

    // Category breakdown
    $stmt = mysqli_prepare($conn,
        "SELECT c.name AS category, COUNT(i.id) AS item_count, COALESCE(SUM(i.quantity * i.cost_price), 0) AS category_value
         FROM vendor_inventory i
         LEFT JOIN vendor_categories c ON i.category_id = c.id
         WHERE i.vendor_id = ? AND i.is_active = 1
         GROUP BY i.category_id
         ORDER BY category_value DESC"
    );
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $breakdown = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['category']       = $row['category'] ?? 'Uncategorised';
        $row['item_count']     = (int)$row['item_count'];
        $row['category_value'] = (float)$row['category_value'];
        $breakdown[] = $row;
    }

    $summary['category_breakdown'] = $breakdown;

    ResponseHandler::success('Inventory summary retrieved.', $summary);
}


// =========================================
// HELPERS
// =========================================
function fetchInventoryItemById($conn, $id) {
    $stmt = mysqli_prepare($conn, "SELECT i.*, c.name AS category_name FROM vendor_inventory i LEFT JOIN vendor_categories c ON i.category_id = c.id WHERE i.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function logStockAdjustment($conn, $inventoryId, $vendorId, $type, $change, $before, $after, $reference, $notes) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO vendor_inventory_logs (inventory_id, vendor_id, adjustment_type, quantity_change, quantity_before, quantity_after, reference, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iisdddss", $inventoryId, $vendorId, $type, $change, $before, $after, $reference, $notes);
    mysqli_stmt_execute($stmt);
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'addInventoryItem':
        addInventoryItem();
        break;
    case 'listInventoryItems':
        listInventoryItems();
        break;
    case 'getInventoryItem':
        getInventoryItem();
        break;
    case 'updateInventoryItem':
        updateInventoryItem();
        break;
    case 'deleteInventoryItem':
        deleteInventoryItem();
        break;
    case 'adjustStock':
        adjustStock();
        break;
    case 'getStockLogs':
        getStockLogs();
        break;
    case 'uploadInventoryImage':
        uploadInventoryImage();
        break;
    case 'getInventorySummary':
        getInventorySummary();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
