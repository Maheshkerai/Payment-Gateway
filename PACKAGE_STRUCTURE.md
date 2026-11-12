# Package Structure

```
packages/mahesh-kerai/payment-gateways/
│
├── composer.json                    # Package composer configuration
├── LICENSE                          # MIT License
├── README.md                        # Main documentation
├── INSTALLATION.md                  # Installation guide
├── EXAMPLES.md                      # Usage examples
├── PACKAGE_STRUCTURE.md             # This file
├── .gitignore                       # Git ignore rules
│
├── config/
│   └── payment-gateways.php        # Package configuration
│
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_payment_gateways_table.php
│   │   └── 2024_01_01_000002_create_payment_transactions_table.php
│   └── seeders/
│       └── PaymentGatewaysSeeder.php
│
├── routes/
│   └── web.php                      # Webhook routes
│
└── src/
    ├── PaymentGatewaysServiceProvider.php  # Laravel service provider
    │
    ├── Contracts/
    │   └── PaymentGatewayInterface.php     # Gateway interface
    │
    ├── Gateways/
    │   ├── BaseGateway.php                 # Base gateway class
    │   ├── StripeGateway.php              # Stripe implementation
    │   ├── RazorpayGateway.php            # Razorpay implementation
    │   ├── FlutterwaveGateway.php         # Flutterwave implementation
    │   ├── PaystackGateway.php            # Paystack implementation
    │   └── JazzCashGateway.php            # JazzCash implementation
    │
    ├── Services/
    │   └── PaymentService.php             # Main payment service
    │
    ├── Models/
    │   ├── PaymentGateway.php             # Payment gateway model
    │   └── PaymentTransaction.php         # Payment transaction model
    │
    ├── Http/
    │   └── Controllers/
    │       └── WebhookController.php      # Webhook handler
    │
    └── Helpers/
        └── payment_helper.php             # Helper functions
```

## Key Components

### Service Provider
- `PaymentGatewaysServiceProvider.php` - Registers all services, routes, and migrations

### Core Services
- `PaymentService.php` - Main service with `onlinePaymentMethod()` unified interface

### Payment Gateways
- `BaseGateway.php` - Abstract base class for all gateways
- `StripeGateway.php` - Stripe payment integration
- `RazorpayGateway.php` - Razorpay payment integration
- `FlutterwaveGateway.php` - Flutterwave payment integration
- `PaystackGateway.php` - Paystack payment integration
- `JazzCashGateway.php` - JazzCash payment integration (Page Redirection, secure hash verified)

### Models
- `PaymentGateway` - Stores gateway configurations
- `PaymentTransaction` - Logs all payment transactions

### Helpers
- `onlinePaymentMethod()` - Unified payment initialization
- `verifyPayment()` - Payment verification

### Database
- `payment_gateways` table - Gateway configurations
- `payment_transactions` table - Transaction logs

## Database Schema

### payment_gateways
- id (bigint, primary key)
- name (string, unique)
- secret_key (text)
- publishable_key (text)
- webhook_secret (text)
- webhook_url (string)
- status (tinyint: 1=Active, 0=Inactive)
- created_at, updated_at

### payment_transactions
- id (bigint, primary key)
- user_id (bigint, nullable)
- payment_gateway_id (bigint, foreign key)
- amount (decimal 10,2)
- order_id (string, unique)
- payment_id (string, nullable)
- payment_signature (string, nullable)
- status (enum: pending, success, failed, refunded)
- created_at, updated_at

