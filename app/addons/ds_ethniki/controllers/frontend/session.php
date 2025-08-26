<?php
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Merchant credentials
$merchant_id = Registry::get('addons.ds_ethniki.merchant_id');
$username = "merchant.$merchant_id";
$password =  Registry::get('addons.ds_ethniki.password');
$debug_mode = Registry::get('addons.ds_ethniki.debug_mode');

$url = "https://ibanke-commerce.nbg.gr/api/rest/version/100/merchant/$merchant_id/session";

// Init cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_POST, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($debug_mode == 'Y') {
    // Log for debugging
    error_log("NBG Session API Call - HTTP Code: $http_code");
}

if ($curl_error) {
    if ($debug_mode == 'Y') {
        error_log("cURL Error: $curl_error");
    }
}
if ($response) {
    if ($debug_mode == 'Y') {
        error_log("API Response: $response");
    }
}

// Decode and extract session data
$data = json_decode($response, true);

$session_id = $data['session']['id'] ?? 'UNKNOWN_SESSION_ID';
$aes_key = $data['session']['aes256Key'] ?? 'UNKNOWN_AES_KEY';

// Output in fixed format with dynamic values
header('Content-Type: application/json');
echo json_encode([
    'merchant' => $merchant_id,
    'result' => 'SUCCESS',
    'session' => [
        'id' => $session_id,
        'aes256Key' => $aes_key
    ]
]);
exit;
