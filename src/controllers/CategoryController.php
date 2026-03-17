<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/FileUploader.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify vendor & get vendor_id from vendors table
// ========================
function verifyCategoryVendor() {
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
// HELPER: Generate category slug (unique per vendor)
// ========================
function generateCategorySlug($conn, $vendorId, $name) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
    $slug = preg_replace('/-+/', '-', $slug);

    $baseSlug = $slug;
    $counter  = 0;

    while (true) {
        $checkSlug = $counter === 0 ? $baseSlug : $baseSlug . '-' . $counter;
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE vendor_id = ? AND slug = ?");
        mysqli_stmt_bind_param($stmt, "is", $vendorId, $checkSlug);
        mysqli_stmt_execute($stmt);
        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
            return $checkSlug;
        }
        $counter++;
    }
}


// =========================================
// CREATE CATEGORY
// =========================================
function createCategory() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    // Support both JSON body and multipart/form-data (when image is included)
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $body = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);

    if (empty($body['name'])) {
        ResponseHandler::error('Category name is required.', null, 400);
        return;
    }

    $name        = UtilHandler::sanitizeInput($conn, $body['name']);
    $description = isset($body['description']) ? UtilHandler::sanitizeInput($conn, $body['description']) : null;
    $type        = in_array($body['type'] ?? '', ['inventory', 'menu', 'both']) ? $body['type'] : 'both';
    $sortOrder   = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $uuid        = Uuid::uuid4()->toString();
    $slug        = generateCategorySlug($conn, $vendorId, $name);

    $stmt = mysqli_prepare($conn, "INSERT INTO vendor_categories (uuid, vendor_id, name, slug, description, type, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sissssi", $uuid, $vendorId, $name, $slug, $description, $type, $sortOrder);

    if (mysqli_stmt_execute($stmt)) {
        $catId = mysqli_insert_id($conn);

        // Handle optional image upload
        if (!empty($_FILES['image'])) {
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            $maxSize      = 2 * 1024 * 1024; // 2 MB
            $uploadResult = FileUploader::upload($_FILES['image'], 'uploads/categories', 'cat', $allowedMimes, $maxSize);

            if ($uploadResult['success'] && !empty($uploadResult['files'][0])) {
                $imagePath = $uploadResult['files'][0];
                $imgStmt   = mysqli_prepare($conn, "UPDATE vendor_categories SET image = ? WHERE id = ?");
                mysqli_stmt_bind_param($imgStmt, "si", $imagePath, $catId);
                mysqli_stmt_execute($imgStmt);
            }
        }

        $cat = fetchCategoryById($conn, $catId);
        ResponseHandler::success('Category created successfully.', $cat, 201);
    } else {
        ResponseHandler::error('Failed to create category.', null, 500);
    }
}


// =========================================
// LIST CATEGORIES
// =========================================
function listCategories() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    $type = $_GET['type'] ?? null; // inventory | menu | both

    $query  = "SELECT * FROM vendor_categories WHERE vendor_id = ? AND is_active = 1";
    $params = [$vendorId];
    $types  = "i";

    if ($type && in_array($type, ['inventory', 'menu', 'both'])) {
        // If requesting 'inventory' → return type=inventory OR type=both
        // If requesting 'menu' → return type=menu OR type=both
        if ($type === 'inventory' || $type === 'menu') {
            $query .= " AND (type = ? OR type = 'both')";
            $params[] = $type;
            $types .= "s";
        } else {
            $query .= " AND type = 'both'";
        }
    }

    $query .= " ORDER BY sort_order ASC, name ASC";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $categories = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['sort_order'] = (int)$row['sort_order'];
        $row['is_active']  = (bool)$row['is_active'];
        $categories[] = $row;
    }

    ResponseHandler::success('Categories retrieved successfully.', ['categories' => $categories]);
}


// =========================================
// GET SINGLE CATEGORY
// =========================================
function getCategory() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    $categoryId = $_GET['id'] ?? null;
    if (!$categoryId) {
        ResponseHandler::error('Category ID is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_categories WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
    mysqli_stmt_execute($stmt);
    $cat = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$cat) {
        ResponseHandler::error('Category not found.', null, 404);
        return;
    }

    $cat['sort_order'] = (int)$cat['sort_order'];
    $cat['is_active']  = (bool)$cat['is_active'];

    ResponseHandler::success('Category retrieved successfully.', $cat);
}


// =========================================
// UPDATE CATEGORY
// =========================================
function updateCategory() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    $categoryId = $body['category_id'] ?? null;
    if (!$categoryId) {
        ResponseHandler::error('category_id is required.', null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_categories WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Category not found.', null, 404);
        return;
    }

    $updates = [];
    $params  = [];
    $types   = "";

    if (!empty($body['name'])) {
        $name = UtilHandler::sanitizeInput($conn, $body['name']);
        $updates[] = "name = ?";
        $params[]  = $name;
        $types    .= "s";

        // Regenerate slug
        $slug = generateCategorySlug($conn, $vendorId, $name);
        $updates[] = "slug = ?";
        $params[]  = $slug;
        $types    .= "s";
    }

    if (isset($body['description'])) {
        $updates[] = "description = ?";
        $params[]  = UtilHandler::sanitizeInput($conn, $body['description']);
        $types    .= "s";
    }

    if (isset($body['type']) && in_array($body['type'], ['inventory', 'menu', 'both'])) {
        $updates[] = "type = ?";
        $params[]  = $body['type'];
        $types    .= "s";
    }

    if (isset($body['sort_order'])) {
        $updates[] = "sort_order = ?";
        $params[]  = (int)$body['sort_order'];
        $types    .= "i";
    }

    if (isset($body['is_active'])) {
        $updates[] = "is_active = ?";
        $params[]  = $body['is_active'] ? 1 : 0;
        $types    .= "i";
    }

    if (empty($updates)) {
        ResponseHandler::error('No valid fields to update.', null, 400);
        return;
    }

    $params[] = $categoryId;
    $types   .= "i";

    $sql  = "UPDATE vendor_categories SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (mysqli_stmt_execute($stmt)) {
        $cat = fetchCategoryById($conn, $categoryId);
        ResponseHandler::success('Category updated successfully.', $cat);
    } else {
        ResponseHandler::error('Failed to update category.', null, 500);
    }
}


// =========================================
// DELETE CATEGORY
// =========================================
function deleteCategory() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    $body       = json_decode(file_get_contents('php://input'), true);
    $categoryId = $body['category_id'] ?? null;

    if (!$categoryId) {
        ResponseHandler::error('category_id is required.', null, 400);
        return;
    }

    // Verify ownership
    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Category not found.', null, 404);
        return;
    }

    // Check if any inventory or menu items reference this category
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM vendor_inventory WHERE category_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $categoryId);
    mysqli_stmt_execute($stmt);
    $invCount = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM vendor_menu_items WHERE category_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $categoryId);
    mysqli_stmt_execute($stmt);
    $menuCount = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

    if ($invCount > 0 || $menuCount > 0) {
        ResponseHandler::error("Cannot delete — category is in use by {$invCount} inventory item(s) and {$menuCount} menu item(s). Reassign them first or set the category to inactive.", null, 409);
        return;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM vendor_categories WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Category deleted successfully.');
    } else {
        ResponseHandler::error('Failed to delete category.', null, 500);
    }
}


// =========================================
// UPLOAD CATEGORY IMAGE
// =========================================
function uploadCategoryImage() {
    global $conn;

    $vendorId = verifyCategoryVendor();
    if (!$vendorId) return;

    $categoryId = $_POST['category_id'] ?? $_GET['category_id'] ?? null;
    if (!$categoryId) {
        ResponseHandler::error('category_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_categories WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Category not found.', null, 404);
        return;
    }

    if (empty($_FILES['image'])) {
        ResponseHandler::error('No image file provided.', null, 400);
        return;
    }

    $uploader      = new FileUploader();
    $allowedMimes  = ['image/png', 'image/jpeg', 'image/webp'];
    $maxSize       = 2 * 1024 * 1024; // 2 MB
    $result        = $uploader->upload($_FILES['image'], 'uploads/categories', 'cat', $allowedMimes, $maxSize);

    if (!$result['success']) {
        ResponseHandler::error('Image upload failed.', ['errors' => $result['errors']], 400);
        return;
    }

    $imagePath = $result['files'][0];

    $stmt = mysqli_prepare($conn, "UPDATE vendor_categories SET image = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $imagePath, $categoryId);

    if (mysqli_stmt_execute($stmt)) {
        ResponseHandler::success('Category image uploaded successfully.', ['image' => $imagePath]);
    } else {
        ResponseHandler::error('Failed to save image path.', null, 500);
    }
}


// =========================================
// HELPER: Fetch a single category by ID
// =========================================
function fetchCategoryById($conn, $id) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_categories WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $cat = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($cat) {
        $cat['sort_order'] = (int)$cat['sort_order'];
        $cat['is_active']  = (bool)$cat['is_active'];
    }
    return $cat;
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'createCategory':
        createCategory();
        break;
    case 'listCategories':
        listCategories();
        break;
    case 'getCategory':
        getCategory();
        break;
    case 'updateCategory':
        updateCategory();
        break;
    case 'deleteCategory':
        deleteCategory();
        break;
    case 'uploadCategoryImage':
        uploadCategoryImage();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
