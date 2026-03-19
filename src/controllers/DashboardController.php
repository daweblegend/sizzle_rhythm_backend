<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';


// ========================
// HELPER: Verify vendor/team-member and return vendor_id
// (Same pattern as verifyPOSVendor — supports both roles)
// ========================
function verifyDashboardVendor() {
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
            ResponseHandler::error('Vendor store profile not found. Please create your store profile first.', null, 404);
            return null;
        }
        return ['vendor_id' => (int)$row['vendor_id'], 'user_id' => (int)$row['user_id']];
    }

    if ($row['role'] === 'team_member') {
        $stmt = mysqli_prepare($conn, "SELECT vendor_id, team_role, permissions FROM vendor_team_members WHERE user_id = ? AND status = 'active'");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $team = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$team) {
            ResponseHandler::error('You are not an active member of any vendor team.', null, 403);
            return null;
        }

        return ['vendor_id' => (int)$team['vendor_id'], 'user_id' => (int)$row['user_id']];
    }

    ResponseHandler::error('Access denied. Vendor or team member privileges required.', null, 403);
    return null;
}


// ========================
// HELPER: Parse date filter from query params
// Returns [start_date, end_date] as 'Y-m-d' strings
// Supports: today (default), yesterday, 7days, this_month, date_range (from & to)
// ========================
function parseDateFilter() {
    $filter = $_GET['period'] ?? 'today';
    $today  = date('Y-m-d');

    switch ($filter) {
        case 'yesterday':
            $start = date('Y-m-d', strtotime('-1 day'));
            $end   = $start;
            break;

        case '7days':
            $start = date('Y-m-d', strtotime('-6 days'));
            $end   = $today;
            break;

        case 'this_month':
            $start = date('Y-m-01');
            $end   = $today;
            break;

        case 'this_year':
            $start = date('Y-01-01');
            $end   = $today;
            break;

        case 'date_range':
            $start = $_GET['from'] ?? $today;
            $end   = $_GET['to'] ?? $today;
            // Validate format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                ResponseHandler::error('Invalid date format. Use YYYY-MM-DD for "from" and "to" parameters.');
                return null;
            }
            if ($start > $end) {
                $tmp = $start;
                $start = $end;
                $end = $tmp;
            }
            break;

        case 'today':
        default:
            $filter = 'today';
            $start = $today;
            $end   = $today;
            break;
    }

    return ['start' => $start, 'end' => $end, 'filter' => $filter];
}


// ========================
// HELPER: Calculate comparison period
// If current = [start, end] then previous = same duration ending the day before start
// ========================
function getComparisonPeriod($start, $end) {
    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    $days    = (int)(($endTs - $startTs) / 86400) + 1; // inclusive

    $prevEnd   = date('Y-m-d', $startTs - 86400);
    $prevStart = date('Y-m-d', strtotime("-" . ($days - 1) . " days", strtotime($prevEnd)));

    return ['start' => $prevStart, 'end' => $prevEnd];
}


// ========================
// HELPER: Calculate percentage change
// ========================
function pctChange($current, $previous) {
    if ($previous == 0) {
        return $current > 0 ? 100.0 : 0.0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}


// ========================
// HELPER: Determine graph mode based on filter
// Returns: 'daily', 'weekly', or 'monthly'
//   today      → daily (last 7 days ending today)
//   yesterday  → daily (last 7 days ending yesterday)
//   7days      → daily (last 7 days)
//   this_month → weekly (weeks of the month)
//   this_year  → monthly (months of the year)
//   date_range → auto: ≤14 days = daily, ≤3 months = weekly, else monthly
// ========================
function getGraphConfig($dates) {
    $filter  = $dates['filter'];
    $startTs = strtotime($dates['start']);
    $endTs   = strtotime($dates['end']);
    $days    = (int)(($endTs - $startTs) / 86400) + 1;

    switch ($filter) {
        case 'today':
            // Last 7 days ending today
            return [
                'mode'  => 'daily',
                'start' => date('Y-m-d', strtotime('-6 days')),
                'end'   => date('Y-m-d'),
            ];

        case 'yesterday':
            // Last 7 days ending yesterday
            $yest = date('Y-m-d', strtotime('-1 day'));
            return [
                'mode'  => 'daily',
                'start' => date('Y-m-d', strtotime('-6 days', strtotime($yest))),
                'end'   => $yest,
            ];

        case '7days':
            return [
                'mode'  => 'daily',
                'start' => $dates['start'],
                'end'   => $dates['end'],
            ];

        case 'this_month':
            return [
                'mode'  => 'weekly',
                'start' => $dates['start'],
                'end'   => $dates['end'],
            ];

        case 'this_year':
            return [
                'mode'  => 'monthly',
                'start' => $dates['start'],
                'end'   => $dates['end'],
            ];

        case 'date_range':
        default:
            if ($days <= 14) {
                return ['mode' => 'daily', 'start' => $dates['start'], 'end' => $dates['end']];
            } elseif ($days <= 93) {
                return ['mode' => 'weekly', 'start' => $dates['start'], 'end' => $dates['end']];
            } else {
                return ['mode' => 'monthly', 'start' => $dates['start'], 'end' => $dates['end']];
            }
    }
}


// ========================
// HELPER: Build graph data points from a daily-value map
// Supports daily, weekly, and monthly bucketing.
//
// $dailyMap = ['2026-03-16' => 7600, ...] (values per day from DB)
// Returns ['points' => [...], 'total' => float, 'graph_range' => [...]]
// ========================
function buildGraphPoints($graphCfg, $dailyMap, $valueKey = 'revenue') {
    $mode  = $graphCfg['mode'];
    $start = $graphCfg['start'];
    $end   = $graphCfg['end'];

    $points = [];
    $total  = 0;

    if ($mode === 'daily') {
        // One point per day
        $filter = $_GET['period'] ?? 'today';
        $period = new DatePeriod(
            new DateTime($start),
            new DateInterval('P1D'),
            (new DateTime($end))->modify('+1 day')
        );
        foreach ($period as $dt) {
            $d = $dt->format('Y-m-d');
            $val = $dailyMap[$d] ?? 0;
            $total += $val;
            // Only 7days filter uses day names (Mon, Tue); all others use date (Mar 14)
            $label = ($filter === '7days') ? $dt->format('D') : $dt->format('M j');
            $points[] = [
                'date'     => $d,
                'label'    => $label,
                $valueKey  => $val,
            ];
        }
    } elseif ($mode === 'weekly') {
        // Group into weeks (Mon–Sun buckets within the date range)
        $startDt = new DateTime($start);
        $endDt   = new DateTime($end);
        $weekNum = 1;

        // Walk through the range week by week
        $cursor = clone $startDt;
        while ($cursor <= $endDt) {
            // Week bucket: from $cursor to next Sunday (or $endDt, whichever is earlier)
            $weekStart = clone $cursor;
            // Find end of this week (Sunday) or end of range
            $weekEnd = clone $cursor;
            // Move to Sunday
            if ($weekEnd->format('N') != 7) { // N = 1(Mon)..7(Sun)
                $weekEnd->modify('next Sunday');
            }
            if ($weekEnd > $endDt) {
                $weekEnd = clone $endDt;
            }

            // Sum daily values in this bucket
            $bucketVal = 0;
            $bucketPeriod = new DatePeriod(
                $weekStart,
                new DateInterval('P1D'),
                (clone $weekEnd)->modify('+1 day')
            );
            foreach ($bucketPeriod as $dt) {
                $bucketVal += ($dailyMap[$dt->format('Y-m-d')] ?? 0);
            }
            $total += $bucketVal;

            $label = $weekStart->format('M j') . ' - ' . $weekEnd->format('M j');
            $points[] = [
                'date'     => $weekStart->format('Y-m-d'),
                'end_date' => $weekEnd->format('Y-m-d'),
                'label'    => $label,
                'week'     => $weekNum,
                $valueKey  => $bucketVal,
            ];

            $weekNum++;
            $cursor = (clone $weekEnd)->modify('+1 day');
        }
    } elseif ($mode === 'monthly') {
        // Group into calendar months
        $startDt = new DateTime($start);
        $endDt   = new DateTime($end);

        $cursor = new DateTime($startDt->format('Y-m-01'));
        while ($cursor <= $endDt) {
            $monthStart = clone $cursor;
            // Month bucket: first day to last day of that month, capped by range
            if ($monthStart < $startDt) {
                $monthStart = clone $startDt;
            }
            $monthEnd = new DateTime($cursor->format('Y-m-t')); // last day of month
            if ($monthEnd > $endDt) {
                $monthEnd = clone $endDt;
            }

            // Sum daily values in this bucket
            $bucketVal = 0;
            $bucketPeriod = new DatePeriod(
                $monthStart,
                new DateInterval('P1D'),
                (clone $monthEnd)->modify('+1 day')
            );
            foreach ($bucketPeriod as $dt) {
                $bucketVal += ($dailyMap[$dt->format('Y-m-d')] ?? 0);
            }
            $total += $bucketVal;

            $points[] = [
                'date'      => $monthStart->format('Y-m-d'),
                'end_date'  => $monthEnd->format('Y-m-d'),
                'label'     => $cursor->format('M Y'),    // Jan 2026, Feb 2026, etc.
                'month'     => $cursor->format('M'),       // Jan, Feb, etc.
                $valueKey   => $bucketVal,
            ];

            $cursor->modify('+1 month');
        }
    }

    return [
        'points' => $points,
        'total'  => $total,
        'graph_range' => [
            'mode'  => $mode,
            'start' => $start,
            'end'   => $end,
        ],
    ];
}


// =========================================
// DASHBOARD: Status Cards (overview)
// =========================================
function getDashboardOverview() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $prev = getComparisonPeriod($dates['start'], $dates['end']);

    // ---- Current period stats ----
    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status IN ('pending','preparing','ready') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Previous period stats (for comparison %) ----
    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status IN ('pending','preparing','ready') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $previous = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // ---- Low stock count (always current, not date-filtered) ----
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS low_stock_count
        FROM vendor_inventory
        WHERE vendor_id = ? AND is_active = 1 AND quantity <= low_stock_level
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $totalOrders     = (int)$current['total_orders'];
    $pendingOrders   = (int)$current['pending_orders'];
    $completedOrders = (int)$current['completed_orders'];
    $cancelledOrders = (int)$current['cancelled_orders'];
    $revenue         = (float)$current['revenue'];
    $lowStockCount   = (int)$stockRow['low_stock_count'];

    ResponseHandler::success('Dashboard overview retrieved.', [
        'period' => [
            'filter' => $dates['filter'],
            'start'  => $dates['start'],
            'end'    => $dates['end'],
        ],
        'cards' => [
            'total_orders' => [
                'value'  => $totalOrders,
                'change' => pctChange($totalOrders, (int)$previous['total_orders']),
            ],
            'pending_orders' => [
                'value'  => $pendingOrders,
                'change' => pctChange($pendingOrders, (int)$previous['pending_orders']),
            ],
            'completed_orders' => [
                'value'  => $completedOrders,
                'change' => pctChange($completedOrders, (int)$previous['completed_orders']),
            ],
            'cancelled_orders' => [
                'value'  => $cancelledOrders,
            ],
            'revenue' => [
                'value'  => $revenue,
                'change' => pctChange($revenue, (float)$previous['revenue']),
            ],
            'low_stock_alert' => [
                'value' => $lowStockCount,
            ],
        ],
    ]);
}


// =========================================
// DASHBOARD: Weekly Sales Graph
// Returns daily revenue for the selected period
// =========================================
function getDashboardSalesGraph() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $prev = getComparisonPeriod($dates['start'], $dates['end']);
    $graphCfg = getGraphConfig($dates);

    // ---- Fetch daily revenue within graph range ----
    $stmt = mysqli_prepare($conn, "
        SELECT DATE(created_at) AS day, COALESCE(SUM(total_amount), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $graphCfg['start'], $graphCfg['end']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $dailyMap = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $dailyMap[$row['day']] = (float)$row['revenue'];
    }

    $graph = buildGraphPoints($graphCfg, $dailyMap, 'revenue');

    // ---- Previous period total (for % change) ----
    $stmt = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(total_amount), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $prevRevenue = (float)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['revenue'];

    ResponseHandler::success('Sales graph data retrieved.', [
        'period' => [
            'filter' => $dates['filter'],
            'start'  => $dates['start'],
            'end'    => $dates['end'],
        ],
        'graph_range'       => $graph['graph_range'],
        'total_revenue'     => $graph['total'],
        'previous_revenue'  => $prevRevenue,
        'change_percentage' => pctChange($graph['total'], $prevRevenue),
        'data'              => $graph['points'],
    ]);
}


// =========================================
// DASHBOARD: Order Volume Graph
// Returns daily order count for the selected period
// =========================================
function getDashboardOrderVolume() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $prev = getComparisonPeriod($dates['start'], $dates['end']);
    $graphCfg = getGraphConfig($dates);

    // ---- Fetch daily order count within graph range ----
    $stmt = mysqli_prepare($conn, "
        SELECT DATE(created_at) AS day, COUNT(*) AS order_count
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $graphCfg['start'], $graphCfg['end']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $dailyMap = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $dailyMap[$row['day']] = (int)$row['order_count'];
    }

    $graph = buildGraphPoints($graphCfg, $dailyMap, 'order_count');

    // ---- Previous period total ----
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS order_count
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $prevOrders = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['order_count'];

    ResponseHandler::success('Order volume data retrieved.', [
        'period' => [
            'filter' => $dates['filter'],
            'start'  => $dates['start'],
            'end'    => $dates['end'],
        ],
        'graph_range'       => $graph['graph_range'],
        'total_orders'      => $graph['total'],
        'previous_orders'   => $prevOrders,
        'change_percentage' => pctChange($graph['total'], $prevOrders),
        'data'              => $graph['points'],
    ]);
}


// =========================================
// DASHBOARD: Recent Orders (all statuses)
// =========================================
function getDashboardRecentOrders() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Optional status filter
    $validStatuses = ['draft', 'pending', 'preparing', 'ready', 'completed', 'cancelled'];
    $statusFilter  = isset($_GET['status']) && in_array($_GET['status'], $validStatuses) ? $_GET['status'] : null;

    // Build WHERE clause
    $where  = "WHERE o.vendor_id = ? AND o.archived = 0";
    $params = [$vendorId];
    $types  = "i";

    if ($statusFilter) {
        $where .= " AND o.status = ?";
        $params[] = $statusFilter;
        $types .= "s";
    }

    // Count
    $countStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM pos_orders o $where");
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
    mysqli_stmt_execute($countStmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total'];

    // Fetch orders with items summary
    $query = "
        SELECT o.id, o.uuid, o.order_number, o.customer_name, o.customer_phone,
               o.order_type, o.table_number, o.subtotal, o.discount_amount,
               o.tax_amount, o.total_amount, o.status, o.payment_status,
               pm.name AS payment_method_name,
               o.created_at
        FROM pos_orders o
        LEFT JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        $where
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $fetchTypes  = $types . "ii";
    $fetchParams = array_merge($params, [$limit, $offset]);

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $fetchTypes, ...$fetchParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $orders = [];
    while ($order = mysqli_fetch_assoc($result)) {
        $orderId = (int)$order['id'];

        // Fetch items for this order
        $itemStmt = mysqli_prepare($conn, "SELECT item_name, quantity, line_total FROM pos_order_items WHERE order_id = ?");
        mysqli_stmt_bind_param($itemStmt, "i", $orderId);
        mysqli_stmt_execute($itemStmt);
        $itemResult = mysqli_stmt_get_result($itemStmt);

        $items = [];
        while ($item = mysqli_fetch_assoc($itemResult)) {
            $items[] = [
                'item_name'  => $item['item_name'],
                'quantity'   => (int)$item['quantity'],
                'line_total' => (float)$item['line_total'],
            ];
        }

        $orders[] = [
            'id'                   => $orderId,
            'uuid'                 => $order['uuid'],
            'order_number'         => $order['order_number'],
            'customer_name'        => $order['customer_name'],
            'customer_phone'       => $order['customer_phone'],
            'order_type'           => $order['order_type'],
            'table_number'         => $order['table_number'],
            'subtotal'             => (float)$order['subtotal'],
            'discount_amount'      => (float)$order['discount_amount'],
            'tax_amount'           => (float)$order['tax_amount'],
            'total_amount'         => (float)$order['total_amount'],
            'status'               => $order['status'],
            'payment_status'       => $order['payment_status'],
            'payment_method_name'  => $order['payment_method_name'],
            'items'                => $items,
            'item_count'           => count($items),
            'created_at'           => $order['created_at'],
        ];
    }

    ResponseHandler::success('Recent orders retrieved.', [
        'orders'     => $orders,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ]);
}


// =========================================
// DASHBOARD: Low Stock Items
// =========================================
function getDashboardLowStock() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // Count
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS total
        FROM vendor_inventory
        WHERE vendor_id = ? AND is_active = 1 AND quantity <= low_stock_level
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $total = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch
    $stmt = mysqli_prepare($conn, "
        SELECT i.id, i.uuid, i.name, i.sku, i.unit, i.quantity, i.low_stock_level,
               i.cost_price, i.selling_price, i.image, i.supplier,
               c.name AS category_name
        FROM vendor_inventory i
        LEFT JOIN vendor_categories c ON i.category_id = c.id
        WHERE i.vendor_id = ? AND i.is_active = 1 AND i.quantity <= i.low_stock_level
        ORDER BY (i.quantity / GREATEST(i.low_stock_level, 1)) ASC
        LIMIT ? OFFSET ?
    ");
    mysqli_stmt_bind_param($stmt, "iii", $vendorId, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = [
            'id'              => (int)$row['id'],
            'uuid'            => $row['uuid'],
            'name'            => $row['name'],
            'sku'             => $row['sku'],
            'unit'            => $row['unit'],
            'quantity'        => (int)$row['quantity'],
            'low_stock_level' => (int)$row['low_stock_level'],
            'cost_price'      => (float)$row['cost_price'],
            'selling_price'   => (float)$row['selling_price'],
            'image'           => $row['image'],
            'supplier'        => $row['supplier'],
            'category_name'   => $row['category_name'],
        ];
    }

    ResponseHandler::success('Low stock items retrieved.', [
        'items'      => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ]);
}


// =========================================
// DASHBOARD: Top Selling Items
// =========================================
function getDashboardTopItems() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

    $stmt = mysqli_prepare($conn, "
        SELECT oi.item_name,
               COALESCE(oi.menu_item_id, 0) AS menu_item_id,
               SUM(oi.quantity) AS total_qty,
               SUM(oi.line_total) AS total_sales,
               COUNT(DISTINCT oi.order_id) AS order_count
        FROM pos_order_items oi
        JOIN pos_orders o ON oi.order_id = o.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'completed'
        GROUP BY oi.item_name, oi.menu_item_id
        ORDER BY total_qty DESC
        LIMIT ?
    ");
    mysqli_stmt_bind_param($stmt, "issi", $vendorId, $dates['start'], $dates['end'], $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = [
            'item_name'    => $row['item_name'],
            'menu_item_id' => (int)$row['menu_item_id'] ?: null,
            'total_qty'    => (int)$row['total_qty'],
            'total_sales'  => (float)$row['total_sales'],
            'order_count'  => (int)$row['order_count'],
        ];
    }

    ResponseHandler::success('Top selling items retrieved.', [
        'period' => [
            'filter' => $dates['filter'],
            'start'  => $dates['start'],
            'end'    => $dates['end'],
        ],
        'items' => $items,
    ]);
}


// =========================================
// DASHBOARD: Payment Breakdown
// =========================================
function getDashboardPaymentBreakdown() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $stmt = mysqli_prepare($conn, "
        SELECT pm.name, pm.slug,
               COUNT(*) AS order_count,
               SUM(o.total_amount) AS method_total
        FROM pos_orders o
        JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'completed'
        GROUP BY pm.id
        ORDER BY method_total DESC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $methods = [];
    $total = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $methodTotal = (float)$row['method_total'];
        $total += $methodTotal;
        $methods[] = [
            'name'        => $row['name'],
            'slug'        => $row['slug'],
            'order_count' => (int)$row['order_count'],
            'total'       => $methodTotal,
        ];
    }

    // Add percentage
    foreach ($methods as &$m) {
        $m['percentage'] = $total > 0 ? round(($m['total'] / $total) * 100, 1) : 0;
    }
    unset($m);

    ResponseHandler::success('Payment breakdown retrieved.', [
        'period' => [
            'filter' => $dates['filter'],
            'start'  => $dates['start'],
            'end'    => $dates['end'],
        ],
        'total'   => $total,
        'methods' => $methods,
    ]);
}


// =========================================
// DASHBOARD: Full Dashboard (single call)
// Combines overview cards + sales graph + order volume + recent orders + top items + low stock
// =========================================
function getDashboardFull() {
    global $conn;

    $auth = verifyDashboardVendor();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $dates = parseDateFilter();
    if (!$dates) return;

    $prev = getComparisonPeriod($dates['start'], $dates['end']);

    // ============ 1. STATUS CARDS ============

    // Current period
    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status IN ('pending','preparing','ready') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN discount_amount ELSE 0 END), 0) AS total_discounts,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status = 'completed' THEN total_amount ELSE 0 END), 0) AS collected_revenue,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND status NOT IN ('cancelled') THEN total_amount ELSE 0 END), 0) AS unpaid_amount
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Previous period
    $stmt = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status IN ('pending','preparing','ready') THEN 1 ELSE 0 END) AS pending_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $previous = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Low stock
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS low_stock_count
        FROM vendor_inventory
        WHERE vendor_id = ? AND is_active = 1 AND quantity <= low_stock_level
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $lowStock = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['low_stock_count'];

    $cards = [
        'total_orders' => [
            'value'  => (int)$current['total_orders'],
            'change' => pctChange((int)$current['total_orders'], (int)$previous['total_orders']),
        ],
        'pending_orders' => [
            'value'  => (int)$current['pending_orders'],
            'change' => pctChange((int)$current['pending_orders'], (int)$previous['pending_orders']),
        ],
        'completed_orders' => [
            'value'  => (int)$current['completed_orders'],
            'change' => pctChange((int)$current['completed_orders'], (int)$previous['completed_orders']),
        ],
        'cancelled_orders' => [
            'value'  => (int)$current['cancelled_orders'],
        ],
        'revenue' => [
            'value'  => (float)$current['revenue'],
            'change' => pctChange((float)$current['revenue'], (float)$previous['revenue']),
        ],
        'collected_revenue' => [
            'value' => (float)$current['collected_revenue'],
        ],
        'unpaid_amount' => [
            'value' => (float)$current['unpaid_amount'],
        ],
        'total_discounts' => [
            'value' => (float)$current['total_discounts'],
        ],
        'low_stock_alert' => [
            'value' => $lowStock,
        ],
    ];

    // ============ 2. SALES GRAPH ============

    $graphCfg = getGraphConfig($dates);

    $stmt = mysqli_prepare($conn, "
        SELECT DATE(created_at) AS day, COALESCE(SUM(total_amount), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $graphCfg['start'], $graphCfg['end']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $salesMap = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $salesMap[$row['day']] = (float)$row['revenue'];
    }

    $salesGraphData = buildGraphPoints($graphCfg, $salesMap, 'revenue');

    // Previous sales total for % change
    $stmt = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(total_amount), 0) AS revenue
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $prevSales = (float)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['revenue'];

    $salesGraph = [
        'graph_range'       => $salesGraphData['graph_range'],
        'total_revenue'     => $salesGraphData['total'],
        'previous_revenue'  => $prevSales,
        'change_percentage' => pctChange($salesGraphData['total'], $prevSales),
        'data'              => $salesGraphData['points'],
    ];

    // ============ 3. ORDER VOLUME GRAPH ============

    $stmt = mysqli_prepare($conn, "
        SELECT DATE(created_at) AS day, COUNT(*) AS order_count
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $graphCfg['start'], $graphCfg['end']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $volumeMap = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $volumeMap[$row['day']] = (int)$row['order_count'];
    }

    $volumeGraphData = buildGraphPoints($graphCfg, $volumeMap, 'order_count');

    // Previous volume
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS order_count
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status != 'cancelled'
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $prev['start'], $prev['end']);
    mysqli_stmt_execute($stmt);
    $prevVolume = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['order_count'];

    $orderVolume = [
        'graph_range'       => $volumeGraphData['graph_range'],
        'total_orders'      => $volumeGraphData['total'],
        'previous_orders'   => $prevVolume,
        'change_percentage' => pctChange($volumeGraphData['total'], $prevVolume),
        'data'              => $volumeGraphData['points'],
    ];

    // ============ 4. RECENT ORDERS ============

    $stmt = mysqli_prepare($conn, "
        SELECT o.id, o.uuid, o.order_number, o.customer_name, o.customer_phone,
               o.order_type, o.table_number, o.total_amount, o.status, o.payment_status,
               pm.name AS payment_method_name,
               o.created_at
        FROM pos_orders o
        LEFT JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.vendor_id = ? AND o.archived = 0
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, "i", $vendorId);
    mysqli_stmt_execute($stmt);
    $recentResult = mysqli_stmt_get_result($stmt);

    $recentOrders = [];
    while ($order = mysqli_fetch_assoc($recentResult)) {
        $orderId = (int)$order['id'];

        $itemStmt = mysqli_prepare($conn, "SELECT item_name, quantity, line_total FROM pos_order_items WHERE order_id = ?");
        mysqli_stmt_bind_param($itemStmt, "i", $orderId);
        mysqli_stmt_execute($itemStmt);
        $itemRes = mysqli_stmt_get_result($itemStmt);

        $items = [];
        while ($item = mysqli_fetch_assoc($itemRes)) {
            $items[] = [
                'item_name'  => $item['item_name'],
                'quantity'   => (int)$item['quantity'],
                'line_total' => (float)$item['line_total'],
            ];
        }

        $recentOrders[] = [
            'id'                  => $orderId,
            'uuid'                => $order['uuid'],
            'order_number'        => $order['order_number'],
            'customer_name'       => $order['customer_name'],
            'customer_phone'      => $order['customer_phone'],
            'order_type'          => $order['order_type'],
            'table_number'        => $order['table_number'],
            'total_amount'        => (float)$order['total_amount'],
            'status'              => $order['status'],
            'payment_status'      => $order['payment_status'],
            'payment_method_name' => $order['payment_method_name'],
            'items'               => $items,
            'item_count'          => count($items),
            'created_at'          => $order['created_at'],
        ];
    }

    // ============ 5. TOP SELLING ITEMS ============

    $stmt = mysqli_prepare($conn, "
        SELECT oi.item_name,
               COALESCE(oi.menu_item_id, 0) AS menu_item_id,
               SUM(oi.quantity) AS total_qty,
               SUM(oi.line_total) AS total_sales,
               COUNT(DISTINCT oi.order_id) AS order_count
        FROM pos_order_items oi
        JOIN pos_orders o ON oi.order_id = o.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'completed'
        GROUP BY oi.item_name, oi.menu_item_id
        ORDER BY total_qty DESC
        LIMIT 10
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $topResult = mysqli_stmt_get_result($stmt);

    $topItems = [];
    while ($row = mysqli_fetch_assoc($topResult)) {
        $topItems[] = [
            'item_name'    => $row['item_name'],
            'menu_item_id' => (int)$row['menu_item_id'] ?: null,
            'total_qty'    => (int)$row['total_qty'],
            'total_sales'  => (float)$row['total_sales'],
            'order_count'  => (int)$row['order_count'],
        ];
    }

    // ============ 6. PAYMENT METHOD BREAKDOWN ============

    $stmt = mysqli_prepare($conn, "
        SELECT pm.name, pm.slug,
               COUNT(*) AS order_count,
               SUM(o.total_amount) AS method_total
        FROM pos_orders o
        JOIN pos_payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.vendor_id = ? AND DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'completed'
        GROUP BY pm.id
        ORDER BY method_total DESC
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $pmResult = mysqli_stmt_get_result($stmt);

    $paymentMethods = [];
    $pmTotal = 0;
    while ($row = mysqli_fetch_assoc($pmResult)) {
        $methodTotal = (float)$row['method_total'];
        $pmTotal += $methodTotal;
        $paymentMethods[] = [
            'name'        => $row['name'],
            'slug'        => $row['slug'],
            'order_count' => (int)$row['order_count'],
            'total'       => $methodTotal,
        ];
    }
    foreach ($paymentMethods as &$m) {
        $m['percentage'] = $pmTotal > 0 ? round(($m['total'] / $pmTotal) * 100, 1) : 0;
    }
    unset($m);

    // ============ 7. ORDER TYPE BREAKDOWN ============

    $stmt = mysqli_prepare($conn, "
        SELECT order_type, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
        FROM pos_orders
        WHERE vendor_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status = 'completed'
        GROUP BY order_type
    ");
    mysqli_stmt_bind_param($stmt, "iss", $vendorId, $dates['start'], $dates['end']);
    mysqli_stmt_execute($stmt);
    $otResult = mysqli_stmt_get_result($stmt);

    $orderTypes = [];
    while ($row = mysqli_fetch_assoc($otResult)) {
        $orderTypes[] = [
            'order_type' => $row['order_type'],
            'count'      => (int)$row['count'],
            'total'      => (float)$row['total'],
        ];
    }

    // ============ RESPOND ============

    ResponseHandler::success('Dashboard data retrieved.', [
        'period' => [
            'filter'          => $dates['filter'],
            'start'           => $dates['start'],
            'end'             => $dates['end'],
            'comparison_start' => $prev['start'],
            'comparison_end'   => $prev['end'],
        ],
        'cards'              => $cards,
        'sales_graph'        => $salesGraph,
        'order_volume'       => $orderVolume,
        'recent_orders'      => $recentOrders,
        'top_selling_items'  => $topItems,
        'payment_breakdown'  => $paymentMethods,
        'order_type_breakdown' => $orderTypes,
    ]);
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getDashboardOverview':
        getDashboardOverview();
        break;
    case 'getDashboardSalesGraph':
        getDashboardSalesGraph();
        break;
    case 'getDashboardOrderVolume':
        getDashboardOrderVolume();
        break;
    case 'getDashboardRecentOrders':
        getDashboardRecentOrders();
        break;
    case 'getDashboardLowStock':
        getDashboardLowStock();
        break;
    case 'getDashboardTopItems':
        getDashboardTopItems();
        break;
    case 'getDashboardPaymentBreakdown':
        getDashboardPaymentBreakdown();
        break;
    case 'getDashboardFull':
        getDashboardFull();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
