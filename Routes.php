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

    // -- Account Management --
    [
        'method' => 'POST',
        'url' => '/v1/admin/accounts/create',
        'handler' => 'createAccount',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'createAccount'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/accounts',
        'handler' => 'listUsers',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'listUsers'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/accounts/user',
        'handler' => 'getUser',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'getUser'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/admin/accounts/update',
        'handler' => 'updateAccount',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'updateAccount'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/accounts/reset-password',
        'handler' => 'resetUserPassword',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'resetUserPassword'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/accounts/toggle-status',
        'handler' => 'toggleAccountStatus',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'toggleAccountStatus'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/admin/test-email',
        'handler' => 'testEmail',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'testEmail'
    ],

    // -- Public: Account setup (user sets own password via emailed link) --
    [
        'method' => 'POST',
        'url' => '/v1/account/setup',
        'handler' => 'completeAccountSetup',
        'path' => '/src/controllers/AdminController.php',
        'action' => 'completeAccountSetup'
    ],

    // -- Legacy admin routes --
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

    // =====================
    // VENDOR STORE ROUTES
    // =====================

    // -- Authenticated Vendor Routes --
    [
        'method' => 'POST',
        'url' => '/v1/vendor/profile/create',
        'handler' => 'createVendorProfile',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'createVendorProfile'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/profile',
        'handler' => 'getVendorProfile',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'getVendorProfile'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/profile/update',
        'handler' => 'updateVendorProfile',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'updateVendorProfile'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/profile/logo',
        'handler' => 'uploadVendorLogo',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'uploadVendorLogo'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/profile/banner',
        'handler' => 'uploadVendorBanner',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'uploadVendorBanner'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/opening-hours',
        'handler' => 'updateOpeningHours',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'updateOpeningHours'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/store/toggle',
        'handler' => 'toggleStoreStatus',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'toggleStoreStatus'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/delivery-settings',
        'handler' => 'updateDeliverySettings',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'updateDeliverySettings'
    ],

    // -- Vendor Payment Gateway Config --
    [
        'method' => 'POST',
        'url' => '/v1/vendor/payment/gateway/configure',
        'handler' => 'configureVendorGateway',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'configureVendorGateway'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/payment/gateway/config',
        'handler' => 'getVendorGatewayConfig',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'getVendorGatewayConfig'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/payment/gateway/test',
        'handler' => 'testVendorGatewayConnection',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'testVendorGatewayConnection'
    ],

    // -- Public Vendor Routes --
    [
        'method' => 'GET',
        'url' => '/v1/vendors',
        'handler' => 'listVendors',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'listVendors'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendors/profile',
        'handler' => 'getVendorBySlug',
        'path' => '/src/controllers/VendorController.php',
        'action' => 'getVendorBySlug'
    ],

    // =====================
    // VENDOR CATEGORY ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/vendor/categories',
        'handler' => 'createCategory',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'createCategory'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/categories',
        'handler' => 'listCategories',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'listCategories'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/categories/single',
        'handler' => 'getCategory',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'getCategory'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/categories',
        'handler' => 'updateCategory',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'updateCategory'
    ],
    [
        'method' => 'DELETE',
        'url' => '/v1/vendor/categories',
        'handler' => 'deleteCategory',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'deleteCategory'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/categories/image',
        'handler' => 'uploadCategoryImage',
        'path' => '/src/controllers/CategoryController.php',
        'action' => 'uploadCategoryImage'
    ],

    // =====================
    // VENDOR INVENTORY ROUTES
    // =====================
    [
        'method' => 'POST',
        'url' => '/v1/vendor/inventory',
        'handler' => 'addInventoryItem',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'addInventoryItem'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/inventory',
        'handler' => 'listInventoryItems',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'listInventoryItems'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/inventory/single',
        'handler' => 'getInventoryItem',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'getInventoryItem'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/inventory',
        'handler' => 'updateInventoryItem',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'updateInventoryItem'
    ],
    [
        'method' => 'DELETE',
        'url' => '/v1/vendor/inventory',
        'handler' => 'deleteInventoryItem',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'deleteInventoryItem'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/inventory/adjust',
        'handler' => 'adjustStock',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'adjustStock'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/inventory/logs',
        'handler' => 'getStockLogs',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'getStockLogs'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/inventory/image',
        'handler' => 'uploadInventoryImage',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'uploadInventoryImage'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/inventory/summary',
        'handler' => 'getInventorySummary',
        'path' => '/src/controllers/InventoryController.php',
        'action' => 'getInventorySummary'
    ],

    // =====================
    // VENDOR MENU ROUTES
    // =====================

    // -- Authenticated (vendor manages own menu) --
    [
        'method' => 'POST',
        'url' => '/v1/vendor/menu',
        'handler' => 'createMenuItem',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'createMenuItem'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/menu',
        'handler' => 'listMenuItems',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'listMenuItems'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/menu/single',
        'handler' => 'getMenuItem',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'getMenuItem'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/menu',
        'handler' => 'updateMenuItem',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'updateMenuItem'
    ],
    [
        'method' => 'DELETE',
        'url' => '/v1/vendor/menu',
        'handler' => 'deleteMenuItem',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'deleteMenuItem'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/menu/toggle',
        'handler' => 'toggleMenuItemAvailability',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'toggleMenuItemAvailability'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/menu/image',
        'handler' => 'uploadMenuItemImage',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'uploadMenuItemImage'
    ],

    // -- Public (customers browse menu) --
    [
        'method' => 'GET',
        'url' => '/v1/vendors/menu',
        'handler' => 'getVendorMenu',
        'path' => '/src/controllers/MenuController.php',
        'action' => 'getVendorMenu'
    ],

    //*****************************************************
    // ********** POS PAYMENT METHODS (Admin) *************
    // ****************************************************
    [
        'method' => 'POST',
        'url' => '/v1/admin/pos/payment-methods',
        'handler' => 'createPaymentMethod',
        'path' => '/src/controllers/POSPaymentMethodController.php',
        'action' => 'createPaymentMethod'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/admin/pos/payment-methods',
        'handler' => 'listPaymentMethods',
        'path' => '/src/controllers/POSPaymentMethodController.php',
        'action' => 'listPaymentMethods'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/admin/pos/payment-methods',
        'handler' => 'updatePaymentMethod',
        'path' => '/src/controllers/POSPaymentMethodController.php',
        'action' => 'updatePaymentMethod'
    ],
    [
        'method' => 'DELETE',
        'url' => '/v1/admin/pos/payment-methods',
        'handler' => 'deletePaymentMethod',
        'path' => '/src/controllers/POSPaymentMethodController.php',
        'action' => 'deletePaymentMethod'
    ],

    //*****************************************************
    // ******** POS VENDOR PAYMENT METHODS ****************
    // ****************************************************
    [
        'method' => 'GET',
        'url' => '/v1/vendor/pos/payment-methods',
        'handler' => 'getVendorPaymentMethods',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'getVendorPaymentMethods'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/payment-methods/toggle',
        'handler' => 'toggleVendorPaymentMethod',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'toggleVendorPaymentMethod'
    ],

    //*****************************************************
    // **************** POS ORDERS ************************
    // ****************************************************
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/orders',
        'handler' => 'createOrder',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'createOrder'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/pos/orders',
        'handler' => 'listOrders',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'listOrders'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/pos/orders/single',
        'handler' => 'getOrder',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'getOrder'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/orders/items',
        'handler' => 'addOrderItems',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'addOrderItems'
    ],
    [
        'method' => 'DELETE',
        'url' => '/v1/vendor/pos/orders/items',
        'handler' => 'removeOrderItem',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'removeOrderItem'
    ],
    [
        'method' => 'PUT',
        'url' => '/v1/vendor/pos/orders/status',
        'handler' => 'updateOrderStatus',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'updateOrderStatus'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/orders/payment',
        'handler' => 'processOrderPayment',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'processOrderPayment'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/orders/discount',
        'handler' => 'applyOrderDiscount',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'applyOrderDiscount'
    ],
    [
        'method' => 'POST',
        'url' => '/v1/vendor/pos/orders/archive',
        'handler' => 'toggleOrderArchive',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'toggleOrderArchive'
    ],
    [
        'method' => 'GET',
        'url' => '/v1/vendor/pos/summary',
        'handler' => 'getDailySummary',
        'path' => '/src/controllers/POSOrderController.php',
        'action' => 'getDailySummary'
    ],
];