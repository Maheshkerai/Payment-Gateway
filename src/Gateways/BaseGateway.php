<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface;

abstract class BaseGateway implements PaymentGatewayInterface
{
    /**
     * Gateway configuration.
     *
     * @var array
     */
    protected array $config = [];

    /**
     * Get gateway configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set gateway configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Get configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}

