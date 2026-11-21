<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_Images
{
    private const META_SOURCE_URL = '_delegat_source_url';
    private const META_SOURCE_PATH = '_delegat_source_path';
    private const META_PENDING_URLS = '_delegat_pending_image_urls';
    private const META_LAST_ERRORS = '_delegat_image_sync_errors';
    private const META_LAST_SYNCED = '_delegat_image_synced_at';

    /** Per-product legacy hook (kept for already-scheduled jobs). */
    public const ACTION_SYNC = 'delegat_bridge_sync_images';

    /** Single site-wide drain — never runs inside products/bulk. */
    public const ACTION_DRAIN = 'delegat_bridge_drain_images';

    /** Regenerate attachment metadata (thumbnails) after archive import. */
    public const ACTION_REGEN = 'delegat_bridge_regen_attachment';
    public const ACTION_GROUP = 'delegat-bridge';

    private const DRAIN_BATCH_SIZE = 5;
    private const DRAIN_DELAY_SECONDS = 10;
    private const REGEN_BATCH_SIZE = 3;

    /** Zip bomb / DoS guards for import-archive. */
    private const MAX_UPLOAD_BYTES = 67108864;       // 64 MiB
    private const MAX_ZIP_ENTRIES = 500;
    private const MAX_ENTRY_UNCOMPRESSED = 16777216; // 16 MiB
    private const MAX_TOTAL_UNCOMPRESSED = 67108864; // 64 MiB
    private const MAX_COMPRESSION_RATIO = 100;

    public static function register(): void
    {
        add_action(self::ACTION_SYNC, [self::class, 'handle_sync_images'], 10, 1);
        add_action(self::ACTION_DRAIN, [self::class, 'handle_drain_images'], 10, 0);
        add_action(self::ACTION_REGEN, [self::class, 'handle_regen_attachment'], 10, 1);
    }

    /**
     * Import a ZIP built by Delegat: manifest.json + products/{localId}/{file}.
     * Attachments are created without GD resize; regen is scheduled asynchronously.
     *
     * @return array{success: bool, attached: int, skipped: int, errors: string[], pending_regen: int}
     */
    public static function import_archive_from_upload(array $file): array
    {
        $errors = [];
        $attached = 0;
        $skipped = 0;
        $regen_ids = [];

        $tmp = (string) ($file['tmp_name'] ?? '');
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_readable($tmp)) {
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['archive upload missing or unreadable'],
                'pending_regen' => 0,
            ];
        }

        $upload_size = (int) ($file['size'] ?? 0);
        if ($upload_size <= 0 && is_file($tmp)) {
            $upload_size = (int) filesize($tmp);
        }
        if ($upload_size > self::MAX_UPLOAD_BYTES) {
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['archive upload exceeds ' . self::MAX_UPLOAD_BYTES . ' bytes'],
                'pending_regen' => 0,
            ];
        }

        if (!class_exists('ZipArchive')) {
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['ZipArchive extension is not available'],
                'pending_regen' => 0,
            ];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['failed to open zip archive'],
                'pending_regen' => 0,
            ];
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['archive has too many entries (max ' . self::MAX_ZIP_ENTRIES . ')'],
                'pending_regen' => 0,
            ];
        }

        $total_uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (!self::is_safe_zip_entry_name($name)) {
                $zip->close();
                return [
                    'success'       => false,
                    'attached'      => 0,
                    'skipped'       => 0,
                    'errors'        => ['unsafe zip entry path: ' . $name],
                    'pending_regen' => 0,
                ];
            }
            $uncompressed = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            if ($uncompressed > self::MAX_ENTRY_UNCOMPRESSED) {
                $zip->close();
                return [
                    'success'       => false,
                    'attached'      => 0,
                    'skipped'       => 0,
                    'errors'        => ['zip entry too large: ' . $name],
                    'pending_regen' => 0,
                ];
            }
            if ($compressed > 0 && $uncompressed > 0
                && ($uncompressed / max(1, $compressed)) > self::MAX_COMPRESSION_RATIO
                && $uncompressed > (1024 * 1024)
            ) {
                $zip->close();
                return [
                    'success'       => false,
                    'attached'      => 0,
                    'skipped'       => 0,
                    'errors'        => ['zip entry compression ratio too high: ' . $name],
                    'pending_regen' => 0,
                ];
            }
            $total_uncompressed += $uncompressed;
            if ($total_uncompressed > self::MAX_TOTAL_UNCOMPRESSED) {
                $zip->close();
                return [
                    'success'       => false,
                    'attached'      => 0,
                    'skipped'       => 0,
                    'errors'        => ['archive uncompressed size exceeds ' . self::MAX_TOTAL_UNCOMPRESSED . ' bytes'],
                    'pending_regen' => 0,
                ];
            }
        }

        $manifest_raw = $zip->getFromName('manifest.json');
        if ($manifest_raw === false || $manifest_raw === '') {
            $zip->close();
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['manifest.json missing from archive'],
                'pending_regen' => 0,
            ];
        }

        if (strlen($manifest_raw) > self::MAX_ENTRY_UNCOMPRESSED) {
            $zip->close();
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['manifest.json too large'],
                'pending_regen' => 0,
            ];
        }

        $manifest = json_decode($manifest_raw, true);
        if (!is_array($manifest)) {
            $zip->close();
            return [
                'success'       => false,
                'attached'      => 0,
                'skipped'       => 0,
                'errors'        => ['manifest.json is invalid JSON'],
                'pending_regen' => 0,
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $extracted_total = strlen($manifest_raw);

        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $local_id = (int) ($entry['local_product_id'] ?? 0);
            $woo_product_id = isset($entry['product_id']) ? absint($entry['product_id']) : 0;
            $sku = isset($entry['sku']) ? trim((string) $entry['sku']) : '';
            $files = $entry['files'] ?? [];
            $source_urls = $entry['source_urls'] ?? [];
            if (($local_id <= 0 && $woo_product_id <= 0 && $sku === '') || !is_array($files) || $files === []) {
                continue;
            }

            $product = null;
            if ($woo_product_id > 0 && function_exists('wc_get_product')) {
                $candidate = wc_get_product($woo_product_id);
                if ($candidate instanceof WC_Product) {
                    $product = $candidate;
                }
            }
            if (!$product && $local_id > 0) {
                $product = self::find_product_by_delegat_id((string) $local_id);
            }
            if (!$product && $sku !== '' && function_exists('wc_get_product_id_by_sku') && function_exists('wc_get_product')) {
                $sku_pid = (int) wc_get_product_id_by_sku($sku);
                if ($sku_pid > 0) {
                    $candidate = wc_get_product($sku_pid);
                    if ($candidate instanceof WC_Product) {
                        $product = $candidate;
                    }
                }
            }
            if (!$product) {
                $errors[] = 'product not found for local_product_id=' . $local_id . ' product_id=' . $woo_product_id . ' sku=' . $sku;
                continue;
            }
            $product_id = (int) $product->get_id();
            $folder_key = !empty($entry['folder']) ? basename((string) $entry['folder']) : ($local_id > 0 ? (string) $local_id : ('w' . $product_id));

            $attachment_ids = [];
            foreach ($files as $index => $filename) {
                $filename = basename((string) $filename);
                if ($filename === '' || $filename === '.' || $filename === '..') {
                    continue;
                }
                if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $filename)) {
                    $errors[] = 'unsupported image type: ' . $filename;
                    continue;
                }
                $zip_path = 'products/' . $folder_key . '/' . $filename;
                if (!self::is_safe_zip_entry_name($zip_path)) {
                    $errors[] = 'unsafe zip entry path: ' . $zip_path;
                    continue;
                }
                $bytes = $zip->getFromName($zip_path);
                if ($bytes === false || $bytes === '') {
                    // Fallback path used by older clients that always used local id folder.
                    if ($folder_key !== (string) $local_id && $local_id > 0) {
                        $zip_path = 'products/' . $local_id . '/' . $filename;
                        if (self::is_safe_zip_entry_name($zip_path)) {
                            $bytes = $zip->getFromName($zip_path);
                        }
                    }
                }
                if ($bytes === false || $bytes === '') {
                    $errors[] = 'missing zip entry products/' . $folder_key . '/' . $filename;
                    continue;
                }

                $byte_len = strlen($bytes);
                if ($byte_len > self::MAX_ENTRY_UNCOMPRESSED) {
                    $errors[] = 'extracted entry too large: ' . $filename;
                    continue;
                }
                $extracted_total += $byte_len;
                if ($extracted_total > self::MAX_TOTAL_UNCOMPRESSED) {
                    $zip->close();
                    return [
                        'success'       => false,
                        'attached'      => $attached,
                        'skipped'       => $skipped,
                        'errors'        => array_merge($errors, ['archive uncompressed extract limit exceeded']),
                        'pending_regen' => 0,
                    ];
                }

                $source_url = '';
                if (is_array($source_urls) && isset($source_urls[$index])) {
                    $source_url = trim((string) $source_urls[$index]);
                }
                $path_key = $source_url !== ''
                    ? self::source_path_key($source_url)
                    : strtolower('archive:p' . $product_id . ':' . hash('sha256', $bytes));

                $existing = $source_url !== ''
                    ? self::find_by_source($source_url, $path_key)
                    : self::find_attachment_by_meta(self::META_SOURCE_PATH, $path_key);
                if ($existing) {
                    $attachment_ids[] = $existing;
                    $skipped++;
                    continue;
                }

                $created = self::create_attachment_from_bytes(
                    $bytes,
                    $filename,
                    $product_id,
                    $source_url,
                    $path_key
                );
                if (!empty($created['id'])) {
                    $id = (int) $created['id'];
                    $attachment_ids[] = $id;
                    $regen_ids[] = $id;
                    $attached++;
                } elseif (!empty($created['error'])) {
                    $errors[] = 'local_product_id=' . $local_id . ' product_id=' . $product_id . ': ' . $created['error'];
                }
            }

            $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));
            if ($attachment_ids !== []) {
                $featured = $attachment_ids[0];
                $gallery = array_slice($attachment_ids, 1);
                set_post_thumbnail($product_id, $featured);
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery));
                delete_post_meta($product_id, self::META_PENDING_URLS);
                delete_post_meta($product_id, self::META_LAST_ERRORS);
                update_post_meta($product_id, self::META_LAST_SYNCED, time());
            }
        }

        $zip->close();

        $pending_regen = 0;
        foreach (array_values(array_unique($regen_ids)) as $attachment_id) {
            self::schedule_regen($attachment_id);
            $pending_regen++;
        }

        return [
            'success'       => $errors === [],
            'attached'      => $attached,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'pending_regen' => $pending_regen,
        ];
    }

    private static function is_safe_zip_entry_name(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0")) {
            return false;
        }
        if (str_starts_with($name, '/') || str_contains($name, '\\')) {
            return false;
        }
        if (str_contains($name, '../') || str_starts_with($name, '..')) {
            return false;
        }
        if (preg_match('/\.zip$/i', $name)) {
            return false;
        }
        if ($name === 'manifest.json') {
            return true;
        }
        return (bool) preg_match('#^products/[A-Za-z0-9_-]+/[A-Za-z0-9._-]+$#', $name);
    }

    /**
     * @param int|array<string, mixed> $attachment_id
     */
    public static function handle_regen_attachment($attachment_id): void
    {
        if (is_array($attachment_id)) {
            $attachment_id = (int) ($attachment_id['attachment_id'] ?? 0);
        } else {
            $attachment_id = (int) $attachment_id;
        }
        if ($attachment_id <= 0) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = get_attached_file($attachment_id);
        if (!$file || !is_readable($file)) {
            return;
        }

        $sizes_filter = static function ($sizes) {
            if (!is_array($sizes)) {
                return [];
            }
            $keep = [
                'thumbnail'                     => true,
                'woocommerce_thumbnail'         => true,
                'woocommerce_single'            => true,
                'woocommerce_gallery_thumbnail' => true,
            ];
            return array_intersect_key($sizes, $keep);
        };
        add_filter('intermediate_image_sizes_advanced', $sizes_filter, 999);
        $meta = wp_generate_attachment_metadata($attachment_id, $file);
        remove_filter('intermediate_image_sizes_advanced', $sizes_filter, 999);
        if (is_array($meta)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }
    }

    private static function schedule_regen(int $attachment_id): void
    {
        $args = ['attachment_id' => $attachment_id];
        $run_at = time() + 5;

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_REGEN, $args, self::ACTION_GROUP);
            return;
        }
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($run_at, self::ACTION_REGEN, $args, self::ACTION_GROUP);
            return;
        }

        wp_schedule_single_event($run_at, self::ACTION_REGEN, [$attachment_id]);
    }

    private static function find_product_by_delegat_id(string $delegat_id): ?WC_Product
    {
        if ($delegat_id === '' || !function_exists('wc_get_product')) {
            return null;
        }

        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => Delegat_Bridge_Products::META_PRODUCT_ID,
                    'value' => $delegat_id,
                ],
            ],
        ]);
        if (!$q->have_posts()) {
            return null;
        }
        $product = wc_get_product((int) $q->posts[0]);
        return $product instanceof WC_Product ? $product : null;
    }

    /**
     * Create attachment from raw bytes without generating intermediate sizes.
     *
     * @return array{id?: int, error?: string}
     */
    private static function create_attachment_from_bytes(
        string $body,
        string $filename,
        int $product_id,
        string $source_url,
        string $path_key
    ): array {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return ['error' => 'uploads dir error: ' . (string) $uploads['error']];
        }

        $upload_path = (string) ($uploads['path'] ?? '');
        if ($upload_path === '' || !wp_mkdir_p($upload_path) || !is_writable($upload_path)) {
            return ['error' => 'uploads not writable: ' . ($upload_path !== '' ? $upload_path : '(empty)')];
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename) ?: 'image.jpg';
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $safe)) {
            $safe .= '.jpg';
        }

        $bits = wp_upload_bits($safe, null, $body);
        if (!empty($bits['error'])) {
            return ['error' => 'upload failed: ' . (string) $bits['error']];
        }

        $file_path = (string) ($bits['file'] ?? '');
        $file_url = (string) ($bits['url'] ?? '');
        if ($file_path === '') {
            return ['error' => 'upload_bits returned empty file path'];
        }

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment = [
            'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)) ?: 'image',
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];

        $attachment_id = wp_insert_attachment($attachment, $file_path, $product_id, true);
        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return ['error' => $attachment_id->get_error_message()];
        }

        $id = (int) $attachment_id;
        // Minimal metadata so WP knows the file; sizes generated asynchronously.
        $size = @getimagesize($file_path);
        $meta = [
            'file'   => _wp_relative_upload_path($file_path),
            'width'  => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
            'sizes'  => [],
        ];
        wp_update_attachment_metadata($id, $meta);

        if ($source_url !== '') {
            update_post_meta($id, self::META_SOURCE_URL, $source_url);
        }
        update_post_meta($id, self::META_SOURCE_PATH, $path_key);
        return ['id' => $id];
    }

    /**
     * Queue image URLs. When $defer is false (single upsert), sideload immediately
     * and return errors. When true (bulk), only schedule a delayed drain.
     *
     * @param int      $product_id
     * @param string[] $urls
     * @return array{queued: int, mode: string, synced?: int, errors?: string[]}
     */
    public static function queue_product_images(int $product_id, array $urls, bool $defer = false): array
    {
        $clean = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $clean[] = $url;
            }
        }
        $clean = array_values(array_unique($clean));

        if ($clean === []) {
            delete_post_meta($product_id, self::META_PENDING_URLS);
            return ['queued' => 0, 'mode' => 'none'];
        }

        update_post_meta($product_id, self::META_PENDING_URLS, $clean);
        delete_post_meta($product_id, self::META_LAST_ERRORS);

        if ($defer) {
            $mode = self::schedule_drain();
            return ['queued' => count($clean), 'mode' => $mode];
        }

        // Single-product path: sideload now so we don't depend on WP-Cron/AS wakeups.
        $result = self::process_product_images($product_id);
        return [
            'queued'  => count($clean),
            'mode'    => 'inline',
            'synced'  => $result['synced'],
            'errors'  => $result['errors'],
        ];
    }

    /**
     * Process a few products with pending image URLs, then re-schedule if more remain.
     *
     * @return array{processed: int, pending: int, errors: string[]}
     */
    public static function handle_drain_images(): array
    {
        $errors = [];
        $processed = 0;
        $ids = self::find_pending_product_ids(self::DRAIN_BATCH_SIZE);
        foreach ($ids as $product_id) {
            $result = self::process_product_images($product_id);
            $processed++;
            foreach ($result['errors'] as $err) {
                $errors[] = 'product ' . $product_id . ': ' . $err;
            }
        }

        $pending = count(self::find_pending_product_ids(100));
        if ($pending > 0) {
            self::schedule_drain(true);
        }

        return [
            'processed' => $processed,
            'pending'   => $pending,
            'errors'    => $errors,
        ];
    }

    /**
     * @param int|array<string, mixed> $product_id
     */
    public static function handle_sync_images($product_id): void
    {
        if (is_array($product_id)) {
            $product_id = (int) ($product_id['product_id'] ?? 0);
        } else {
            $product_id = (int) $product_id;
        }

        if ($product_id > 0) {
            self::process_product_images($product_id);
        }
    }

    private static function process_product_images(int $product_id): array
    {
        if ($product_id <= 0 || !get_post($product_id)) {
            return ['synced' => 0, 'errors' => ['product not found']];
        }

        $urls = get_post_meta($product_id, self::META_PENDING_URLS, true);
        if (!is_array($urls) || $urls === []) {
            return ['synced' => 0, 'errors' => []];
        }

        $result = self::sync_product_images($product_id, $urls);

        if ($result['errors'] !== []) {
            update_post_meta($product_id, self::META_LAST_ERRORS, $result['errors']);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[delegat-bridge] product ' . $product_id . ' image errors: ' . implode('; ', $result['errors']));
        } else {
            delete_post_meta($product_id, self::META_LAST_ERRORS);
        }

        // Always clear pending after an attempt — next export can re-queue.
        delete_post_meta($product_id, self::META_PENDING_URLS);
        update_post_meta($product_id, self::META_LAST_SYNCED, time());

        return [
            'synced' => (int) $result['synced'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * @return int[]
     */
    private static function find_pending_product_ids(int $limit): array
    {
        $q = new WP_Query([
            'post_type'              => 'product',
            'post_status'            => 'any',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => self::META_PENDING_URLS,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        return array_map('intval', $q->posts);
    }

    /**
     * Schedule drain at least DRAIN_DELAY_SECONDS in the future so it cannot
     * run inside the current REST request (unlike as_enqueue_async_action).
     */
    private static function schedule_drain(bool $force = false): string
    {
        $run_at = time() + self::DRAIN_DELAY_SECONDS;

        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!$force && as_has_scheduled_action(self::ACTION_DRAIN, [], self::ACTION_GROUP)) {
                return 'action_scheduler_pending';
            }
            if ($force && function_exists('as_unschedule_all_actions')) {
                // Keep at most one follow-up drain.
                as_unschedule_all_actions(self::ACTION_DRAIN, [], self::ACTION_GROUP);
            }
            as_schedule_single_action($run_at, self::ACTION_DRAIN, [], self::ACTION_GROUP);
            return 'action_scheduler';
        }

        if (!$force && wp_next_scheduled(self::ACTION_DRAIN)) {
            return 'wp_cron_pending';
        }
        wp_schedule_single_event($run_at, self::ACTION_DRAIN);
        return 'wp_cron';
    }

    /**
     * @param int      $product_id
     * @param string[] $urls
     * @return array{featured: ?int, gallery: int[], synced: int, errors: string[]}
     */
    public static function sync_product_images(int $product_id, array $urls): array
    {
        $attachment_ids = [];
        $errors = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            $result = self::get_or_sideload($url, $product_id);
            if (!empty($result['id'])) {
                $attachment_ids[] = (int) $result['id'];
            } elseif (!empty($result['error'])) {
                $errors[] = $result['error'];
            }
        }

        $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));
        $featured = $attachment_ids[0] ?? null;
        $gallery = array_slice($attachment_ids, 1);

        if ($featured) {
            set_post_thumbnail($product_id, $featured);
        } else {
            delete_post_thumbnail($product_id);
        }

        update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery));

        return [
            'featured' => $featured,
            'gallery'  => $gallery,
            'synced'   => count($attachment_ids),
            'errors'   => $errors,
        ];
    }

    /**
     * @return array{id?: int, error?: string}
     */
    private static function get_or_sideload(string $url, int $product_id): array
    {
        $path_key = self::source_path_key($url);
        $existing = self::find_by_source($url, $path_key);
        if ($existing) {
            return ['id' => $existing];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $body = self::download_body($url);
        if (is_wp_error($body)) {
            return [
                'error' => sprintf(
                    'download failed for %s: %s',
                    self::short_url($url),
                    $body->get_error_message()
                ),
            ];
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return ['error' => 'uploads dir error: ' . (string) $uploads['error']];
        }

        $upload_path = (string) ($uploads['path'] ?? '');
        if ($upload_path === '' || !wp_mkdir_p($upload_path) || !is_writable($upload_path)) {
            return [
                'error' => sprintf(
                    'uploads not writable: %s (owner fix: chown -R www-data:www-data wp-content/uploads)',
                    $upload_path !== '' ? $upload_path : '(empty)'
                ),
            ];
        }

        $filename = self::filename_from_url($url);
        $bits = wp_upload_bits($filename, null, $body);
        if (!empty($bits['error'])) {
            return [
                'error' => sprintf(
                    'upload failed for %s: %s',
                    self::short_url($url),
                    (string) $bits['error']
                ),
            ];
        }

        $file_path = (string) ($bits['file'] ?? '');
        $file_url = (string) ($bits['url'] ?? '');
        if ($file_path === '') {
            return ['error' => 'upload_bits returned empty file path'];
        }

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment = [
            'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)) ?: 'image',
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];

        $attachment_id = wp_insert_attachment($attachment, $file_path, $product_id, true);
        if (is_wp_error($attachment_id)) {
            @unlink($file_path);
            return [
                'error' => sprintf(
                    'attachment failed for %s: %s',
                    self::short_url($url),
                    $attachment_id->get_error_message()
                ),
            ];
        }

        $id = (int) $attachment_id;

        // Skip most intermediate sizes — GD/Imagick on large PNGs dominates export time.
        // Keep WooCommerce storefront sizes only.
        $sizes_filter = static function ($sizes) {
            if (!is_array($sizes)) {
                return [];
            }
            $keep = [
                'thumbnail'                      => true,
                'woocommerce_thumbnail'          => true,
                'woocommerce_single'             => true,
                'woocommerce_gallery_thumbnail'  => true,
            ];
            return array_intersect_key($sizes, $keep);
        };
        add_filter('intermediate_image_sizes_advanced', $sizes_filter, 999);
        $meta = wp_generate_attachment_metadata($id, $file_path);
        remove_filter('intermediate_image_sizes_advanced', $sizes_filter, 999);

        if (is_array($meta)) {
            wp_update_attachment_metadata($id, $meta);
        }

        update_post_meta($id, self::META_SOURCE_URL, $url);
        update_post_meta($id, self::META_SOURCE_PATH, $path_key);
        return ['id' => $id];
    }

    /**
     * @return string|WP_Error Raw image bytes or error.
     */
    private static function download_body(string $url)
    {
        $attempts = [
            ['sslverify' => apply_filters('delegat_bridge_image_sslverify', true)],
            ['sslverify' => false],
        ];

        $last_error = null;
        foreach ($attempts as $opts) {
            $response = wp_remote_get($url, [
                'timeout'     => 120,
                'redirection' => 5,
                'sslverify'   => $opts['sslverify'],
                'headers'     => [
                    'Accept'     => 'image/*,*/*;q=0.8',
                    'User-Agent' => 'DelegatBridge/' . (defined('DELEGAT_BRIDGE_VERSION') ? DELEGAT_BRIDGE_VERSION : '1.0') . '; WordPress/' . get_bloginfo('version'),
                ],
            ]);

            if (is_wp_error($response)) {
                $last_error = $response;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                $last_error = new WP_Error(
                    'delegat_image_http',
                    sprintf('HTTP %d', $code)
                );
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            if ($body === '' || $body === null) {
                $last_error = new WP_Error('delegat_image_empty', 'Empty image body');
                continue;
            }

            return $body;
        }

        return $last_error instanceof WP_Error
            ? $last_error
            : new WP_Error('delegat_image_download', 'Download failed');
    }

    private static function find_by_source(string $url, string $path_key): ?int
    {
        $by_path = self::find_attachment_by_meta(self::META_SOURCE_PATH, $path_key);
        if ($by_path) {
            return $by_path;
        }

        return self::find_attachment_by_meta(self::META_SOURCE_URL, $url);
    }

    private static function find_attachment_by_meta(string $key, string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
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

        return (int) $q->posts[0];
    }

    private static function source_path_key(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        return strtolower($host . $path);
    }

    private static function short_url(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $base = $host . $path;
        return strlen($base) > 120 ? substr($base, 0, 117) . '...' : $base;
    }

    private static function filename_from_url(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = $path !== '' ? basename($path) : 'image.jpg';
        $base = preg_replace('/[^a-zA-Z0-9._-]/', '-', $base) ?: 'image.jpg';
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $base)) {
            $base .= '.jpg';
        }
        return $base;
    }
}
