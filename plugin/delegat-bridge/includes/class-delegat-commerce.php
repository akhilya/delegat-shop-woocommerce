<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_Commerce
{
    public static function list_prices(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wc_get_product')) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce is not active'], 500);
        }

        $ids_param = $request->get_param('ids');
        $ids = [];
        if (is_string($ids_param) && $ids_param !== '') {
            foreach (explode(',', $ids_param) as $part) {
                $id = absint($part);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        $page = max(1, absint($request->get_param('page') ?: 1));
        $page_size = absint($request->get_param('page_size') ?: 50);
        if ($page_size < 1) {
            $page_size = 50;
        }
        if ($page_size > 200) {
            $page_size = 200;
        }

        $query_args = [
            'status'  => ['publish', 'private', 'draft'],
            'limit'   => $page_size,
            'page'    => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'ids',
        ];
        if ($ids !== []) {
            $query_args['include'] = $ids;
            $query_args['limit'] = count($ids);
            $query_args['page'] = 1;
        }

        $product_ids = wc_get_products($query_args);
        $items = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product((int) $product_id);
            if (!$product) {
                continue;
            }
            $items[] = self::price_payload($product);
        }

        return new WP_REST_Response([
            'success'  => true,
            'items'    => $items,
            'page'     => $page,
            'has_more' => $ids === [] && count($items) >= $page_size,
        ]);
    }

    public static function list_orders(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wc_get_orders')) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce is not active'], 500);
        }

        $page = max(1, absint($request->get_param('page') ?: 1));
        $page_size = absint($request->get_param('page_size') ?: 50);
        if ($page_size < 1) {
            $page_size = 50;
        }
        if ($page_size > 100) {
            $page_size = 100;
        }

        $args = [
            'limit'   => $page_size,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];
        $status = $request->get_param('status');
        if (is_string($status) && $status !== '') {
            $mapped = self::to_woo_status($status);
            if ($mapped === null) {
                $raw = strtolower(trim($status));
                if (str_starts_with($raw, 'wc-')) {
                    $raw = substr($raw, 3);
                }
                $mapped = $raw;
            }
            $args['status'] = $mapped;
        }
        $search = $request->get_param('search');
        if (is_string($search) && $search !== '') {
            $args['s'] = $search;
        }
        $from = $request->get_param('from');
        if (is_string($from) && $from !== '') {
            $args['date_created'] = '>=' . $from;
        }

        $orders = wc_get_orders($args);
        $items = [];
        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                $items[] = self::order_payload($order);
            }
        }

        return new WP_REST_Response([
            'success'  => true,
            'items'    => $items,
            'page'     => $page,
            'has_more' => count($items) >= $page_size,
        ]);
    }

    public static function get_order(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id'] ?? 0);
        if ($id <= 0 || !function_exists('wc_get_order')) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid order id'], 400);
        }
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) {
            return new WP_REST_Response(['success' => false, 'error' => 'Order not found'], 404);
        }
        return new WP_REST_Response(['success' => true, 'order' => self::order_payload($order)]);
    }

    public static function update_order_status(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id'] ?? 0);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }
        $action = strtolower(trim((string) ($body['action'] ?? $body['status'] ?? '')));
        if ($id <= 0 || $action === '' || !function_exists('wc_get_order')) {
            return new WP_REST_Response(['success' => false, 'error' => 'action and order id are required'], 400);
        }
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) {
            return new WP_REST_Response(['success' => false, 'error' => 'Order not found'], 404);
        }

        $woo_status = self::to_woo_status($action);
        if ($woo_status === null) {
            return new WP_REST_Response(['success' => false, 'error' => 'Unsupported order status/action: ' . $action], 400);
        }
        $note = isset($body['reason']) ? (string) $body['reason'] : '';
        $updated = $order->update_status($woo_status, $note, true);
        if (!$updated && $order->get_status() !== $woo_status) {
            return new WP_REST_Response(['success' => false, 'error' => 'Failed to update order status to ' . $woo_status], 400);
        }
        if (!empty($body['tracking_number'])) {
            $order->update_meta_data('_delegat_tracking_number', sanitize_text_field((string) $body['tracking_number']));
            $order->save();
        }

        return new WP_REST_Response(['success' => true, 'order' => self::order_payload($order)]);
    }

    public static function list_promotions(WP_REST_Request $request): WP_REST_Response
    {
        if (!class_exists('WC_Coupon')) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce coupons are not available'], 500);
        }

        $page = max(1, absint($request->get_param('page') ?: 1));
        $coupons = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'paged'          => $page,
            'fields'         => 'ids',
        ]);

        $items = [];
        foreach ($coupons as $coupon_id) {
            $coupon = new WC_Coupon((int) $coupon_id);
            $items[] = self::coupon_payload($coupon);
        }

        return new WP_REST_Response(['success' => true, 'items' => $items, 'page' => $page]);
    }

    public static function get_promotion(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id'] ?? 0);
        if ($id <= 0 || !class_exists('WC_Coupon')) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid coupon id'], 400);
        }
        $coupon = new WC_Coupon($id);
        if ((int) $coupon->get_id() <= 0) {
            return new WP_REST_Response(['success' => false, 'error' => 'Coupon not found'], 404);
        }
        return new WP_REST_Response(['success' => true, 'promotion' => self::coupon_payload($coupon)]);
    }

    public static function update_promotion_products(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id'] ?? 0);
        $body = $request->get_json_params();
        if (!is_array($body) || $id <= 0 || !class_exists('WC_Coupon')) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid request'], 400);
        }

        $coupon = new WC_Coupon($id);
        if ((int) $coupon->get_id() <= 0) {
            return new WP_REST_Response(['success' => false, 'error' => 'Coupon not found'], 404);
        }

        $product_ids = array_map('absint', $coupon->get_product_ids());
        $added = 0;
        $removed = 0;

        $remove_all = !empty($body['removeAll']) || !empty($body['remove_all']);
        if ($remove_all) {
            // Persist and verify draft post_status BEFORE clearing product_ids:
            // WC_Coupon_Data_Store_CPT::update ignores post_status on WooCommerce 8.x-10.x.
            $draft_result = wp_update_post([
                'ID'          => $id,
                'post_status' => 'draft',
            ], true);
            if (is_wp_error($draft_result) || get_post_status($id) !== 'draft') {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => 'Failed to transition coupon to draft before clearing product restrictions',
                ], 500);
            }

            $removed = count($product_ids);
            $coupon = new WC_Coupon($id);
            $coupon->set_product_ids([]);
            $coupon->set_status('draft');
            $coupon->save();
            return new WP_REST_Response([
                'success'   => true,
                'added'     => 0,
                'removed'   => $removed,
                'promotion' => self::coupon_payload($coupon),
            ]);
        }

        if (!empty($body['add']) && is_array($body['add'])) {
            foreach ($body['add'] as $row) {
                $pid = 0;
                if (is_array($row) && isset($row['externalProductId'])) {
                    $pid = absint($row['externalProductId']);
                } elseif (is_array($row) && isset($row['external_product_id'])) {
                    $pid = absint($row['external_product_id']);
                }
                if ($pid > 0 && !in_array($pid, $product_ids, true)) {
                    $product_ids[] = $pid;
                    $added++;
                }
            }
        }

        $remove = $body['removeExternalProductIds'] ?? $body['remove_external_product_ids'] ?? [];
        if (is_array($remove)) {
            $remove_ids = array_map('absint', $remove);
            $before = count($product_ids);
            $product_ids = array_values(array_diff($product_ids, $remove_ids));
            $removed = $before - count($product_ids);
        }

        $coupon->set_product_ids($product_ids);
        $coupon->save();

        return new WP_REST_Response([
            'success' => true,
            'added'   => $added,
            'removed' => $removed,
            'promotion' => self::coupon_payload($coupon),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function price_payload(WC_Product $product): array
    {
        $regular = $product->get_regular_price();
        $sale = $product->get_sale_price();
        return [
            'external_product_id' => (string) $product->get_id(),
            'sku'                 => (string) $product->get_sku(),
            'price'               => $regular !== '' ? (float) $regular : ($product->get_price() !== '' ? (float) $product->get_price() : null),
            'sale_price'          => $sale !== '' ? (float) $sale : null,
            'quantity'            => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function order_payload(WC_Order $order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $sku = '';
            $product = $item->get_product();
            if ($product) {
                $sku = (string) $product->get_sku();
            }
            $items[] = [
                'external_product_id' => (string) $item->get_product_id(),
                'sku'                 => $sku,
                'name'                => $item->get_name(),
                'quantity'            => (int) $item->get_quantity(),
                'price'               => (float) $item->get_subtotal(),
                'discounted_price'    => (float) $item->get_total(),
            ];
        }

        return [
            'external_id'       => (string) $order->get_id(),
            'display_id'        => (string) $order->get_order_number(),
            'status'            => self::from_woo_status($order->get_status()),
            'raw_status'        => $order->get_status(),
            'channel'           => 'shop',
            'created_at'        => $order->get_date_created()?->date('c'),
            'updated_at'        => $order->get_date_modified()?->date('c'),
            'currency'          => $order->get_currency(),
            'total'             => (float) $order->get_total(),
            'discount_total'    => (float) $order->get_discount_total(),
            'customer_name'     => trim($order->get_formatted_billing_full_name()),
            'customer_phone'    => $order->get_billing_phone(),
            'customer_email'    => $order->get_billing_email(),
            'shipping_address'  => $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address(),
            'comment'           => $order->get_customer_note(),
            'items'             => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coupon_payload(WC_Coupon $coupon): array
    {
        $type = $coupon->get_discount_type();
        return [
            'external_id'        => (string) $coupon->get_id(),
            'name'               => $coupon->get_code(),
            'type'               => 'coupon',
            'status'             => $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time() ? 'expired' : 'active',
            'starts_at'          => $coupon->get_date_created()?->date('c'),
            'ends_at'            => $coupon->get_date_expires()?->date('c'),
            'discount_percent'   => $type === 'percent' ? (float) $coupon->get_amount() : null,
            'discount_amount'    => $type !== 'percent' ? (float) $coupon->get_amount() : null,
            'product_ids'        => array_map('strval', $coupon->get_product_ids()),
            'description'        => $coupon->get_description(),
        ];
    }

    private static function to_woo_status(string $action): ?string
    {
        $action = strtolower(trim($action));
        if (str_starts_with($action, 'wc-')) {
            $action = substr($action, 3);
        }
        return match ($action) {
            'confirm', 'confirmed', 'pack', 'processing' => 'processing',
            'ship', 'deliver', 'complete', 'completed', 'delivered' => 'completed',
            'cancel', 'cancelled', 'canceled' => 'cancelled',
            'unpaid', 'pending' => 'pending',
            'on-hold', 'on_hold' => 'on-hold',
            'refunded', 'returned' => 'refunded',
            default => null,
        };
    }

    private static function from_woo_status(string $status): string
    {
        $status = strtolower(trim($status));
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }
        return match ($status) {
            'pending', 'on-hold', 'failed' => 'unpaid',
            'processing' => 'confirmed',
            'completed' => 'delivered',
            'cancelled' => 'cancelled',
            'refunded' => 'returned',
            default => 'unknown',
        };
    }
}
