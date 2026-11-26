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

function smoke_sorted_ids(array $ids): array
{
    $ids = array_values(array_unique(array_map('strval', $ids)));
    sort($ids, SORT_STRING);
    return $ids;
}

function smoke_assert_ambiguous(array $resolution, array $expected_ids, string $label): void
{
    smoke_assert($resolution['product'] === null, $label . ': ambiguous resolution named a canonical product');
    smoke_assert($resolution['ambiguous'] === true, $label . ': ambiguous flag was not set');
    smoke_assert($resolution['sku_conflict_redirect'] === false, $label . ': ambiguous resolution still claimed a redirect');
    smoke_assert(
        smoke_sorted_ids($resolution['possible_ids']) === smoke_sorted_ids($expected_ids),
        $label . ': possible_ids mismatch'
    );
}

function smoke_assert_rest_ambiguous(array $payload, array $expected_ids, string $label): void
{
    $result = smoke_resolve_row($payload);
    smoke_assert($result['found'] === true, $label . ': REST row was not found');
    smoke_assert($result['canonical_id'] === null, $label . ': REST row invented a canonical id');
    smoke_assert($result['sku_conflict_redirect'] === false, $label . ': REST row claimed a redirect');
    smoke_assert($result['ambiguous'] === true, $label . ': REST row was not ambiguous');
    smoke_assert(
        smoke_sorted_ids($result['possible_ids']) === smoke_sorted_ids($expected_ids),
        $label . ': REST possible_ids mismatch'
    );
}

function smoke_assert_redirect(array $resolution, int $owner_id, string $label): void
{
    smoke_assert($resolution['product'] instanceof WC_Product, $label . ': redirect did not resolve a product');
    smoke_assert((int) $resolution['product']->get_id() === $owner_id, $label . ': redirect named the wrong parent');
    smoke_assert($resolution['sku_conflict_redirect'] === true, $label . ': redirect flag was not set');
    smoke_assert($resolution['ambiguous'] === false, $label . ': redirect was over-reported as ambiguous');
}

function smoke_assert_write_target(array $payload, int $expected_id, string $label): void
{
    $result = Delegat_Bridge_Products::upsert($payload);
    smoke_assert(
        !empty($result['success']),
        $label . ': write did not succeed' . (isset($result['error']) ? ': ' . $result['error'] : '')
    );
    smoke_assert(
        isset($result['product_id']) && (int) $result['product_id'] === $expected_id,
        $label . ': write landed on the wrong product'
    );
}

try {
    smoke_assert(class_exists('WooCommerce'), 'WooCommerce is not loaded');
    smoke_assert(class_exists('Delegat_Bridge_Products'), 'Delegat Bridge is not loaded');

    $suffix = strtoupper(bin2hex(random_bytes(4)));

    // A trash-only parent-SKU row is not a live retry owner. Once the initial
    // parent claims that SKU, a later child collision must not be reported as
    // a redirect to the trashed pre-write row.
    $parent_sku = 'SMOKE-PARENT-' . $suffix;
    $claimed_child_sku = 'SMOKE-CLAIMED-' . $suffix;
    $blocking_child_sku = 'SMOKE-BLOCKING-' . $suffix;

    $owner_id = smoke_create_variable_product('Smoke retry owner ' . $suffix, ['Small', 'Blue']);
    $trashed_owner_variation = smoke_create_variation($owner_id, 'trashed-parent-sku', $parent_sku, 'Small');
    $trashed_product = wc_get_product($trashed_owner_variation);
    smoke_assert($trashed_product instanceof WC_Product_Variation, 'trashed parent-SKU variation was not created');
    $trashed_product->delete(false);
    smoke_create_variation($owner_id, 'blocking-blue', $blocking_child_sku, 'Blue');

    $initial_id = smoke_create_variable_product('Smoke initial parent ' . $suffix, ['Small', 'Blue']);
    $payload = [
        'product_id'   => $initial_id,
        'name'         => 'Smoke initial parent ' . $suffix,
        'sku'          => $parent_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'small',
                'sku'        => $claimed_child_sku,
                'attributes' => ['Size' => 'Small'],
            ],
            [
                'variant_id' => 'blocking-blue',
                'sku'        => $blocking_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];

    smoke_assert_ambiguous(
        smoke_card_resolution($payload),
        [$initial_id, $owner_id],
        'trash-only parent owner with prior child claim'
    );
    smoke_assert_rest_ambiguous(
        $payload,
        [$initial_id, $owner_id],
        'trash-only parent owner with prior child claim'
    );
    echo "smoke: trash-only parent owner cannot become a guessed redirect\n";

    // The same guard does not depend on an earlier partial child write.
    $first_collision_payload = $payload;
    $first_collision_payload['variants'] = [$payload['variants'][1]];
    smoke_assert_ambiguous(
        smoke_card_resolution($first_collision_payload),
        [$initial_id, $owner_id],
        'trash-only parent owner at first collision'
    );
    echo "smoke: first-collision trash-owner evidence remains ambiguous\n";


    // A single live parent owner is still a deterministic redirect. Verify
    // both preflight evidence and the real write outcome.
    $live_parent_sku = 'SMOKE-LIVE-PARENT-' . $suffix;
    $live_child_sku = 'SMOKE-LIVE-CHILD-' . $suffix;
    $live_owner_id = smoke_create_variable_product('Smoke live retry owner ' . $suffix, ['Blue'], $live_parent_sku);
    smoke_create_variation($live_owner_id, 'live-blue', $live_child_sku, 'Blue');
    $live_initial_id = smoke_create_variable_product('Smoke live redirect initial ' . $suffix, ['Blue']);
    $redirect_payload = [
        'product_id'   => $live_initial_id,
        'name'         => 'Smoke live redirect initial ' . $suffix,
        'sku'          => $live_parent_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'live-blue',
                'sku'        => $live_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    smoke_assert_redirect(smoke_card_resolution($redirect_payload), $live_owner_id, 'live-owner redirect');
    $rest_redirect = smoke_resolve_row($redirect_payload);
    smoke_assert($rest_redirect['canonical_id'] === (string) $live_owner_id, 'REST live-owner canonical id mismatch');
    smoke_assert($rest_redirect['sku_conflict_redirect'] === true, 'REST live-owner redirect flag mismatch');
    smoke_assert($rest_redirect['ambiguous'] === false, 'REST live-owner redirect was ambiguous');
    smoke_assert_write_target($redirect_payload, $live_owner_id, 'live-owner write redirect');
    echo "smoke: sole live parent owner remains a deterministic redirect\n";

    // Restoring a trashed target whose stored SKU differs from the submitted
    // SKU does not disturb that same sole-live-owner redirect.
    $restore_old_sku = 'SMOKE-RESTORE-OLD-' . $suffix;
    $restore_new_sku = 'SMOKE-RESTORE-NEW-' . $suffix;
    $restore_child_sku = 'SMOKE-RESTORE-CHILD-' . $suffix;
    $restore_target_id = smoke_create_variable_product('Smoke restore target ' . $suffix, ['Small'], $restore_old_sku);
    $restore_target = wc_get_product($restore_target_id);
    $restore_target->delete(false);
    $restore_owner_id = smoke_create_variable_product('Smoke restore live owner ' . $suffix, ['Small'], $restore_new_sku);
    smoke_create_variation($restore_owner_id, 'restore-small', $restore_child_sku, 'Small');
    $restore_redirect_payload = [
        'product_id'   => $restore_target_id,
        'name'         => 'Smoke restore target ' . $suffix,
        'sku'          => $restore_new_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'restore-small',
                'sku'        => $restore_child_sku,
                'attributes' => ['Size' => 'Small'],
            ],
        ],
    ];
    smoke_assert_redirect(
        smoke_card_resolution($restore_redirect_payload),
        $restore_owner_id,
        'trashed target with a different stored SKU'
    );
    smoke_assert_write_target($restore_redirect_payload, $restore_owner_id, 'trashed target write redirect');
    echo "smoke: trashed target with a different SKU does not suppress a redirect\n";

    // If restoring the target does expose a second live row for the submitted
    // SKU, there is no single honest redirect parent.
    $restore_duplicate_sku = 'SMOKE-RESTORE-DUP-' . $suffix;
    $restore_duplicate_child_sku = 'SMOKE-RESTORE-DUP-CHILD-' . $suffix;
    $restore_duplicate_target_id = smoke_create_variable_product(
        'Smoke duplicate restore target ' . $suffix,
        ['Small'],
        $restore_duplicate_sku
    );
    $restore_duplicate_target = wc_get_product($restore_duplicate_target_id);
    $restore_duplicate_target->delete(false);
    $restore_duplicate_owner_id = smoke_create_variable_product(
        'Smoke duplicate restore owner ' . $suffix,
        ['Small'],
        $restore_duplicate_sku
    );
    smoke_create_variation($restore_duplicate_owner_id, 'restore-duplicate-small', $restore_duplicate_child_sku, 'Small');
    $restore_duplicate_payload = [
        'product_id'   => $restore_duplicate_target_id,
        'name'         => 'Smoke duplicate restore target ' . $suffix,
        'sku'          => $restore_duplicate_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'restore-duplicate-small',
                'sku'        => $restore_duplicate_child_sku,
                'attributes' => ['Size' => 'Small'],
            ],
        ],
    ];
    smoke_assert_ambiguous(
        smoke_card_resolution($restore_duplicate_payload),
        [$restore_duplicate_target_id, $restore_duplicate_owner_id],
        'restored duplicate SKU rows'
    );
    echo "smoke: restored duplicate live parent rows are ambiguous\n";

    // Legacy/corrupt duplicate live rows are ambiguous even when the lookup's
    // first row is already the initial parent.
    $initial_duplicate_sku = 'SMOKE-INITIAL-DUP-' . $suffix;
    $initial_duplicate_child_sku = 'SMOKE-INITIAL-DUP-CHILD-' . $suffix;
    $initial_duplicate_target_id = smoke_create_variable_product(
        'Smoke initial duplicate target ' . $suffix,
        ['Blue'],
        $initial_duplicate_sku . '-TARGET-TEMP'
    );
    $initial_duplicate_owner_id = smoke_create_variable_product(
        'Smoke initial duplicate owner ' . $suffix,
        ['Blue'],
        $initial_duplicate_sku . '-OWNER-TEMP'
    );
    $initial_duplicate_blocker_id = smoke_create_variable_product('Smoke initial duplicate blocker ' . $suffix, ['Blue']);
    smoke_create_variation($initial_duplicate_blocker_id, 'initial-duplicate-blue', $initial_duplicate_child_sku, 'Blue');

    global $wpdb;
    $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
    $updated = $wpdb->update(
        $lookup,
        ['sku' => $initial_duplicate_sku],
        ['product_id' => $initial_duplicate_target_id]
    );
    smoke_assert($updated === 1, 'initial duplicate lookup fixture did not update the target SKU');
    $updated = $wpdb->update(
        $lookup,
        ['sku' => $initial_duplicate_sku],
        ['product_id' => $initial_duplicate_owner_id]
    );
    smoke_assert($updated === 1, 'initial duplicate lookup fixture did not update the second owner SKU');

    $initial_duplicate_payload = [
        'product_id'   => $initial_duplicate_target_id,
        'name'         => 'Smoke initial duplicate target ' . $suffix,
        'sku'          => $initial_duplicate_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'initial-duplicate-blue',
                'sku'        => $initial_duplicate_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    smoke_assert_rest_ambiguous(
        $initial_duplicate_payload,
        [$initial_duplicate_target_id, $initial_duplicate_owner_id],
        'duplicate rows including the initial parent'
    );
    echo "smoke: duplicate live rows including the initial parent are ambiguous\n";

    // A transfer between variations under the same initial parent does not
    // change parent-level SKU ownership and must remain a normal initial write.
    $transfer_sku = 'SMOKE-TRANSFER-' . $suffix;
    $transfer_initial_id = smoke_create_variable_product('Smoke transfer initial ' . $suffix, ['Small', 'Blue']);
    $transfer_small_id = smoke_create_variation($transfer_initial_id, 'transfer-small', $transfer_sku, 'Small');
    $transfer_blue_id = smoke_create_variation($transfer_initial_id, 'transfer-blue', $transfer_sku . '-TEMP', 'Blue');
    $transfer_payload = [
        'product_id'   => $transfer_initial_id,
        'name'         => 'Smoke transfer initial ' . $suffix,
        'sku'          => $transfer_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'transfer-small',
                'sku'        => $transfer_sku . '-NEW',
                'attributes' => ['Size' => 'Small'],
            ],
            [
                'variant_id' => 'transfer-blue',
                'sku'        => $transfer_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    $transfer_resolution = smoke_card_resolution($transfer_payload);
    smoke_assert($transfer_resolution['product'] instanceof WC_Product, 'variation transfer lost the initial product');
    smoke_assert((int) $transfer_resolution['product']->get_id() === $transfer_initial_id, 'variation transfer named the wrong parent');
    smoke_assert($transfer_resolution['ambiguous'] === false, 'variation transfer became ambiguous');
    smoke_assert($transfer_resolution['sku_conflict_redirect'] === false, 'variation transfer invented a redirect');

    $transfer_flag_only = $transfer_payload;
    unset($transfer_flag_only['variants']);
    $transfer_flag_resolution = smoke_card_resolution($transfer_flag_only);
    smoke_assert($transfer_flag_resolution['product'] instanceof WC_Product, 'flag-only sole-parent card lost the initial product');
    smoke_assert((int) $transfer_flag_resolution['product']->get_id() === $transfer_initial_id, 'flag-only sole-parent card named the wrong parent');
    smoke_assert($transfer_flag_resolution['ambiguous'] === false, 'flag-only sole-parent card became ambiguous');

    smoke_assert_write_target($transfer_payload, $transfer_initial_id, 'same-parent variation transfer write');
    $transfer_small_after = wc_get_product($transfer_small_id);
    $transfer_blue_after = wc_get_product($transfer_blue_id);
    smoke_assert($transfer_small_after instanceof WC_Product_Variation, 'transfer source variation is unreadable');
    smoke_assert($transfer_blue_after instanceof WC_Product_Variation, 'transfer destination variation is unreadable');
    smoke_assert($transfer_small_after->get_sku() === $transfer_sku . '-NEW', 'variation transfer did not release the source SKU');
    smoke_assert($transfer_blue_after->get_sku() === $transfer_sku, 'variation transfer did not claim the destination SKU');
    echo "smoke: same-parent variation SKU transfer remains a deterministic initial write\n";

    // A live owner shadows unrelated trash rows in the live-first lookup.
    // Ambiguity evidence names the live participants, never the unreachable
    // shadowed trash row.
    $shadow_parent_sku = 'SMOKE-SHADOW-' . $suffix;
    $shadow_child_sku = 'SMOKE-SHADOW-CHILD-' . $suffix;
    $shadow_trash_id = smoke_create_variable_product('Smoke shadowed trash row ' . $suffix, ['Small'], $shadow_parent_sku);
    $shadow_trash = wc_get_product($shadow_trash_id);
    $shadow_trash->delete(false);
    $shadow_owner_id = smoke_create_variable_product('Smoke shadow live owner ' . $suffix, ['Blue'], $shadow_parent_sku);
    $shadow_blocker_id = smoke_create_variable_product('Smoke shadow child blocker ' . $suffix, ['Blue']);
    smoke_create_variation($shadow_blocker_id, 'shadow-blue', $shadow_child_sku, 'Blue');
    $shadow_initial_id = smoke_create_variable_product('Smoke shadow initial ' . $suffix, ['Blue']);
    $shadow_payload = [
        'product_id'   => $shadow_initial_id,
        'name'         => 'Smoke shadow initial ' . $suffix,
        'sku'          => $shadow_parent_sku,
        'has_variants' => true,
        'variants'     => [
            [
                'variant_id' => 'shadow-blue',
                'sku'        => $shadow_child_sku,
                'attributes' => ['Size' => 'Blue'],
            ],
        ],
    ];
    smoke_assert_ambiguous(
        smoke_card_resolution($shadow_payload),
        [$shadow_initial_id, $shadow_owner_id],
        'shadowed trash SKU row'
    );
    echo "smoke: shadowed trash rows stay out of ambiguity candidates\n";

    echo "SMOKE PASS: variable trash redirect ambiguity\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SMOKE FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
