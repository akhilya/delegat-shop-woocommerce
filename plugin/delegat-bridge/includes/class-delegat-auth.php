<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_Auth
{
    public static function get_api_key(): string
    {
        return (string) get_option(DELEGAT_BRIDGE_OPTION_API_KEY, '');
    }

    /**
     * @param WP_REST_Request $request
     */
    public static function permission_check($request): bool|WP_Error
    {
        $expected = self::get_api_key();
        if ($expected === '') {
            return new WP_Error(
                'delegat_no_key',
                'Delegat Bridge API key is not configured',
                ['status' => 503]
            );
        }

        $provided = $request->get_header('X-Delegat-Api-Key');
        if (!$provided) {
            $auth = $request->get_header('Authorization');
            if (is_string($auth) && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
                $provided = trim($m[1]);
            }
        }

        if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
            return new WP_Error(
                'delegat_unauthorized',
                'Invalid or missing API key',
                ['status' => 401]
            );
        }

        return true;
    }
}
