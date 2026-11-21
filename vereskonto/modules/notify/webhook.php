<?php
/**
 * Bale webhook — receives bot updates and binds a chat to a user account
 * when the customer taps "Start" on their personal connect link.
 *
 * Route: POST /wp-json/vereskonto/v1/bale/webhook/<secret>
 * The secret path segment keeps the endpoint private to your bot.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Webhook
 */
final class VKL_Notify_Webhook
{
    private const NAMESPACE = 'vereskonto/v1';

    private VKL_Notify_Bale $bale;

    private VKL_Config $config;

    public function __construct(VKL_Notify_Bale $bale, VKL_Config $config)
    {
        $this->bale   = $bale;
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/bale/webhook/(?P<secret>[A-Za-z0-9]+)', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'handle'],
        ]);
    }

    /**
     * Handle one Bale update.
     *
     * @param WP_REST_Request $request Request.
     */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $secret = (string) $request['secret'];

        if (!hash_equals($this->config->get_string('bale_webhook_secret'), $secret)) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $update = $request->get_json_params();
        $message = $update['message'] ?? null;

        if (!is_array($message)) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $chat_id = isset($message['chat']['id']) ? (string) $message['chat']['id'] : '';
        $text    = isset($message['text']) ? trim((string) $message['text']) : '';

        // "/start <token>" links the chat to the account.
        if ('' !== $chat_id && 0 === strpos($text, '/start')) {
            $parts = preg_split('/\s+/', $text);
            $token = $parts[1] ?? '';

            $user_id = $this->bale->user_by_token($token);

            if ($user_id > 0) {
                $this->bale->bind($user_id, $chat_id);

                $this->bale->send(
                    $user_id,
                    'bale.connected',
                    __('✅ Your account is now connected. You will receive invoices and updates here.', 'vereskala-login')
                );
            }
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
