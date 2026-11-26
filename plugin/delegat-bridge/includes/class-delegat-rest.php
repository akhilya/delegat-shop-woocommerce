<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_REST
{
    public const NAMESPACE = 'delegat/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        $auth = [Delegat_Bridge_Auth::class, 'permission_check'];

        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'health'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_categories'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/categories/upsert', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'upsert_category'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<id>[\\d]+)/fields', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'category_fields'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/products/upsert', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'upsert_product'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<id>[\\d]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_product'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'list_products'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/products/bulk', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'upsert_products_bulk'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/prices/bulk', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'prices_bulk'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/stocks/bulk', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'stocks_bulk'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/resolve', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'resolve_rows'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/images/drain', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'images_drain'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/images/import-archive', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'images_import_archive'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/prices', [
            'methods'             => 'GET',
            'callback'            => [Delegat_Bridge_Commerce::class, 'list_prices'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/orders', [
            'methods'             => 'GET',
            'callback'            => [Delegat_Bridge_Commerce::class, 'list_orders'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/orders/(?P<id>[\\d]+)', [
            'methods'             => 'GET',
            'callback'            => [Delegat_Bridge_Commerce::class, 'get_order'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/orders/(?P<id>[\\d]+)/status', [
            'methods'             => 'POST',
            'callback'            => [Delegat_Bridge_Commerce::class, 'update_order_status'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/promotions', [
            'methods'             => 'GET',
            'callback'            => [Delegat_Bridge_Commerce::class, 'list_promotions'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/promotions/(?P<id>[\\d]+)', [
            'methods'             => 'GET',
            'callback'            => [Delegat_Bridge_Commerce::class, 'get_promotion'],
            'permission_callback' => $auth,
        ]);

        register_rest_route(self::NAMESPACE, '/promotions/(?P<id>[\\d]+)/products', [
            'methods'             => 'POST',
            'callback'            => [Delegat_Bridge_Commerce::class, 'update_promotion_products'],
            'permission_callback' => $auth,
        ]);
    }

    public static function health(): WP_REST_Response
    {
        global $wp_version;

        return new WP_REST_Response([
            'success'              => true,
            'store_name'           => get_bloginfo('name'),
            'wordpress_version'    => $wp_version,
            'woocommerce_version'  => defined('WC_VERSION') ? WC_VERSION : null,
            'api_version'          => DELEGAT_BRIDGE_VERSION,
            'images_async'         => true,
            'action_scheduler'     => function_exists('as_schedule_single_action'),
            'capabilities'         => [
                'categories',
                'categories.upsert',
                'category.fields',
                'products.upsert',
                'products.get',
                'products.batch_get',
                'products.bulk',
                'prices.bulk',
                'stocks.bulk',
                'variants',
                'images.async',
                'images.drain',
                'images.import_archive',
                'prices.get',
                'orders',
                'orders.status',
                'promotions',
                'promotions.products',
                'resolve',
            ],
        ]);
    }

    /**
     * Read-only canonical target resolution for batch preparation. Card rows
     * are resolved with the exact precedence of the upsert write path
     * (product_id, then _delegat_product_id meta, then sku) plus the outgoing
     * simple/variable branch (`has_variants`), because only simple writes
     * redirect on a parent-SKU conflict while variable writes ignore that
     * conflict. Mutation rows use the exact precedence of the price/stock
     * write path (numeric WC variation id owned by the parent first, then
     * Delegat variant meta under the parent). The adapter uses mutation hits
     * to canonicalize strict ownership aliases before duplicate detection.
     * Card evidence remains shape-faithful, but SKU redirects and cross-form
     * card aliases are not duplicate identity in the batch contract. This
     * endpoint never mutates anything.
     */
    public static function resolve_rows(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || !isset($body['rows']) || !is_array($body['rows'])) {
            return new WP_REST_Response(['success' => false, 'error' => 'rows array is required'], 400);
        }
        if (count($body['rows']) > 500) {
            return new WP_REST_Response(['success' => false, 'error' => 'at most 500 rows per resolve request'], 400);
        }

        $results = [];
        foreach ($body['rows'] as $index => $row) {
            $out = [
                'index'                 => (int) $index,
                'found'                 => false,
                'canonical_id'          => null,
                'sku_conflict_redirect' => false,
            ];
            if (is_array($row)) {
                $kind = (string) ($row['kind'] ?? '');
                if ($kind === 'card') {
                    $resolution = Delegat_Bridge_Products::resolve_card_target_with_evidence($row);
                    $product = $resolution['product'];
                    if ($product) {
                        $out['found'] = true;
                        $out['canonical_id'] = (string) $product->get_id();
                        $out['sku_conflict_redirect'] = (bool) $resolution['sku_conflict_redirect'];
                    }
                } elseif ($kind === 'mutation') {
                    $product = Delegat_Bridge_Products::resolve_mutation_target(
                        (string) ($row['product_id'] ?? ''),
                        isset($row['variant_id']) ? (string) $row['variant_id'] : null
                    );
                    if ($product) {
                        $out['found'] = true;
                        $out['canonical_id'] = (string) $product->get_id();
                    }
                }
            }
            $results[] = $out;
        }

        return new WP_REST_Response(['success' => true, 'results' => $results], 200);
    }

    public static function list_categories(WP_REST_Request $request): WP_REST_Response
    {
        $parent_id = $request->get_param('parent_id');
        $search    = $request->get_param('search');

        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ];

        if ($parent_id !== null && $parent_id !== '') {
            $args['parent'] = absint($parent_id);
        }

        if (is_string($search) && $search !== '') {
            $args['search'] = $search;
        }

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $terms->get_error_message(),
            ], 500);
        }

        $categories = [];
        foreach ($terms as $term) {
            $parent_name = null;
            $full_path   = $term->name;
            if ($term->parent) {
                $parent = get_term($term->parent, 'product_cat');
                if ($parent && !is_wp_error($parent)) {
                    $parent_name = $parent->name;
                    $ancestors   = array_reverse(get_ancestors($term->term_id, 'product_cat'));
                    $parts       = [];
                    foreach ($ancestors as $aid) {
                        $a = get_term($aid, 'product_cat');
                        if ($a && !is_wp_error($a)) {
                            $parts[] = $a->name;
                        }
                    }
                    $parts[]   = $term->name;
                    $full_path = implode(' / ', $parts);
                }
            }

            $children = get_terms([
                'taxonomy'   => 'product_cat',
                'parent'     => $term->term_id,
                'hide_empty' => false,
                'fields'     => 'ids',
            ]);
            $children_count = is_array($children) ? count($children) : 0;

            $categories[] = [
                'id'             => (string) $term->term_id,
                'name'           => $term->name,
                'parent_id'      => $term->parent ? (string) $term->parent : null,
                'parent_name'    => $parent_name,
                'full_path'      => $full_path,
                'has_children'   => $children_count > 0,
                'children_count' => $children_count,
            ];
        }

        return new WP_REST_Response([
            'success'    => true,
            'categories' => $categories,
        ]);
    }

    public static function upsert_category(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Category name is required',
            ], 400);
        }

        $category_id = isset($body['category_id']) ? absint($body['category_id']) : 0;
        $parent_id   = isset($body['parent_id']) && $body['parent_id'] !== '' && $body['parent_id'] !== null
            ? absint($body['parent_id'])
            : 0;
        $description = isset($body['description']) ? (string) $body['description'] : '';

        if ($category_id > 0) {
            $result = wp_update_term($category_id, 'product_cat', [
                'name'        => $name,
                'description' => $description,
                'parent'      => $parent_id,
            ]);
        } else {
            $existing = term_exists($name, 'product_cat', $parent_id ?: null);
            if (is_array($existing) && isset($existing['term_id'])) {
                $result = wp_update_term((int) $existing['term_id'], 'product_cat', [
                    'description' => $description,
                    'parent'      => $parent_id,
                ]);
            } else {
                $result = wp_insert_term($name, 'product_cat', [
                    'description' => $description,
                    'parent'      => $parent_id,
                ]);
            }
        }

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $result->get_error_message(),
            ], 400);
        }

        $term_id = (int) $result['term_id'];
        if (isset($body['sort_order'])) {
            update_term_meta($term_id, 'order', (int) $body['sort_order']);
        }

        // Persist Delegat category attribute schema for later /fields lookups
        if (isset($body['fields']) && is_array($body['fields'])) {
            $normalized = [];
            foreach ($body['fields'] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string) ($field['key'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $normalized[] = [
                    'key'         => $key,
                    'name'        => trim((string) ($field['name'] ?? $key)),
                    'type'        => (string) ($field['type'] ?? 'string'),
                    'is_required' => !empty($field['is_required']),
                    'description' => isset($field['description']) ? (string) $field['description'] : null,
                    'group'       => isset($field['group']) ? (string) $field['group'] : 'Attributes',
                    'sort_order'  => isset($field['sort_order']) ? (int) $field['sort_order'] : 0,
                    'options'     => isset($field['options']) && is_array($field['options']) ? $field['options'] : null,
                ];
            }
            update_term_meta($term_id, '_delegat_category_fields', wp_json_encode($normalized));
        }

        $term = get_term($term_id, 'product_cat');

        return new WP_REST_Response([
            'success'     => true,
            'category_id' => (string) $term_id,
            'name'        => $term && !is_wp_error($term) ? $term->name : $name,
        ]);
    }

    public static function category_fields(WP_REST_Request $request): WP_REST_Response
    {
        $defaults = self::default_category_fields();
        $category_id = absint($request['id'] ?? 0);
        $stored = [];

        if ($category_id > 0) {
            $raw = get_term_meta($category_id, '_delegat_category_fields', true);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            } elseif (is_array($raw)) {
                $stored = $raw;
            }
        }

        $fields = $defaults;
        $keys = [];
        foreach ($defaults as $field) {
            $keys[strtolower((string) $field['key'])] = true;
        }

        $sort = count($defaults) + 1;
        foreach ($stored as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || isset($keys[strtolower($key)])) {
                continue;
            }
            $keys[strtolower($key)] = true;
            $fields[] = [
                'key'         => $key,
                'name'        => trim((string) ($field['name'] ?? $key)),
                'type'        => (string) ($field['type'] ?? 'string'),
                'is_required' => !empty($field['is_required']),
                'description' => $field['description'] ?? null,
                'group'       => $field['group'] ?? 'Attributes',
                'sort_order'  => isset($field['sort_order']) && (int) $field['sort_order'] > 0
                    ? (int) $field['sort_order']
                    : $sort++,
                'options'     => $field['options'] ?? null,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'fields'  => $fields,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function default_category_fields(): array
    {
        return [
            [
                'key'         => 'sku',
                'name'        => 'SKU',
                'type'        => 'string',
                'is_required' => true,
                'group'       => 'Basic',
                'sort_order'  => 1,
            ],
            [
                'key'         => 'name',
                'name'        => 'Name',
                'type'        => 'string',
                'is_required' => true,
                'group'       => 'Basic',
                'sort_order'  => 2,
            ],
            [
                'key'         => 'description',
                'name'        => 'Description',
                'type'        => 'string',
                'is_required' => false,
                'group'       => 'Basic',
                'sort_order'  => 3,
            ],
            [
                'key'         => 'quantity',
                'name'        => 'Stock quantity',
                'type'        => 'integer',
                'is_required' => false,
                'group'       => 'Inventory',
                'sort_order'  => 4,
            ],
            [
                'key'         => 'barcode',
                'name'        => 'Barcode',
                'type'        => 'string',
                'is_required' => false,
                'group'       => 'Basic',
                'sort_order'  => 5,
            ],
            [
                'key'         => 'brand',
                'name'        => 'Brand',
                'type'        => 'string',
                'is_required' => false,
                'group'       => 'Basic',
                'sort_order'  => 6,
            ],
            [
                'key'         => 'images',
                'name'        => 'Images',
                'type'        => 'multiselect',
                'is_required' => false,
                'group'       => 'Media',
                'sort_order'  => 7,
                'description' => 'Product image URLs',
            ],
        ];
    }

    public static function get_product(WP_REST_Request $request): WP_REST_Response
    {
        $id = absint($request['id'] ?? 0);
        if ($id <= 0) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid product id'], 400);
        }

        $product = wc_get_product($id);
        if (!$product) {
            return new WP_REST_Response(['success' => false, 'error' => 'Product not found'], 404);
        }

        return new WP_REST_Response(
            array_merge(['success' => true], self::product_payload($product)),
            200
        );
    }

    /**
     * Batch product lookup used by import fan-in. Rows are deliberately
     * independent: one id whose product object no longer resolves (including
     * permanent deletion) is reported in missing_ids and never turns the whole
     * lookup into a 404. Trashed products keep the same visibility as the
     * single read and are returned with their trash status. Found rows use the
     * exact payload shape of GET /products/{id}; variable parents and
     * variations therefore expose the same fields as the existing single read.
     */
    public static function list_products(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wc_get_product')) {
            return new WP_REST_Response(['success' => false, 'error' => 'WooCommerce is not active'], 500);
        }

        $ids_param = $request->get_param('ids');
        if (!is_string($ids_param) || trim($ids_param) === '') {
            return new WP_REST_Response(['success' => false, 'error' => 'ids query parameter is required'], 400);
        }

        $ids = [];
        $seen = [];
        $int_max = (string) PHP_INT_MAX;
        foreach (explode(',', $ids_param) as $part) {
            $token = trim($part);
            if (!preg_match('/^\d+$/', $token)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => 'ids query parameter contains an invalid product id',
                ], 400);
            }

            // Leading zeroes are an alias of the same numeric id, but they must
            // not defeat the integer range check. Reject zero and out-of-range
            // tokens explicitly instead of letting PHP casting change their meaning.
            $digits = ltrim($token, '0');
            if ($digits === ''
                || strlen($digits) > strlen($int_max)
                || (strlen($digits) === strlen($int_max) && strcmp($digits, $int_max) > 0)
            ) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => 'ids query parameter contains an invalid product id',
                ], 400);
            }

            $id = (int) $digits;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        if ($ids === []) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'ids query parameter must contain at least one numeric product id',
            ], 400);
        }

        $items = [];
        $missing_ids = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                $missing_ids[] = (string) $id;
                continue;
            }
            $items[] = self::product_payload($product);
        }

        return new WP_REST_Response([
            'success'     => true,
            'items'       => $items,
            'missing_ids' => $missing_ids,
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private static function product_payload(WC_Product $product): array
    {
        $image_ids = $product->get_gallery_image_ids();
        $featured = $product->get_image_id();
        if ($featured) {
            array_unshift($image_ids, (int) $featured);
        }
        $image_ids = array_values(array_unique(array_filter(array_map('intval', $image_ids))));
        $image_urls = [];
        foreach ($image_ids as $attachment_id) {
            $url = wp_get_attachment_url($attachment_id);
            if (is_string($url) && $url !== '') {
                $image_urls[] = $url;
            }
        }

        $price = $product->get_regular_price();
        if ($price === '' || $price === null) {
            $price = $product->get_price();
        }
        $sale = $product->get_sale_price();

        $category_id = '';
        $category_name = null;
        $term_ids = $product->get_category_ids();
        if (is_array($term_ids) && $term_ids !== []) {
            $primary_term_id = (int) $term_ids[0];
            if ($primary_term_id > 0) {
                $category_id = (string) $primary_term_id;
                $term = get_term($primary_term_id, 'product_cat');
                if ($term && !is_wp_error($term) && is_string($term->name) && $term->name !== '') {
                    $category_name = $term->name;
                }
            }
        }

        return [
            'product_id'     => (string) $product->get_id(),
            'sku'            => (string) $product->get_sku(),
            'name'           => $product->get_name(),
            'description'    => $product->get_description(),
            'price'          => $price !== '' && $price !== null ? (float) $price : null,
            'sale_price'     => $sale !== '' && $sale !== null ? (float) $sale : null,
            'quantity'       => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
            'status'         => $product->get_status(),
            'image_urls'     => $image_urls,
            'category_id'    => $category_id !== '' ? $category_id : null,
            'category_name'  => $category_name,
        ];
    }

    public static function upsert_product(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid JSON body'], 400);
        }

        $result = Delegat_Bridge_Products::upsert($body);
        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status);
    }

    public static function upsert_products_bulk(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid JSON body'], 400);
        }

        $products = $body['products'] ?? null;
        if (!is_array($products)) {
            return new WP_REST_Response(['success' => false, 'error' => 'products array is required'], 400);
        }

        $results = [];
        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                $results[] = [
                    'index'   => (int) $index,
                    'success' => false,
                    'error'   => 'Invalid product payload',
                ];
                continue;
            }

            $local_id = null;
            if (isset($product['raw_fields']['local_product_id'])) {
                $local_id = (int) $product['raw_fields']['local_product_id'];
            }

            // Bulk must not sideload inline — adapter (or AS) drains afterwards.
            $product['defer_images'] = true;

            $upsert = Delegat_Bridge_Products::upsert($product);
            $results[] = [
                'index'            => (int) $index,
                'success'          => !empty($upsert['success']),
                'product_id'       => $upsert['product_id'] ?? null,
                'error'            => $upsert['error'] ?? null,
                'local_product_id' => $local_id,
                'images_queued'    => $upsert['images_queued'] ?? null,
                'images_mode'      => $upsert['images_mode'] ?? null,
                'images_synced'    => $upsert['images_synced'] ?? null,
                'image_errors'     => $upsert['image_errors'] ?? null,
                'variant_ids'      => $upsert['variant_ids'] ?? null,
            ];
        }

        $all_ok = !in_array(false, array_column($results, 'success'), true);
        $queued = 0;
        foreach ($results as $row) {
            if (!empty($row['images_queued'])) {
                $queued += (int) $row['images_queued'];
            }
        }

        return new WP_REST_Response([
            'success'         => $all_ok,
            'bridge_version'  => DELEGAT_BRIDGE_VERSION,
            'images_queued'   => $queued,
            'images_async'    => true,
            'results'         => $results,
        ], $all_ok ? 200 : 207);
    }

    public static function prices_bulk(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || !isset($body['prices']) || !is_array($body['prices'])) {
            return new WP_REST_Response(['success' => false, 'error' => 'prices array is required'], 400);
        }

        $results = [];
        foreach ($body['prices'] as $index => $item) {
            try {
                if (!is_array($item)) {
                    $results[] = ['index' => (int) $index, 'success' => false, 'error' => 'Invalid item'];
                    continue;
                }
                $update = Delegat_Bridge_Products::update_price($item);
                $results[] = [
                    'index'      => (int) $index,
                    'success'    => !empty($update['success']),
                    'product_id' => $update['product_id'] ?? ($item['product_id'] ?? null),
                    'error'      => $update['error'] ?? null,
                ];
            } catch (Throwable $e) {
                // One bad row must not abort the request after earlier rows committed:
                // the adapter can only reconcile siblings when every row has a verdict.
                // The boolean verdict is deliberately OMITTED here, not false:
                // WooCommerce persists the product inside save() before running
                // after-save hooks, so an exception thrown from such a hook means
                // the write may already have committed. Reporting a definitive
                // failure would skip end-state confirmation of a row that landed.
                $results[] = [
                    'index'      => (int) $index,
                    'uncertain'  => true,
                    'product_id' => is_array($item) ? ($item['product_id'] ?? null) : null,
                    'error'      => $e->getMessage(),
                ];
            }
        }

        // The batch is fully successful only when every row carries an explicit
        // success=true; an uncertain or missing verdict must degrade the batch
        // to 207 partial evidence so the adapter reconciles the row.
        $all_ok = count($results) > 0;
        foreach ($results as $r) {
            if (empty($r['success'])) {
                $all_ok = false;
                break;
            }
        }
        return new WP_REST_Response([
            'success' => $all_ok,
            'results' => $results,
        ], $all_ok ? 200 : 207);
    }

    public static function images_drain(): WP_REST_Response
    {
        $result = Delegat_Bridge_Images::handle_drain_images();
        return new WP_REST_Response([
            'success'        => true,
            'bridge_version' => DELEGAT_BRIDGE_VERSION,
            'processed'      => $result['processed'],
            'pending'        => $result['pending'],
            'errors'         => $result['errors'],
        ]);
    }

    public static function images_import_archive(WP_REST_Request $request): WP_REST_Response
    {
        $files = $request->get_file_params();
        $file = $files['archive'] ?? null;
        $tmp_created = null;

        if (!is_array($file)) {
            $body = $request->get_json_params();
            $b64 = is_array($body) && isset($body['archive_base64']) && is_string($body['archive_base64'])
                ? $body['archive_base64']
                : '';
            if ($b64 !== '') {
                $raw = base64_decode($b64, true);
                if ($raw === false || $raw === '') {
                    return new WP_REST_Response([
                        'success'       => false,
                        'error'         => 'archive_base64 is not valid base64',
                        'attached'      => 0,
                        'skipped'       => 0,
                        'errors'        => ['archive_base64 is not valid base64'],
                        'pending_regen' => 0,
                    ], 400);
                }
                $tmp_created = tempnam(sys_get_temp_dir(), 'delegat_img_');
                if ($tmp_created === false || file_put_contents($tmp_created, $raw) === false) {
                    if ($tmp_created !== false && is_file($tmp_created)) {
                        @unlink($tmp_created);
                    }
                    return new WP_REST_Response([
                        'success'       => false,
                        'error'         => 'Failed to stage archive_base64 payload',
                        'attached'      => 0,
                        'skipped'       => 0,
                        'errors'        => ['Failed to stage archive_base64 payload'],
                        'pending_regen' => 0,
                    ], 500);
                }
                $file = [
                    'tmp_name' => $tmp_created,
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => strlen($raw),
                ];
            }
        }

        if (!is_array($file)) {
            return new WP_REST_Response([
                'success'       => false,
                'error'         => 'multipart field "archive" (zip) or JSON "archive_base64" is required',
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['multipart field "archive" (zip) or JSON "archive_base64" is required'],
                'pending_regen' => 0,
            ], 400);
        }

        try {
            $result = Delegat_Bridge_Images::import_archive_from_upload($file);
        } finally {
            if ($tmp_created !== null && is_file($tmp_created)) {
                @unlink($tmp_created);
            }
        }

        $status = !empty($result['success']) ? 200 : 207;
        return new WP_REST_Response([
            'success'        => !empty($result['success']),
            'bridge_version' => DELEGAT_BRIDGE_VERSION,
            'attached'       => $result['attached'] ?? 0,
            'skipped'        => $result['skipped'] ?? 0,
            'errors'         => $result['errors'] ?? [],
            'pending_regen'  => $result['pending_regen'] ?? 0,
        ], $status);
    }

    public static function stocks_bulk(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body) || !isset($body['stocks']) || !is_array($body['stocks'])) {
            return new WP_REST_Response(['success' => false, 'error' => 'stocks array is required'], 400);
        }

        $results = [];
        foreach ($body['stocks'] as $index => $item) {
            try {
                if (!is_array($item)) {
                    $results[] = ['index' => (int) $index, 'success' => false, 'error' => 'Invalid item'];
                    continue;
                }
                $update = Delegat_Bridge_Products::update_stock($item);
                $results[] = [
                    'index'      => (int) $index,
                    'success'    => !empty($update['success']),
                    'product_id' => $update['product_id'] ?? ($item['product_id'] ?? null),
                    'error'      => $update['error'] ?? null,
                ];
            } catch (Throwable $e) {
                // Keep committed siblings attributable: every input row receives
                // its own result even when one row throws inside WooCommerce.
                // The boolean verdict is deliberately OMITTED here, not false:
                // an exception from a WooCommerce after-save hook fires after the
                // row already committed, so a definitive failure verdict would
                // falsely skip end-state confirmation of a write that landed.
                $results[] = [
                    'index'      => (int) $index,
                    'uncertain'  => true,
                    'product_id' => is_array($item) ? ($item['product_id'] ?? null) : null,
                    'error'      => $e->getMessage(),
                ];
            }
        }

        // Fully successful only when every row carries explicit success=true;
        // uncertain rows degrade the batch to 207 partial evidence.
        $all_ok = count($results) > 0;
        foreach ($results as $r) {
            if (empty($r['success'])) {
                $all_ok = false;
                break;
            }
        }
        return new WP_REST_Response([
            'success' => $all_ok,
            'results' => $results,
        ], $all_ok ? 200 : 207);
    }
}
