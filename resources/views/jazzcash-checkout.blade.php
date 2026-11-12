<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JazzCash Payment</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
            color: #333;
        }
        .payment-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .btn {
            margin-top: 15px;
            padding: 10px 20px;
            border: 0;
            border-radius: 4px;
            background: #54af58;
            color: #fff;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <h2>Redirecting to JazzCash...</h2>
        <p>Please do not close or refresh this page.</p>

        <form action="{{ $endpoint }}" id="jazzcash_payment_form" method="POST">
            @foreach($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript>
                <button type="submit" class="btn">Continue to JazzCash</button>
            </noscript>
        </form>
    </div>

    <script>
        document.getElementById('jazzcash_payment_form').submit();
    </script>
</body>
</html>
