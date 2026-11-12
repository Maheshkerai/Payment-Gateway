@extends('payment-gateways::layout')

@section('title')
Payment Gateway
@endsection

@section('wizard-steps')
<div class="gateway-sidebar-header">
    <h4 class="mb-0">Payment Gateways</h4>
    <p class="text-muted small mb-3">Select a gateway to configure</p>
</div>
@foreach($gatewayConfigs as $key => $config)
    <div class="wizard-step gateway-item {{ ($gateway->name ?? '') == $key ? 'active' : '' }}" 
         data-gateway="{{ $key }}"
         data-gateway-label="{{ $supportedGateways[$key] ?? ucfirst($key) }}">
        <div class="step-number">{{ $loop->iteration }}</div>
        <div class="step-content">
            <div class="step-title">{{ $supportedGateways[$key] ?? ucfirst($key) }}</div>
            <p class="step-description">Click to configure</p>
        </div>
    </div>
@endforeach
@endsection

@section('content')
<form method="POST" 
      action="{{ $gateway->name ? route('payment-gateways.update', $gateway->name) : '#' }}"
      id="paymentGatewayForm">
    @csrf
    @method('PUT')

    <!-- Hidden field for gateway name (required for identification) -->
    <input type="hidden" name="name" id="gateway_name" value="{{ $gateway->name ?? '' }}" required readonly>
    <!-- Hidden field to store the gateway ID -->
    <input type="hidden" id="gateway_id" value="{{ $gateway->id ?? '' }}">

    <!-- Dynamic Fields Container -->
    <div id="gatewayFieldsContainer">
        <!-- Fields will be loaded dynamically via JavaScript -->
    </div>

    <!-- Action Buttons -->
    <div class="wizard-actions" id="formActions">
        <div class="ml-auto">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save"></i> Submit
            </button>
        </div>
    </div>
</form>
@endsection

@section('script')
<script>
    const gatewayConfigs = @json($gatewayConfigs);
    const supportedGateways = @json($supportedGateways);
    const currentGateway = @json($gateway->name ?? '');
    const gatewayData = @json($gateway && method_exists($gateway, 'toArray') ? $gateway->toArray() : ['name' => $gateway->name ?? '']);
    const existingGateways = @json($existingGateways ?? []);
    const oldInput = @json(old());
    
    // Base URL for form actions
    const baseUrl = '{{ url("/payment-gateways/gateways") }}';

    $(document).ready(function() {
        // Check URL query parameter first (for redirects after form submission)
        const urlParams = new URLSearchParams(window.location.search);
        const gatewayFromUrl = urlParams.get('gateway');
        
        // Determine which gateway to load: URL param > hidden field > first gateway
        let gatewayName = gatewayFromUrl || $('#gateway_name').val();
        
        if (gatewayName) {
            // Update hidden field if it came from URL
            if (gatewayFromUrl && gatewayFromUrl !== $('#gateway_name').val()) {
                $('#gateway_name').val(gatewayFromUrl);
                // Update form action
                const form = $('#paymentGatewayForm');
                form.attr('action', baseUrl + '/' + gatewayFromUrl);
            }
            
            // Ensure the correct gateway is selected in sidebar
            $('.gateway-item[data-gateway="' + gatewayName + '"]').addClass('active');
            loadGatewayFields(gatewayName);
        } else {
            // Fallback: load first gateway if name is not set
            const firstGateway = Object.keys(gatewayConfigs)[0];
            if (firstGateway) {
                selectGateway(firstGateway);
            }
        }

        // Gateway selection
        $('.gateway-item').on('click', function() {
            const gatewayKey = $(this).data('gateway');
            selectGateway(gatewayKey);
        });

        function selectGateway(gatewayKey) {
            // Update active state
            $('.gateway-item').removeClass('active');
            $('.gateway-item[data-gateway="' + gatewayKey + '"]').addClass('active');
            
            // Update form action to point to the correct gateway
            const form = $('#paymentGatewayForm');
            const newAction = baseUrl + '/' + gatewayKey;
            form.attr('action', newAction);
            
            // Update hidden name field - this is the key identifier for the update
            $('#gateway_name').val(gatewayKey);
            
            // Update URL without page reload (optional - for better UX)
            if (window.history && window.history.pushState) {
                const newUrl = window.location.pathname + '?gateway=' + gatewayKey;
                window.history.pushState({path: newUrl}, '', newUrl);
            }
            
            // Load fields
            loadGatewayFields(gatewayKey);
        }

        // Helper function to get field value (prioritize old input, then existing gateway data)
        function getFieldValue(fieldName, gatewayKey) {
            // Priority: old input > existing gateway data from database > empty
            if (oldInput && oldInput[fieldName] !== undefined && oldInput[fieldName] !== null && oldInput[fieldName] !== '') {
                return oldInput[fieldName];
            }
            
            // Check if this gateway exists in the database
            if (existingGateways && existingGateways[gatewayKey]) {
                const existingData = existingGateways[gatewayKey];
                if (existingData[fieldName] !== undefined && existingData[fieldName] !== null && existingData[fieldName] !== '') {
                    return existingData[fieldName];
                }
            }
            
            // If editing and this is the current gateway, use gatewayData
            if (gatewayData && gatewayData.name === gatewayKey) {
                if (gatewayData[fieldName] !== undefined && gatewayData[fieldName] !== null && gatewayData[fieldName] !== '') {
                    return gatewayData[fieldName];
                }
            }
            
            return '';
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        function loadGatewayFields(gatewayKey) {
            const config = gatewayConfigs[gatewayKey] || {};
            const gatewayLabel = supportedGateways[gatewayKey] || gatewayKey.charAt(0).toUpperCase() + gatewayKey.slice(1);
            
            // Check if this is the current gateway being edited
            const isCurrentGateway = gatewayData.name === gatewayKey;
            
            // Show form actions
            $('#formActions').show();
            
            // Build fields HTML
            let fieldsHTML = '<h2 class="form-section-title">' + escapeHtml(gatewayLabel) + ' Configuration</h2>';
            fieldsHTML += '<p class="form-section-description">Configure your ' + escapeHtml(gatewayLabel) + ' payment gateway settings.</p>';
            
            // Common fields
            fieldsHTML += '<div class="form-section mt-4"><h5 class="mb-3">Basic Settings</h5>';
            
            // Status field
            let currentStatus = getFieldValue('status', gatewayKey);
            if (currentStatus === '') {
                // Check existing gateways
                if (existingGateways && existingGateways[gatewayKey] && existingGateways[gatewayKey].status !== undefined) {
                    currentStatus = existingGateways[gatewayKey].status;
                } else if (isCurrentGateway && gatewayData.status !== undefined) {
                    currentStatus = gatewayData.status;
                } else {
                    currentStatus = 1; // Default to Active
                }
            }
            // Convert boolean to integer if needed
            if (currentStatus === true) currentStatus = 1;
            if (currentStatus === false) currentStatus = 0;
            
            fieldsHTML += '<div class="form-group">';
            fieldsHTML += '<label for="status">Status <span class="text-danger">*</span></label>';
            fieldsHTML += '<select name="status" id="status" class="form-control" required>';
            fieldsHTML += '<option value="1" ' + (currentStatus == 1 || currentStatus === '1' ? 'selected' : '') + '>Active</option>';
            fieldsHTML += '<option value="0" ' + (currentStatus == 0 || currentStatus === '0' ? 'selected' : '') + '>Inactive</option>';
            fieldsHTML += '</select>';
            fieldsHTML += '</div>';
            
            fieldsHTML += '</div>';
            
            // API Credentials section
            fieldsHTML += '<div class="form-section mt-4"><h5 class="mb-3">API Credentials</h5>';
            
            // Publishable Key / Client ID
            if (config.publishable_key !== undefined) {
                const fieldLabel = gatewayKey === 'paypal' ? 'Client ID' : 
                                 gatewayKey === 'razorpay' ? 'Key ID' : 
                                 gatewayKey === 'flutterwave' ? 'Public Key' : 
                                 gatewayKey === 'jazzcash' ? 'Merchant ID' : 
                                 'Publishable Key';
                const currentValue = escapeHtml(getFieldValue('publishable_key', gatewayKey));
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="publishable_key">' + escapeHtml(fieldLabel) + '</label>';
                fieldsHTML += '<input type="text" name="publishable_key" id="publishable_key" class="form-control" value="' + currentValue + '" placeholder="Enter ' + escapeHtml(fieldLabel) + '">';
                fieldsHTML += '<small class="form-text text-muted">Public key or client ID from your payment gateway</small>';
                fieldsHTML += '</div>';
            }
            
            // Secret Key / Client Secret
            if (config.secret_key !== undefined) {
                const fieldLabel = gatewayKey === 'paypal' ? 'Secret Key' : 
                                 gatewayKey === 'razorpay' ? 'Secret Key' : 
                                 gatewayKey === 'phonepe' ? 'Client Secret' : 
                                 gatewayKey === 'jazzcash' ? 'Integrity Salt' : 
                                 'Secret Key';
                const currentValue = escapeHtml(getFieldValue('secret_key', gatewayKey));
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="secret_key">' + escapeHtml(fieldLabel) + '</label>';
                fieldsHTML += '<div class="password-toggle">';
                fieldsHTML += '<input type="password" name="secret_key" id="secret_key" class="form-control" value="' + currentValue + '" placeholder="Enter ' + escapeHtml(fieldLabel) + '">';
                fieldsHTML += '<button type="button" class="password-toggle-btn" data-target="secret_key"><i class="fas fa-eye"></i></button>';
                fieldsHTML += '</div>';
                fieldsHTML += '<small class="form-text text-muted">Secret key or client secret from your payment gateway</small>';
                fieldsHTML += '</div>';
            }
            
            // Username (for PhonePe)
            if (config.username !== undefined) {
                const currentValue = escapeHtml(getFieldValue('username', gatewayKey));
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="username">Username</label>';
                fieldsHTML += '<input type="text" name="username" id="username" class="form-control" value="' + currentValue + '" placeholder="Enter Username">';
                fieldsHTML += '<small class="form-text text-muted">Required for PhonePe webhook authentication</small>';
                fieldsHTML += '</div>';
            }
            
            // Password (for PhonePe)
            if (config.password !== undefined) {
                const currentValue = escapeHtml(getFieldValue('password', gatewayKey));
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="password">Password</label>';
                fieldsHTML += '<div class="password-toggle">';
                fieldsHTML += '<input type="password" name="password" id="password" class="form-control" value="' + currentValue + '" placeholder="Enter Password">';
                fieldsHTML += '<button type="button" class="password-toggle-btn" data-target="password"><i class="fas fa-eye"></i></button>';
                fieldsHTML += '</div>';
                fieldsHTML += '<small class="form-text text-muted">' + (gatewayKey === 'jazzcash' ? 'JazzCash merchant password' : 'Required for PhonePe webhook authentication') + '</small>';
                fieldsHTML += '</div>';
            }
            
            // Client Version (for PhonePe)
            if (config.client_version !== undefined) {
                let currentValue = getFieldValue('client_version', gatewayKey);
                if (!currentValue && isCurrentGateway && gatewayData.client_version) {
                    currentValue = gatewayData.client_version;
                }
                if (!currentValue) {
                    // Try to get from config, but it might be an env() call
                    currentValue = (typeof config.client_version === 'string' && !config.client_version.includes('env(')) 
                        ? config.client_version 
                        : '1';
                }
                currentValue = escapeHtml(currentValue);
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="client_version">Client Version</label>';
                fieldsHTML += '<input type="text" name="client_version" id="client_version" class="form-control" value="' + currentValue + '" placeholder="Enter Client Version">';
                fieldsHTML += '<small class="form-text text-muted">PhonePe client version (default: 1)</small>';
                fieldsHTML += '</div>';
            }
            
            // Mode (for PayPal, Midtrans, PhonePe)
            if (config.mode !== undefined) {
                let currentValue = getFieldValue('mode', gatewayKey);
                if (!currentValue && isCurrentGateway && gatewayData.mode) {
                    currentValue = gatewayData.mode;
                }
                if (!currentValue) {
                    // Try to get from config, but it might be an env() call
                    if (typeof config.mode === 'string' && !config.mode.includes('env(')) {
                        currentValue = config.mode;
                    } else {
                        currentValue = gatewayKey === 'phonepe' ? 'uat' : 'sandbox';
                    }
                }
                
                const modeOptions = gatewayKey === 'phonepe' 
                    ? [['uat', 'UAT'], ['production', 'Production']]
                    : [['sandbox', 'Sandbox'], ['production', 'Production']];
                
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="mode">Mode <span class="text-danger">*</span></label>';
                fieldsHTML += '<select name="mode" id="mode" class="form-control" required>';
                modeOptions.forEach(function(option) {
                    const selected = (currentValue === option[0] || (!currentValue && option[0] === (gatewayKey === 'phonepe' ? 'uat' : 'sandbox'))) ? 'selected' : '';
                    fieldsHTML += '<option value="' + escapeHtml(option[0]) + '" ' + selected + '>' + escapeHtml(option[1]) + '</option>';
                });
                fieldsHTML += '</select>';
                fieldsHTML += '<small class="form-text text-muted">Select ' + (gatewayKey === 'phonepe' ? 'UAT' : 'Sandbox') + ' for testing or Production for live transactions</small>';
                fieldsHTML += '</div>';
            }
            
            fieldsHTML += '</div>';
            
            // Webhook section
            fieldsHTML += '<div class="form-section mt-4"><h5 class="mb-3">Webhook Configuration</h5>';
            
            // Webhook Secret
            if (config.webhook_secret !== undefined) {
                const currentValue = escapeHtml(getFieldValue('webhook_secret', gatewayKey));
                fieldsHTML += '<div class="form-group">';
                fieldsHTML += '<label for="webhook_secret">Webhook Secret</label>';
                fieldsHTML += '<div class="password-toggle">';
                fieldsHTML += '<input type="password" name="webhook_secret" id="webhook_secret" class="form-control" value="' + currentValue + '" placeholder="Enter Webhook Secret">';
                fieldsHTML += '<button type="button" class="password-toggle-btn" data-target="webhook_secret"><i class="fas fa-eye"></i></button>';
                fieldsHTML += '</div>';
                fieldsHTML += '<small class="form-text text-muted">Secret key for verifying webhook requests</small>';
                fieldsHTML += '</div>';
            }
            
            // Webhook URL
            let webhookUrl = getFieldValue('webhook_url', gatewayKey);
            if (!webhookUrl) {
                // Check existing gateways first
                if (existingGateways && existingGateways[gatewayKey] && existingGateways[gatewayKey].webhook_url) {
                    webhookUrl = existingGateways[gatewayKey].webhook_url;
                } else if (isCurrentGateway && gatewayData.webhook_url) {
                    webhookUrl = gatewayData.webhook_url;
                } else {
                    webhookUrl = 'payment-gateways/webhooks/' + gatewayKey;
                }
            }
            webhookUrl = escapeHtml(webhookUrl);
            fieldsHTML += '<div class="form-group">';
            fieldsHTML += '<label for="webhook_url">Webhook URL</label>';
            fieldsHTML += '<input type="text" readonly name="webhook_url" id="webhook_url" class="form-control" value="' + webhookUrl + '" placeholder="e.g., payment-gateways/webhooks/' + escapeHtml(gatewayKey) + '">';
            fieldsHTML += '<small class="form-text text-muted">Relative URL path for webhook endpoint</small>';
            fieldsHTML += '</div>';
            
            fieldsHTML += '</div>';
            
            // Update container
            $('#gatewayFieldsContainer').html(fieldsHTML);
            
            // Re-initialize password toggles
            initPasswordToggles();
        }

        function initPasswordToggles() {
            $('.password-toggle-btn').off('click').on('click', function() {
                const targetId = $(this).data('target');
                const input = $('#' + targetId);
                const icon = $(this).find('i');
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
        }

        // Initialize password toggles on page load
        initPasswordToggles();
        
        // Form submission handler
        $('#paymentGatewayForm').on('submit', function(e) {
            // Ensure gateway name is set
            const gatewayName = $('#gateway_name').val();
            if (!gatewayName) {
                e.preventDefault();
                alert('Gateway name is required.');
                return false;
            }
            
            // Ensure form action matches the gateway name
            const formAction = $(this).attr('action');
            const expectedAction = formAction.split('/').slice(0, -1).join('/') + '/' + gatewayName;
            $(this).attr('action', expectedAction);
            
            // Log form data for debugging
            console.log('Form submitting...');
            console.log('Gateway Name:', gatewayName);
            console.log('Form Action:', $(this).attr('action'));
            console.log('Form Method:', $(this).attr('method'));
            
            // Collect all form field values for debugging
            const formData = {};
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                const type = $(this).attr('type');
                if (name && type !== 'hidden' && type !== 'submit' && type !== 'button') {
                    formData[name] = $(this).val();
                } else if (name && !type) {
                    // For elements without type (like select, textarea)
                    formData[name] = $(this).val();
                }
            });
            console.log('Form field values:', formData);
            
            // Verify all expected fields are present
            const expectedFields = ['name', 'status'];
            const missingFields = expectedFields.filter(field => !(field in formData));
            if (missingFields.length > 0) {
                console.warn('Missing expected fields:', missingFields);
            }
            
            // Allow form to submit normally
            return true;
        });
    });
</script>
@endsection

