<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify vendor & return vendor_id
// ========================
function verifyPOSVendor() {
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

    return ['vendor_id' => (int)$row['vendor_id'], 'user_id' => (int)$row['user_id']];
}


// ========================
// HELPER: Generate order number (per vendor, sequential per day)
// ========================
function generateOrderNumber($conn, $vendorId) {
    $datePrefix = date('Ymd');
    $prefix     = "ORD-{$datePrefix}-";

    $stmt = mysqli_prepare($conn, "SELECT order_number FROM pos_orders WHERE vendor_id = ? AND order_number LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    mysqli_stmt_bind_param($stmt, "is", $vendorId, $like);
    mysqli_stmt_execute($stmt);
    $last = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($last) {
        $lastSeq = (int)substr($last['order_number'], strlen($prefix));
        $seq     = $lastSeq + 1;
    } else {
        $seq = 1;
    }

    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}


// ========================
// HELPER: Format order with items
// ========================
function formatOrder($order) {
    $order['id']              = (int)$order['id'];
    $order['vendor_id']       = (int)$order['vendor_id'];
    $order['created_by']      = (int)$order['created_by'];
    $order['subtotal']        = (float)$order['subtotal'];
    $order['discount_amount'] = (float)$order['discount_amount'];
    $order['tax_amount']      = (float)$order['tax_amount'];
    $order['total_amount']    = (float)$order['total_amount'];
    $order['amount_paid']     = (float)$order['amount_paid'];
    $order['change_due']      = (float)$order['change_due'];
    $order['archived']        = (bool)$order['archived'];
    $order['payment_method_id'] = $order['payment_method_id'] !== null ? (int)$order['payment_method_id'] : null;
    return $order;
}

function formatOrderItem($item) {
    $item['id']           = (int)$item['id'];
    $item['order_id']     = (int)$item['order_id'];
    $item['menu_item_id'] = $item['menu_item_id'] !== null ? (int)$item['menu_item_id'] : null;
    $item['item_price']   = (float)$item['item_price'];
    $item['quantity']     = (int)$item['quantity'];
    $item['line_total']   = (float)$item['line_total'];
    if (isset($item['options']) && is_string($item['options'])) {
        $item['options'] = json_decode($item['options'], true);
    }
    return $item;
}


// ========================
// HELPER: Fetch full order with items & payment method name
// ========================
function fetchOrderFull($conn, $orderId, $vendorId) {
    $stmt = mysqli_prepare($conn, "
        SELECT o.*, pm.name AS payment_method_name, pm.slug AS payment_method_slug
        FROM pos_orders o
        LEFT JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.id = ? AND o.vendor_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$order) return null;

    $order = formatOrder($order);

    // Fetch items
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_order_items WHERE order_id = ? ORDER BY id ASC");
    mysqli_stmt_bind_param($stmt, "i", $orderId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items  = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = formatOrderItem($row);
    }
    $order['items'] = $items;

    return $order;
}


// =========================================
// VENDOR: Get enabled payment methods
// =========================================
function getVendorPaymentMethods() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    // Return all active payment methods with vendor's enabled state
    $stmt = mysqli_prepare($conn, "
        SELECT pm.*, COALESCE(vpm.is_enabled, 0) AS vendor_enabled
        FROM pos_payment_methods pm
        LEFT JOIN vendor_payment_methods vpm ON vpm.payment_method_id = pm.id AND vpm.vendor_id = ?
        WHERE pm.is_active = 1
        ORDER BY pm.sort_order ASC
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $result  = mysqli_stmt_get_result($stmt);
    $methods = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['requires_gateway'] = (bool)$row['requires_gateway'];
        $row['is_active']        = (bool)$row['is_active'];
        $row['sort_order']       = (int)$row['sort_order'];
        $row['vendor_enabled']   = (bool)$row['vendor_enabled'];

        // For 'online', check if vendor has a gateway configured
        if ($row['requires_gateway']) {
            $gStmt = mysqli_prepare($conn, "
                SELECT pgc.id FROM payment_gateway_configs pgc
                WHERE pgc.user_id = (SELECT user_id FROM vendors WHERE id = ?)
                AND pgc.is_active = 1 LIMIT 1
            ");
            mysqli_stmt_bind_param($gStmt, "i", $vendorId);
            mysqli_stmt_execute($gStmt);
            $row['has_gateway_configured'] = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($gStmt));
        } else {
            $row['has_gateway_configured'] = null;
        }

        $methods[] = $row;
    }

    ResponseHandler::success('Payment methods retrieved.', ['payment_methods' => $methods]);
}


// =========================================
// VENDOR: Toggle a payment method on/off
// =========================================
function toggleVendorPaymentMethod() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body            = json_decode(file_get_contents('php://input'), true);
    $paymentMethodId = isset($body['payment_method_id']) ? (int)$body['payment_method_id'] : 0;

    if (!$paymentMethodId) {
        ResponseHandler::error('payment_method_id is required.', null, 400);
        return;
    }

    // Verify payment method exists and is active
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_payment_methods WHERE id = ? AND is_active = 1");
    mysqli_stmt_bind_param($stmt, "i", $paymentMethodId);
    mysqli_stmt_execute($stmt);
    $pm = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$pm) {
        ResponseHandler::error('Payment method not found or is inactive.', null, 404);
        return;
    }

    // If this is an 'online' method, ensure vendor has a payment gateway configured
    if ($pm['requires_gateway']) {
        $gStmt = mysqli_prepare($conn, "
            SELECT pgc.id FROM payment_gateway_configs pgc
            WHERE pgc.user_id = (SELECT user_id FROM vendors WHERE id = ?)
            AND pgc.is_active = 1 LIMIT 1
        ");
        mysqli_stmt_bind_param($gStmt, "i", $vendorId);
        mysqli_stmt_execute($gStmt);
        if (!mysqli_fetch_assoc(mysqli_stmt_get_result($gStmt))) {
            ResponseHandler::error('You must configure a payment gateway before enabling online payments.', null, 400);
            return;
        }
    }

    // Check current state
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_payment_methods WHERE vendor_id = ? AND payment_method_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $vendorId, $paymentMethodId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($existing) {
        $newState = $existing['is_enabled'] ? 0 : 1;
        $stmt = mysqli_prepare($conn, "UPDATE vendor_payment_methods SET is_enabled = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $newState, $existing['id']);
        mysqli_stmt_execute($stmt);
    } else {
        // First time — enable it
        $newState = 1;
        $stmt = mysqli_prepare($conn, "INSERT INTO vendor_payment_methods (vendor_id, payment_method_id, is_enabled) VALUES (?, ?, 1)");
        mysqli_stmt_bind_param($stmt, "ii", $vendorId, $paymentMethodId);
        mysqli_stmt_execute($stmt);
    }

    $label = $newState ? 'enabled' : 'disabled';
    ResponseHandler::success("{$pm['name']} payment method {$label}.", [
        'payment_method_id' => (int)$paymentMethodId,
        'name'              => $pm['name'],
        'is_enabled'        => (bool)$newState
    ]);
}


// =========================================
// POS: Create Order (add items to cart/draft)
// =========================================
function createOrder() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];
    $userId   = $auth['user_id'];

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['items']) || !is_array($body['items'])) {
        ResponseHandler::error('items array is required with at least one item.', null, 400);
        return;
    }

    $orderType    = in_array($body['order_type'] ?? '', ['dine_in', 'takeaway', 'delivery']) ? $body['order_type'] : 'dine_in';
    $tableNumber  = isset($body['table_number']) ? UtilHandler::sanitizeInput($conn, $body['table_number']) : null;
    $customerName = isset($body['customer_name']) ? UtilHandler::sanitizeInput($conn, $body['customer_name']) : null;
    $customerPhone = isset($body['customer_phone']) ? UtilHandler::sanitizeInput($conn, $body['customer_phone']) : null;
    $customerNote = isset($body['customer_note']) ? UtilHandler::sanitizeInput($conn, $body['customer_note']) : null;
    $notes        = isset($body['notes']) ? UtilHandler::sanitizeInput($conn, $body['notes']) : null;

    $uuid        = Uuid::uuid4()->toString();
    $orderNumber = generateOrderNumber($conn, $vendorId);

    // Validate and compute items
    $subtotal   = 0;
    $validItems = [];

    foreach ($body['items'] as $idx => $item) {
        $menuItemId = isset($item['menu_item_id']) ? (int)$item['menu_item_id'] : 0;
        $quantity   = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;

        if ($menuItemId) {
            // Fetch from menu
            $stmt = mysqli_prepare($conn, "SELECT id, name, price, options FROM vendor_menu_items WHERE id = ? AND vendor_id = ? AND is_active = 1 AND is_available = 1");
            mysqli_stmt_bind_param($stmt, "ii", $menuItemId, $vendorId);
            mysqli_stmt_execute($stmt);
            $menuRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if (!$menuRow) {
                ResponseHandler::error("Item #{$idx}: Menu item not found or unavailable.", null, 400);
                return;
            }

            $itemName  = $menuRow['name'];
            $itemPrice = (float)$menuRow['price'];
        } else {
            // Custom / ad-hoc item (vendor types in name + price)
            if (empty($item['item_name']) || !isset($item['item_price'])) {
                ResponseHandler::error("Item #{$idx}: Either menu_item_id OR item_name + item_price is required.", null, 400);
                return;
            }
            $itemName  = UtilHandler::sanitizeInput($conn, $item['item_name']);
            $itemPrice = (float)$item['item_price'];
        }

        // Override price if explicitly provided (e.g. discount at counter)
        if (isset($item['item_price']) && $menuItemId) {
            $itemPrice = (float)$item['item_price'];
        }

        $lineTotal = round($itemPrice * $quantity, 2);
        $subtotal += $lineTotal;

        $validItems[] = [
            'menu_item_id'         => $menuItemId ?: null,
            'item_name'            => $itemName,
            'item_price'           => $itemPrice,
            'quantity'             => $quantity,
            'options'              => isset($item['options']) ? json_encode($item['options']) : null,
            'special_instructions' => isset($item['special_instructions']) ? UtilHandler::sanitizeInput($conn, $item['special_instructions']) : null,
            'line_total'           => $lineTotal,
        ];
    }

    // Discount
    $discountAmount = isset($body['discount_amount']) ? max(0, (float)$body['discount_amount']) : 0;
    $discountReason = isset($body['discount_reason']) ? UtilHandler::sanitizeInput($conn, $body['discount_reason']) : null;
    if ($discountAmount > $subtotal) $discountAmount = $subtotal;

    // Tax
    $taxAmount = isset($body['tax_amount']) ? max(0, (float)$body['tax_amount']) : 0;

    // Total
    $totalAmount = round($subtotal - $discountAmount + $taxAmount, 2);

    // Status — default is 'draft', but allow 'pending' directly
    $status = in_array($body['status'] ?? '', ['draft', 'pending']) ? $body['status'] : 'draft';

    // Insert order
    $stmt = mysqli_prepare($conn, "
        INSERT INTO pos_orders (uuid, vendor_id, order_number, created_by, customer_name, customer_phone, customer_note, order_type, table_number, subtotal, discount_amount, discount_reason, tax_amount, total_amount, status, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    mysqli_stmt_bind_param($stmt, "sisisisssddsddss",
        $uuid, $vendorId, $orderNumber, $userId,
        $customerName, $customerPhone, $customerNote,
        $orderType, $tableNumber,
        $subtotal, $discountAmount, $discountReason, $taxAmount, $totalAmount,
        $status, $notes
    );

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to create order.', ['db_error' => mysqli_error($conn)], 500);
        return;
    }

    $orderId = mysqli_insert_id($conn);

    // Insert items
    $itemStmt = mysqli_prepare($conn, "INSERT INTO pos_order_items (order_id, menu_item_id, item_name, item_price, quantity, options, special_instructions, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($validItems as $vi) {
        mysqli_stmt_bind_param($itemStmt, "iisdissd",
            $orderId, $vi['menu_item_id'], $vi['item_name'], $vi['item_price'],
            $vi['quantity'], $vi['options'], $vi['special_instructions'], $vi['line_total']
        );
        mysqli_stmt_execute($itemStmt);
    }

    $order = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success('Order created successfully.', $order, 201);
}


// =========================================
// POS: Add items to an existing order
// =========================================
function addOrderItems() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;

    if (!$orderId) {
        ResponseHandler::error('order_id is required.', null, 400);
        return;
    }
    if (empty($body['items']) || !is_array($body['items'])) {
        ResponseHandler::error('items array is required.', null, 400);
        return;
    }

    // Fetch order — must be draft or pending
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_orders WHERE id = ? AND vendor_id = ? AND status IN ('draft', 'pending', 'preparing')");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found or is no longer editable.', null, 404);
        return;
    }

    $addedTotal = 0;

    $itemStmt = mysqli_prepare($conn, "INSERT INTO pos_order_items (order_id, menu_item_id, item_name, item_price, quantity, options, special_instructions, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($body['items'] as $idx => $item) {
        $menuItemId = isset($item['menu_item_id']) ? (int)$item['menu_item_id'] : 0;
        $quantity   = isset($item['quantity']) ? max(1, (int)$item['quantity']) : 1;

        if ($menuItemId) {
            $mStmt = mysqli_prepare($conn, "SELECT id, name, price FROM vendor_menu_items WHERE id = ? AND vendor_id = ? AND is_active = 1 AND is_available = 1");
            mysqli_stmt_bind_param($mStmt, "ii", $menuItemId, $vendorId);
            mysqli_stmt_execute($mStmt);
            $menuRow = mysqli_fetch_assoc(mysqli_stmt_get_result($mStmt));

            if (!$menuRow) {
                ResponseHandler::error("Item #{$idx}: Menu item not found or unavailable.", null, 400);
                return;
            }

            $itemName  = $menuRow['name'];
            $itemPrice = isset($item['item_price']) ? (float)$item['item_price'] : (float)$menuRow['price'];
        } else {
            if (empty($item['item_name']) || !isset($item['item_price'])) {
                ResponseHandler::error("Item #{$idx}: Either menu_item_id OR item_name + item_price is required.", null, 400);
                return;
            }
            $itemName  = UtilHandler::sanitizeInput($conn, $item['item_name']);
            $itemPrice = (float)$item['item_price'];
        }

        $lineTotal  = round($itemPrice * $quantity, 2);
        $addedTotal += $lineTotal;
        $opts        = isset($item['options']) ? json_encode($item['options']) : null;
        $instructions = isset($item['special_instructions']) ? UtilHandler::sanitizeInput($conn, $item['special_instructions']) : null;
        $miId        = $menuItemId ?: null;

        mysqli_stmt_bind_param($itemStmt, "iisdissd",
            $orderId, $miId, $itemName, $itemPrice, $quantity, $opts, $instructions, $lineTotal
        );
        mysqli_stmt_execute($itemStmt);
    }

    // Recalculate totals
    $newSubtotal = (float)$order['subtotal'] + $addedTotal;
    $newTotal    = round($newSubtotal - (float)$order['discount_amount'] + (float)$order['tax_amount'], 2);

    $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET subtotal = ?, total_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ddi", $newSubtotal, $newTotal, $orderId);
    mysqli_stmt_execute($stmt);

    $updated = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success('Items added to order.', $updated);
}


// =========================================
// POS: Remove item from order
// =========================================
function removeOrderItem() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body       = json_decode(file_get_contents('php://input'), true);
    $orderId    = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    $orderItemId = isset($body['order_item_id']) ? (int)$body['order_item_id'] : 0;

    if (!$orderId || !$orderItemId) {
        ResponseHandler::error('order_id and order_item_id are required.', null, 400);
        return;
    }

    // Verify order is editable
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_orders WHERE id = ? AND vendor_id = ? AND status IN ('draft', 'pending', 'preparing')");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found or is no longer editable.', null, 404);
        return;
    }

    // Get item
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_order_items WHERE id = ? AND order_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $orderItemId, $orderId);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$item) {
        ResponseHandler::error('Order item not found.', null, 404);
        return;
    }

    // Delete item
    $stmt = mysqli_prepare($conn, "DELETE FROM pos_order_items WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $orderItemId);
    mysqli_stmt_execute($stmt);

    // Recalculate
    $newSubtotal = (float)$order['subtotal'] - (float)$item['line_total'];
    if ($newSubtotal < 0) $newSubtotal = 0;
    $discount = min((float)$order['discount_amount'], $newSubtotal);
    $newTotal = round($newSubtotal - $discount + (float)$order['tax_amount'], 2);

    $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET subtotal = ?, discount_amount = ?, total_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "dddi", $newSubtotal, $discount, $newTotal, $orderId);
    mysqli_stmt_execute($stmt);

    $updated = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success('Item removed from order.', $updated);
}


// =========================================
// POS: Update order status
// =========================================
function updateOrderStatus() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    $status  = $body['status'] ?? '';

    if (!$orderId || !$status) {
        ResponseHandler::error('order_id and status are required.', null, 400);
        return;
    }

    $validStatuses = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        ResponseHandler::error('Invalid status. Allowed: ' . implode(', ', $validStatuses), null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_orders WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found.', null, 404);
        return;
    }

    // Validate transitions
    $current = $order['status'];
    $allowed = [
        'draft'     => ['pending', 'cancelled'],
        'pending'   => ['preparing', 'ready', 'completed', 'cancelled'],
        'preparing' => ['ready', 'completed', 'cancelled'],
        'ready'     => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    if (!in_array($status, $allowed[$current] ?? [])) {
        ResponseHandler::error("Cannot transition from '{$current}' to '{$status}'.", null, 400);
        return;
    }

    $updates = "status = ?";
    $params  = [$status];
    $types   = "s";

    if ($status === 'completed') {
        $updates .= ", completed_at = NOW()";
    }
    if ($status === 'cancelled') {
        $updates .= ", cancelled_at = NOW()";
        if (!empty($body['cancel_reason'])) {
            $updates .= ", cancel_reason = ?";
            $params[] = UtilHandler::sanitizeInput($conn, $body['cancel_reason']);
            $types   .= "s";
        }
    }

    $params[] = $orderId;
    $types   .= "i";

    $sql  = "UPDATE pos_orders SET {$updates} WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);

    $updated = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success("Order status updated to '{$status}'.", $updated);
}


// =========================================
// POS: Process payment for an order
// =========================================
function processOrderPayment() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;
    $pmId    = isset($body['payment_method_id']) ? (int)$body['payment_method_id'] : 0;
    $amountPaid = isset($body['amount_paid']) ? (float)$body['amount_paid'] : 0;

    if (!$orderId || !$pmId) {
        ResponseHandler::error('order_id and payment_method_id are required.', null, 400);
        return;
    }

    // Fetch order
    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_orders WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found.', null, 404);
        return;
    }

    if ($order['payment_status'] === 'paid') {
        ResponseHandler::error('Order is already fully paid.', null, 400);
        return;
    }

    if (in_array($order['status'], ['cancelled'])) {
        ResponseHandler::error('Cannot pay for a cancelled order.', null, 400);
        return;
    }

    // Verify payment method is enabled for this vendor
    $stmt = mysqli_prepare($conn, "
        SELECT pm.* FROM pos_payment_methods pm
        JOIN vendor_payment_methods vpm ON vpm.payment_method_id = pm.id
        WHERE pm.id = ? AND vpm.vendor_id = ? AND vpm.is_enabled = 1 AND pm.is_active = 1
    ");
    mysqli_stmt_bind_param($stmt, "ii", $pmId, $vendorId);
    mysqli_stmt_execute($stmt);
    $pm = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$pm) {
        ResponseHandler::error('Payment method not found or not enabled for your store.', null, 400);
        return;
    }

    // For online payments, just store the reference — actual verification is separate
    $gatewayRef = isset($body['gateway_reference']) ? UtilHandler::sanitizeInput($conn, $body['gateway_reference']) : null;

    $totalDue = (float)$order['total_amount'];
    if ($amountPaid <= 0) $amountPaid = $totalDue;

    $previouslyPaid = (float)$order['amount_paid'];
    $newPaid        = $previouslyPaid + $amountPaid;
    $changeDue      = max(0, round($newPaid - $totalDue, 2));
    $paymentStatus  = $newPaid >= $totalDue ? 'paid' : 'partial';

    $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET payment_method_id = ?, payment_status = ?, amount_paid = ?, change_due = ?, gateway_reference = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "isddsi", $pmId, $paymentStatus, $newPaid, $changeDue, $gatewayRef, $orderId);
    mysqli_stmt_execute($stmt);

    // If fully paid and order is still draft, auto-move to pending
    if ($paymentStatus === 'paid' && $order['status'] === 'draft') {
        $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET status = 'pending' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $orderId);
        mysqli_stmt_execute($stmt);
    }

    $updated = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success("Payment recorded. Status: {$paymentStatus}.", $updated);
}


// =========================================
// POS: Apply discount to order
// =========================================
function applyOrderDiscount() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;

    if (!$orderId) {
        ResponseHandler::error('order_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM pos_orders WHERE id = ? AND vendor_id = ? AND status IN ('draft', 'pending')");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found or is no longer editable.', null, 404);
        return;
    }

    $subtotal       = (float)$order['subtotal'];
    $discountType   = $body['discount_type'] ?? 'flat'; // flat | percent
    $discountValue  = isset($body['discount_value']) ? (float)$body['discount_value'] : 0;
    $discountReason = isset($body['discount_reason']) ? UtilHandler::sanitizeInput($conn, $body['discount_reason']) : null;

    if ($discountType === 'percent') {
        $discountAmount = round($subtotal * ($discountValue / 100), 2);
    } else {
        $discountAmount = round($discountValue, 2);
    }

    $discountAmount = min($discountAmount, $subtotal);
    $newTotal       = round($subtotal - $discountAmount + (float)$order['tax_amount'], 2);

    $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET discount_amount = ?, discount_reason = ?, total_amount = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "dsdi", $discountAmount, $discountReason, $newTotal, $orderId);
    mysqli_stmt_execute($stmt);

    $updated = fetchOrderFull($conn, $orderId, $vendorId);
    ResponseHandler::success('Discount applied.', $updated);
}


// =========================================
// POS: Archive / unarchive order
// =========================================
function toggleOrderArchive() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body    = json_decode(file_get_contents('php://input'), true);
    $orderId = isset($body['order_id']) ? (int)$body['order_id'] : 0;

    if (!$orderId) {
        ResponseHandler::error('order_id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id, archived FROM pos_orders WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $orderId, $vendorId);
    mysqli_stmt_execute($stmt);
    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$order) {
        ResponseHandler::error('Order not found.', null, 404);
        return;
    }

    $newState = $order['archived'] ? 0 : 1;
    $stmt = mysqli_prepare($conn, "UPDATE pos_orders SET archived = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newState, $orderId);
    mysqli_stmt_execute($stmt);

    $label = $newState ? 'archived' : 'unarchived';
    ResponseHandler::success("Order {$label}.", ['order_id' => $orderId, 'archived' => (bool)$newState]);
}


// =========================================
// POS: Get single order
// =========================================
function getOrder() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$orderId) {
        ResponseHandler::error('id query parameter is required.', null, 400);
        return;
    }

    $order = fetchOrderFull($conn, $orderId, $vendorId);
    if (!$order) {
        ResponseHandler::error('Order not found.', null, 404);
        return;
    }

    ResponseHandler::success('Order retrieved.', $order);
}


// =========================================
// POS: List orders (with filters)
// =========================================
function listOrders() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $page     = max(1, (int)($_GET['page'] ?? 1));
    $limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset   = ($page - 1) * $limit;
    $status   = $_GET['status'] ?? null;
    $payment  = $_GET['payment_status'] ?? null;
    $archived = $_GET['archived'] ?? null;
    $search   = $_GET['search'] ?? null;
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo   = $_GET['date_to'] ?? null;
    $sort     = $_GET['sort'] ?? 'newest';

    $query  = "SELECT o.*, pm.name AS payment_method_name FROM pos_orders o LEFT JOIN pos_payment_methods pm ON o.payment_method_id = pm.id WHERE o.vendor_id = ?";
    $countQ = "SELECT COUNT(*) AS total FROM pos_orders o WHERE o.vendor_id = ?";
    $params = [$vendorId];
    $types  = "i";

    if ($status && in_array($status, ['draft', 'pending', 'preparing', 'ready', 'completed', 'cancelled'])) {
        $query  .= " AND o.status = ?";
        $countQ .= " AND o.status = ?";
        $params[] = $status;
        $types   .= "s";
    }

    if ($payment && in_array($payment, ['unpaid', 'partial', 'paid', 'refunded'])) {
        $query  .= " AND o.payment_status = ?";
        $countQ .= " AND o.payment_status = ?";
        $params[] = $payment;
        $types   .= "s";
    }

    if ($archived === 'true') {
        $query  .= " AND o.archived = 1";
        $countQ .= " AND o.archived = 1";
    } elseif ($archived === 'false' || $archived === null) {
        // By default, hide archived orders
        $query  .= " AND o.archived = 0";
        $countQ .= " AND o.archived = 0";
    }
    // archived=all shows everything

    if ($search) {
        $searchTerm = '%' . UtilHandler::sanitizeInput($conn, $search) . '%';
        $query  .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
        $countQ .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types   .= "sss";
    }

    if ($dateFrom) {
        $query  .= " AND DATE(o.created_at) >= ?";
        $countQ .= " AND DATE(o.created_at) >= ?";
        $params[] = $dateFrom;
        $types   .= "s";
    }
    if ($dateTo) {
        $query  .= " AND DATE(o.created_at) <= ?";
        $countQ .= " AND DATE(o.created_at) <= ?";
        $params[] = $dateTo;
        $types   .= "s";
    }

    switch ($sort) {
        case 'oldest':   $query .= " ORDER BY o.created_at ASC"; break;
        case 'total_high': $query .= " ORDER BY o.total_amount DESC"; break;
        case 'total_low':  $query .= " ORDER BY o.total_amount ASC"; break;
        default:          $query .= " ORDER BY o.created_at DESC"; break;
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
    $result = mysqli_stmt_get_result($stmt);

    $orders = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row = formatOrder($row);
        // Item count (lightweight — don't fetch full items for list)
        $iStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt, SUM(quantity) AS qty FROM pos_order_items WHERE order_id = ?");
        mysqli_stmt_bind_param($iStmt, "i", $row['id']);
        mysqli_stmt_execute($iStmt);
        $ic = mysqli_fetch_assoc(mysqli_stmt_get_result($iStmt));
        $row['item_count']    = (int)($ic['cnt'] ?? 0);
        $row['total_quantity'] = (int)($ic['qty'] ?? 0);
        $orders[] = $row;
    }

    ResponseHandler::success('Orders retrieved.', [
        'orders'     => $orders,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / $limit),
        ]
    ]);
}


// =========================================
// POS: Daily sales summary
// =========================================
function getDailySummary() {
    global $conn;

    $auth = verifyPOSVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $date = $_GET['date'] ?? date('Y-m-d');

    // Total orders / revenue by status
    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
            SUM(CASE WHEN status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN status = 'completed' THEN discount_amount ELSE 0 END) AS total_discounts,
            SUM(CASE WHEN payment_status = 'paid' AND status = 'completed' THEN total_amount ELSE 0 END) AS collected_revenue,
            SUM(CASE WHEN payment_status = 'unpaid' AND status NOT IN ('cancelled') THEN total_amount ELSE 0 END) AS unpaid_amount
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) = ?
    ");
    mysqli_stmt_bind_param($stmt, "is", $vendorId, $date);
    mysqli_stmt_execute($stmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Payment method breakdown
    $stmt = mysqli_prepare($conn, "
        SELECT pm.name, pm.slug, COUNT(*) AS order_count, SUM(o.total_amount) AS method_total
        FROM pos_orders o
        JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) = ? AND o.status = 'completed'
        GROUP BY pm.id
        ORDER BY method_total DESC
    ");
    mysqli_stmt_bind_param($stmt, "is", $vendorId, $date);
    mysqli_stmt_execute($stmt);
    $pmResult   = mysqli_stmt_get_result($stmt);
    $pmBreakdown = [];
    while ($row = mysqli_fetch_assoc($pmResult)) {
        $row['order_count']  = (int)$row['order_count'];
        $row['method_total'] = (float)$row['method_total'];
        $pmBreakdown[] = $row;
    }

    // Top selling items
    $stmt = mysqli_prepare($conn, "
        SELECT oi.item_name, SUM(oi.quantity) AS total_qty, SUM(oi.line_total) AS total_sales
        FROM pos_order_items oi
        JOIN pos_orders o ON oi.order_id = o.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) = ? AND o.status = 'completed'
        GROUP BY oi.item_name
        ORDER BY total_qty DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, "is", $vendorId, $date);
    mysqli_stmt_execute($stmt);
    $topResult = mysqli_stmt_get_result($stmt);
    $topItems  = [];
    while ($row = mysqli_fetch_assoc($topResult)) {
        $row['total_qty']   = (int)$row['total_qty'];
        $row['total_sales'] = (float)$row['total_sales'];
        $topItems[] = $row;
    }

    // Order type breakdown
    $stmt = mysqli_prepare($conn, "
        SELECT order_type, COUNT(*) AS count, SUM(total_amount) AS total
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) = ? AND status = 'completed'
        GROUP BY order_type
    ");
    mysqli_stmt_bind_param($stmt, "is", $vendorId, $date);
    mysqli_stmt_execute($stmt);
    $otResult = mysqli_stmt_get_result($stmt);
    $orderTypes = [];
    while ($row = mysqli_fetch_assoc($otResult)) {
        $row['count'] = (int)$row['count'];
        $row['total'] = (float)$row['total'];
        $orderTypes[] = $row;
    }

    ResponseHandler::success('Daily summary retrieved.', [
        'date'                => $date,
        'total_orders'        => (int)$summary['total_orders'],
        'completed_orders'    => (int)$summary['completed_orders'],
        'cancelled_orders'    => (int)$summary['cancelled_orders'],
        'active_orders'       => (int)$summary['active_orders'],
        'total_revenue'       => (float)($summary['total_revenue'] ?? 0),
        'total_discounts'     => (float)($summary['total_discounts'] ?? 0),
        'collected_revenue'   => (float)($summary['collected_revenue'] ?? 0),
        'unpaid_amount'       => (float)($summary['unpaid_amount'] ?? 0),
        'payment_breakdown'   => $pmBreakdown,
        'top_selling_items'   => $topItems,
        'order_type_breakdown' => $orderTypes,
    ]);
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getVendorPaymentMethods':
        getVendorPaymentMethods();
        break;
    case 'toggleVendorPaymentMethod':
        toggleVendorPaymentMethod();
        break;
    case 'createOrder':
        createOrder();
        break;
    case 'addOrderItems':
        addOrderItems();
        break;
    case 'removeOrderItem':
        removeOrderItem();
        break;
    case 'updateOrderStatus':
        updateOrderStatus();
        break;
    case 'processOrderPayment':
        processOrderPayment();
        break;
    case 'applyOrderDiscount':
        applyOrderDiscount();
        break;
    case 'toggleOrderArchive':
        toggleOrderArchive();
        break;
    case 'getOrder':
        getOrder();
        break;
    case 'listOrders':
        listOrders();
        break;
    case 'getDailySummary':
        getDailySummary();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
