<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/AdminActionsHelper.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify admin role
// ========================
function verifyPMAdmin() {
    global $conn;

    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return null;

    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);

    if (!AdminActionsHelper::isAdmin($userId)) {
        ResponseHandler::error('Access denied. Admin privileges required.', null, 403);
        return null;
    }

    return $userId;
}

// ========================
// HELPER: Generate slug
// ========================
function generatePMSlug($conn, $name) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
    $slug = preg_replace('/-+/', '-', $slug);

    $baseSlug = $slug;
    $counter  = 0;

    while (true) {
        $checkSlug = $counter === 0 ? $baseSlug : $baseSlug . '-' . $counter;
        $stmt = mysqli_prepare($conn, "SELECT id FROM pos_payment_methods WHERE slug = ?");
        mysqli_stmt_bind_param($stmt, "s", $checkSlug);
        mysqli_stmt_execute($stmt);
        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
            return $checkSlug;
        }
        $counter++;
    }
}

// ========================
// HELPER: Format row
// ========================
function formatPaymentMethod($row) {
    $row['id']               = (int)$row['id'];
    $row['requires_gateway'] = (bool)$row['requires_gateway'];
    $row['is_active']        = (bool)$row['is_active'];
    $row['sort_order']       = (int)$row['sort_order'];
    return $row;
}


// =========================================
// ADMIN: Create payment method
// =========================================
function createPaymentMethod() {
    global $conn;

    $adminId = verifyPMAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['name'])) {
        ResponseHandler::error('name is required.', null, 400);
        return;
    }

    $name            = UtilHandler::sanitizeInput($conn, $body['name']);
    $description     = isset($body['description']) ? UtilHandler::sanitizeInput($conn, $body['description']) : null;
    $icon            = isset($body['icon']) ? UtilHandler::sanitizeInput($conn, $body['icon']) : null;
    $requiresGateway = !empty($body['requires_gateway']) ? 1 : 0;
    $sortOrder       = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
    $uuid            = Uuid::uuid4()->toString();
    $slug            = generatePMSlug($conn, $name);

    // Check duplicate name
    $stmt = mysqli_prepare($conn, "SELECT id FROM pos_payment_methods WHERE name = ?");
    mysqli_stmt_bind_param($stmt, "s", $name);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('A payment method with this name already exists.', null, 409);
        return;
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO pos_payment_methods (uuid, name, slug, description, icon, requires_gateway, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sssssii", $uuid, $name, $slug, $description, $icon, $requiresGateway, $sortOrder);

    if (mysqli_stmt_execute($stmt)) {
        $id   = mysqli_insert_id($conn);
        $stmt = mysqli_prepare($conn, "SELECT * FROM pos_payment_methods WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        ResponseHandler::success('Payment method created.', formatPaymentMethod($row), 201);
    } else {
        ResponseHandler::error('Failed to create payment method.', null, 500);
    }
}


// =========================================
// ADMIN: List all payment methods
// =========================================
function listPaymentMethods() {
    global $conn;

    $adminId = verifyPMAdmin();
    if (!$adminId) return;

    $result  = mysqli_query($conn, "SELECT * FROM pos_payment_methods ORDER BY sort_order ASC, name ASC");
    $methods = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $methods[] = formatPaymentMethod($row);
    }

    ResponseHandler::success('Payment methods retrieved.', ['payment_methods' => $methods]);
}


// =========================================
// ADMIN: Update payment method
// =========================================
function updatePaymentMethod() {
    global $conn;

    $adminId = verifyPMAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$id) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_payment_methods WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Payment method not found.', null, 404);
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
        $slug = generatePMSlug($conn, $name);
        $updates[] = "slug = ?";
        $params[]  = $slug;
        $types    .= "s";
    }
    if (isset($body['description'])) {
        $updates[] = "description = ?";
        $params[]  = UtilHandler::sanitizeInput($conn, $body['description']);
        $types    .= "s";
    }
    if (isset($body['icon'])) {
        $updates[] = "icon = ?";
        $params[]  = UtilHandler::sanitizeInput($conn, $body['icon']);
        $types    .= "s";
    }
    if (isset($body['requires_gateway'])) {
        $updates[] = "requires_gateway = ?";
        $params[]  = $body['requires_gateway'] ? 1 : 0;
        $types    .= "i";
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

    $params[] = $id;
    $types   .= "i";

    $sql  = "UPDATE pos_payment_methods SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (mysqli_stmt_execute($stmt)) {
        $stmt = mysqli_prepare($conn, "SELECT * FROM pos_payment_methods WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        ResponseHandler::success('Payment method updated.', formatPaymentMethod($row));
    } else {
        ResponseHandler::error('Failed to update payment method.', null, 500);
    }
}


// =========================================
// ADMIN: Delete payment method
// =========================================
function deletePaymentMethod() {
    global $conn;

    $adminId = verifyPMAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);
    $id   = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$id) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    // Check if any orders reference this method
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM pos_orders WHERE payment_method_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $cnt = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

    if ($cnt > 0) {
        ResponseHandler::error("Cannot delete — {$cnt} order(s) use this payment method. Deactivate it instead.", null, 409);
        return;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM pos_payment_methods WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);

    if (mysqli_stmt_execute($stmt) && mysqli_affected_rows($conn) > 0) {
        ResponseHandler::success('Payment method deleted.');
    } else {
        ResponseHandler::error('Payment method not found or already deleted.', null, 404);
    }
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'createPaymentMethod':
        createPaymentMethod();
        break;
    case 'listPaymentMethods':
        listPaymentMethods();
        break;
    case 'updatePaymentMethod':
        updatePaymentMethod();
        break;
    case 'deletePaymentMethod':
        deletePaymentMethod();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
