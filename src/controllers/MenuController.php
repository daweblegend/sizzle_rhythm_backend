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
function verifyMenuVendor() {
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
// HELPER: Generate menu item slug (unique per vendor)
// ========================
function generateMenuSlug($conn, $vendorId, $name) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
    $slug = preg_replace('/-+/', '-', $slug);

    $baseSlug = $slug;
    $counter  = 0;

    while (true) {
        $checkSlug = $counter === 0 ? $baseSlug : $baseSlug . '-' . $counter;
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_menu_items WHERE vendor_id = ? AND slug = ?");
        mysqli_stmt_bind_param($stmt, "is", $vendorId, $checkSlug);
        mysqli_stmt_execute($stmt);
        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
            return $checkSlug;
        }
        $counter++;
    }
}

// ========================
// HELPER: Format menu item
// ========================
function formatMenuItem($item) {
    if (isset($item['tags']) && is_string($item['tags'])) {
        $item['tags'] = json_decode($item['tags'], true);
    }
    if (isset($item['options']) && is_string($item['options'])) {
        $item['options'] = json_decode($item['options'], true);
    }
    $item['price']          = (float)$item['price'];
    $item['discount_price'] = $item['discount_price'] !== null ? (float)$item['discount_price'] : null;
    $item['calories']       = $item['calories'] !== null ? (int)$item['calories'] : null;
    $item['sort_order']     = (int)$item['sort_order'];
    $item['total_orders']   = (int)$item['total_orders'];
    $item['average_rating'] = (float)$item['average_rating'];
    $item['is_available']   = (bool)$item['is_available'];
    $item['is_featured']    = (bool)$item['is_featured'];
    $item['is_active']      = (bool)$item['is_active'];
    return $item;
}


// =========================================
// CREATE MENU ITEM
// =========================================
function createMenuItem() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    // Support both JSON body and multipart/form-data (when image is included)
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $body = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);

    if (empty($body['name']) || !isset($body['price'])) {
        ResponseHandler::error('name and price are required.', null, 400);
        return;
    }

    $uuid           = Uuid::uuid4()->toString();
    $name           = UtilHandler::sanitizeInput($conn, $body['name']);
    $slug           = generateMenuSlug($conn, $vendorId, $name);
    $description    = isset($body['description']) ? UtilHandler::sanitizeInput($conn, $body['description']) : null;
    $price          = (float)$body['price'];
    $discountPrice  = isset($body['discount_price']) ? (float)$body['discount_price'] : null;
    $prepTime       = isset($body['preparation_time']) ? UtilHandler::sanitizeInput($conn, $body['preparation_time']) : null;
    $calories       = isset($body['calories']) ? (int)$body['calories'] : null;

    // tags & options: accept array or JSON string (multipart sends strings)
    $tagsRaw    = $body['tags'] ?? null;
    $optionsRaw = $body['options'] ?? null;
    if ($tagsRaw !== null) {
        $decoded = is_array($tagsRaw) ? $tagsRaw : json_decode($tagsRaw, true);
        $tags = $decoded !== null ? json_encode($decoded) : null;
    } else {
        $tags = null;
    }
    if ($optionsRaw !== null) {
        $decoded = is_array($optionsRaw) ? $optionsRaw : json_decode($optionsRaw, true);
        $options = $decoded !== null ? json_encode($decoded) : null;
    } else {
        $options = null;
    }

    $isFeatured     = !empty($body['is_featured']) ? 1 : 0;
    $sortOrder      = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $categoryId     = !empty($body['category_id']) ? (int)$body['category_id'] : null;

    // Validate category
    if ($categoryId) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ? AND (type = 'menu' OR type = 'both')");
        mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
        mysqli_stmt_execute($stmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            ResponseHandler::error('Invalid category. Category must belong to your store and be usable for menu.', null, 400);
            return;
        }
    }

    if ($price < 0) {
        ResponseHandler::error('Price cannot be negative.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO vendor_menu_items (uuid, vendor_id, category_id, name, slug, description, price, discount_price, preparation_time, calories, tags, options, is_featured, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "siisssddsissii",
        $uuid, $vendorId, $categoryId, $name, $slug, $description,
        $price, $discountPrice, $prepTime, $calories, $tags, $options,
        $isFeatured, $sortOrder
    );

    if (mysqli_stmt_execute($stmt)) {
        $itemId = mysqli_insert_id($conn);

        // Handle optional image upload
        if (!empty($_FILES['image'])) {
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            $maxSize      = 3 * 1024 * 1024; // 3 MB
            $uploadResult = FileUploader::upload($_FILES['image'], 'uploads/menu', 'menu', $allowedMimes, $maxSize);

            if ($uploadResult['success'] && !empty($uploadResult['files'][0])) {
                $imagePath = $uploadResult['files'][0];
                $imgStmt   = mysqli_prepare($conn, "UPDATE vendor_menu_items SET image = ? WHERE id = ?");
                mysqli_stmt_bind_param($imgStmt, "si", $imagePath, $itemId);
                mysqli_stmt_execute($imgStmt);
            }
        }

        $item = fetchMenuItemById($conn, $itemId);
        ResponseHandler::success('Menu item created successfully.', formatMenuItem($item), 201);
    } else {
        ResponseHandler::error('Failed to create menu item.', null, 500);
    }
}


// =========================================
// LIST MENU ITEMS (vendor — own items)
// =========================================
function listMenuItems() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $page       = max(1, (int)($_GET['page'] ?? 1));
    $limit      = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset     = ($page - 1) * $limit;
    $categoryId = $_GET['category_id'] ?? null;
    $search     = $_GET['search'] ?? null;
    $available  = $_GET['available'] ?? null;
    $featured   = $_GET['featured'] ?? null;
    $sort       = $_GET['sort'] ?? 'sort_order';

    $query  = "SELECT m.*, c.name AS category_name FROM vendor_menu_items m LEFT JOIN vendor_categories c ON m.category_id = c.id WHERE m.vendor_id = ? AND m.is_active = 1";
    $countQ = "SELECT COUNT(*) AS total FROM vendor_menu_items m WHERE m.vendor_id = ? AND m.is_active = 1";
    $params = [$vendorId];
    $types  = "i";

    if ($categoryId) {
        $query  .= " AND m.category_id = ?";
        $countQ .= " AND m.category_id = ?";
        $params[] = (int)$categoryId;
        $types   .= "i";
    }

    if ($search) {
        $searchTerm = '%' . UtilHandler::sanitizeInput($conn, $search) . '%';
        $query  .= " AND (m.name LIKE ? OR m.description LIKE ?)";
        $countQ .= " AND (m.name LIKE ? OR m.description LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types   .= "ss";
    }

    if ($available === 'true')  { $query .= " AND m.is_available = 1"; $countQ .= " AND m.is_available = 1"; }
    if ($available === 'false') { $query .= " AND m.is_available = 0"; $countQ .= " AND m.is_available = 0"; }
    if ($featured === 'true')   { $query .= " AND m.is_featured = 1";  $countQ .= " AND m.is_featured = 1";  }

    switch ($sort) {
        case 'price_low':   $query .= " ORDER BY m.price ASC"; break;
        case 'price_high':  $query .= " ORDER BY m.price DESC"; break;
        case 'name':        $query .= " ORDER BY m.name ASC"; break;
        case 'newest':      $query .= " ORDER BY m.created_at DESC"; break;
        case 'popular':     $query .= " ORDER BY m.total_orders DESC"; break;
        case 'rating':      $query .= " ORDER BY m.average_rating DESC"; break;
        default:            $query .= " ORDER BY m.sort_order ASC, m.name ASC"; break;
    }

    // Count
    $stmt = mysqli_prepare($conn, $countQ);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch
    $query   .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types   .= "ii";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = formatMenuItem($row);
    }

    ResponseHandler::success('Menu items retrieved successfully.', [
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
// GET SINGLE MENU ITEM (vendor)
// =========================================
function getMenuItem() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $itemId = $_GET['id'] ?? null;
    if (!$itemId) {
        ResponseHandler::error('Item ID is required.', null, 400);
        return;
    }

    $item = fetchMenuItemById($conn, (int)$itemId);
    if (!$item || (int)$item['vendor_id'] !== $vendorId) {
        ResponseHandler::error('Menu item not found.', null, 404);
        return;
    }

    ResponseHandler::success('Menu item retrieved.', formatMenuItem($item));
}


// =========================================
// UPDATE MENU ITEM
// =========================================
function updateMenuItem() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_menu_items WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Menu item not found.', null, 404);
        return;
    }

    $updates = [];
    $params  = [];
    $types   = "";

    // Text fields
    $textFields = ['description', 'preparation_time'];
    foreach ($textFields as $f) {
        if (isset($body[$f])) {
            $updates[] = "$f = ?";
            $params[]  = UtilHandler::sanitizeInput($conn, $body[$f]);
            $types    .= "s";
        }
    }

    // Name (regenerate slug)
    if (!empty($body['name'])) {
        $name = UtilHandler::sanitizeInput($conn, $body['name']);
        $slug = generateMenuSlug($conn, $vendorId, $name);
        $updates[] = "name = ?";
        $params[]  = $name;
        $types    .= "s";
        $updates[] = "slug = ?";
        $params[]  = $slug;
        $types    .= "s";
    }

    // Decimal fields
    if (isset($body['price'])) {
        $updates[] = "price = ?";
        $params[]  = (float)$body['price'];
        $types    .= "d";
    }
    if (isset($body['discount_price'])) {
        $updates[] = "discount_price = ?";
        $params[]  = $body['discount_price'] !== null ? (float)$body['discount_price'] : null;
        $types    .= "d";
    }

    // Integer
    if (isset($body['calories'])) {
        $updates[] = "calories = ?";
        $params[]  = $body['calories'] !== null ? (int)$body['calories'] : null;
        $types    .= "i";
    }
    if (isset($body['sort_order'])) {
        $updates[] = "sort_order = ?";
        $params[]  = (int)$body['sort_order'];
        $types    .= "i";
    }

    // Booleans
    foreach (['is_available', 'is_featured', 'is_active'] as $boolField) {
        if (isset($body[$boolField])) {
            $updates[] = "$boolField = ?";
            $params[]  = $body[$boolField] ? 1 : 0;
            $types    .= "i";
        }
    }

    // JSON
    if (isset($body['tags'])) {
        $updates[] = "tags = ?";
        $params[]  = json_encode($body['tags']);
        $types    .= "s";
    }
    if (isset($body['options'])) {
        $updates[] = "options = ?";
        $params[]  = json_encode($body['options']);
        $types    .= "s";
    }

    // Category
    if (isset($body['category_id'])) {
        $catId = (int)$body['category_id'];
        if ($catId > 0) {
            $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ? AND (type = 'menu' OR type = 'both')");
            mysqli_stmt_bind_param($stmt, "ii", $catId, $vendorId);
            mysqli_stmt_execute($stmt);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                ResponseHandler::error('Invalid category for menu.', null, 400);
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

    $sql  = "UPDATE vendor_menu_items SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (mysqli_stmt_execute($stmt)) {
        $item = fetchMenuItemById($conn, $itemId);
        ResponseHandler::success('Menu item updated successfully.', formatMenuItem($item));
    } else {
        ResponseHandler::error('Failed to update menu item.', null, 500);
    }
}


// =========================================
// TOGGLE MENU ITEM AVAILABILITY
// =========================================
function toggleMenuItemAvailability() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id, is_available FROM vendor_menu_items WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$item) {
        ResponseHandler::error('Menu item not found.', null, 404);
        return;
    }

    $newStatus = $item['is_available'] ? 0 : 1;

    $stmt = mysqli_prepare($conn, "UPDATE vendor_menu_items SET is_available = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newStatus, $itemId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Availability toggled.', ['is_available' => (bool)$newStatus]);
    } else {
        ResponseHandler::error('Failed to toggle availability.', null, 500);
    }
}


// =========================================
// DELETE MENU ITEM
// =========================================
function deleteMenuItem() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $body   = json_decode(file_get_contents('php://input'), true);
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_menu_items WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Menu item not found.', null, 404);
        return;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM vendor_menu_items WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Menu item deleted successfully.');
    } else {
        ResponseHandler::error('Failed to delete menu item.', null, 500);
    }
}


// =========================================
// UPLOAD MENU ITEM IMAGE
// =========================================
function uploadMenuItemImage() {
    global $conn;

    $vendorId = verifyMenuVendor();
    if (!$vendorId) return;

    $itemId = $_POST['item_id'] ?? $_GET['item_id'] ?? null;
    if (!$itemId) {
        ResponseHandler::error('item_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_menu_items WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $itemId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Menu item not found.', null, 404);
        return;
    }

    if (empty($_FILES['image'])) {
        ResponseHandler::error('No image file provided.', null, 400);
        return;
    }

    $uploader     = new FileUploader();
    $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
    $maxSize      = 3 * 1024 * 1024; // 3 MB
    $result       = $uploader->upload($_FILES['image'], 'uploads/menu', 'menu', $allowedMimes, $maxSize);

    if (!$result['success']) {
        ResponseHandler::error('Image upload failed.', ['errors' => $result['errors']], 400);
        return;
    }

    $imagePath = $result['files'][0];

    $stmt = mysqli_prepare($conn, "UPDATE vendor_menu_items SET image = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $imagePath, $itemId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Menu item image uploaded successfully.', ['image' => $imagePath]);
    } else {
        ResponseHandler::error('Failed to save image path.', null, 500);
    }
}


// =========================================
// PUBLIC: Browse vendor menu (by vendor slug)
// =========================================
function getVendorMenu() {
    global $conn;

    $vendorSlug = $_GET['vendor'] ?? null;
    $vendorId   = $_GET['vendor_id'] ?? null;

    if (!$vendorSlug && !$vendorId) {
        ResponseHandler::error('vendor (slug) or vendor_id query parameter is required.', null, 400);
        return;
    }

    // Resolve vendor
    if ($vendorSlug) {
        $stmt = mysqli_prepare($conn, "SELECT id, business_name, slug, is_open FROM vendors WHERE slug = ? AND is_active = 1");
        mysqli_stmt_bind_param($stmt, "s", $vendorSlug);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, business_name, slug, is_open FROM vendors WHERE id = ? AND is_active = 1");
        mysqli_stmt_bind_param($stmt, "i", $vendorId);
    }
    mysqli_stmt_execute($stmt);
    $vendor = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$vendor) {
        ResponseHandler::error('Vendor not found.', null, 404);
        return;
    }

    $vId        = (int)$vendor['id'];
    $categoryId = $_GET['category_id'] ?? null;
    $search     = $_GET['search'] ?? null;
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $limit      = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset     = ($page - 1) * $limit;

    // Fetch categories for this vendor (menu type)
    $stmt = mysqli_prepare($conn, "SELECT id, name, slug, image, sort_order FROM vendor_categories WHERE vendor_id = ? AND (type = 'menu' OR type = 'both') AND is_active = 1 ORDER BY sort_order ASC, name ASC");
    mysqli_stmt_bind_param($stmt, "i", $vId);
    mysqli_stmt_execute($stmt);
    $catResult  = mysqli_stmt_get_result($stmt);
    $categories = [];
    while ($row = mysqli_fetch_assoc($catResult)) {
        $row['sort_order'] = (int)$row['sort_order'];
        $categories[] = $row;
    }

    // Build menu item query
    $query  = "SELECT m.*, c.name AS category_name FROM vendor_menu_items m LEFT JOIN vendor_categories c ON m.category_id = c.id WHERE m.vendor_id = ? AND m.is_active = 1 AND m.is_available = 1";
    $countQ = "SELECT COUNT(*) AS total FROM vendor_menu_items m WHERE m.vendor_id = ? AND m.is_active = 1 AND m.is_available = 1";
    $params = [$vId];
    $types  = "i";

    if ($categoryId) {
        $query  .= " AND m.category_id = ?";
        $countQ .= " AND m.category_id = ?";
        $params[] = (int)$categoryId;
        $types   .= "i";
    }

    if ($search) {
        $searchTerm = '%' . mysqli_real_escape_string($conn, $search) . '%';
        $query  .= " AND (m.name LIKE ? OR m.description LIKE ?)";
        $countQ .= " AND (m.name LIKE ? OR m.description LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types   .= "ss";
    }

    $query .= " ORDER BY m.sort_order ASC, m.name ASC";

    // Count
    $stmt = mysqli_prepare($conn, $countQ);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch
    $query   .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types   .= "ii";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = formatMenuItem($row);
    }

    ResponseHandler::success('Vendor menu retrieved.', [
        'vendor' => [
            'id'            => (int)$vendor['id'],
            'business_name' => $vendor['business_name'],
            'slug'          => $vendor['slug'],
            'is_open'       => (bool)$vendor['is_open']
        ],
        'categories' => $categories,
        'items'      => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit)
        ]
    ]);
}


// =========================================
// HELPER
// =========================================
function fetchMenuItemById($conn, $id) {
    $stmt = mysqli_prepare($conn, "SELECT m.*, c.name AS category_name FROM vendor_menu_items m LEFT JOIN vendor_categories c ON m.category_id = c.id WHERE m.id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'createMenuItem':
        createMenuItem();
        break;
    case 'listMenuItems':
        listMenuItems();
        break;
    case 'getMenuItem':
        getMenuItem();
        break;
    case 'updateMenuItem':
        updateMenuItem();
        break;
    case 'toggleMenuItemAvailability':
        toggleMenuItemAvailability();
        break;
    case 'deleteMenuItem':
        deleteMenuItem();
        break;
    case 'uploadMenuItemImage':
        uploadMenuItemImage();
        break;
    case 'getVendorMenu':
        getVendorMenu();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
