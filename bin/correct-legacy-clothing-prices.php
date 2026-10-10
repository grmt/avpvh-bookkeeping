<?php
/**
 * Corrigeer kledingbestellingen van vóór 9 oktober 2026 naar €16 / €29.
 * Gebruik: wp eval-file <dit-bestand> [apply]. Zonder apply alleen controle.
 * Bewaart de oude bedragen; wijzigt geen betalingen en verstuurt geen e-mail.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
global $wpdb;
$apply = ($args[0] ?? '') === 'apply';
$receipt_key = 'avbk_legacy_clothing_prices_before_20261009';
$wpdb->query('START TRANSACTION');
try {
    $orders = $wpdb->get_results("SELECT id,created_at,quantity,unit_price,total_amount,fee_item_id FROM {$wpdb->prefix}avb_orders WHERE order_type='tshirt' AND created_at<'2026-10-09 00:00:00' FOR UPDATE");
    $plan = [];
    $old_total = 0;
    $new_total = 0;
    $missing_fees = 0;
    foreach ($orders as $order) {
        $items = $wpdb->get_results($wpdb->prepare("SELECT id,item_key,quantity,unit_price,total_price FROM {$wpdb->prefix}avb_order_items WHERE order_id=%d FOR UPDATE", $order->id));
        if (!$items) {
            throw new RuntimeException('Een oudere bestelling heeft geen kledingregels.');
        }
        $total = 0;
        $quantity = 0;
        $changed = false;
        foreach ($items as $item) {
            if (!preg_match('/(?:^|_)(tshirt|hoodie)$/', $item->item_key, $match)) {
                throw new RuntimeException('Onbekend kledingtype; er wordt niets aangepast.');
            }
            $price = $match[1] === 'tshirt' ? 1600 : 2900;
            $line = (int)$item->quantity * $price;
            $changed = $changed || (int)round((float)$item->unit_price * 100) !== $price || (int)round((float)$item->total_price * 100) !== $line;
            $item->new_unit_price = $price / 100;
            $item->new_total_price = $line / 100;
            $total += $line;
            $quantity += (int)$item->quantity;
        }
        $changed = $changed || (int)round((float)$order->total_amount * 100) !== $total;
        if (!$changed) {
            continue;
        }
        $fee = $order->fee_item_id ? $wpdb->get_row($wpdb->prepare("SELECT id,amount_due FROM {$wpdb->prefix}avb_fee_items WHERE id=%d FOR UPDATE", $order->fee_item_id)) : null;
        if ($fee && (int)round((float)$fee->amount_due * 100) !== (int)round((float)$order->total_amount * 100)) {
            throw new RuntimeException('Een betalingspost heeft een afwijkend bedrag; er wordt niets aangepast.');
        }
        $missing_fees += $fee ? 0 : 1;
        $plan[] = ['order'=>$order,'items'=>$items,'fee'=>$fee,'new_total'=>$total/100,'quantity'=>$quantity];
        $old_total += (int)round((float)$order->total_amount * 100);
        $new_total += $total;
    }
    WP_CLI::log(wp_json_encode(['mode'=>$apply?'apply':'dry-run','orders'=>count($plan),'old_total'=>$old_total/100,'new_total'=>$new_total/100,'orders_without_fee'=>$missing_fees]));
    if (!$apply || !$plan) {
        $wpdb->query('ROLLBACK');
        WP_CLI::success('Controle afgerond.');
        return;
    }
    if (get_option($receipt_key, false) !== false || !add_option($receipt_key, ['corrected_at'=>current_time('mysql'),'cutoff'=>'2026-10-09','before'=>$plan], '', false)) {
        throw new RuntimeException('Er bestaat al een correctieregistratie; controleer die eerst.');
    }
    foreach ($plan as $change) {
        foreach ($change['items'] as $item) {
            if ($wpdb->update("{$wpdb->prefix}avb_order_items", ['unit_price'=>$item->new_unit_price,'total_price'=>$item->new_total_price], ['id'=>$item->id]) === false) {
                throw new RuntimeException('Bijwerken van een kledingregel mislukt.');
            }
        }
        if ($wpdb->update("{$wpdb->prefix}avb_orders", ['unit_price'=>round($change['new_total']/$change['quantity'],2),'quantity'=>$change['quantity'],'total_amount'=>$change['new_total']], ['id'=>$change['order']->id]) === false) {
            throw new RuntimeException('Bijwerken van een bestelling mislukt.');
        }
        if ($change['fee'] && $wpdb->update("{$wpdb->prefix}avb_fee_items", ['amount_due'=>$change['new_total']], ['id'=>$change['fee']->id]) === false) {
            throw new RuntimeException('Bijwerken van een betalingspost mislukt.');
        }
    }
    $wpdb->query('COMMIT');
    WP_CLI::success('Oudere kledingprijzen en gekoppelde bedragen gecorrigeerd.');
} catch (Throwable $error) {
    $wpdb->query('ROLLBACK');
    WP_CLI::error($error->getMessage());
}
