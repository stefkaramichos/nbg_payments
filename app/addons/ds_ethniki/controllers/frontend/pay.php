<?php
// pay.php — INITIATE + AUTHENTICATE + optionally show 3DS
use Tygh\Registry;

$debug_mode = Registry::get('addons.ds_ethniki.debug_mode');

// Output settings
if (function_exists('ini_set')) {
    ini_set('zlib.output_compression', 'Off');
    ini_set('output_buffering', 'Off');
    ini_set('implicit_flush', 'On');
    ob_implicit_flush(1);
}

// Merchant credentials
$merchant_id = Registry::get('addons.ds_ethniki.merchant_id');
$username = "merchant.$merchant_id";
$password = Registry::get('addons.ds_ethniki.password');

// Get and log input
$data = json_decode(file_get_contents("php://input"), true);

$sessionId = $data['sessionId'] ?? null;
$orderId = $data['orderId'] ?? null;
$transactionId = $data['transactionId'] ?? null;
$amount = isset($data['amount']) ? number_format((float)$data['amount'], 2, '.', '') : null;

if (!$sessionId || !$orderId || !$transactionId || !$amount) {
    error_log("❌ Missing required parameters. sessionId: $sessionId, orderId: $orderId, transactionId: $transactionId, amount: $amount");
    echo json_encode([
        "status" => "error",
        "message" => "Missing required parameters"
    ]);
    exit;
}

// Helper function
function eth_callNBG($url, $body, $username, $password, $method = 'PUT', $retries = 2) {
    global $debug_mode;

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        if ($debug_mode === 'Y') {
            error_log("Calling NBG (attempt $attempt): $url");
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Connection: close"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

        $res = curl_exec($ch);

        if ($res === false) {
            $err = curl_error($ch);
            error_log("cURL Error (attempt $attempt): $err");
            curl_close($ch);
            if (strpos($err, 'unexpected eof') === false || $attempt === $retries) {
                return null;
            }
            sleep(1);
            continue;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($debug_mode === 'Y') {
            error_log("HTTP Code: $httpCode");
            error_log("Response: $res");
        }

        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Decode Error: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    return null;
}

// STEP 1: INITIATE_AUTHENTICATION
$initResponse = eth_callNBG(
    "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/$transactionId",
    [
        "apiOperation" => "INITIATE_AUTHENTICATION",
        "session" => ["id" => $sessionId],
        "order" => ["currency" => "EUR", "reference" => $orderId],
        "authentication" => [
            "channel" => "PAYER_BROWSER",
            "purpose" => "PAYMENT_TRANSACTION",
            "acceptVersions" => "3DS1,3DS2"
        ]
    ],
    $username,
    $password
);

if (
    !$initResponse ||
    ($initResponse['result'] ?? null) !== 'SUCCESS' ||
    ($initResponse['order']['authenticationStatus'] ?? null) !== 'AUTHENTICATION_AVAILABLE'
) {
    error_log("❌ 3DS initiation failed: " . json_encode($initResponse));
    echo json_encode([
        "status" => "error",
        "message" => "3DS initiation failed",
        "initResponse" => $initResponse
    ]);
    exit;
}

// STEP 2: AUTHENTICATE_PAYER
$redirectUrl = fn_url("index.php?dispatch=3ds-callback.3ds-callback-view")
    . "&session_id=" . urlencode($sessionId)
    . "&order_id=" . urlencode($orderId)
    . "&transaction_id=" . urlencode($transactionId)
    . "&amount=" . urlencode($amount)
    . "&submitted=1";

$authResponse = eth_callNBG(
    "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/$transactionId",
    [
        "apiOperation" => "AUTHENTICATE_PAYER",
        "session" => ["id" => $sessionId],
        "order" => ["amount" => $amount, "currency" => "EUR"],
        "authentication" => [
            "redirectResponseUrl" => $redirectUrl
        ],
        "device" => [
            "browser" => "CHROME",
            "browserDetails" => [
                "javaEnabled" => true,
                "language" => "en-US",
                "colorDepth" => 24,
                "screenHeight" => 1080,
                "screenWidth" => 1920,
                "timeZone" => 120,
                "3DSecureChallengeWindowSize" => "FULL_SCREEN"
            ]
        ]
    ],
    $username,
    $password
);

$authStatus = $authResponse['order']['authenticationStatus'] ?? null;
$challengeHtml = $authResponse['authentication']['redirect']['html'] ?? null;

if ($debug_mode === 'Y') {
    error_log("Authentication Status: $authStatus");
}

if ($authStatus === 'AUTHENTICATION_PENDING' && $challengeHtml) {
    $creq = $authResponse['authentication']['redirect']['customizedHtml']['3ds2']['cReq'];
    $acsUrl = $authResponse['authentication']['redirect']['customizedHtml']['3ds2']['acsUrl'];

    echo json_encode([
        "status" => "3ds_redirect",
        "acs_url" => $acsUrl,
        "creq" => $creq
    ]);
    exit;
}

// STEP 3: RE-CHECK AUTHENTICATION STATUS AFTER CALLBACK (FOR FRICTIONLESS OR CHALLENGE)
$authStatusCheck = eth_callNBG(
    "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/$transactionId",
    null,
    $username,
    $password,
    "GET"
);

$finalAuthStatus = $authStatusCheck['order']['authenticationStatus'] ?? null;

if ($finalAuthStatus === 'AUTHENTICATION_SUCCESSFUL') {
    if ($debug_mode === 'Y') {
        error_log("✅ Authentication successful. Proceeding to PAY.");
    }

    $payResponse = eth_callNBG(
        "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/{$transactionId}PAY",
        [
            "apiOperation" => "PAY",
            "session" => ["id" => $sessionId],
            "order" => [
                "amount" => $amount,
                "currency" => "EUR",
                "reference" => $orderId,
                "notificationUrl" => "http://localhost/ethniki_php/webhook"
            ],
            "transaction" => [
                "reference" => $transactionId . "PAY",
                "source" => "INTERNET"
            ],
            "sourceOfFunds" => ["type" => "CARD"]
        ],
        $username,
        $password
    );

    $orderStatus = $payResponse['order']['status'] ?? null;

    if ($payResponse && $orderStatus === 'CAPTURED') {
        

        if (preg_match('/ord_(\d+)/', $orderId, $matches)) {
            $order_id = (int)$matches[1];
            $transaction_id = $payResponse['transaction']['id'] ??
                              ($payResponse['transaction'][0]['transaction']['transactionId'] ?? '');

            db_query("UPDATE ?:orders SET ds_transaction_id = ?s WHERE order_id = ?i", $transaction_id, $order_id);
<<<<<<< HEAD
            fn_ds_ethniki_change_order_status_ds($orderId, 'O', true);
=======
            fn_ds_ethniki_change_order_status_ds($orderId, 'O', false);
>>>>>>> 73c10fdc90621ab9c4464cd215f547ff90470df2

            if ($debug_mode === 'Y') {
                error_log("✅ Updated ds_transaction_id for order #$order_id: $transaction_id");
            }
        } else {
            error_log("⚠️ Failed to extract numeric order ID from orderId: $orderId");
        }

        echo json_encode([
            "status" => "paid",
            "response" => $payResponse
        ]);
        exit;
    } else {
<<<<<<< HEAD
        fn_ds_ethniki_change_order_status_ds($orderId, 'F', true);
=======
        fn_ds_ethniki_change_order_status_ds($orderId, 'F', false);
>>>>>>> 73c10fdc90621ab9c4464cd215f547ff90470df2
        $gatewayCode = $payResponse['response']['gatewayCode'] ?? 'N/A';
        $acquirerMessage = $payResponse['response']['acquirerMessage'] ?? '';
        error_log("❌ Payment not captured. Order status: $orderStatus. Gateway: $gatewayCode. Message: $acquirerMessage");
        error_log("❌ Full payResponse: " . json_encode($payResponse));
    }
}

error_log("❌ Authentication not completed or failed. Final status: " . $finalAuthStatus);
echo json_encode([
    "status" => "error",
    "message" => "3DS authentication not completed or failed",
    "authStatus" => $finalAuthStatus,
    "authResponse" => $authStatusCheck
]);
