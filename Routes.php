<?php

return [
    // Handle all OPTIONS requests for CORS preflight
    [
        'method' => 'OPTIONS',
        'url' => '/v1/{path:.*}',
        'handler' => 'options',
        'path' => '/src/controllers/options.php',
        'action' => 'handleOptions'
    ],
    //*****************************************************
    // ******************** AUTH ROUTES *******************
    // ****************************************************
    [
        'method' => 'POST',
        'url' => '/v1/auth/register',
        'handler' => 'register',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'register'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/auth/login',
        'handler' => 'login',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'login'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/auth/send-otp',
        'handler' => 'sendOTP',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'sendOTP'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/auth/verify',
        'handler' => 'verifyAccount',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'verifyAccount'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/forgot-password/send-otp',
        'handler' => 'forgotPasswordSendOTP',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'forgotPasswordRequestOTP'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/forgot-password/verify-otp',
        'handler' => 'forgotPasswordVerifyOTP',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'forgotPasswordValidateOTP'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/forgot-password/update-password',
        'handler' => 'forgotPasswordUpdatePassword',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'forgotPasswordUpdatePassword'
    ],
    // Google OAuth routes
    [
        'method' => 'GET',
        'url' => '/v1/auth/google',
        'handler' => 'googleAuth',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'googleAuth'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/auth/google/callback',
        'handler' => 'googleCallback',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'googleCallback'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/auth/google/signin',
        'handler' => 'googleSignIn',
        'path' => '/src/controllers/AuthController.php',
        'action' => 'googleSignIn'
    ],
    // =====================
    // USER ACCOUNT ROUTES
    // =====================
    [
        'method' => 'GET',
        'url' => '/v1/user/profile',
        'handler' => 'getUserProfile',
        'path' => '/src/controllers/UserController.php',
        'action' => 'getUserProfile'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/user/profile/update',
        'handler' => 'updateUserProfile',
        'path' => '/src/controllers/UserController.php',
        'action' => 'updateUserProfile'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/user/change-password',
        'handler' => 'changeUserPassword',
        'path' => '/src/controllers/UserController.php',
        'action' => 'changeUserPassword'
    ],
    // =====================
    // PAYMENT ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/payment/initialize',
        'handler' => 'initializePayment',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'initializePayment'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/payment/verify',
        'handler' => 'verifyPayment',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'verifyPayment'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/payment/verify',
        'handler' => 'verifyPayment',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'verifyPayment'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/payment/webhook/{gateway}',
        'handler' => 'handleWebhook',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'handleWebhook'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/payment/history',
        'handler' => 'getPaymentHistory',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'getPaymentHistory'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/payment/gateways',
        'handler' => 'getPaymentGateways',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'getPaymentGateways'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/payment/callback',
        'handler' => 'handlePaymentCallback',
        'path' => '/src/controllers/PaymentController.php',
        'action' => 'handlePaymentCallback'
    ],

    // =====================
    // PAYMENT ADMIN ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/admin/payment/gateway/configure',
        'handler' => 'configureGateway',
        'path' => '/src/controllers/PaymentAdminController.php',
        'action' => 'configureGateway'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/payment/gateway/config',
        'handler' => 'getGatewayConfig',
        'path' => '/src/controllers/PaymentAdminController.php',
        'action' => 'getGatewayConfig'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/payment/gateway/test',
        'handler' => 'testGatewayConnection',
        'path' => '/src/controllers/PaymentAdminController.php',
        'action' => 'testGatewayConnection'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/payment/analytics',
        'handler' => 'getPaymentAnalytics',
        'path' => '/src/controllers/PaymentAdminController.php',
        'action' => 'getPaymentAnalytics'
    ],
    
    // =====================
    // VTU SERVICE ROUTES (New Service Provider System)
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/purchase-airtime',
        'handler' => 'purchaseAirtime',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'purchaseAirtime'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/purchase-data',
        'handler' => 'purchaseData',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'purchaseData'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/purchase-cable-tv',
        'handler' => 'purchaseCableTV',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'purchaseCableTV'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/purchase-electricity',
        'handler' => 'purchaseElectricity',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'purchaseElectricity'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/validate-meter',
        'handler' => 'validateMeterNumber',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'validateMeterNumber'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vtu-services/data-plans',
        'handler' => 'getDataPlans',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'getDataPlans'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vtu-services/electricity-discos',
        'handler' => 'getElectricityDiscos',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'getElectricityDiscos'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vtu-services/services',
        'handler' => 'getAvailableServices',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'getAvailableServices'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vtu-services/providers',
        'handler' => 'getActiveProviders',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'getActiveProviders'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vtu-services/test-network-mapping',
        'handler' => 'testNetworkMapping',
        'path' => '/src/controllers/VTUServicesController.php',
        'action' => 'testNetworkMapping'
    ],

    // =====================
    // PLAN MANAGEMENT ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/admin/plans/sync',
        'handler' => 'syncPlans',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'syncPlans'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/plans',
        'handler' => 'getPlans',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'getPlans'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/plans/active',
        'handler' => 'getActivePlans',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'getActivePlans'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/admin/plans/update',
        'handler' => 'updatePlan',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'updatePlan'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/admin/plans/bulk-update',
        'handler' => 'bulkUpdatePlans',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'bulkUpdatePlans'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/plans/stats',
        'handler' => 'getPlanStats',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'getPlanStats'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/plans/add',
        'handler' => 'addPlan',
        'path' => '/src/controllers/PlanController.php',
        'action' => 'addPlan'
    ],

    // =====================
    // ADMIN ROUTES
    // =====================
    [
        'method' => 'GET',
        'url' => '/v1/admin/data-plans',
        'handler' => 'getAllDataPlans',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'getAllDataPlans'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/select-data-plans',
        'handler' => 'selectDataPlans',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'selectDataPlans'
    ],
    // =====================
    // EMAIL QUEUE ADMIN ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/admin/email-queue/process',
        'handler' => 'processEmailQueue',
        'path' => '/src/controllers/EmailQueueController.php',
        'action' => 'processEmailQueue'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/email-queue/stats',
        'handler' => 'getQueueStats',
        'path' => '/src/controllers/EmailQueueController.php',
        'action' => 'getQueueStats'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/email-queue/retry',
        'handler' => 'retryFailedEmails',
        'path' => '/src/controllers/EmailQueueController.php',
        'action' => 'retryFailedEmails'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/email-queue/test',
        'handler' => 'queueTestEmail',
        'path' => '/src/controllers/EmailQueueController.php',
        'action' => 'queueTestEmail'
    ],
];