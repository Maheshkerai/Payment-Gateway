<?php

// Router script for `php -S`: a tiny stand-in for the part of Razorpay's REST API the package calls
// when verifying a payment (GET /v1/payments/{id}), so receipt tests run without network access.

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

header('Content-Type: application/json');

if (preg_match('#^/v1/payments/(pay_[A-Za-z0-9]+)$#', $path, $matches)) {
    echo json_encode([
        'id' => $matches[1],
        'entity' => 'payment',
        'amount' => 10050,
        'currency' => 'INR',
        'status' => 'captured',
        'method' => 'upi',
    ]);

    return;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The requested URL was not found']]);
