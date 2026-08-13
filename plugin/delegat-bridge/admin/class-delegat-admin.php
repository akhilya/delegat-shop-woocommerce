<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Delegat_Bridge_Admin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'handle_actions']);
    }

    public static function menu(): void
    {
        add_options_page(
            'Delegat Bridge',
            'Delegat Bridge',
            'manage_woocommerce',
            'delegat-bridge',
            [self::class, 'render_page']
        );
    }

    public static function handle_actions(): void
    {
        if (!isset($_POST['delegat_bridge_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer('delegat_bridge_settings');

        $action = sanitize_text_field((string) $_POST['delegat_bridge_action']);
        if ($action === 'save_key') {
            $key = isset($_POST['api_key']) ? sanitize_text_field((string) $_POST['api_key']) : '';
            if ($key !== '') {
                update_option(DELEGAT_BRIDGE_OPTION_API_KEY, $key);
                add_settings_error('delegat_bridge', 'saved', 'API key saved.', 'success');
            }
        }

        if ($action === 'regenerate_key') {
            update_option(DELEGAT_BRIDGE_OPTION_API_KEY, wp_generate_password(48, false, false));
            add_settings_error('delegat_bridge', 'regen', 'API key regenerated.', 'success');
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $key = Delegat_Bridge_Auth::get_api_key();
        $base = esc_url(rest_url(Delegat_Bridge_REST::NAMESPACE));
        settings_errors('delegat_bridge');
        ?>
        <div class="wrap">
            <h1>Delegat Bridge</h1>
            <p>REST base URL for Delegat connection:</p>
            <p><code><?php echo esc_html($base); ?></code></p>

            <form method="post">
                <?php wp_nonce_field('delegat_bridge_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_key">API Key</label></th>
                        <td>
                            <input type="text" class="regular-text" id="api_key" name="api_key"
                                   value="<?php echo esc_attr($key); ?>" autocomplete="off" />
                            <p class="description">Send as <code>X-Delegat-Api-Key</code> or <code>Authorization: Bearer</code>.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="delegat_bridge_action" value="save_key" class="button button-primary">Save</button>
                    <button type="submit" name="delegat_bridge_action" value="regenerate_key" class="button">Regenerate</button>
                </p>
            </form>

            <h2>Health</h2>
            <p>Authenticated GET <code><?php echo esc_html($base); ?>/health</code></p>
        </div>
        <?php
    }
}
