<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Initialize a payment transaction.
     *
     * @param float $amount
     * @param string $currency
     * @param string $orderId
     * @param array $metadata
     * @return array
     */
    public function initializePayment(float $amount, string $currency, string $orderId, array $metadata = []): array;

    /**
     * Verify a payment transaction.
     *
     * @param array $data
     * @return array
     */
    public function verifyPayment(array $data): array;

    /**
     * Handle webhook callback.
     *
     * @param array $payload
     * @return array
     */
    public function handleWebhook(array $payload): array;

    /**
     * Get gateway configuration.
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Set gateway configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;
}

