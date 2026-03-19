<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify vendor or team member with customer permission
// ========================
function verifyCustomerAccess() {
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

    if ($row['role'] === 'vendor') {
        if (!$row['vendor_id']) {
            ResponseHandler::error('Vendor store not found.', null, 404);
            return null;
        }
        return ['vendor_id' => (int)$row['vendor_id'], 'user_id' => (int)$row['user_id']];
    }

    if ($row['role'] === 'team_member') {
        $stmt = mysqli_prepare($conn, "SELECT vendor_id, permissions FROM vendor_team_members WHERE user_id = ? AND status = 'active'");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $team = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$team) {
            ResponseHandler::error('You are not an active team member.', null, 403);
            return null;
        }

        $perms = $team['permissions'] ? json_decode($team['permissions'], true) : [];
        if (!in_array('customers.view', $perms) && !in_array('customers.manage', $perms)) {
            ResponseHandler::error('You do not have permission to access customer management.', null, 403);
            return null;
        }

        return ['vendor_id' => (int)$team['vendor_id'], 'user_id' => (int)$row['user_id']];
    }

    ResponseHandler::error('Access denied.', null, 403);
    return null;
}


// ========================
// HELPER: Format customer
// ========================
function formatCustomer($row) {
    $row['id']           = (int)$row['id'];
    $row['vendor_id']    = (int)$row['vendor_id'];
    $row['user_id']      = (int)$row['user_id'];
    $row['total_orders'] = (int)$row['total_orders'];
    $row['total_spent']  = (float)$row['total_spent'];
    $row['is_active']    = (bool)$row['is_active'];
    if (isset($row['tags']) && is_string($row['tags'])) {
        $row['tags'] = json_decode($row['tags'], true) ?? [];
    }
    return $row;
}


// =========================================
// POST: Add customer
// Creates or links a user as this vendor's customer.
// If email/phone matches an existing user, links them.
// Otherwise creates a new user with role=customer.
// =========================================
function addCustomer() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['first_name']) || empty($body['last_name'])) {
        ResponseHandler::error('first_name and last_name are required.', null, 400);
        return;
    }

    $firstName = UtilHandler::sanitizeInput($conn, $body['first_name']);
    $lastName  = UtilHandler::sanitizeInput($conn, $body['last_name']);
    $email     = !empty($body['email']) ? UtilHandler::sanitizeInput($conn, strtolower(trim($body['email']))) : null;
    $phone     = !empty($body['phone']) ? UtilHandler::sanitizeInput($conn, $body['phone']) : null;
    $tags      = !empty($body['tags']) && is_array($body['tags']) ? json_encode($body['tags']) : null;
    $notes     = !empty($body['notes']) ? UtilHandler::sanitizeInput($conn, $body['notes']) : null;

    if (!$email && !$phone) {
        ResponseHandler::error('At least email or phone is required.', null, 400);
        return;
    }

    // Try to find existing user by email or phone
    $targetUserId = null;

    if ($email) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $found = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($found) $targetUserId = (int)$found['id'];
    }

    if (!$targetUserId && $phone) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE phone = ?");
        mysqli_stmt_bind_param($stmt, "s", $phone);
        mysqli_stmt_execute($stmt);
        $found = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($found) $targetUserId = (int)$found['id'];
    }

    if (!$targetUserId) {
        // Create new user account with role=customer
        $uuid     = Uuid::uuid4()->toString();
        $tempPass = bin2hex(random_bytes(8));
        $hashed   = password_hash($tempPass, PASSWORD_DEFAULT);

        // Generate username
        $username = strtolower($firstName . '.' . $lastName . rand(10, 99));
        $username = preg_replace('/[^a-z0-9_.]/', '', $username);

        $baseUsername = $username;
        $counter = 0;
        while (true) {
            $checkName = $counter === 0 ? $baseUsername : $baseUsername . $counter;
            $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
            mysqli_stmt_bind_param($stmt, "s", $checkName);
            mysqli_stmt_execute($stmt);
            if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
                $username = $checkName;
                break;
            }
            $counter++;
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO users (uuid, first_name, last_name, email, phone, password, username, role, email_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'customer', '0', 1, NOW(), NOW())");
        mysqli_stmt_bind_param($stmt, "sssssss", $uuid, $firstName, $lastName, $email, $phone, $hashed, $username);

        if (!mysqli_stmt_execute($stmt)) {
            ResponseHandler::error('Failed to create customer account.', ['db_error' => mysqli_error($conn)], 500);
            return;
        }

        $targetUserId = mysqli_insert_id($conn);
    }

    // Check if already a customer of this vendor
    $stmt = mysqli_prepare($conn, "SELECT id FROM vendor_customers WHERE vendor_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $vendorId, $targetUserId);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('This customer is already in your directory.', null, 409);
        return;
    }

    // Insert vendor_customer relationship
    $vcUuid = Uuid::uuid4()->toString();
    $stmt = mysqli_prepare($conn, "INSERT INTO vendor_customers (uuid, vendor_id, user_id, tags, notes) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "siiss", $vcUuid, $vendorId, $targetUserId, $tags, $notes);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to add customer.', ['db_error' => mysqli_error($conn)], 500);
        return;
    }

    $customerId = mysqli_insert_id($conn);

    // Fetch full record
    $stmt = mysqli_prepare($conn, "
        SELECT vc.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar
        FROM vendor_customers vc
        JOIN users u ON u.id = vc.user_id
        WHERE vc.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $customerId);
    mysqli_stmt_execute($stmt);
    $customer = formatCustomer(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)));

    ResponseHandler::success('Customer added successfully.', $customer, 201);
}


// =========================================
// GET: List customers
// =========================================
function listCustomers() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? null;
    $tag    = $_GET['tag'] ?? null;
    $sort   = $_GET['sort'] ?? 'newest';

    $query  = "SELECT vc.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar FROM vendor_customers vc JOIN users u ON u.id = vc.user_id WHERE vc.vendor_id = ? AND vc.is_active = 1";
    $countQ = "SELECT COUNT(*) AS total FROM vendor_customers vc JOIN users u ON u.id = vc.user_id WHERE vc.vendor_id = ? AND vc.is_active = 1";
    $params = [$vendorId];
    $types  = "i";

    if ($search) {
        $term     = '%' . UtilHandler::sanitizeInput($conn, $search) . '%';
        $query   .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $countQ  .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types   .= "ssss";
    }

    if ($tag) {
        $tagSearch = '%' . UtilHandler::sanitizeInput($conn, $tag) . '%';
        $query    .= " AND vc.tags LIKE ?";
        $countQ   .= " AND vc.tags LIKE ?";
        $params[]  = $tagSearch;
        $types    .= "s";
    }

    switch ($sort) {
        case 'name':        $query .= " ORDER BY u.first_name ASC, u.last_name ASC"; break;
        case 'top_spender': $query .= " ORDER BY vc.total_spent DESC"; break;
        case 'most_orders': $query .= " ORDER BY vc.total_orders DESC"; break;
        case 'last_order':  $query .= " ORDER BY vc.last_order_at DESC"; break;
        default:            $query .= " ORDER BY vc.created_at DESC"; break;
    }

    $query .= " LIMIT ? OFFSET ?";

    // Count
    $cStmt = mysqli_prepare($conn, $countQ);
    mysqli_stmt_bind_param($cStmt, $types, ...$params);
    mysqli_stmt_execute($cStmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($cStmt))['total'];

    // Fetch
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataTypes  = $types . "ii";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
    mysqli_stmt_execute($stmt);
    $result    = mysqli_stmt_get_result($stmt);
    $customers = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = formatCustomer($row);
    }

    ResponseHandler::success('Customers retrieved.', [
        'customers'  => $customers,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ]
    ]);
}


// =========================================
// GET: Get single customer with order history
// =========================================
function getCustomer() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$customerId) {
        ResponseHandler::error('id query parameter is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "
        SELECT vc.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar
        FROM vendor_customers vc
        JOIN users u ON u.id = vc.user_id
        WHERE vc.id = ? AND vc.vendor_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $customerId, $vendorId);
    mysqli_stmt_execute($stmt);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$customer) {
        ResponseHandler::error('Customer not found.', null, 404);
        return;
    }

    $customer = formatCustomer($customer);

    // Fetch recent orders from this customer (by matching customer_phone or name in pos_orders)
    $stmt = mysqli_prepare($conn, "
        SELECT id, uuid, order_number, order_type, total_amount, payment_status, status, created_at
        FROM pos_orders
        WHERE vendor_id = ? AND customer_phone = (SELECT phone FROM users WHERE id = ?)
        ORDER BY created_at DESC LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, "ii", $vendorId, $customer['user_id']);
    mysqli_stmt_execute($stmt);
    $orderResult = mysqli_stmt_get_result($stmt);
    $recentOrders = [];
    while ($row = mysqli_fetch_assoc($orderResult)) {
        $row['id']           = (int)$row['id'];
        $row['total_amount'] = (float)$row['total_amount'];
        $recentOrders[] = $row;
    }

    $customer['recent_orders'] = $recentOrders;

    ResponseHandler::success('Customer retrieved.', $customer);
}


// =========================================
// PUT: Update customer (tags, notes)
// =========================================
function updateCustomer() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body       = json_decode(file_get_contents('php://input'), true);
    $customerId = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$customerId) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_customers WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $customerId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Customer not found.', null, 404);
        return;
    }

    $updates = [];
    $params  = [];
    $types   = "";

    if (isset($body['tags']) && is_array($body['tags'])) {
        $updates[] = "tags = ?";
        $params[]  = json_encode($body['tags']);
        $types    .= "s";
    }

    if (isset($body['notes'])) {
        $updates[] = "notes = ?";
        $params[]  = UtilHandler::sanitizeInput($conn, $body['notes']);
        $types    .= "s";
    }

    if (empty($updates)) {
        ResponseHandler::error('Nothing to update. Provide tags or notes.', null, 400);
        return;
    }

    $params[] = $customerId;
    $types   .= "i";

    $sql  = "UPDATE vendor_customers SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);

    // Fetch updated
    $stmt = mysqli_prepare($conn, "
        SELECT vc.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar
        FROM vendor_customers vc JOIN users u ON u.id = vc.user_id
        WHERE vc.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $customerId);
    mysqli_stmt_execute($stmt);
    $customer = formatCustomer(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)));

    ResponseHandler::success('Customer updated.', $customer);
}


// =========================================
// DELETE: Remove customer from vendor directory
// =========================================
function removeCustomer() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body       = json_decode(file_get_contents('php://input'), true);
    $customerId = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$customerId) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_customers WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $customerId, $vendorId);
    mysqli_stmt_execute($stmt);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        ResponseHandler::error('Customer not found.', null, 404);
        return;
    }

    // Soft-delete: deactivate from this vendor's directory (user account stays intact)
    $stmt = mysqli_prepare($conn, "UPDATE vendor_customers SET is_active = 0 WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $customerId);
    mysqli_stmt_execute($stmt);

    ResponseHandler::success('Customer removed from your directory.', ['id' => $customerId]);
}


// =========================================
// GET: Customer stats/summary
// =========================================
function getCustomerStats() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_customers,
            SUM(CASE WHEN total_orders > 0 THEN 1 ELSE 0 END) AS active_customers,
            SUM(CASE WHEN total_orders = 0 THEN 1 ELSE 0 END) AS inactive_customers,
            COALESCE(SUM(total_spent), 0) AS total_revenue_from_customers,
            COALESCE(AVG(total_spent), 0) AS avg_spend_per_customer,
            COALESCE(MAX(total_spent), 0) AS highest_spender_amount,
            COALESCE(AVG(total_orders), 0) AS avg_orders_per_customer
        FROM vendor_customers
        WHERE vendor_id = ? AND is_active = 1
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Top 5 customers
    $stmt = mysqli_prepare($conn, "
        SELECT vc.id, u.first_name, u.last_name, u.phone, vc.total_orders, vc.total_spent
        FROM vendor_customers vc JOIN users u ON u.id = vc.user_id
        WHERE vc.vendor_id = ? AND vc.is_active = 1
        ORDER BY vc.total_spent DESC LIMIT 5
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $topResult = mysqli_stmt_get_result($stmt);
    $topCustomers = [];
    while ($row = mysqli_fetch_assoc($topResult)) {
        $row['id']           = (int)$row['id'];
        $row['total_orders'] = (int)$row['total_orders'];
        $row['total_spent']  = (float)$row['total_spent'];
        $topCustomers[] = $row;
    }

    ResponseHandler::success('Customer statistics retrieved.', [
        'total_customers'           => (int)$stats['total_customers'],
        'active_customers'          => (int)$stats['active_customers'],
        'inactive_customers'        => (int)$stats['inactive_customers'],
        'total_revenue_from_customers' => (float)$stats['total_revenue_from_customers'],
        'avg_spend_per_customer'    => round((float)$stats['avg_spend_per_customer'], 2),
        'highest_spender_amount'    => (float)$stats['highest_spender_amount'],
        'avg_orders_per_customer'   => round((float)$stats['avg_orders_per_customer'], 1),
        'top_customers'             => $topCustomers,
    ]);
}


// =========================================
// GET: Full paginated order history for a customer
// =========================================
function getCustomerOrderHistory() {
    global $conn;

    $auth = verifyCustomerAccess();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$customerId) {
        ResponseHandler::error('id query parameter is required.', null, 400);
        return;
    }

    // Verify customer belongs to this vendor
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM vendor_customers WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $customerId, $vendorId);
    mysqli_stmt_execute($stmt);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$customer) {
        ResponseHandler::error('Customer not found.', null, 404);
        return;
    }

    $userId = (int)$customer['user_id'];

    // Get customer phone for matching POS orders
    $stmt = mysqli_prepare($conn, "SELECT phone FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $phone = $user['phone'] ?? '';

    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Count total
    $countStmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS total FROM pos_orders
        WHERE vendor_id = ? AND customer_phone = ?
    ");
    mysqli_stmt_bind_param($countStmt, "is", $vendorId, $phone);
    mysqli_stmt_execute($countStmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];

    // Fetch orders
    $stmt = mysqli_prepare($conn, "
        SELECT o.id, o.uuid, o.order_number, o.order_type, o.table_number,
               o.subtotal, o.discount_amount, o.tax_amount, o.total_amount,
               o.payment_status, o.amount_paid, o.change_due,
               o.status, o.customer_name, o.customer_phone, o.customer_note,
               o.notes, o.created_at, o.completed_at,
               pm.name AS payment_method_name
        FROM pos_orders o
        LEFT JOIN pos_payment_methods pm ON pm.id = o.payment_method_id
        WHERE o.vendor_id = ? AND o.customer_phone = ?
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, "isii", $vendorId, $phone, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $orders = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['id']              = (int)$row['id'];
        $row['subtotal']        = (float)$row['subtotal'];
        $row['discount_amount'] = (float)$row['discount_amount'];
        $row['tax_amount']      = (float)$row['tax_amount'];
        $row['total_amount']    = (float)$row['total_amount'];
        $row['amount_paid']     = (float)$row['amount_paid'];
        $row['change_due']      = (float)$row['change_due'];
        $orders[] = $row;
    }

    ResponseHandler::success('Customer order history retrieved.', [
        'orders'     => $orders,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => ceil($total / $limit),
        ]
    ]);
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'addCustomer':
        addCustomer();
        break;
    case 'listCustomers':
        listCustomers();
        break;
    case 'getCustomer':
        getCustomer();
        break;
    case 'updateCustomer':
        updateCustomer();
        break;
    case 'removeCustomer':
        removeCustomer();
        break;
    case 'getCustomerStats':
        getCustomerStats();
        break;
    case 'getCustomerOrderHistory':
        getCustomerOrderHistory();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
