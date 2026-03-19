<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/FileUploader.php';
require_once APP_ROOT . '/src/services/PaymentGatewayManager.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify vendor role
// ========================
function verifyVendor() {
    global $conn;

    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return null;

    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);

    $stmt = mysqli_prepare($conn, "SELECT id, role FROM users WHERE id = ? AND is_active = 1");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        ResponseHandler::error('User not found or account is inactive.', null, 404);
        return null;
    }

    if ($user['role'] !== 'vendor') {
        ResponseHandler::error('Access denied. Vendor privileges required.', null, 403);
        return null;
    }

    return (int)$userId;
}

// ========================
// HELPER: Generate unique slug
// ========================
function generateVendorSlug($conn, $businessName) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $businessName), '-'));
    $slug = preg_replace('/-+/', '-', $slug);

    $baseSlug = $slug;
    $counter = 0;

    while (true) {
        $checkSlug = $counter === 0 ? $baseSlug : $baseSlug . '-' . $counter;
        $stmt = mysqli_prepare($conn, "SELECT id FROM vendors WHERE slug = ?");
        mysqli_stmt_bind_param($stmt, "s", $checkSlug);
        mysqli_stmt_execute($stmt);
        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
            return $checkSlug;
        }
        $counter++;
    }
}

// ========================
// HELPER: Get vendor by user ID
// ========================
function getVendorByUserId($userId) {
    global $conn;
    $stmt = mysqli_prepare($conn, "SELECT * FROM vendors WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

// ========================
// HELPER: Format vendor response
// ========================
function formatVendorResponse($vendor) {
    $jsonFields = ['cuisine_type', 'tags', 'opening_hours', 'currency'];
    foreach ($jsonFields as $field) {
        if (isset($vendor[$field]) && is_string($vendor[$field])) {
            $vendor[$field] = json_decode($vendor[$field], true);
        }
    }

    $vendor['minimum_order'] = $vendor['minimum_order'] !== null ? (float)$vendor['minimum_order'] : 0;
    $vendor['delivery_fee'] = $vendor['delivery_fee'] !== null ? (float)$vendor['delivery_fee'] : 0;
    $vendor['delivery_radius_km'] = $vendor['delivery_radius_km'] !== null ? (float)$vendor['delivery_radius_km'] : null;
    $vendor['average_rating'] = (float)$vendor['average_rating'];
    $vendor['total_orders'] = (int)$vendor['total_orders'];
    $vendor['total_reviews'] = (int)$vendor['total_reviews'];
    $vendor['is_open'] = (bool)$vendor['is_open'];
    $vendor['delivery_available'] = (bool)$vendor['delivery_available'];
    $vendor['pickup_available'] = (bool)$vendor['pickup_available'];
    $vendor['is_verified'] = (bool)$vendor['is_verified'];
    $vendor['is_active'] = (bool)$vendor['is_active'];

    return $vendor;
}


// =========================================
// VENDOR: Create / Setup Store Profile
// =========================================
function createVendorProfile() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $existing = getVendorByUserId($userId);
    if ($existing) {
        ResponseHandler::error('Vendor profile already exists. Use the update endpoint instead.');
        return;
    }

    // Support both JSON body and multipart/form-data (when logo/banner is included)
    $isMultipart = !empty($_POST) || !empty($_FILES);
    $body = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);

    if (empty($body['business_name'])) {
        ResponseHandler::error('business_name is required.');
        return;
    }

    $businessName = UtilHandler::sanitizeInput($conn, $body['business_name']);
    $slug = generateVendorSlug($conn, $businessName);
    $uuid = Uuid::uuid4()->toString();

    // Optional text fields
    $description  = !empty($body['description']) ? UtilHandler::sanitizeInput($conn, $body['description']) : null;
    $contactEmail = !empty($body['contact_email']) ? UtilHandler::sanitizeInput($conn, $body['contact_email']) : null;
    $contactPhone = !empty($body['contact_phone']) ? UtilHandler::sanitizeInput($conn, $body['contact_phone']) : null;
    $address      = !empty($body['address']) ? UtilHandler::sanitizeInput($conn, $body['address']) : null;
    $city         = !empty($body['city']) ? UtilHandler::sanitizeInput($conn, $body['city']) : null;
    $state        = !empty($body['state']) ? UtilHandler::sanitizeInput($conn, $body['state']) : null;
    $country      = !empty($body['country']) ? UtilHandler::sanitizeInput($conn, $body['country']) : 'Nigeria';
    $prepTime     = !empty($body['preparation_time']) ? UtilHandler::sanitizeInput($conn, $body['preparation_time']) : null;
    $estDelivery  = !empty($body['estimated_delivery_time']) ? UtilHandler::sanitizeInput($conn, $body['estimated_delivery_time']) : null;

    // JSON fields — accept array or JSON string (multipart sends strings)
    $cuisineTypeRaw = $body['cuisine_type'] ?? null;
    $tagsRaw        = $body['tags'] ?? null;
    $openingHrsRaw  = $body['opening_hours'] ?? null;
    $currencyRaw    = $body['currency'] ?? null;

    $cuisineType = $cuisineTypeRaw ? json_encode(is_array($cuisineTypeRaw) ? $cuisineTypeRaw : json_decode($cuisineTypeRaw, true)) : null;
    $tags        = $tagsRaw ? json_encode(is_array($tagsRaw) ? $tagsRaw : json_decode($tagsRaw, true)) : null;
    $openingHrs  = $openingHrsRaw ? json_encode(is_array($openingHrsRaw) ? $openingHrsRaw : json_decode($openingHrsRaw, true)) : null;
    $currency    = $currencyRaw ? json_encode(is_array($currencyRaw) ? $currencyRaw : json_decode($currencyRaw, true)) : json_encode(['code' => 'NGN', 'symbol' => '₦']);

    // Numeric fields
    $minimumOrder = isset($body['minimum_order']) && is_numeric($body['minimum_order']) ? (float)$body['minimum_order'] : 0;
    $deliveryFee  = isset($body['delivery_fee']) && is_numeric($body['delivery_fee']) ? (float)$body['delivery_fee'] : 0;
    $deliveryRadius = isset($body['delivery_radius_km']) && is_numeric($body['delivery_radius_km']) ? (float)$body['delivery_radius_km'] : null;

    // Booleans
    $deliveryAvailable = isset($body['delivery_available']) ? ($body['delivery_available'] ? 1 : 0) : 1;
    $pickupAvailable   = isset($body['pickup_available']) ? ($body['pickup_available'] ? 1 : 0) : 1;

    $query = "INSERT INTO vendors (
        uuid, user_id, business_name, slug, description,
        contact_email, contact_phone, address, city, state, country, currency,
        cuisine_type, tags, preparation_time, minimum_order,
        opening_hours, delivery_available, pickup_available,
        delivery_fee, delivery_radius_km, estimated_delivery_time,
        created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "sisssssssssssssdsiidds",
        $uuid, $userId, $businessName, $slug, $description,
        $contactEmail, $contactPhone, $address, $city, $state, $country, $currency,
        $cuisineType, $tags, $prepTime, $minimumOrder,
        $openingHrs, $deliveryAvailable, $pickupAvailable,
        $deliveryFee, $deliveryRadius, $estDelivery
    );

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to create vendor profile.', ['db_error' => mysqli_error($conn)], 500);
        return;
    }

    $vendorId = mysqli_insert_id($conn);
    $allowedImageMimes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];

    // Handle optional logo upload
    if (!empty($_FILES['logo'])) {
        $uploadDir = APP_ROOT . '/uploads/vendors/logos';
        $logoResult = FileUploader::upload($_FILES['logo'], $uploadDir, 'logo-' . $slug . '-', $allowedImageMimes, 2 * 1024 * 1024);

        if ($logoResult['success'] && !empty($logoResult['files'][0])) {
            $logoPath = '/' . $logoResult['files'][0];
            $logoStmt = mysqli_prepare($conn, "UPDATE vendors SET logo = ? WHERE id = ?");
            mysqli_stmt_bind_param($logoStmt, "si", $logoPath, $vendorId);
            mysqli_stmt_execute($logoStmt);
        }
    }

    // Handle optional banner upload
    if (!empty($_FILES['banner'])) {
        $uploadDir = APP_ROOT . '/uploads/vendors/banners';
        $bannerResult = FileUploader::upload($_FILES['banner'], $uploadDir, 'banner-' . $slug . '-', $allowedImageMimes, 5 * 1024 * 1024);

        if ($bannerResult['success'] && !empty($bannerResult['files'][0])) {
            $bannerPath = '/' . $bannerResult['files'][0];
            $bannerStmt = mysqli_prepare($conn, "UPDATE vendors SET banner_image = ? WHERE id = ?");
            mysqli_stmt_bind_param($bannerStmt, "si", $bannerPath, $vendorId);
            mysqli_stmt_execute($bannerStmt);
        }
    }

    $vendor = getVendorByUserId($userId);
    ResponseHandler::success('Vendor profile created successfully.', formatVendorResponse($vendor), 201);
}


// =========================================
// VENDOR: Get Own Profile
// =========================================
function getVendorProfile() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found. Please set up your store first.', null, 404);
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, username, email, phone, avatar FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $data = formatVendorResponse($vendor);
    $data['user'] = $user;

    ResponseHandler::success('Vendor profile retrieved successfully.', $data);
}


// =========================================
// VENDOR: Update Profile
// =========================================
function updateVendorProfile() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found. Please set up your store first.', null, 404);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body)) {
        ResponseHandler::error('No fields to update.');
        return;
    }

    $fields = [];
    $params = [];
    $types  = '';

    // Text fields
    $textFields = [
        'business_name', 'description', 'contact_email', 'contact_phone',
        'address', 'city', 'state', 'country',
        'preparation_time', 'estimated_delivery_time'
    ];
    foreach ($textFields as $field) {
        if (isset($body[$field])) {
            $fields[] = "$field = ?";
            $params[] = UtilHandler::sanitizeInput($conn, $body[$field]);
            $types .= 's';
        }
    }

    // Regenerate slug if business_name changed
    if (isset($body['business_name'])) {
        $newSlug = generateVendorSlug($conn, $body['business_name']);
        $fields[] = "slug = ?";
        $params[] = $newSlug;
        $types .= 's';
    }

    // Decimal fields
    $decimalFields = ['minimum_order', 'delivery_fee', 'delivery_radius_km'];
    foreach ($decimalFields as $field) {
        if (isset($body[$field])) {
            $fields[] = "$field = ?";
            $params[] = (float)$body[$field];
            $types .= 'd';
        }
    }

    // Boolean fields
    $boolFields = ['delivery_available', 'pickup_available', 'is_open'];
    foreach ($boolFields as $field) {
        if (isset($body[$field])) {
            $fields[] = "$field = ?";
            $params[] = $body[$field] ? 1 : 0;
            $types .= 'i';
        }
    }

    // JSON fields
    $jsonFields = ['cuisine_type', 'tags', 'opening_hours', 'currency'];
    foreach ($jsonFields as $field) {
        if (isset($body[$field])) {
            $fields[] = "$field = ?";
            $params[] = is_array($body[$field]) ? json_encode($body[$field]) : $body[$field];
            $types .= 's';
        }
    }

    // Coordinates
    if (isset($body['latitude'])) {
        $fields[] = "latitude = ?";
        $params[] = (float)$body['latitude'];
        $types .= 'd';
    }
    if (isset($body['longitude'])) {
        $fields[] = "longitude = ?";
        $params[] = (float)$body['longitude'];
        $types .= 'd';
    }

    if (empty($fields)) {
        ResponseHandler::error('No valid fields to update.');
        return;
    }

    $fields[] = "updated_at = NOW()";
    $query = "UPDATE vendors SET " . implode(', ', $fields) . " WHERE user_id = ?";
    $types .= 'i';
    $params[] = $userId;

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to update vendor profile.', null, 500);
        return;
    }

    $updated = getVendorByUserId($userId);
    ResponseHandler::success('Vendor profile updated successfully.', formatVendorResponse($updated));
}


// =========================================
// VENDOR: Upload Logo
// =========================================
function uploadVendorLogo() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found.', null, 404);
        return;
    }

    if (!isset($_FILES['logo'])) {
        ResponseHandler::error('No logo file provided. Use field name "logo".');
        return;
    }

    $uploadDir = APP_ROOT . '/uploads/vendors/logos';
    $result = FileUploader::upload(
        $_FILES['logo'],
        $uploadDir,
        'logo-' . $vendor['slug'] . '-',
        ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'],
        2 * 1024 * 1024 // 2MB
    );

    if (!$result['success']) {
        ResponseHandler::error('Failed to upload logo.', $result['errors']);
        return;
    }

    $uploadedFile = $result['files'][0] ?? null;
    if (!$uploadedFile) {
        ResponseHandler::error('Upload succeeded but file path is missing.', null, 500);
        return;
    }

    $relativePath = '/' . $uploadedFile;

    // Delete old logo
    if ($vendor['logo']) {
        $oldPath = APP_ROOT . $vendor['logo'];
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $stmt = mysqli_prepare($conn, "UPDATE vendors SET logo = ?, updated_at = NOW() WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $relativePath, $userId);
    mysqli_stmt_execute($stmt);

    ResponseHandler::success('Logo uploaded successfully.', ['logo' => $relativePath]);
}


// =========================================
// VENDOR: Upload Banner Image
// =========================================
function uploadVendorBanner() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found.', null, 404);
        return;
    }

    if (!isset($_FILES['banner'])) {
        ResponseHandler::error('No banner file provided. Use field name "banner".');
        return;
    }

    $uploadDir = APP_ROOT . '/uploads/vendors/banners';
    $result = FileUploader::upload(
        $_FILES['banner'],
        $uploadDir,
        'banner-' . $vendor['slug'] . '-',
        ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'],
        5 * 1024 * 1024 // 5MB
    );

    if (!$result['success']) {
        ResponseHandler::error('Failed to upload banner.', $result['errors']);
        return;
    }

    $uploadedFile = $result['files'][0] ?? null;
    if (!$uploadedFile) {
        ResponseHandler::error('Upload succeeded but file path is missing.', null, 500);
        return;
    }

    $relativePath = '/' . $uploadedFile;

    // Delete old banner
    if ($vendor['banner_image']) {
        $oldPath = APP_ROOT . $vendor['banner_image'];
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $stmt = mysqli_prepare($conn, "UPDATE vendors SET banner_image = ?, updated_at = NOW() WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $relativePath, $userId);
    mysqli_stmt_execute($stmt);

    ResponseHandler::success('Banner uploaded successfully.', ['banner_image' => $relativePath]);
}


// =========================================
// VENDOR: Update Opening Hours
// =========================================
function updateOpeningHours() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found.', null, 404);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['opening_hours']) || !is_array($body['opening_hours'])) {
        ResponseHandler::error('opening_hours (object with day keys) is required.');
        return;
    }

    $validDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    foreach (array_keys($body['opening_hours']) as $day) {
        if (!in_array(strtolower($day), $validDays)) {
            ResponseHandler::error("Invalid day key: '$day'. Use: " . implode(', ', $validDays));
            return;
        }
        $dayData = $body['opening_hours'][$day];
        if (!isset($dayData['open']) || !isset($dayData['close'])) {
            ResponseHandler::error("Day '$day' requires 'open' and 'close' times (e.g. \"08:00\", \"22:00\").");
            return;
        }
    }

    $hoursJson = json_encode($body['opening_hours']);

    $stmt = mysqli_prepare($conn, "UPDATE vendors SET opening_hours = ?, updated_at = NOW() WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $hoursJson, $userId);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to update opening hours.', null, 500);
        return;
    }

    ResponseHandler::success('Opening hours updated successfully.', [
        'opening_hours' => $body['opening_hours']
    ]);
}


// =========================================
// VENDOR: Toggle Open/Closed Status
// =========================================
function toggleStoreStatus() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found.', null, 404);
        return;
    }

    $newStatus = $vendor['is_open'] ? 0 : 1;

    $stmt = mysqli_prepare($conn, "UPDATE vendors SET is_open = ?, updated_at = NOW() WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newStatus, $userId);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to toggle store status.', null, 500);
        return;
    }

    $label = $newStatus ? 'open' : 'closed';
    ResponseHandler::success("Store is now $label.", ['is_open' => (bool)$newStatus]);
}


// =========================================
// VENDOR: Update Delivery Settings
// =========================================
function updateDeliverySettings() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    $vendor = getVendorByUserId($userId);
    if (!$vendor) {
        ResponseHandler::error('Vendor profile not found.', null, 404);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (empty($body)) {
        ResponseHandler::error('No fields to update.');
        return;
    }

    $fields = [];
    $params = [];
    $types  = '';

    if (isset($body['delivery_available'])) {
        $fields[] = "delivery_available = ?";
        $params[] = $body['delivery_available'] ? 1 : 0;
        $types .= 'i';
    }
    if (isset($body['pickup_available'])) {
        $fields[] = "pickup_available = ?";
        $params[] = $body['pickup_available'] ? 1 : 0;
        $types .= 'i';
    }
    if (isset($body['delivery_fee'])) {
        $fields[] = "delivery_fee = ?";
        $params[] = (float)$body['delivery_fee'];
        $types .= 'd';
    }
    if (isset($body['delivery_radius_km'])) {
        $fields[] = "delivery_radius_km = ?";
        $params[] = (float)$body['delivery_radius_km'];
        $types .= 'd';
    }
    if (isset($body['estimated_delivery_time'])) {
        $fields[] = "estimated_delivery_time = ?";
        $params[] = UtilHandler::sanitizeInput($conn, $body['estimated_delivery_time']);
        $types .= 's';
    }
    if (isset($body['minimum_order'])) {
        $fields[] = "minimum_order = ?";
        $params[] = (float)$body['minimum_order'];
        $types .= 'd';
    }

    if (empty($fields)) {
        ResponseHandler::error('No valid delivery fields to update.');
        return;
    }

    $fields[] = "updated_at = NOW()";
    $query = "UPDATE vendors SET " . implode(', ', $fields) . " WHERE user_id = ?";
    $types .= 'i';
    $params[] = $userId;

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to update delivery settings.', null, 500);
        return;
    }

    $updated = getVendorByUserId($userId);
    ResponseHandler::success('Delivery settings updated successfully.', [
        'delivery_available' => (bool)$updated['delivery_available'],
        'pickup_available' => (bool)$updated['pickup_available'],
        'delivery_fee' => (float)$updated['delivery_fee'],
        'delivery_radius_km' => $updated['delivery_radius_km'] !== null ? (float)$updated['delivery_radius_km'] : null,
        'estimated_delivery_time' => $updated['estimated_delivery_time'],
        'minimum_order' => (float)$updated['minimum_order']
    ]);
}


// =========================================
// PUBLIC: Get Vendor by Slug
// =========================================
function getVendorBySlug() {
    global $conn;

    $slug = !empty($_GET['slug']) ? UtilHandler::sanitizeInput($conn, $_GET['slug']) : null;

    if (!$slug) {
        ResponseHandler::error('slug query parameter is required.');
        return;
    }

    $stmt = mysqli_prepare($conn, "
        SELECT v.*, u.first_name, u.last_name, u.username, u.avatar
        FROM vendors v
        JOIN users u ON v.user_id = u.id
        WHERE v.slug = ? AND v.is_active = 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $slug);
    mysqli_stmt_execute($stmt);
    $vendor = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$vendor) {
        ResponseHandler::error('Vendor not found.', null, 404);
        return;
    }

    $user = [
        'first_name' => $vendor['first_name'],
        'last_name'  => $vendor['last_name'],
        'username'   => $vendor['username'],
        'avatar'     => $vendor['avatar'],
    ];
    unset($vendor['first_name'], $vendor['last_name'], $vendor['username'], $vendor['avatar']);
    unset($vendor['user_id'], $vendor['id']);

    $data = formatVendorResponse($vendor);
    $data['user'] = $user;

    ResponseHandler::success('Vendor profile retrieved.', $data);
}


// =========================================
// PUBLIC: List / Search Vendors
// =========================================
function listVendors() {
    global $conn;

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $offset  = ($page - 1) * $limit;
    $cuisine = !empty($_GET['cuisine']) ? UtilHandler::sanitizeInput($conn, $_GET['cuisine']) : null;
    $city    = !empty($_GET['city']) ? UtilHandler::sanitizeInput($conn, $_GET['city']) : null;
    $search  = !empty($_GET['search']) ? UtilHandler::sanitizeInput($conn, $_GET['search']) : null;
    $openNow = isset($_GET['open_now']) && $_GET['open_now'] === 'true';
    $delivery = isset($_GET['delivery']) && $_GET['delivery'] === 'true';
    $sortBy  = !empty($_GET['sort']) ? $_GET['sort'] : 'rating'; // rating | newest | delivery_fee

    $conditions = ["v.is_active = 1"];
    $params = [];
    $types  = '';

    if ($openNow) {
        $conditions[] = "v.is_open = 1";
    }
    if ($delivery) {
        $conditions[] = "v.delivery_available = 1";
    }
    if ($cuisine) {
        // Search within JSON array
        $conditions[] = "JSON_CONTAINS(v.cuisine_type, ?)";
        $params[] = json_encode($cuisine);
        $types .= 's';
    }
    if ($city) {
        $conditions[] = "v.city LIKE ?";
        $params[] = "%{$city}%";
        $types .= 's';
    }
    if ($search) {
        $conditions[] = "(v.business_name LIKE ? OR v.description LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $searchTerm = "%{$search}%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $types .= 'ssss';
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $orderBy = match ($sortBy) {
        'newest'       => 'v.created_at DESC',
        'delivery_fee' => 'v.delivery_fee ASC',
        default        => 'v.average_rating DESC, v.total_reviews DESC',
    };

    // Count
    $countQuery = "SELECT COUNT(*) as total FROM vendors v JOIN users u ON v.user_id = u.id $where";
    $stmt = mysqli_prepare($conn, $countQuery);
    if ($types) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $total = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch
    $dataQuery = "
        SELECT v.uuid, v.business_name, v.slug, v.description, v.logo, v.banner_image,
               v.city, v.state, v.cuisine_type, v.tags, v.preparation_time,
               v.minimum_order, v.is_open, v.delivery_available, v.pickup_available,
               v.delivery_fee, v.estimated_delivery_time,
               v.is_verified, v.average_rating, v.total_reviews, v.total_orders,
               u.first_name, u.last_name, u.username, u.avatar
        FROM vendors v
        JOIN users u ON v.user_id = u.id
        $where
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $dataTypes = $types . 'ii';
    $dataParams = array_merge($params, [$limit, $offset]);

    $stmt = mysqli_prepare($conn, $dataQuery);
    mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $vendors = [];
    while ($row = mysqli_fetch_assoc($result)) {
        foreach (['cuisine_type', 'tags'] as $jf) {
            if (isset($row[$jf]) && is_string($row[$jf])) {
                $row[$jf] = json_decode($row[$jf], true);
            }
        }
        $row['minimum_order'] = (float)$row['minimum_order'];
        $row['delivery_fee'] = (float)$row['delivery_fee'];
        $row['average_rating'] = (float)$row['average_rating'];
        $row['total_reviews'] = (int)$row['total_reviews'];
        $row['total_orders'] = (int)$row['total_orders'];
        $row['is_open'] = (bool)$row['is_open'];
        $row['delivery_available'] = (bool)$row['delivery_available'];
        $row['pickup_available'] = (bool)$row['pickup_available'];
        $row['is_verified'] = (bool)$row['is_verified'];

        $row['user'] = [
            'first_name' => $row['first_name'],
            'last_name'  => $row['last_name'],
            'username'   => $row['username'],
            'avatar'     => $row['avatar'],
        ];
        unset($row['first_name'], $row['last_name'], $row['username'], $row['avatar']);

        $vendors[] = $row;
    }

    ResponseHandler::success('Vendors retrieved successfully.', [
        'vendors' => $vendors,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => (int)$total,
            'total_pages' => (int)ceil($total / $limit)
        ]
    ]);
}


// ===========================================
// VENDOR PAYMENT GATEWAY CONFIGURATION
// ===========================================

/**
 * Configure Payment Gateway
 * Saves payment gateway credentials and settings for the vendor
 */
function configureVendorGateway() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $requiredFields = ['gateway', 'credentials', 'environment'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                ResponseHandler::error("Field '{$field}' is required", null, 400);
                return;
            }
        }

        $gateway     = strtolower($input['gateway']);
        $credentials = $input['credentials'];
        $environment = $input['environment'];
        $settings    = $input['settings'] ?? [];

        $gatewayManager = new PaymentGatewayManager();
        $success = $gatewayManager->saveGatewayConfig(
            $gateway,
            $credentials,
            $userId,          // auto-set from JWT — vendor can only configure for themselves
            $environment,
            $settings
        );

        if ($success) {
            ResponseHandler::success('Gateway configured successfully', [
                'gateway'     => $gateway,
                'environment' => $environment,
                'user_id'     => $userId
            ]);
        } else {
            ResponseHandler::error('Failed to save gateway configuration. Make sure the gateway is valid.', null, 500);
        }

    } catch (Exception $e) {
        ResponseHandler::error('Failed to configure gateway', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Gateway Configuration
 * Retrieves the vendor's payment gateway configuration (credentials are hidden)
 */
function getVendorGatewayConfig() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }

    try {
        $gateway     = $_GET['gateway'] ?? null;
        $environment = $_GET['environment'] ?? 'sandbox';

        if (empty($gateway)) {
            ResponseHandler::error('Gateway query parameter is required', null, 400);
            return;
        }

        $gatewayManager = new PaymentGatewayManager();
        $config = $gatewayManager->getGatewayConfig($gateway, $userId, $environment);

        if ($config) {
            // Strip sensitive credentials — return only key names so the vendor
            // knows which credentials are stored without exposing secrets.
            $credentialKeys = array_keys($config['credentials'] ?? []);
            unset($config['credentials']);
            $config['credential_keys'] = $credentialKeys;

            ResponseHandler::success('Gateway configuration retrieved', $config);
        } else {
            ResponseHandler::error('Gateway configuration not found', null, 404);
        }

    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve gateway configuration', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Test Gateway Connection
 * Tests the gateway connection with provided credentials without saving
 */
function testVendorGatewayConnection() {
    global $conn;

    $userId = verifyVendor();
    if (!$userId) return;

    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $requiredFields = ['gateway', 'credentials', 'environment'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                ResponseHandler::error("Field '{$field}' is required", null, 400);
                return;
            }
        }

        $gateway     = strtolower($input['gateway']);
        $credentials = $input['credentials'];
        $environment = $input['environment'];

        // Build a temporary config for the gateway class
        $tempConfig = [
            'gateway_id'  => 1,
            'slug'        => $gateway,
            'credentials' => $credentials,
            'environment' => $environment,
            'settings'    => []
        ];

        $gatewayClass = ucfirst($gateway) . 'Gateway';
        $gatewayFile  = __DIR__ . '/../services/gateways/' . $gatewayClass . '.php';

        if (!file_exists($gatewayFile)) {
            ResponseHandler::error('Gateway implementation not found', null, 404);
            return;
        }

        require_once $gatewayFile;

        if (!class_exists($gatewayClass)) {
            ResponseHandler::error('Gateway class not found', null, 404);
            return;
        }

        try {
            $gatewayInstance = new $gatewayClass($tempConfig);

            ResponseHandler::success('Gateway connection test successful', [
                'gateway'              => $gateway,
                'environment'          => $environment,
                'supported_methods'    => $gatewayInstance->getSupportedMethods(),
                'supported_currencies' => $gatewayInstance->getSupportedCurrencies()
            ]);

        } catch (Exception $e) {
            ResponseHandler::error('Gateway connection test failed: ' . $e->getMessage(), null, 400);
        }

    } catch (Exception $e) {
        ResponseHandler::error('Failed to test gateway connection', ['error' => $e->getMessage()], 500);
    }
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'createVendorProfile':
        createVendorProfile();
        break;
    case 'getVendorProfile':
        getVendorProfile();
        break;
    case 'updateVendorProfile':
        updateVendorProfile();
        break;
    case 'uploadVendorLogo':
        uploadVendorLogo();
        break;
    case 'uploadVendorBanner':
        uploadVendorBanner();
        break;
    case 'updateOpeningHours':
        updateOpeningHours();
        break;
    case 'toggleStoreStatus':
        toggleStoreStatus();
        break;
    case 'updateDeliverySettings':
        updateDeliverySettings();
        break;
    case 'getVendorBySlug':
        getVendorBySlug();
        break;
    case 'listVendors':
        listVendors();
        break;
    case 'configureVendorGateway':
        configureVendorGateway();
        break;
    case 'getVendorGatewayConfig':
        getVendorGatewayConfig();
        break;
    case 'testVendorGatewayConnection':
        testVendorGatewayConnection();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
