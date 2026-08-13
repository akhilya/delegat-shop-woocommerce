<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_Products
{
    public const META_PRODUCT_ID = '_delegat_product_id';
    public const META_VARIANT_ID = '_delegat_variant_id';

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, product_id?: string, variant_ids?: array<string, string>, error?: string}
     */
    public static function upsert(array $payload): array
    {
        try {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                return ['success' => false, 'error' => 'Product name is required'];
            }

            $variants = $payload['variants'] ?? null;
            $has_variants = is_array($variants) && count($variants) > 0;

            $product = self::find_existing($payload);
            if ($has_variants) {
                return self::upsert_variable($product, $payload, $variants);
            }

            return self::upsert_simple($product, $payload);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function find_existing(array $payload): ?WC_Product
    {
        $product_id = isset($payload['product_id']) ? absint($payload['product_id']) : 0;
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
            if ($product) {
                return self::normalize_to_catalog_product($product);
            }
        }

        $delegat_id = isset($payload['delegat_product_id']) ? (string) $payload['delegat_product_id'] : '';
        if ($delegat_id !== '') {
            $found = self::find_by_meta(self::META_PRODUCT_ID, $delegat_id);
            if ($found) {
                return self::normalize_to_catalog_product($found);
            }
        }

        $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
        if ($sku !== '') {
            $found = self::find_by_sku($sku);
            if ($found) {
                return self::normalize_to_catalog_product($found);
            }
        }

        return null;
    }

    /**
     * Resolve product by SKU via WC helper and the product meta lookup table
     * (covers trash / HPOS cases where wc_get_product_id_by_sku returns 0).
     */
    private static function find_by_sku(string $sku): ?WC_Product
    {
        $id = (int) wc_get_product_id_by_sku($sku);
        if ($id <= 0) {
            global $wpdb;
            $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT product_id FROM {$lookup} WHERE sku = %s LIMIT 1",
                $sku
            ));
        }

        if ($id <= 0) {
            return null;
        }

        $product = wc_get_product($id);
        return $product ?: null;
    }

    /**
     * Variations are not upsert targets — use the parent catalog product.
     */
    private static function normalize_to_catalog_product(WC_Product $product): WC_Product
    {
        if ($product->is_type('variation')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    return $parent;
                }
            }
        }

        return $product;
    }

    private static function find_by_meta(string $key, string $value): ?WC_Product
    {
        $q = new WP_Query([
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => $key,
                    'value' => $value,
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            return null;
        }

        $product = wc_get_product((int) $q->posts[0]);
        return $product ?: null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, product_id?: string, error?: string}
     */
    private static function upsert_simple(?WC_Product $existing, array $payload): array
    {
        try {
            return self::save_simple($existing, $payload);
        } catch (Throwable $e) {
            if (!self::is_sku_conflict($e)) {
                return ['success' => false, 'error' => $e->getMessage()];
            }

            $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
            $by_sku = $sku !== '' ? self::find_by_sku($sku) : null;
            if (!$by_sku) {
                return ['success' => false, 'error' => $e->getMessage()];
            }

            try {
                return self::save_simple(self::normalize_to_catalog_product($by_sku), $payload);
            } catch (Throwable $retry) {
                return ['success' => false, 'error' => $retry->getMessage()];
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{success: bool, product_id?: string, error?: string}
     */
    private static function save_simple(?WC_Product $existing, array $payload): array
    {
        if ($existing && $existing->is_type('variable')) {
            // Convert path is complex; recreate as simple by keeping ID if possible.
            $product = new WC_Product_Simple($existing->get_id());
        } elseif ($existing && $existing->is_type('simple')) {
            $product = $existing;
        } elseif ($existing) {
            // Unknown type with an ID — still update in place as simple when possible.
            $product = new WC_Product_Simple($existing->get_id());
        } else {
            $product = new WC_Product_Simple();
        }

        self::apply_common($product, $payload);
        $product->set_manage_stock(true);
        if (array_key_exists('quantity', $payload) && $payload['quantity'] !== null) {
            $product->set_stock_quantity((int) $payload['quantity']);
            $product->set_stock_status(((int) $payload['quantity']) > 0 ? 'instock' : 'outofstock');
        }
        self::apply_prices($product, $payload);

        $id = $product->save();
        $images = self::after_save($id, $payload);

        $result = [
            'success'    => true,
            'product_id' => (string) $id,
        ];
        if ($images !== null) {
            $result['images_queued'] = $images['queued'];
            $result['images_mode'] = $images['mode'];
            if (isset($images['synced'])) {
                $result['images_synced'] = $images['synced'];
            }
            if (!empty($images['errors'])) {
                $result['image_errors'] = $images['errors'];
            }
        }
        return $result;
    }

    private static function is_sku_conflict(Throwable $e): bool
    {
        $message = $e->getMessage();
        return strpos($message, 'already present in the lookup table') !== false
            || strpos($message, 'Invalid or duplicated SKU') !== false
            || strpos($message, 'product_invalid_sku') !== false;
    }

    /**
     * @param array<string, mixed>   $payload
     * @param array<int, mixed>      $variants
     * @return array{success: bool, product_id?: string, variant_ids?: array<string, string>, error?: string}
     */
    private static function upsert_variable(?WC_Product $existing, array $payload, array $variants): array
    {
        try {
            return self::save_variable($existing, $payload, $variants);
        } catch (Throwable $e) {
            if (!self::is_sku_conflict($e)) {
                return ['success' => false, 'error' => $e->getMessage()];
            }

            $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
            $by_sku = $sku !== '' ? self::find_by_sku($sku) : null;
            if (!$by_sku) {
                return ['success' => false, 'error' => $e->getMessage()];
            }

            try {
                return self::save_variable(self::normalize_to_catalog_product($by_sku), $payload, $variants);
            } catch (Throwable $retry) {
                return ['success' => false, 'error' => $retry->getMessage()];
            }
        }
    }

    /**
     * @param array<string, mixed>   $payload
     * @param array<int, mixed>      $variants
     * @return array{success: bool, product_id?: string, variant_ids?: array<string, string>, error?: string}
     */
    private static function save_variable(?WC_Product $existing, array $payload, array $variants): array
    {
        if ($existing && $existing->is_type('variable')) {
            $product = $existing;
        } elseif ($existing) {
            $product = new WC_Product_Variable($existing->get_id());
        } else {
            $product = new WC_Product_Variable();
        }

        self::apply_common($product, $payload);

        $attribute_names = self::collect_variation_attribute_names($variants);
        if ($attribute_names === []) {
            $attribute_names = ['Size'];
        }

        $attributes = [];
        foreach ($attribute_names as $attr_name) {
            $options = [];
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $attrs = isset($variant['attributes']) && is_array($variant['attributes'])
                    ? $variant['attributes']
                    : [];
                $val = '';
                if (isset($attrs[$attr_name])) {
                    $val = (string) $attrs[$attr_name];
                } elseif ($attr_name === 'Size') {
                    $val = (string) ($variant['marketplace_size'] ?? $variant['tech_size'] ?? '');
                }
                if ($val !== '') {
                    $options[] = $val;
                }
            }
            $options = array_values(array_unique($options));
            if ($options === []) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_name);
            $attribute->set_options($options);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[] = $attribute;
        }

        // Merge non-variation attributes from Delegat category fields
        if (!empty($payload['attributes']) && is_array($payload['attributes'])) {
            $variation_names = [];
            foreach ($attribute_names as $attr_name) {
                $variation_names[strtolower((string) $attr_name)] = true;
            }
            foreach ($payload['attributes'] as $name => $value) {
                $name = (string) $name;
                if ($name === '' || isset($variation_names[strtolower($name)])) {
                    continue;
                }
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($name);
                $attribute->set_options([(string) $value]);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
            }
        }

        $product->set_attributes($attributes);
        $id = $product->save();
        $images = self::after_save($id, $payload);

        $variant_ids = [];
        $seen_variation_ids = [];

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $delegat_variant_id = (string) ($variant['variant_id'] ?? '');
            if ($delegat_variant_id === '') {
                continue;
            }

            $variation = self::find_variation($id, $delegat_variant_id);
            if (!$variation) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($id);
            }

            $var_attrs = [];
            $attrs = isset($variant['attributes']) && is_array($variant['attributes'])
                ? $variant['attributes']
                : [];
            foreach ($attribute_names as $attr_name) {
                $val = '';
                if (isset($attrs[$attr_name])) {
                    $val = (string) $attrs[$attr_name];
                } elseif ($attr_name === 'Size') {
                    $val = (string) ($variant['marketplace_size'] ?? $variant['tech_size'] ?? '');
                }
                if ($val !== '') {
                    $var_attrs[sanitize_title($attr_name)] = $val;
                }
            }
            $variation->set_attributes($var_attrs);

            if (!empty($variant['sku'])) {
                $variation->set_sku((string) $variant['sku']);
            }

            $variation->set_manage_stock(true);
            if (array_key_exists('quantity', $variant) && $variant['quantity'] !== null) {
                $qty = (int) $variant['quantity'];
                $variation->set_stock_quantity($qty);
                $variation->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
            }

            $price = isset($variant['price']) ? $variant['price'] : ($payload['price'] ?? null);
            $sale  = isset($variant['sale_price']) ? $variant['sale_price'] : ($payload['sale_price'] ?? null);
            self::apply_prices($variation, [
                'price'      => $price,
                'sale_price' => $sale,
            ]);

            $variation->set_status('publish');
            $vid = $variation->save();
            update_post_meta($vid, self::META_VARIANT_ID, $delegat_variant_id);
            $variant_ids[$delegat_variant_id] = (string) $vid;
            $seen_variation_ids[] = $vid;
        }

        WC_Product_Variable::sync($id);

        $result = [
            'success'     => true,
            'product_id'  => (string) $id,
            'variant_ids' => $variant_ids,
        ];
        if ($images !== null) {
            $result['images_queued'] = $images['queued'];
            $result['images_mode'] = $images['mode'];
            if (isset($images['synced'])) {
                $result['images_synced'] = $images['synced'];
            }
            if (!empty($images['errors'])) {
                $result['image_errors'] = $images['errors'];
            }
        }
        return $result;
    }

    /**
     * @param array<int, mixed> $variants
     * @return string[]
     */
    private static function collect_variation_attribute_names(array $variants): array
    {
        $names = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $attrs = isset($variant['attributes']) && is_array($variant['attributes'])
                ? $variant['attributes']
                : [];
            foreach (array_keys($attrs) as $key) {
                $names[] = (string) $key;
            }
            if ($attrs === [] && (
                !empty($variant['tech_size']) || !empty($variant['marketplace_size'])
            )) {
                $names[] = 'Size';
            }
        }
        return array_values(array_unique($names));
    }

    private static function find_variation(int $parent_id, string $delegat_variant_id): ?WC_Product_Variation
    {
        $q = new WP_Query([
            'post_type'      => 'product_variation',
            'post_parent'    => $parent_id,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => self::META_VARIANT_ID,
                    'value' => $delegat_variant_id,
                ],
            ],
        ]);

        if (!$q->have_posts()) {
            return null;
        }

        $product = wc_get_product((int) $q->posts[0]);
        return $product instanceof WC_Product_Variation ? $product : null;
    }

    /**
     * @param WC_Product           $product
     * @param array<string, mixed> $payload
     */
    private static function apply_common(WC_Product $product, array $payload): void
    {
        $product->set_name((string) $payload['name']);
        if (isset($payload['description'])) {
            $product->set_description((string) $payload['description']);
        }
        if (isset($payload['short_description'])) {
            $product->set_short_description((string) $payload['short_description']);
        }
        if (!empty($payload['sku']) && !$product->is_type('variable')) {
            $product->set_sku((string) $payload['sku']);
        } elseif (!empty($payload['sku']) && $product->is_type('variable')) {
            // Parent SKU optional for variable products
            try {
                $product->set_sku((string) $payload['sku']);
            } catch (Exception $e) {
                // Ignore SKU conflict on parent
            }
        }

        $status = isset($payload['status']) ? (string) $payload['status'] : 'publish';
        $product->set_status($status === 'draft' ? 'draft' : 'publish');
        $product->set_catalog_visibility('visible');

        if (!empty($payload['category_ids']) && is_array($payload['category_ids'])) {
            $term_ids = array_map('absint', $payload['category_ids']);
            $term_ids = array_values(array_filter($term_ids));
            if ($term_ids) {
                $product->set_category_ids($term_ids);
            }
        }

        // Product attributes (custom fields from Delegat category attributes)
        if (!empty($payload['attributes']) && is_array($payload['attributes'])) {
            $existing = [];
            // Preserve variation attributes when updating a variable product
            if ($product->is_type('variable')) {
                foreach ($product->get_attributes() as $attr) {
                    if ($attr->get_variation()) {
                        $existing[] = $attr;
                    }
                }
            }

            $variation_names = [];
            foreach ($existing as $attr) {
                $variation_names[strtolower($attr->get_name())] = true;
            }

            foreach ($payload['attributes'] as $name => $value) {
                $name = (string) $name;
                if ($name === '' || isset($variation_names[strtolower($name)])) {
                    continue;
                }
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($name);
                $attribute->set_options([(string) $value]);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $existing[] = $attribute;
            }

            if ($existing) {
                $product->set_attributes($existing);
            }
        }
    }

    /**
     * @param WC_Product           $product
     * @param array<string, mixed> $payload
     */
    private static function apply_prices(WC_Product $product, array $payload): void
    {
        $regular = isset($payload['price']) && $payload['price'] !== null && $payload['price'] !== ''
            ? (string) $payload['price']
            : '';
        $sale = isset($payload['sale_price']) && $payload['sale_price'] !== null && $payload['sale_price'] !== ''
            ? (string) $payload['sale_price']
            : '';

        if ($regular !== '') {
            $product->set_regular_price($regular);
        }
        if ($sale !== '' && $regular !== '' && (float) $sale < (float) $regular) {
            $product->set_sale_price($sale);
        } else {
            $product->set_sale_price('');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{queued: int, mode: string, synced?: int, errors?: string[]}|null
     */
    private static function after_save(int $product_id, array $payload): ?array
    {
        if (!empty($payload['delegat_product_id'])) {
            update_post_meta($product_id, self::META_PRODUCT_ID, (string) $payload['delegat_product_id']);
        }

        if (!empty($payload['barcode'])) {
            update_post_meta($product_id, '_global_unique_id', (string) $payload['barcode']);
        }

        if (!empty($payload['brand'])) {
            update_post_meta($product_id, '_delegat_brand', (string) $payload['brand']);
        }

        if (empty($payload['image_urls']) || !is_array($payload['image_urls'])) {
            return null;
        }

        // Default: queue + adapter/AS drain (fast upsert). Inline only when explicitly requested.
        $defer = empty($payload['sync_images_inline']);
        return Delegat_Bridge_Images::queue_product_images($product_id, $payload['image_urls'], $defer);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{success: bool, product_id?: string, error?: string}
     */
    public static function update_price(array $item): array
    {
        $product = self::resolve_target(
            (string) ($item['product_id'] ?? ''),
            isset($item['variant_id']) ? (string) $item['variant_id'] : null
        );
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found', 'product_id' => (string) ($item['product_id'] ?? '')];
        }

        self::apply_prices($product, $item);
        $id = $product->save();
        return ['success' => true, 'product_id' => (string) $id];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{success: bool, product_id?: string, error?: string}
     */
    public static function update_stock(array $item): array
    {
        $product = self::resolve_target(
            (string) ($item['product_id'] ?? ''),
            isset($item['variant_id']) ? (string) $item['variant_id'] : null
        );
        if (!$product) {
            return ['success' => false, 'error' => 'Product not found', 'product_id' => (string) ($item['product_id'] ?? '')];
        }

        $qty = (int) ($item['quantity'] ?? 0);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $id = $product->save();
        return ['success' => true, 'product_id' => (string) $id];
    }

    private static function resolve_target(string $product_id, ?string $variant_id): ?WC_Product
    {
        $pid = absint($product_id);
        if ($pid <= 0) {
            return null;
        }

        if ($variant_id !== null && $variant_id !== '') {
            // Prefer WC variation ID if numeric; else Delegat variant meta under parent
            if (ctype_digit($variant_id)) {
                $variation = wc_get_product((int) $variant_id);
                if ($variation instanceof WC_Product_Variation) {
                    return $variation;
                }
            }
            $found = self::find_variation($pid, $variant_id);
            if ($found) {
                return $found;
            }
        }

        $product = wc_get_product($pid);
        return $product ?: null;
    }
}
