<?php
// CORS Options handler
require_once __DIR__ . '/../../Config/global.php';

// Send success response for OPTIONS preflight
http_response_code(200);
echo json_encode(['message' => 'CORS preflight request handled']);
exit();
?>
