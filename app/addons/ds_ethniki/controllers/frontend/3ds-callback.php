<?php
// 3ds-callback.php — Handles redirect from ACS (3D Secure challenge)

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$debug_mode = Registry::get('addons.ds_ethniki.debug_mode');

if ($mode === '3ds-callback-view') {
    Tygh::$app['view']->assign('ethniki_error', true);
}

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

$sessionId     = $_GET['session_id']     ?? null;
$orderId       = $_GET['order_id']       ?? null;
$transactionId = $_GET['transaction_id'] ?? null;
$amount        = isset($_GET['amount']) ? number_format((float)$_GET['amount'], 2, '.', '') : null;

if (!$sessionId || !$orderId || !$transactionId || !$amount || $amount <= 0) {
    if ($debug_mode === 'Y') {
        error_log("❌ Missing or invalid input — aborting payment.");
    }
    echo "<h1>❌ Missing or invalid payment data.</h1>";
    exit;
}

// CURL helper
function eth_callNBG($url, $body, $username, $password, $method = 'PUT', $retries = 2) {
    global $debug_mode;

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        if ($debug_mode === 'Y') {
            error_log("🔁 Calling NBG (attempt $attempt): $url");
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
            if ($debug_mode === 'Y') {
                error_log("❌ cURL Error (attempt $attempt): $err");
            }
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
            error_log("🔢 HTTP Code: $httpCode");
        }

        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($debug_mode === 'Y') {
                error_log("❌ JSON Decode Error: " . json_last_error_msg());
            }
            return null;
        }

        return $decoded;
    }

    return null;
}

// Step 1: VERIFY AUTHENTICATION RESULT
$authCheck = eth_callNBG(
    "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/$transactionId",
    null,
    $username,
    $password,
    'GET'
);

$authStatus = $authCheck['order']['authenticationStatus'] ?? null;

if ($debug_mode === 'Y') {
    error_log("Authentication: $authStatus");
}

if ($authStatus !== 'AUTHENTICATION_SUCCESSFUL') {
    if ($debug_mode === 'Y') {
        error_log("❌ Authentication failed or not completed: $authStatus");
    }
    fn_ds_ethniki_change_order_status_ds($orderId, 'F', false);
    Tygh::$app['view']->assign('text_page_result', "❌ Η ταυτοποίηση 3DS απέτυχε.");
    Tygh::$app['view']->assign('heading_page_result', "Αποτυχία Πληρωμής");
    return;
}

// Step 2: Final PAY Operation
if ($debug_mode === 'Y') {
    error_log("🚀 Performing final PAY operation...");
}

$payPayload = [
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
];

// Add authentication details if present
$authPayload = [];

if (!empty($authCheck['authentication']['3ds'])) {
    $auth3ds = $authCheck['authentication']['3ds'];
    $authPayload['3ds'] = array_filter([
        'acsEci' => $auth3ds['acsEci'] ?? null,
        'authenticationToken' => $auth3ds['authenticationToken'] ?? null,
        'transactionId' => $auth3ds['transactionId'] ?? null,
    ]);
}

if (!empty($authCheck['authentication']['3ds2'])) {
    $auth3ds2 = $authCheck['authentication']['3ds2'];
    $authPayload['3ds2'] = array_filter([
        'acsReference' => $auth3ds2['acsReference'] ?? null,
        'dsReference' => $auth3ds2['dsReference'] ?? null,
        'protocolVersion' => $auth3ds2['protocolVersion'] ?? null,
        'transactionStatus' => $auth3ds2['transactionStatus'] ?? null,
    ]);
}

if (!empty($authPayload)) {
    $payPayload['authentication'] = $authPayload;
}

// Perform the PAY request
$payResponse = eth_callNBG(
    "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/order/$orderId/transaction/{$transactionId}PAY",
    $payPayload,
    $username,
    $password
);

if ($debug_mode === 'Y') {
    error_log("💬 PAY Response: " . json_encode($payResponse));
}

$orderStatus = $payResponse['order']['status'] ?? null;

// Step 3: Handle Response
if ($payResponse && $orderStatus === 'CAPTURED') {
    fn_ds_ethniki_change_order_status_ds($orderId, 'O', false);
    Tygh::$app['view']->assign('text_page_result', "✅ Πληρωμή ολοκληρώθηκε με επιτυχία!");
    Tygh::$app['view']->assign('heading_page_result', "Eπιτυχής Πληρωμή");

    if (preg_match('/ord_(\d+)/', $orderId, $matches)) {
        $order_id = (int)$matches[1];
        $transaction_id = $payResponse['transaction']['id'] ??
                          ($payResponse['transaction'][0]['transaction']['transactionId'] ?? '');

        db_query("UPDATE ?:orders SET ds_transaction_id = ?s WHERE order_id = ?i", $transaction_id, $order_id);

        if ($debug_mode === 'Y') {
            error_log("✅ Updated ds_transaction_id for order #$order_id: $transaction_id");
        }
    } else {
        if ($debug_mode === 'Y') {
            error_log("⚠️ Failed to extract numeric order ID from orderId: $orderId");
        }
    }
} else { 
    $transaction_id = fn_ds_ethniki_get_transaction_id($orderId);
    if(!$transaction_id){
        fn_ds_ethniki_change_order_status_ds($orderId, 'F', false);
        $gatewayCode = $payResponse['response']['gatewayCode'] ?? 'N/A';
        $acquirerMessage = $payResponse['response']['acquirerMessage'] ?? '';
        error_log("❌ Payment not captured. Order status: $orderStatus. Gateway: $gatewayCode. Message: $acquirerMessage");
        error_log("❌ Full payResponse: " . json_encode($payResponse));
        Tygh::$app['view']->assign('text_page_result', "❌ Η πληρωμή απέτυχε."); 
        Tygh::$app['view']->assign('heading_page_result', "Αποτυχία Πληρωμής");
    }
}
 