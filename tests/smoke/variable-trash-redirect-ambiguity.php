<?php
/**
 * Dockerized WooCommerce smoke regression for variable-card redirect evidence.
 * Executed by wp eval-file with WordPress, WooCommerce, and the bridge loaded.
 */

function smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smoke_create_variable_product(string $name, array $options, string $sku = ''): int
{
    $product = new WC_Product_Variable();
    $product->set_name($name);
    $product->set_status('publish');
    if ($sku !== '') {
        $product->set_sku($sku);
    }

    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Size');
    $attribute->set_options($options);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes([$attribute]);

    return $product->save();
}

function smoke_create_variation(int $parent_id, string $delegat_variant_id, string $sku, string $option): int
{
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($parent_id);
    $variation->set_attributes(['size' => $option]);
    $variation->set_sku($sku);
    $variation->set_regular_price('10');
    $variation->set_status('publish');
    $id = $variation->save();
    update_post_meta($id, Delegat_Bridge_Products::META_VARIANT_ID, $delegat_variant_id);

    return $id;
}

function smoke_card_resolution(array $payload): array
{
    return Delegat_Bridge_Products::resolve_card_target_with_evidence($payload);
}

function smoke_resolve_row(array $payload): array
{
    $row = ['kind' => 'card'] + $payload;
    $request = new WP_REST_Request('POST', '/delegat/v1/resolve');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) json_encode(['rows' => [$row]]));

    $response = Delegat_Bridge_REST::resolve_rows($request);
    smoke_assert($response instanceof WP_REST_Response, 'resolve did not return a REST response');
    $data = $response->get_data();
    smoke_assert(is_array($data) && !empty($data['success']), 'resolve endpoint did not succeed');
    smoke_assert(isset($data['results'][0]) && is_array($data['results'][0]), 'resolve endpoint returned no row result');

    return $data['results'][0];
}

function smoke_assert_ambiguous(array $resolution, int $initial_id, int $owner_id, string $label): void
{
    smoke_assert($resolution['product'] === null, $label . ': ambiguous resolution named a canonical product');
    smoke_assert($resolution['ambiguous'] === true, $label . ': ambiguous flag was not set');
    smoke_assert($resolution['sku_conflict_redirect'] === false, $label . ': ambiguous resolution still claimed a redirect');
    $possible = array_map('strval', $resolution['possible_ids']);
    sort($possible);
    $expected = [(string) $initial_id, (string) $owner_id];
    sort($expected);
    smoke_assert($possible === $expected, $label . ': possible_ids did not contain exactly the initial and owner parents');
}

function smoke_assert_rest_ambiguous(array $payload, int $initial_id, int $owner_id, string $label): void
{
    $result = smoke_resolve_row($payload);
    smoke_assert($result['found'] === true, $label . ': REST row was not found');
    smoke_assert($result['canonical_id'] === null, $label . ': REST row invented a canonical id');
    smoke_assert($result['sku_conflict_redirect'] === false, $label . ': REST row claimed a redirect');
    smoke_assert($result['ambiguous'] === true, $label . ': REST row was not ambiguous');
    $possible = array_map('strval', $result['possible_ids']);
    sort($possible);
    $expected = [(string) $initial_id, (string) $owner_id];
    sort($expected);
    smoke_assert($possible === $expected, $label . ': REST possible_ids mismatch');
}

try {
    smoke_assert(class_exists('WooCommerce'), 'WooCommerce is not loaded');
    smoke_assert(class_exists('Delegat_Bridge_Products'), 'Delegat Bridge is not loaded');

    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $parent_sku = 'SMOKE-PARENT-' . $suffix;
    $claimed_child_sku = 'SMOKE-CLAIMED-' . $suffix;
    $blocking_child_sku = 'SMOKE-BLOCKING-' . $suffix;

    $owner_id = smoke_create_variable_product('Smoke retry owner ' . $suffix, ['Small', 'Blue']);
    $trashed_owner_variation = smoke_create_variation($owner_id, 'trashed-parent-sku', $parent_sku, 'Small');
    $trashed_product = wc_get_product($trashed_owner_variation);
    smoke_assert($trashed_product instanceof WC_Product_Variation, 'trashed parent-SKU variation was not created');
    $trashed_product->delete(false);
    $trashed_product = wc_get_product($trashed_owner_variation);
    smoke_assert($trashed_product instanceof WC_Product_Variation, 'trashed parent-SKU variation is unreadable');
    smoke_assert($trashed_product->get_status() === 'trash', 'parent-SKU variation was not moved to trash');
    smoke_create_variation($owner_id, 'blue', $blocking_child_sku, 'Blue');

    $initial_id = smoke_create_variable_product('Smoke initial parent ' . $suffix, ['Small', 'Blue']);
    $payload = [
        'product_id'  => $initial_id,
        'name'        => 'Smoke initial parent ' . $suffix,
        'sku'         => $parent_sku,
        'has_variants' => true,
        'variants'    => [
            [
                'variant_id' => 'small',
                'sku'        => $claimed_child_sku,
                'attributes' => ['Size' => 'Small'],
            ],
            [
                'variant_id' => 'blue',
                'sku'        => $blocking_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];

    smoke_assert_ambiguous(
        smoke_card_resolution($payload),
        $initial_id,
        $owner_id,
        'trash-only parent owner with prior child claim'
    );
    smoke_assert_rest_ambiguous(
        $payload,
        $initial_id,
        $owner_id,
        'trash-only parent owner with prior child claim'
    );
    echo "smoke: trash-only parent owner plus prior child claim is ambiguous\n";

    // The first-collision control isolates the parent-SKU claim from the
    // child partial-write ambiguity that was already covered before this fix.
    $first_collision_payload = $payload;
    $first_collision_payload['variants'] = [$payload['variants'][1]];
    smoke_assert_ambiguous(
        smoke_card_resolution($first_collision_payload),
        $initial_id,
        $owner_id,
        'trash-only parent owner at first collision'
    );
    smoke_assert_rest_ambiguous(
        $first_collision_payload,
        $initial_id,
        $owner_id,
        'trash-only parent owner at first collision'
    );
    echo "smoke: trash-only parent owner cannot become a guessed redirect\n";

    $live_parent_sku = 'SMOKE-LIVE-PARENT-' . $suffix;
    $live_child_sku = 'SMOKE-LIVE-CHILD-' . $suffix;
    $live_owner_id = smoke_create_variable_product('Smoke live retry owner ' . $suffix, ['Blue'], $live_parent_sku);
    smoke_create_variation($live_owner_id, 'live-blue', $live_child_sku, 'Blue');
    $redirect_payload = [
        'product_id'  => $initial_id,
        'name'        => 'Smoke initial parent ' . $suffix,
        'sku'         => $live_parent_sku,
        'has_variants' => true,
        'variants'    => [
            [
                'variant_id' => 'live-blue',
                'sku'        => $live_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    $redirect = smoke_card_resolution($redirect_payload);
    smoke_assert($redirect['product'] instanceof WC_Product, 'live-owner redirect did not resolve a product');
    smoke_assert((int) $redirect['product']->get_id() === $live_owner_id, 'live-owner redirect named the wrong parent');
    smoke_assert($redirect['sku_conflict_redirect'] === true, 'live-owner redirect flag was not set');
    smoke_assert($redirect['ambiguous'] === false, 'live-owner redirect was over-reported as ambiguous');
    $rest_redirect = smoke_resolve_row($redirect_payload);
    smoke_assert($rest_redirect['canonical_id'] === (string) $live_owner_id, 'REST live-owner canonical id mismatch');
    smoke_assert($rest_redirect['sku_conflict_redirect'] === true, 'REST live-owner redirect flag mismatch');
    smoke_assert($rest_redirect['ambiguous'] === false, 'REST live-owner redirect was ambiguous');
    echo "smoke: live parent owner remains a deterministic redirect\n";

    // Pre-existing duplicated lookup rows are ambiguous even when the live row
    // would be a writable retry parent: LIMIT 1 still cannot represent every
    // catalog target named by the duplicated SKU.
    $duplicate_parent_sku = 'SMOKE-DUP-PARENT-' . $suffix;
    $duplicate_child_sku = 'SMOKE-DUP-CHILD-' . $suffix;
    $duplicate_trash_owner_id = smoke_create_variable_product('Smoke duplicate trash owner ' . $suffix, ['Small']);
    $duplicate_trash_variation = smoke_create_variation(
        $duplicate_trash_owner_id,
        'duplicate-trashed-parent-sku',
        $duplicate_parent_sku,
        'Small'
    );
    $duplicate_trash_product = wc_get_product($duplicate_trash_variation);
    smoke_assert($duplicate_trash_product instanceof WC_Product_Variation, 'duplicate trash variation was not created');
    $duplicate_trash_product->delete(false);

    $duplicate_live_owner_id = smoke_create_variable_product(
        'Smoke duplicate live owner ' . $suffix,
        ['Blue'],
        $duplicate_parent_sku
    );
    smoke_create_variation($duplicate_live_owner_id, 'duplicate-blue', $duplicate_child_sku, 'Blue');
    $duplicate_payload = [
        'product_id'  => $initial_id,
        'name'        => 'Smoke initial parent ' . $suffix,
        'sku'         => $duplicate_parent_sku,
        'has_variants' => true,
        'variants'    => [
            [
                'variant_id' => 'duplicate-blue',
                'sku'        => $duplicate_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    $duplicate_result = smoke_resolve_row($duplicate_payload);
    smoke_assert($duplicate_result['canonical_id'] === null, 'duplicate SKU rows invented a canonical id');
    smoke_assert($duplicate_result['sku_conflict_redirect'] === false, 'duplicate SKU rows claimed a redirect');
    smoke_assert($duplicate_result['ambiguous'] === true, 'duplicate SKU rows were not ambiguous');
    $duplicate_possible = array_map('strval', $duplicate_result['possible_ids']);
    sort($duplicate_possible);
    $duplicate_expected = [
        (string) $initial_id,
        (string) $duplicate_live_owner_id,
        (string) $duplicate_trash_owner_id,
    ];
    sort($duplicate_expected);
    smoke_assert($duplicate_possible === $duplicate_expected, 'duplicate SKU possible_ids did not include every catalog target');
    echo "smoke: duplicated parent SKU rows expose every possible parent\n";

    echo "SMOKE PASS: variable trash redirect ambiguity\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SMOKE FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
