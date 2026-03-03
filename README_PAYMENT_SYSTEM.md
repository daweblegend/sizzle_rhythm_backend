# Sizzle & Rhythm - Multi-Gateway Payment System

A robust, scalable payment system supporting multiple payment gateways including Nomba, Paystack, Flutterwave, and Monnify.

## 🚀 Features

- **Multi-Gateway Support**: Nomba, Paystack, Flutterwave, Monnify
- **Flexible Configuration**: System-wide or user-specific gateway configurations
- **Webhook Handling**: Automatic webhook processing for all gateways
- **Transaction Management**: Complete transaction lifecycle tracking
- **Admin Dashboard**: Payment analytics and gateway management
- **Security**: Encrypted credentials and webhook signature verification
- **Extensible**: Easy to add new payment gateways

## 📋 Prerequisites

- PHP 8.0+
- MySQL 8.0+
- cURL extension enabled
- OpenSSL extension enabled

## ⚙️ Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/sizzle_rhythm_backend.git
   cd sizzle_rhythm_backend
   ```

2. **Configure database**
   Update `Config/database.php` with your database credentials:
   ```php
   $servername = 'localhost';
   $username = 'root';
   $password = '';
   $dbname = "sizzle_rhythm";
   ```

3. **Run setup script**
   ```bash
   php setup.php
   ```

4. **Configure payment gateways** (see Configuration section below)

## 🔧 Configuration

### Payment Gateway Configuration

Configure each gateway through the admin API endpoint:

#### Nomba Configuration
```bash
POST /v1/admin/payment/gateway/configure
```

```json
{
  "gateway": "nomba",
  "environment": "sandbox",
  "credentials": {
    "client_id": "your-client-id",
    "client_secret": "your-client-secret",
    "account_id": "your-account-id",
    "webhook_secret": "your-webhook-secret"
  },
  "settings": {
    "fee_percentage": 1.5,
    "capped_at": 2000
  }
}
```

#### Paystack Configuration
```json
{
  "gateway": "paystack",
  "environment": "sandbox",
  "credentials": {
    "public_key": "pk_test_...",
    "secret_key": "sk_test_...",
    "webhook_secret": "your-webhook-secret"
  }
}
```

#### Flutterwave Configuration
```json
{
  "gateway": "flutterwave",
  "environment": "sandbox",
  "credentials": {
    "public_key": "FLWPUBK_TEST-...",
    "secret_key": "FLWSECK_TEST-...",
    "encryption_key": "FLWSECK_TEST...",
    "webhook_secret": "your-webhook-hash"
  }
}
```

#### Monnify Configuration
```json
{
  "gateway": "monnify",
  "environment": "sandbox",
  "credentials": {
    "api_key": "your-api-key",
    "secret_key": "your-secret-key",
    "contract_code": "your-contract-code",
    "webhook_secret": "your-webhook-secret"
  }
}
```

### Test Gateway Connection
```bash
POST /v1/admin/payment/gateway/test
```

## 🔗 API Endpoints

### Public Endpoints

#### Get Available Gateways
```bash
GET /v1/payment/gateways
```

Response:
```json
{
  "status": "success",
  "data": {
    "gateways": [
      {
        "id": 1,
        "name": "Nomba",
        "slug": "nomba",
        "display_name": "Nomba Payment Gateway",
        "is_active": true,
        "is_default": true,
        "supported_currencies": ["NGN", "USD"],
        "supported_countries": ["NG"],
        "is_configured": true
      }
    ]
  }
}
```

#### Initialize Payment
```bash
POST /v1/payment/initialize
Authorization: Bearer <jwt-token>
```

```json
{
  "gateway": "nomba",
  "amount": 1000.00,
  "currency": "NGN",
  "customer_email": "customer@example.com",
  "customer_name": "John Doe",
  "description": "Payment for Order #123",
  "callback_url": "https://yourapp.com/payment/callback",
  "redirect_url": "https://yourapp.com/payment/success",
  "environment": "sandbox",
  "metadata": {
    "order_id": "123",
    "customer_id": "456"
  }
}
```

Response:
```json
{
  "status": "success",
  "message": "Payment initialized successfully",
  "data": {
    "status": "success",
    "gateway_reference": "SR_ABC123_1234567890",
    "payment_url": "https://checkout.nomba.com/...",
    "data": {
      "orderReference": "SR_ABC123_1234567890",
      "checkoutLink": "https://checkout.nomba.com/..."
    }
  }
}
```

#### Verify Payment
```bash
GET /v1/payment/verify?reference=SR_ABC123_1234567890
```

Response:
```json
{
  "status": "success",
  "message": "Payment verified successfully",
  "data": {
    "status": "success",
    "reference": "SR_ABC123_1234567890",
    "gateway_reference": "SR_ABC123_1234567890",
    "amount": 1000.00,
    "currency": "NGN",
    "payment_method": "card",
    "transaction_date": "2024-03-02T10:30:00Z"
  }
}
```

#### Get Payment History
```bash
GET /v1/payment/history?limit=20&offset=0&status=completed
Authorization: Bearer <jwt-token>
```

### Webhook Endpoints

Each gateway has its own webhook endpoint:
```bash
POST /v1/payment/webhook/nomba
POST /v1/payment/webhook/paystack
POST /v1/payment/webhook/flutterwave
POST /v1/payment/webhook/monnify
```

### Admin Endpoints

#### Get Payment Analytics
```bash
GET /v1/admin/payment/analytics?period=30
Authorization: Bearer <admin-jwt-token>
```

#### Get Gateway Configuration
```bash
GET /v1/admin/payment/gateway/config?gateway=nomba&environment=sandbox
Authorization: Bearer <admin-jwt-token>
```

## 🏗️ Database Schema

### Core Tables

1. **payment_gateways** - Available payment gateways
2. **payment_gateway_configs** - Gateway configurations and credentials
3. **payment_transactions** - Transaction records
4. **webhook_logs** - Webhook activity logs
5. **users** - User accounts

### Transaction Statuses

- `pending` - Transaction created but not yet processed
- `processing` - Payment is being processed by gateway
- `completed` - Payment successful
- `failed` - Payment failed
- `cancelled` - Payment cancelled by user
- `refunded` - Payment refunded

## 🔐 Security

- **Credential Encryption**: All gateway credentials are encrypted in the database
- **JWT Authentication**: API endpoints protected with JWT tokens
- **Webhook Verification**: Signature verification for all webhook payloads
- **Role-based Access**: Admin endpoints require admin privileges
- **Input Validation**: All inputs sanitized and validated

## 🧪 Testing

### Test Gateway Connection
```bash
curl -X POST http://localhost/sizzle_rhythm_backend/v1/admin/payment/gateway/test \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <admin-token>" \
  -d '{
    "gateway": "nomba",
    "environment": "sandbox",
    "credentials": {
      "client_id": "test-client-id",
      "client_secret": "test-client-secret",
      "account_id": "test-account-id"
    }
  }'
```

### Initialize Test Payment
```bash
curl -X POST http://localhost/sizzle_rhythm_backend/v1/payment/initialize \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <user-token>" \
  -d '{
    "gateway": "nomba",
    "amount": 100.00,
    "currency": "NGN",
    "customer_email": "test@example.com",
    "environment": "sandbox"
  }'
```

## 🛠️ Adding New Gateways

1. **Create Gateway Class**
   Create a new file in `src/services/gateways/YourGateway.php`:
   ```php
   class YourGateway extends BasePaymentGateway {
     // Implement required methods
   }
   ```

2. **Add to Database**
   Insert gateway info into `payment_gateways` table

3. **Update Routes**
   Add webhook route if needed

## 📊 Monitoring

- **Transaction Logs**: All transactions logged with detailed information
- **Webhook Logs**: All webhook activities tracked
- **Error Handling**: Comprehensive error logging and reporting
- **Analytics**: Built-in analytics for payment performance

## 🤝 Support

For support and questions:
- Create an issue in this repository
- Contact the development team
- Check the documentation in `/docs` folder

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.
