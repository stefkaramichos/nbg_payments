<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }


function fn_ds_ethniki_change_order_status($order_id, $status, $email = true) {
    // Extract only the numeric part (e.g., from "ord_123" to 123)
    if (preg_match('/\d+$/', $order_id, $matches)) {
        $numeric_order_id = (int) $matches[0];
    } else {
        error_log("❌ Invalid order ID format: $order_id");
        return false;
    }

    error_log("📦 Changing order status for #$numeric_order_id to '$status'");

    $order = fn_get_order_info($numeric_order_id);
    if (empty($order)) {
        error_log("❌ Order #$numeric_order_id not found.");
        return false;
    }

    fn_change_order_status($numeric_order_id, $status, '', fn_get_notification_rules([], $email));
    
}


function fn_ds_ethniki_get_transaction_id($order_id) {
    
    if (preg_match('/\d+$/', $order_id, $matches)) {
        $numeric_order_id = (int) $matches[0];
    } else {
        error_log("❌ Invalid order ID format: $order_id");
        return false;
    }

    $transaction_id = db_get_field("SELECT ds_transaction_id FROM ?:orders WHERE order_id = ?i", $numeric_order_id);
    if (!$transaction_id) {
        error_log("❌ Transaction ID not found for order #$numeric_order_id");
        return null;
    }       

    return $transaction_id;
}
