<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }


function fn_ds_ethniki_change_order_status_ds($order_id, $status, $email = true) {
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


function fn_ds_ethniki_change_order_status(
    $status_to,
    $status_from,
    $order_info,
    &$force_notification,
    $order_statuses,
    $place_order
) {

     // Run ONLY for the Ethniki payment method
    $ethniki_payment_id = (int) Registry::get('addons.ds_ethniki.payment_id');
    if (empty($order_info['payment_id']) || (int) $order_info['payment_id'] !== $ethniki_payment_id) {
        return; // not our payment → do nothing
    }

    // Block 'O' by default
    $blocked_statuses = ['O'];

    // If this change isn't one we block, do nothing
    if (!in_array($status_to, $blocked_statuses, true)) {
        return;
    }

    // --- ALLOW explicit opt-in to send emails ---
    // Cases to allow:
    //  - $force_notification === true
    //  - $force_notification['C'] === true (customer)
    //  - $force_notification['A'] === true (admin)
    //  - $force_notification['V'] === true (vendor)
    $forced_on =
        $force_notification === true
        || (is_array($force_notification) && (
            (($force_notification['C'] ?? null) === true)
            || (($force_notification['A'] ?? null) === true)
            || (($force_notification['V'] ?? null) === true)
        ));


    if ($forced_on) {
        // Respect the explicit request to send; don't block.
        return;
    }
    // --------------------------------------------

    // Otherwise, block notifications for 'O'
    if (!is_array($force_notification)) {
        $force_notification = [];
    }
    $force_notification['C'] = false; // customer
    $force_notification['A'] = false; // admin
    $force_notification['V'] = false; // vendor
}
