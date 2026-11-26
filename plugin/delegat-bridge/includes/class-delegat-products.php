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
            $has_variants = self::payload_has_variants($payload);

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
     * The write branch is selected from the outgoing card shape, never from
     * the type of product that already exists: a simple product can be
     * converted to variable and vice versa. Read-only resolution receives the
     * same shape as the explicit has_variants flag instead of the full
     * variants array.
     *
     * @param array<string, mixed> $payload
     */
    private static function payload_has_variants(array $payload, bool $include_resolution_flag = false): bool
    {
        $variants = $payload['variants'] ?? null;
        if (is_array($variants)) {
            return count($variants) > 0;
        }

        return $include_resolution_flag && !empty($payload['has_variants']);
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
            // Prefer WC variation ID if numeric and owned by this parent; else Delegat variant meta under parent.
            // Never fall back to updating the parent product when an explicit variant_id cannot be resolved.
            if (ctype_digit($variant_id)) {
                $variation = wc_get_product((int) $variant_id);
                if ($variation instanceof WC_Product_Variation && (int) $variation->get_parent_id() === $pid) {
                    return $variation;
                }
            }
            $found = self::find_variation($pid, $variant_id);
            return $found ?: null;
        }

        $product = wc_get_product($pid);
        return $product ?: null;
    }

    /**
     * Read-only canonical resolution of a card upsert payload, using the exact
     * precedence of the write path (product_id, then _delegat_product_id meta,
     * then sku). Exposed so /resolve can report write-shape-faithful evidence;
     * batch deduplication deliberately does not use cross-form card aliases as
     * identity.
     *
     * @param array<string, mixed> $payload
     */
    public static function resolve_card_target(array $payload): ?WC_Product
    {
        $resolution = self::resolve_card_target_with_evidence($payload);
        return $resolution['product'];
    }

    /**
     * Canonical card target plus the reason-dependent evidence preparation
     * needs. A SKU-conflict redirect is not the same kind of identity as a
     * direct product/meta/SKU-resolution hit: another surviving card in the
     * same batch may release that SKU before this write runs, so using the
     * redirect target for deduplication can drop the release and overwrite the
     * current owner. The endpoint therefore exposes the redirect separately;
     * the batch contract treats every redirect as ordered sequential bridge
     * behavior, never as duplicate identity.
     *
     * @param array<string, mixed> $payload
     * @return array{product: ?WC_Product, sku_conflict_redirect: bool, ambiguous: bool, possible_ids: list<string>}
     */
    public static function resolve_card_target_with_evidence(array $payload): array
    {
        $target = self::find_existing($payload);
        $sku_conflict_redirect = false;
        $ambiguous = false;
        $possible_ids = [];

        // Mirror the write path's SKU-conflict correction using the OUTGOING
        // card shape, not the existing product type. upsert_simple retries the
        // save against the SKU owner whenever applying the submitted SKU
        // collides — including when the write is converting an existing
        // variable product back to simple. WooCommerce's uniqueness check
        // excludes posts in trash, while find_by_sku deliberately includes
        // them for identity resolution and post-conflict retry lookup. A
        // trash-only owner is therefore never evidence that a simple write
        // will redirect before that retry happens.
        //
        // Variable cards are different: apply_common swallows a parent-SKU
        // conflict, but a colliding variation SKU can still make upsert_variable
        // retry against the parent SKU owner. Whether that retry is the actual
        // destination depends on child Delegat ids/SKUs and on rows already
        // present under both parents. Full child rows are mirrored against the
        // current database; omitted rows or a collision that neither parent can
        // accept are exposed as ambiguity rather than either parent as
        // definitive write evidence.
        $has_variants = self::payload_has_variants($payload, true);
        if ($target) {
            $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
            if ($sku !== '') {
                $owner = self::find_by_sku($sku);
                if ($owner) {
                    $owner_can_conflict = self::sku_owner_conflicts_with_write($owner);
                    $owner = self::normalize_to_catalog_product($owner);
                    $owner_is_initial = (int) $owner->get_id() === (int) $target->get_id();
                    if (!$has_variants) {
                        if (!$owner_is_initial && $owner_can_conflict) {
                            $target = $owner;
                            $sku_conflict_redirect = true;
                        }
                    } else {
                        $sku_catalog_target_ids = self::sku_catalog_target_ids($sku);
                        if (!$owner_is_initial || count($sku_catalog_target_ids) > 1) {
                            $variable_target = self::resolve_variable_sku_target(
                                $target,
                                $owner,
                                $payload,
                                $owner_can_conflict,
                                $sku_catalog_target_ids
                            );
                            if ($variable_target === 'redirect' && !$owner_is_initial) {
                                $target = $owner;
                                $sku_conflict_redirect = true;
                            } elseif ($variable_target === 'ambiguous') {
                                $ambiguous = true;
                                $possible_ids = array_values(array_unique(array_merge(
                                    [
                                        (string) $target->get_id(),
                                        (string) $owner->get_id(),
                                    ],
                                    array_map('strval', $sku_catalog_target_ids)
                                )));
                                $target = null;
                            }
                        }
                    }
                }
            }
        }

        return [
            'product'               => $target,
            'sku_conflict_redirect' => $sku_conflict_redirect,
            'ambiguous'             => $ambiguous,
            'possible_ids'          => $possible_ids,
        ];
    }

    /**
     * WooCommerce SKU uniqueness ignores trash even though bridge identity
     * lookup intentionally includes it. Check the object before normalizing a
     * variation to its parent: the lookup row whose status matters is the SKU
     * owner itself, not the parent catalog product.
     */
    private static function sku_owner_conflicts_with_write(WC_Product $owner): bool
    {
        return $owner->get_status() !== 'trash';
    }

    /**
     * Catalog product ids eligible to be selected for one SKU by the write
     * retry, with variation rows normalized to their parents. Non-trash rows
     * shadow the trash-inclusive fallback exactly as find_by_sku does. More
     * than one id in the applicable tier means LIMIT 1 cannot provide an
     * honest redirect target.
     *
     * @return int[]
     */
    private static function sku_catalog_target_ids(string $sku): array
    {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT
                CASE
                    WHEN posts.post_type = 'product_variation' AND parent_posts.ID IS NOT NULL THEN posts.post_parent
                    ELSE posts.ID
                END AS catalog_target_id,
                CASE WHEN posts.post_status = 'trash' THEN 0 ELSE 1 END AS primary_lookup
            FROM {$lookup} AS lookup
            INNER JOIN {$wpdb->posts} AS posts ON posts.ID = lookup.product_id
            LEFT JOIN {$wpdb->posts} AS parent_posts ON parent_posts.ID = posts.post_parent
            WHERE lookup.sku = %s",
            $sku
        ));

        $primary_ids = [];
        $fallback_ids = [];
        foreach ((array) $rows as $row) {
            $id = (int) $row->catalog_target_id;
            if ((int) $row->primary_lookup === 1) {
                $primary_ids[] = $id;
            } else {
                $fallback_ids[] = $id;
            }
        }
        $ids = $primary_ids !== [] ? $primary_ids : $fallback_ids;

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Resolve the variable-card branch of a SKU-owner mismatch. A parent SKU
     * conflict itself is swallowed by apply_common; only a child variation SKU
     * conflict can invoke upsert_variable's retry against the parent SKU owner.
     * With full child rows we mirror that collision check against the current
     * database for both the initial parent and the retry parent. Without those
     * rows, or when neither parent can accept the child SKUs, neither side is
     * honest canonical write evidence. Existing duplicate SKU rows cannot
     * support one redirect target either. A trash-only parent-SKU owner also
     * cannot support redirect evidence when the writer will submit that SKU:
     * the initial parent can claim it before the child collision, so the retry
     * lookup runs against mutated duplicate rows instead of the ownership
     * state mirrored here.
     *
     * @param array<string, mixed> $payload
     * @return 'initial'|'redirect'|'ambiguous'
     */
    private static function resolve_variable_sku_target(WC_Product $target, WC_Product $owner, array $payload, bool $owner_can_conflict, array $sku_catalog_target_ids): string
    {
        $variants = $payload['variants'] ?? null;
        if (!is_array($variants)) {
            return 'ambiguous';
        }
        if (!self::variable_card_may_have_sku_collision($payload)) {
            return 'initial';
        }
        $hidden_parent_sku_collision = !$owner_can_conflict
            && !empty($payload['sku'])
            && self::variable_child_reuses_parent_sku($payload, $variants);
        if (!self::variable_write_has_sku_conflict((int) $target->get_id(), $variants)
            && !$hidden_parent_sku_collision
        ) {
            return 'initial';
        }

        // apply_common uses empty(), so the string "0" is not a submitted
        // parent SKU even though the trim() guard above treats it as present.
        if (count($sku_catalog_target_ids) > 1 || (!$owner_can_conflict && !empty($payload['sku']))) {
            return 'ambiguous';
        }
        if (self::variable_conflict_follows_sku_claim((int) $target->get_id(), $variants)) {
            return 'ambiguous';
        }
        if (!self::variable_write_has_sku_conflict((int) $owner->get_id(), $variants)) {
            return 'redirect';
        }
        return 'ambiguous';
    }

    /**
     * A variable write can only redirect through a child SKU collision when
     * the submitted children carry SKUs. The empty() mirror is deliberate:
     * the writer tests !empty($variant['sku']), so the string "0" is not a
     * submitted child SKU even though it is non-empty after trim semantics.
     *
     * @param array<string, mixed> $payload
     */
    private static function variable_card_may_have_sku_collision(array $payload): bool
    {
        $variants = $payload['variants'] ?? null;
        if (!is_array($variants)) {
            return true;
        }
        foreach ($variants as $variant) {
            if (is_array($variant) && !empty($variant['sku'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a writable child row uses the parent SKU. When that parent SKU
     * is trash-only before the write, the parent save claims it first and the
     * child then collides with the parent even though the pre-write lookup did
     * not show a child-SKU conflict.
     *
     * @param array<string, mixed> $payload
     * @param array<int, mixed>      $variants
     */
    private static function variable_child_reuses_parent_sku(array $payload, array $variants): bool
    {
        $parent_sku = (string) $payload['sku'];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            if ((string) ($variant['variant_id'] ?? '') === '' || empty($variant['sku'])) {
                continue;
            }
            if ((string) $variant['sku'] === $parent_sku) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the first predicted initial-parent SKU collision is preceded by
     * a successful child SKU claim. save_variable commits earlier children
     * before the collision and upsert_variable does not roll them back, so
     * those claims still block the retry parent even when they were absent
     * from the database during this read-only resolution.
     *
     * @param array<int, mixed> $variants
     */
    private static function variable_conflict_follows_sku_claim(int $parent_id, array $variants): bool
    {
        $prior_claim = false;
        $seen_sku_variant_ids = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $delegat_variant_id = (string) ($variant['variant_id'] ?? '');
            if ($delegat_variant_id === '' || empty($variant['sku'])) {
                continue;
            }
            $sku = (string) $variant['sku'];
            $duplicate_distinct_child = isset($seen_sku_variant_ids[$sku])
                && $seen_sku_variant_ids[$sku] !== $delegat_variant_id;
            $seen_sku_variant_ids[$sku] = $delegat_variant_id;

            $target_variation = self::find_variation($parent_id, $delegat_variant_id);
            $owner = self::find_by_sku($sku);
            $self_owned = $target_variation && $owner
                && (int) $target_variation->get_id() === (int) $owner->get_id();
            $conflicts = $duplicate_distinct_child
                || ($owner && self::sku_owner_conflicts_with_write($owner) && !$self_owned);
            if ($conflicts) {
                return $prior_claim;
            }
            if (!$self_owned) {
                $prior_claim = true;
            }
        }
        return false;
    }

    /**
     * Predict whether save_variable would hit a SKU uniqueness error on one
     * parent. Variation lookup mirrors the writer exactly: Delegat variant
     * meta scoped to that parent selects the row being written, while SKU
     * lookup remains trash-inclusive for identity but trash-exclusive for the
     * WooCommerce uniqueness conflict itself. Distinct child rows repeating
     * one SKU inside the same card are also a conflict because the first save
     * can claim the SKU before the second row runs.
     *
     * @param array<int, mixed> $variants
     */
    private static function variable_write_has_sku_conflict(int $parent_id, array $variants): bool
    {
        $seen_sku_variant_ids = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $delegat_variant_id = (string) ($variant['variant_id'] ?? '');
            if ($delegat_variant_id === '' || empty($variant['sku'])) {
                continue;
            }
            $sku = (string) $variant['sku'];
            if (isset($seen_sku_variant_ids[$sku]) && $seen_sku_variant_ids[$sku] !== $delegat_variant_id) {
                return true;
            }
            $seen_sku_variant_ids[$sku] = $delegat_variant_id;

            $target_variation = self::find_variation($parent_id, $delegat_variant_id);
            $owner = self::find_by_sku($sku);
            if (!$owner || !self::sku_owner_conflicts_with_write($owner)) {
                continue;
            }
            if ($target_variation && (int) $target_variation->get_id() === (int) $owner->get_id()) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Read-only canonical resolution of a price/stock mutation target, using
     * the exact precedence of the write path (numeric WC variation id owned by
     * the parent first, then Delegat variant meta scoped to the parent; never
     * falling back to the parent when a variant_id is given).
     */
    public static function resolve_mutation_target(string $product_id, ?string $variant_id): ?WC_Product
    {
        return self::resolve_target($product_id, $variant_id);
    }
}
