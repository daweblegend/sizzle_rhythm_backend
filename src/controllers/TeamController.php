<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/MailHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// AVAILABLE PERMISSIONS
// ========================
// This serves as the single source of truth for all permission slugs.
// The frontend can fetch this list to render checkboxes when assigning permissions.

define('TEAM_PERMISSIONS', [
    'pos.create_order',
    'pos.edit_order',
    'pos.cancel_order',
    'pos.process_payment',
    'pos.apply_discount',
    'pos.view_orders',
    'pos.daily_summary',
    'menu.view',
    'menu.create',
    'menu.edit',
    'menu.delete',
    'inventory.view',
    'inventory.create',
    'inventory.edit',
    'inventory.adjust_stock',
    'categories.view',
    'categories.manage',
    'customers.view',
    'customers.manage',
    'store.view_profile',
    'store.edit_profile',
]);

define('TEAM_ROLES', ['manager', 'cashier', 'attendant', 'kitchen', 'inventory_clerk']);

// Default permission sets per role (used when vendor doesn't specify custom permissions)
define('DEFAULT_ROLE_PERMISSIONS', [
    'manager' => [
        'pos.create_order', 'pos.edit_order', 'pos.cancel_order', 'pos.process_payment',
        'pos.apply_discount', 'pos.view_orders', 'pos.daily_summary',
        'menu.view', 'menu.create', 'menu.edit', 'menu.delete',
        'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.adjust_stock',
        'categories.view', 'categories.manage',
        'customers.view', 'customers.manage',
        'store.view_profile', 'store.edit_profile',
    ],
    'cashier' => [
        'pos.create_order', 'pos.edit_order', 'pos.process_payment',
        'pos.apply_discount', 'pos.view_orders', 'pos.daily_summary',
        'menu.view', 'customers.view',
    ],
    'attendant' => [
        'pos.create_order', 'pos.edit_order', 'pos.view_orders',
        'pos.process_payment', 'menu.view', 'customers.view',
    ],
    'kitchen' => [
        'pos.view_orders', 'menu.view', 'inventory.view',
    ],
    'inventory_clerk' => [
        'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.adjust_stock',
        'categories.view', 'categories.manage',
    ],
]);


// ========================
// HELPER: Verify vendor owner (only owners can manage team)
// ========================
function verifyTeamOwner() {
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
        ResponseHandler::error('Access denied. Only vendor owners can manage teams.', null, 403);
        return null;
    }
    if (!$row['vendor_id']) {
        ResponseHandler::error('Vendor store profile not found. Please create your store profile first.', null, 404);
        return null;
    }

    return ['vendor_id' => (int)$row['vendor_id'], 'user_id' => (int)$row['user_id']];
}


// ========================
// HELPER: Format team member for response
// ========================
function formatTeamMember($row) {
    $row['id']         = (int)$row['id'];
    $row['vendor_id']  = (int)$row['vendor_id'];
    $row['user_id']    = (int)$row['user_id'];
    $row['invited_by'] = (int)$row['invited_by'];
    if (isset($row['permissions']) && is_string($row['permissions'])) {
        $row['permissions'] = json_decode($row['permissions'], true) ?? [];
    }
    return $row;
}


// =========================================
// GET: Available permissions & roles
// =========================================
function getAvailablePermissions() {
    $auth = verifyTeamOwner();
    if (!$auth) return;

    ResponseHandler::success('Available roles and permissions.', [
        'roles'       => TEAM_ROLES,
        'permissions'  => TEAM_PERMISSIONS,
        'role_defaults' => DEFAULT_ROLE_PERMISSIONS,
    ]);
}


// =========================================
// POST: Add team member
// Creates a user account (role=team_member) and links to vendor.
// If user already exists by email, reassigns them.
// =========================================
function addTeamMember() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];
    $ownerId  = $auth['user_id'];

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['first_name']) || empty($body['last_name']) || empty($body['email'])) {
        ResponseHandler::error('first_name, last_name, and email are required.', null, 400);
        return;
    }

    $firstName = UtilHandler::sanitizeInput($conn, $body['first_name']);
    $lastName  = UtilHandler::sanitizeInput($conn, $body['last_name']);
    $email     = UtilHandler::sanitizeInput($conn, strtolower(trim($body['email'])));
    $phone     = !empty($body['phone']) ? UtilHandler::sanitizeInput($conn, $body['phone']) : null;
    $teamRole  = !empty($body['team_role']) && in_array($body['team_role'], TEAM_ROLES) ? $body['team_role'] : 'attendant';

    // Permissions: use provided array, or fall back to role defaults
    $permissions = [];
    if (!empty($body['permissions']) && is_array($body['permissions'])) {
        // Validate each permission slug
        $permissions = array_values(array_intersect($body['permissions'], TEAM_PERMISSIONS));
    } else {
        $permissions = DEFAULT_ROLE_PERMISSIONS[$teamRole] ?? [];
    }

    if (!UtilHandler::isValidEmail($email)) {
        ResponseHandler::error('Invalid email address.', null, 400);
        return;
    }

    // Check if user already exists
    $stmt = mysqli_prepare($conn, "SELECT id, role FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $existingUser = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($existingUser) {
        $targetUserId = (int)$existingUser['id'];

        // Can't add the vendor owner themselves
        if ($targetUserId === $ownerId) {
            ResponseHandler::error('You cannot add yourself as a team member.', null, 400);
            return;
        }

        // If user is already a vendor or admin, can't reassign them
        if (in_array($existingUser['role'], ['vendor', 'admin'])) {
            ResponseHandler::error('This user is already a vendor or admin and cannot be added as a team member.', null, 400);
            return;
        }

        // If already a team_member, check if they belong to another vendor
        if ($existingUser['role'] === 'team_member') {
            $stmt = mysqli_prepare($conn, "SELECT vendor_id FROM vendor_team_members WHERE user_id = ? AND status IN ('invited', 'active')");
            mysqli_stmt_bind_param($stmt, "i", $targetUserId);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if ($existing && (int)$existing['vendor_id'] !== $vendorId) {
                ResponseHandler::error('This user is already a team member at another vendor.', null, 409);
                return;
            }
            if ($existing && (int)$existing['vendor_id'] === $vendorId) {
                ResponseHandler::error('This user is already on your team.', null, 409);
                return;
            }
        }

        // Update role to team_member
        $stmt = mysqli_prepare($conn, "UPDATE users SET role = 'team_member', updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $targetUserId);
        mysqli_stmt_execute($stmt);

    } else {
        // Create new user account
        $uuid         = Uuid::uuid4()->toString();
        $customPass   = !empty($body['password']) ? $body['password'] : null;
        $tempPass     = $customPass ?? bin2hex(random_bytes(8)); // use provided or generate 16-char temp
        $hashed       = password_hash($tempPass, PASSWORD_DEFAULT);
        $isCustomPass = $customPass !== null;

        $username = strtolower($firstName . '.' . $lastName . rand(10, 99));
        $username = preg_replace('/[^a-z0-9_.]/', '', $username);

        // Ensure username uniqueness
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

        $stmt = mysqli_prepare($conn, "INSERT INTO users (uuid, first_name, last_name, email, phone, password, username, role, email_verified, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'team_member', '1', 1, NOW(), NOW())");
        mysqli_stmt_bind_param($stmt, "sssssss", $uuid, $firstName, $lastName, $email, $phone, $hashed, $username);

        if (!mysqli_stmt_execute($stmt)) {
            ResponseHandler::error('Failed to create team member account.', ['db_error' => mysqli_error($conn)], 500);
            return;
        }

        $targetUserId = mysqli_insert_id($conn);

        // Send invite email with temp password
        try {
            $vendorStmt = mysqli_prepare($conn, "SELECT business_name FROM vendors WHERE id = ?");
            mysqli_stmt_bind_param($vendorStmt, "i", $vendorId);
            mysqli_stmt_execute($vendorStmt);
            $vendorRow = mysqli_fetch_assoc(mysqli_stmt_get_result($vendorStmt));
            $storeName = $vendorRow['business_name'] ?? 'a vendor';

            $subject = "You've been invited to join {$storeName} on Sizzle & Rhythm";
            $passLine = $isCustomPass
                ? "<li><strong>Password:</strong> Your password was set by your manager.</li>"
                : "<li><strong>Temporary Password:</strong> {$tempPass}</li>";
            $htmlBody = "
                <h2>Welcome to {$storeName}!</h2>
                <p>Hi {$firstName},</p>
                <p>You've been added as a <strong>{$teamRole}</strong> at <strong>{$storeName}</strong> on Sizzle & Rhythm.</p>
                <p>Here are your login credentials:</p>
                <ul>
                    <li><strong>Email:</strong> {$email}</li>
                    <li><strong>Username:</strong> {$username}</li>
                    {$passLine}
                </ul>
                <p>Please log in and change your password immediately.</p>
            ";
            MailHandler::sendTransactionalEmail($email, $subject, $htmlBody, false, 1);
        } catch (\Exception $e) {
            // Non-blocking — the account is still created
        }
    }

    // Insert into vendor_team_members
    $tmUuid        = Uuid::uuid4()->toString();
    $permJson      = json_encode($permissions);
    $statusDefault = $existingUser ? 'active' : 'invited';
    $joinedAt      = $existingUser ? date('Y-m-d H:i:s') : null;

    $stmt = mysqli_prepare($conn, "INSERT INTO vendor_team_members (uuid, vendor_id, user_id, team_role, permissions, invited_by, status, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "siississ",
        $tmUuid, $vendorId, $targetUserId, $teamRole, $permJson, $ownerId, $statusDefault, $joinedAt
    );

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to add team member.', ['db_error' => mysqli_error($conn)], 500);
        return;
    }

    // Fetch full record
    $memberId = mysqli_insert_id($conn);
    $stmt = mysqli_prepare($conn, "
        SELECT tm.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar
        FROM vendor_team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $memberId);
    mysqli_stmt_execute($stmt);
    $member = formatTeamMember(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)));

    ResponseHandler::success('Team member added successfully.', $member, 201);
}


// =========================================
// GET: List team members
// =========================================
function listTeamMembers() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $status = $_GET['status'] ?? null;
    $role   = $_GET['role'] ?? null;
    $search = $_GET['search'] ?? null;

    $query  = "SELECT tm.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar, u.is_active AS user_is_active FROM vendor_team_members tm JOIN users u ON u.id = tm.user_id WHERE tm.vendor_id = ?";
    $params = [$vendorId];
    $types  = "i";

    if ($status && in_array($status, ['invited', 'active', 'suspended', 'removed'])) {
        $query   .= " AND tm.status = ?";
        $params[] = $status;
        $types   .= "s";
    } else {
        // Default: hide removed members
        $query .= " AND tm.status != 'removed'";
    }

    if ($role && in_array($role, TEAM_ROLES)) {
        $query   .= " AND tm.team_role = ?";
        $params[] = $role;
        $types   .= "s";
    }

    if ($search) {
        $term     = '%' . UtilHandler::sanitizeInput($conn, $search) . '%';
        $query   .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types   .= "ssss";
    }

    $query .= " ORDER BY tm.created_at DESC";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result  = mysqli_stmt_get_result($stmt);
    $members = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $members[] = formatTeamMember($row);
    }

    ResponseHandler::success('Team members retrieved.', ['team_members' => $members, 'count' => count($members)]);
}


// =========================================
// GET: Get single team member
// =========================================
function getTeamMember() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$memberId) {
        ResponseHandler::error('id query parameter is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "
        SELECT tm.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar, u.is_active AS user_is_active
        FROM vendor_team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.id = ? AND tm.vendor_id = ?
    ");
    mysqli_stmt_bind_param($stmt, "ii", $memberId, $vendorId);
    mysqli_stmt_execute($stmt);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$member) {
        ResponseHandler::error('Team member not found.', null, 404);
        return;
    }

    ResponseHandler::success('Team member retrieved.', formatTeamMember($member));
}


// =========================================
// PUT: Update team member role & permissions
// =========================================
function updateTeamMember() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body     = json_decode(file_get_contents('php://input'), true);
    $memberId = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$memberId) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    // Verify member belongs to this vendor
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_team_members WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $memberId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Team member not found.', null, 404);
        return;
    }

    $updates = [];
    $params  = [];
    $types   = "";

    if (!empty($body['team_role']) && in_array($body['team_role'], TEAM_ROLES)) {
        $updates[] = "team_role = ?";
        $params[]  = $body['team_role'];
        $types    .= "s";
    }

    if (isset($body['permissions']) && is_array($body['permissions'])) {
        $validPerms = array_values(array_intersect($body['permissions'], TEAM_PERMISSIONS));
        $updates[]  = "permissions = ?";
        $params[]   = json_encode($validPerms);
        $types     .= "s";
    }

    if (empty($updates)) {
        ResponseHandler::error('Nothing to update. Provide team_role or permissions.', null, 400);
        return;
    }

    $params[] = $memberId;
    $types   .= "i";

    $sql  = "UPDATE vendor_team_members SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);

    // Fetch updated record
    $stmt = mysqli_prepare($conn, "
        SELECT tm.*, u.first_name, u.last_name, u.email, u.phone, u.username, u.avatar
        FROM vendor_team_members tm JOIN users u ON u.id = tm.user_id
        WHERE tm.id = ?
    ");
    mysqli_stmt_bind_param($stmt, "i", $memberId);
    mysqli_stmt_execute($stmt);
    $member = formatTeamMember(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)));

    ResponseHandler::success('Team member updated.', $member);
}


// =========================================
// PUT: Change team member status (activate, suspend, remove)
// =========================================
function updateTeamMemberStatus() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body     = json_decode(file_get_contents('php://input'), true);
    $memberId = isset($body['id']) ? (int)$body['id'] : 0;
    $status   = $body['status'] ?? '';

    if (!$memberId || !$status) {
        ResponseHandler::error('id and status are required.', null, 400);
        return;
    }

    $validStatuses = ['active', 'suspended', 'removed'];
    if (!in_array($status, $validStatuses)) {
        ResponseHandler::error('Invalid status. Allowed: active, suspended, removed.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_team_members WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $memberId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Team member not found.', null, 404);
        return;
    }

    $joinedAt = null;
    if ($status === 'active' && $existing['status'] === 'invited') {
        $joinedAt = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($conn, "UPDATE vendor_team_members SET status = ?, joined_at = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssi", $status, $joinedAt, $memberId);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE vendor_team_members SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $status, $memberId);
    }
    mysqli_stmt_execute($stmt);

    // If removed, revert user role back to customer
    if ($status === 'removed') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET role = 'customer', updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $existing['user_id']);
        mysqli_stmt_execute($stmt);
    }

    // If suspended, we keep role as team_member but the auth check blocks them (status != active)
    // If activated, ensure role is team_member
    if ($status === 'active') {
        $stmt = mysqli_prepare($conn, "UPDATE users SET role = 'team_member', updated_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $existing['user_id']);
        mysqli_stmt_execute($stmt);
    }

    ResponseHandler::success("Team member status updated to '{$status}'.", ['id' => $memberId, 'status' => $status]);
}


// =========================================
// DELETE: Remove team member completely
// =========================================
function removeTeamMember() {
    global $conn;

    $auth = verifyTeamOwner();
    if (!$auth) return;
    $vendorId = $auth['vendor_id'];

    $body     = json_decode(file_get_contents('php://input'), true);
    $memberId = isset($body['id']) ? (int)$body['id'] : 0;

    if (!$memberId) {
        ResponseHandler::error('id is required.', null, 400);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM vendor_team_members WHERE id = ? AND vendor_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $memberId, $vendorId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('Team member not found.', null, 404);
        return;
    }

    // Delete the team record
    $stmt = mysqli_prepare($conn, "DELETE FROM vendor_team_members WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $memberId);
    mysqli_stmt_execute($stmt);

    // Revert user role to customer
    $stmt = mysqli_prepare($conn, "UPDATE users SET role = 'customer', updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $existing['user_id']);
    mysqli_stmt_execute($stmt);

    ResponseHandler::success('Team member removed and user reverted to customer role.', ['id' => $memberId]);
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'getAvailablePermissions':
        getAvailablePermissions();
        break;
    case 'addTeamMember':
        addTeamMember();
        break;
    case 'listTeamMembers':
        listTeamMembers();
        break;
    case 'getTeamMember':
        getTeamMember();
        break;
    case 'updateTeamMember':
        updateTeamMember();
        break;
    case 'updateTeamMemberStatus':
        updateTeamMemberStatus();
        break;
    case 'removeTeamMember':
        removeTeamMember();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
