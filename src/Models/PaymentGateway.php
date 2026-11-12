<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

final class PaymentGateway extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'payment_gateways';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'secret_key',
        'publishable_key',
        'webhook_secret',
        'webhook_url',
        'username',
        'password',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Get the payment transactions for this gateway.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Get the webhook URL for the payment gateway.
     *
     * @return string
     */
    public function getWebhookUrlAttribute()
    {
        return url('payment-gateways/webhooks/' . $this->name);
    }
}

