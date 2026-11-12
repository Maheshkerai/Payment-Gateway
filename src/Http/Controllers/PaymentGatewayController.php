<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ViewErrorBag;

final class PaymentGatewayController
{
    /**
     * Display the payment gateway configuration form.
     * Loads the first gateway by default, or a specific gateway if requested.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        $supportedGateways = [
            'stripe' => 'Stripe',
            'razorpay' => 'Razorpay',
            'flutterwave' => 'Flutterwave',
            'paystack' => 'Paystack',
            'paypal' => 'PayPal',
            'midtrans' => 'Midtrans',
            'phonepe' => 'PhonePe',
            'jazzcash' => 'JazzCash',
        ];
        
        // Get gateway configurations from config file
        $gatewayConfigs = config('payment-gateways.gateways', []);
        
        // Get all existing gateways from database for pre-filling
        $existingGateways = PaymentGateway::all()->keyBy('name')->map(function ($g) {
            return $g->toArray();
        })->toArray();
        
        // Get the gateway to display (from query parameter or first available)
        $gatewayName = $request->query('gateway');
        if ($gatewayName && isset($existingGateways[$gatewayName])) {
            $gateway = PaymentGateway::where('name', $gatewayName)->first();
        } else {
            // Load first gateway from database, or create a placeholder
            $gateway = PaymentGateway::first();
            // if (!$gateway) {
            //     // If no gateways exist, create a placeholder for the first config gateway
            //     $firstConfigGateway = array_key_first($gatewayConfigs);
            //     $gateway = new PaymentGateway();
            //     $gateway->name = $firstConfigGateway ?? 'stripe';
            // }
        }
        
        // Ensure $errors is always available
        $errors = session()->get('errors') ?: new ViewErrorBag();
        
        return view('payment-gateways::form', compact('gateway', 'supportedGateways', 'gatewayConfigs', 'existingGateways', 'errors'));
    }

    /**
     * Update the specified payment gateway in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $name
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, string $name): RedirectResponse
    {
        $gateway = PaymentGateway::where('name', $name)->firstOrFail();
        
        // Validate request - name should match the route parameter
        $validator = $this->validateRequest($request, $gateway->id);
        
        if ($validator->fails()) {
            return redirect()
                ->route('payment-gateways.index', ['gateway' => $name])
                ->withErrors($validator)
                ->withInput();
        }
        
        // Ensure the name in request matches the route parameter
        $request->merge(['name' => $name]);

        // Get all fields, including empty ones
        $data = [
            'name' => $request->input('name'),
            'secret_key' => $request->input('secret_key'),
            'publishable_key' => $request->input('publishable_key'),
            'webhook_secret' => $request->input('webhook_secret'),
            'webhook_url' => $request->input('webhook_url'),
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'status' => (int) ($request->input('status') ?? 0),
        ];
        
        // Log the data being updated for debugging
        Log::info('Updating payment gateway', [
            'name' => $name,
            'gateway_id' => $gateway->id,
            'data' => $data,
            'request_all' => $request->all()
        ]);

        $updated = $gateway->update($data);
        
        Log::info('Update result', [
            'updated' => $updated,
            'gateway_after' => $gateway->fresh()->toArray()
        ]);

        return redirect()
            ->route('payment-gateways.index', ['gateway' => $name])
            ->with('success', 'Payment gateway updated successfully.');
    }

    /**
     * Validate the request data.
     *
     * @param \Illuminate\Http\Request $request
     * @param int|null $id
     * @return \Illuminate\Contracts\Validation\Validator
     */
    private function validateRequest(Request $request, ?int $id = null)
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', 'exists:payment_gateways,name'],
            'secret_key' => ['nullable', 'string'],
            'publishable_key' => ['nullable', 'string'],
            'webhook_secret' => ['nullable', 'string'],
            'webhook_url' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'integer', 'in:0,1'],
            'mode' => ['nullable', 'string'],
            'client_version' => ['nullable', 'string'],
        ];

        $validator = Validator::make($request->all(), $rules);
        
        // Log validation errors for debugging
        if ($validator->fails()) {
            Log::warning('Payment gateway validation failed', [
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all()
            ]);
        }

        return $validator;
    }
}

