<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Payment Gateways')</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background-color: #f5f5f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .wizard-container {
            max-width: 1200px;
            margin: 50px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .wizard-sidebar {
            background: #f8f9fa;
            padding: 30px 20px;
            min-height: 600px;
            border-right: 1px solid #e9ecef;
        }
        
        .gateway-sidebar-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .gateway-sidebar-header h4 {
            color: #212529;
            font-weight: 600;
        }
        
        .wizard-step {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .wizard-step:hover {
            opacity: 0.8;
        }
        
        .wizard-step.active .step-number {
            background: #17a2b8;
            color: #fff;
            border-color: #17a2b8;
        }
        
        .wizard-step.completed .step-number {
            background: #28a745;
            color: #fff;
            border-color: #28a745;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 15px;
            border: 2px solid #dee2e6;
            transition: all 0.3s ease;
        }
        
        .step-content {
            flex: 1;
        }
        
        .step-title {
            font-weight: 600;
            color: #212529;
            margin-bottom: 4px;
            font-size: 16px;
        }
        
        .step-description {
            font-size: 13px;
            color: #6c757d;
            margin: 0;
        }
        
        .wizard-main {
            padding: 40px;
            min-height: 600px;
        }
        
        .wizard-form-step {
            display: none;
        }
        
        .wizard-form-step.active {
            display: block;
        }
        
        .form-section-title {
            font-size: 24px;
            font-weight: 600;
            color: #212529;
            margin-bottom: 8px;
        }
        
        .form-section-description {
            color: #6c757d;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .form-section h5 {
            color: #495057;
            font-weight: 600;
            font-size: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .form-group label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control {
            border-radius: 4px;
            border: 1px solid #ced4da;
            padding: 10px 12px;
        }
        
        .form-control:focus {
            border-color: #17a2b8;
            box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
        }
        
        .wizard-actions {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
        }
        
        .btn {
            padding: 10px 24px;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #17a2b8;
            border-color: #17a2b8;
        }
        
        .btn-primary:hover {
            background: #138496;
            border-color: #117a8b;
        }
        
        .btn-secondary {
            background: #6c757d;
            border-color: #6c757d;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            border-color: #545b62;
        }
        
        .alert {
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .password-toggle {
            position: relative;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px;
        }
        
        .password-toggle-btn:hover {
            color: #495057;
        }
    </style>
    
    @yield('css')
</head>
<body>
    <div class="wizard-container">
        <div class="row no-gutters">
            @hasSection('wizard-steps')
            <div class="col-md-4">
                <div class="wizard-sidebar">
                    @yield('wizard-steps')
                </div>
            </div>
            <div class="col-md-8">
            @else
            <div class="col-md-12">
            @endif
                <div class="wizard-main">
                    @if(session('success'))
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            {{ session('success') }}
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    @endif
                    
                    @php
                        $errors = $errors ?? (session()->get('errors') ?: new \Illuminate\Support\ViewErrorBag());
                    @endphp
                    
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    
                    @yield('content')
                </div>
            </div>
        </div>
    </div>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    
    @yield('script')
</body>
</html>

