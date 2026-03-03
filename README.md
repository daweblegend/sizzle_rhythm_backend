# 🎵 Sizzle Rhythm Backend

A robust PHP-based backend API for the Sizzle Rhythm music platform, featuring multi-gateway payment processing, JWT authentication, and comprehensive music management capabilities.

## ✨ Features

### 🔐 Authentication & Security
- JWT-based authentication with role-based access control
- Google OAuth integration
- OTP-based verification system
- Password reset and account recovery
- Secure API endpoints with middleware protection

### 💰 Payment Processing
- **Multi-Gateway Support**: Seamlessly integrate with multiple payment providers
  - 🏦 Nomba
  - 💳 Paystack
  - 🔄 Monnify
  - ⚡ Flutterwave
- Dynamic gateway switching and configuration
- Automatic retry logic for failed transactions
- Comprehensive payment verification and webhook handling
- Real-time transaction status tracking

### 🎵 Music & Content Management
- User profile management
- Music upload and processing
- Sample management system
- File upload handling with validation

### 📧 Communication
- Email queue system with PHPMailer
- Push notifications via Pusher
- Real-time notifications
- Webhook event handling

### 🛠 Developer Experience
- Comprehensive Swagger/OpenAPI documentation
- Built-in testing utilities
- Payment reliability testing tools
- Detailed logging and debugging features

## 🚀 Quick Start

### Prerequisites
- PHP 8.0 or higher
- MySQL 8.0+
- Composer
- XAMPP/WAMP/LAMP stack

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/daweblegend/sizzle_rhythm_backend.git
   cd sizzle_rhythm_backend
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp Config/env.php.example Config/env.php
   ```
   
   Configure your environment variables:
   ```php
   // Database Configuration
   $_ENV['DB_HOST'] = 'localhost';
   $_ENV['DB_USER'] = 'your_db_user';
   $_ENV['DB_PASS'] = 'your_db_password';
   $_ENV['DB_NAME'] = 'sizzle_rhythm';
   
   // JWT Configuration
   $_ENV['JWT_SECRET'] = 'your-super-secret-jwt-key';
   
   // Payment Gateway APIs
   $_ENV['NOMBA_CLIENT_ID'] = 'your-nomba-client-id';
   $_ENV['NOMBA_CLIENT_SECRET'] = 'your-nomba-client-secret';
   $_ENV['PAYSTACK_SECRET_KEY'] = 'your-paystack-secret-key';
   // ... other gateway configurations
   ```

4. **Database setup**
   ```bash
   php setup.php
   ```
   
   This will:
   - Create the database schema
   - Set up payment gateway configurations
   - Insert default data

5. **Start the server**
   ```bash
   # For development
   php -S localhost:8000
   
   # Or use your preferred web server pointing to the project root
   ```

6. **Access the API**
   - API Base URL: `http://localhost:8000`
   - Documentation: `http://localhost:8000/docs`

## 📚 API Documentation

### Interactive Documentation
Access the complete Swagger/OpenAPI documentation at `/docs` endpoint:
```
http://your-domain.com/docs
```

### Core Endpoints

#### Authentication
```http
POST   /v1/auth/login           # User login
POST   /v1/auth/register        # User registration
POST   /v1/auth/refresh         # Refresh JWT token
POST   /v1/auth/google          # Google OAuth login
POST   /v1/auth/forgot-password # Password reset request
```

#### Payment Management
```http
POST   /v1/payment/initialize    # Initialize payment
GET    /v1/payment/verify        # Verify payment status
GET    /v1/payment/history       # Payment history
POST   /v1/payment/webhook       # Payment webhooks

# Admin endpoints (requires admin role)
GET    /v1/admin/payment/gateways          # List gateways
POST   /v1/admin/payment/gateways          # Create gateway
PUT    /v1/admin/payment/gateways/{id}     # Update gateway
GET    /v1/admin/payment/transactions      # All transactions
```

#### User Management
```http
GET    /v1/user/profile         # Get user profile
PUT    /v1/user/profile         # Update profile
POST   /v1/user/upload-avatar   # Upload profile picture
```

### Example Usage

#### Initialize a Payment
```bash
curl -X POST "http://localhost:8000/v1/payment/initialize" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "paystack",
    "amount": 5000.00,
    "currency": "NGN",
    "customer_email": "customer@example.com",
    "customer_name": "John Doe",
    "callback_url": "https://yourapp.com/callback",
    "metadata": {
      "order_id": "ORD123",
      "customer_id": "CUST456"
    }
  }'
```

#### Verify Payment
```bash
curl -X GET "http://localhost:8000/v1/payment/verify?reference=PAY_REFERENCE" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

## 🏗 Architecture

### Project Structure
```
sizzle_rhythm_backend/
├── 📁 Config/              # Configuration files
│   ├── database.php        # Database configuration
│   ├── env.php            # Environment variables
│   └── global.php         # Global settings
├── 📁 Database/           # Database schema and migrations
├── 📁 src/
│   ├── 📁 controllers/    # API Controllers
│   ├── 📁 services/       # Business logic services
│   │   ├── PaymentGatewayManager.php
│   │   └── 📁 gateways/   # Payment gateway implementations
│   └── 📁 webhooks/       # Webhook handlers
├── 📁 Middleware/         # Authentication and request middleware
├── 📁 Utils/             # Utility classes and helpers
├── 📁 docs/              # Swagger documentation assets
├── 📁 tests/             # Test files and utilities
├── Routes.php            # API route definitions
├── bootstrap.php         # Application bootstrap
└── index.php            # Entry point
```

### Payment Gateway Architecture

The payment system uses a flexible, provider-agnostic architecture:

```php
PaymentGatewayManager
├── NombaGateway
├── PaystackGateway
├── MonnifyGateway
└── FlutterwaveGateway
```

Each gateway implements the `BasePaymentGateway` interface, ensuring consistent behavior across all payment providers.

## 🔧 Configuration

### Payment Gateways

Configure payment gateways through the admin API or directly in the database:

```sql
-- Example: Configure Paystack
INSERT INTO payment_gateways (name, slug, is_active) VALUES ('Paystack', 'paystack', 1);

INSERT INTO payment_gateway_configs (gateway_id, user_id, environment, credentials) VALUES (
  1, 1, 'live', 
  JSON_OBJECT(
    'public_key', 'pk_live_xxxxx',
    'secret_key', 'sk_live_xxxxx',
    'webhook_secret', 'whsec_xxxxx'
  )
);
```

### Environment Variables

Key environment variables to configure:

```php
// Database
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'sizzle_rhythm';
$_ENV['DB_USER'] = 'username';
$_ENV['DB_PASS'] = 'password';

// Security
$_ENV['JWT_SECRET'] = 'your-jwt-secret-key';
$_ENV['ENCRYPTION_KEY'] = 'your-encryption-key';

// External Services
$_ENV['PUSHER_APP_ID'] = 'your-pusher-app-id';
$_ENV['PUSHER_KEY'] = 'your-pusher-key';
$_ENV['PUSHER_SECRET'] = 'your-pusher-secret';
$_ENV['PUSHER_CLUSTER'] = 'mt1';

// Google OAuth
$_ENV['GOOGLE_CLIENT_ID'] = 'your-google-client-id';
$_ENV['GOOGLE_CLIENT_SECRET'] = 'your-google-client-secret';

// Email Configuration
$_ENV['SMTP_HOST'] = 'smtp.gmail.com';
$_ENV['SMTP_USERNAME'] = 'your-email@gmail.com';
$_ENV['SMTP_PASSWORD'] = 'your-app-password';
```

## 🧪 Testing

### Payment Reliability Testing

Test payment gateway reliability:

```bash
php test_payment_reliability.php
```

This will:
- Run multiple payment initialization tests
- Test authentication reliability
- Generate detailed reliability reports
- Provide recommendations for improvements

### Gateway Reference Testing

Test specific payment verification:

```bash
php test_gateway_reference.php
```

### Manual Testing

Use the included Swagger documentation for interactive API testing:
1. Open `http://localhost:8000/docs`
2. Authenticate using the `/auth/login` endpoint
3. Use the JWT token for authorized endpoints

## 🚀 Deployment

### Production Checklist

- [ ] Set `$isLive = true` in gateway configurations
- [ ] Configure production database credentials
- [ ] Set up SSL certificates
- [ ] Configure production payment gateway credentials
- [ ] Set up proper error logging
- [ ] Configure backup strategies
- [ ] Set up monitoring and alerts
- [ ] Review and secure all API endpoints

### Web Server Configuration

#### Apache (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/sizzle_rhythm_backend;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 🤝 Contributing

### Development Setup

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Make your changes
4. Run tests: `php test_payment_reliability.php`
5. Commit your changes: `git commit -m 'Add amazing feature'`
6. Push to the branch: `git push origin feature/amazing-feature`
7. Open a Pull Request

### Coding Standards

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add PHPDoc comments for all public methods
- Include error handling and logging
- Write tests for new features

### Adding New Payment Gateways

To add a new payment gateway:

1. Create a new class extending `BasePaymentGateway`
2. Implement required methods: `initializePayment()`, `verifyPayment()`, `handleWebhook()`
3. Add the gateway to `PaymentGatewayManager::getGatewayInstance()`
4. Update the database schema if needed
5. Add Swagger documentation for new endpoints
6. Write tests for the new gateway

## 🐛 Troubleshooting

### Common Issues

**Payment verification fails intermittently**
- Check internet connectivity
- Verify gateway credentials
- Review gateway-specific logs
- Use the payment reliability tester

**JWT token expires quickly**
- Check JWT secret configuration
- Verify token expiration settings
- Ensure system time is synchronized

**Database connection issues**
- Verify database credentials in `Config/env.php`
- Check MySQL service status
- Ensure database exists and has proper permissions

### Debug Mode

Enable debug logging by setting:
```php
$_ENV['DEBUG'] = true;
```

Logs are stored in the `logs/` directory.

## 📄 License

This project is proprietary software. All rights reserved.

## 👥 Support

For support and questions:
- 📧 Email: support@sizzlerhythm.com
- 📱 Create an issue on GitHub
- 📖 Check the documentation at `/docs`

## 🎯 Roadmap

- [ ] Add more payment gateways (Stripe, Razorpay)
- [ ] Implement subscription management
- [ ] Add comprehensive analytics dashboard
- [ ] Mobile SDK for easier integration
- [ ] Advanced fraud detection
- [ ] Multi-currency support enhancement
- [ ] GraphQL API support

---

**Made with ❤️ by the Sizzle Rhythm Team**