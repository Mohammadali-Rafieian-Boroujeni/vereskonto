<?php
/**
 * Structural test: the chatbot never states a shipping price that was typed
 * into its own code or text. Prices come from VKL_Smart_Shipping, which reads
 * WooCommerce and the checkout logic.
 *
 *   php tests/shipping-test.php        (exit 0 = passed; also run by smoke.py)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

$root     = dirname(__DIR__);
$failures = 0;
$checks   = 0;

function check(bool $ok, string $what): void
{
    global $failures, $checks;

    $checks++;

    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: $what\n");
    }
}

// Money-looking literals that must not appear in the files that talk to customers.
$forbidden = '/(?<![\d.])(?:150000|150,000|4000000|4,000,000|389000|120000)(?![\d])|۱۵۰٬۰۰۰|۴٬۰۰۰٬۰۰۰|۳۸۹٬۰۰۰|۱۲۰٬۰۰۰/u';

foreach (['chatbot.php', 'ai.php'] as $file) {
    $text = (string) file_get_contents($root . '/modules/smart/' . $file);

    check(1 !== preg_match($forbidden, $text, $m), "$file contains a hard-coded shipping amount" . (isset($m[0]) ? ' (' . $m[0] . ')' : ''));
}

// The admin canned replies may only use placeholders, never typed amounts.
$admin = (string) file_get_contents($root . '/modules/smart/admin.php');
check(1 !== preg_match($forbidden, $admin, $m), 'smart/admin.php canned replies contain a hard-coded shipping amount' . (isset($m[0]) ? ' (' . $m[0] . ')' : ''));

// Both consumers must go through the shared reader.
$chatbot = (string) file_get_contents($root . '/modules/smart/chatbot.php');
$ai      = (string) file_get_contents($root . '/modules/smart/ai.php');
check(str_contains($chatbot, 'VKL_Smart_Shipping'), 'chatbot.php does not use VKL_Smart_Shipping');
check(str_contains($ai, 'VKL_Smart_Shipping'), 'ai.php does not use VKL_Smart_Shipping');
check(str_contains($admin, 'VKL_Smart_Shipping'), 'smart/admin.php does not use VKL_Smart_Shipping');

// The reader asks the real sources.
$ship = (string) file_get_contents($root . '/modules/smart/shipping.php');
check(str_contains($ship, 'WC_Shipping_Zone'), 'shipping.php does not read WooCommerce shipping zones');
check(str_contains($ship, 'VPC_Settings::cfg()'), 'shipping.php does not read the checkout plugin settings');
check(str_contains($ship, 'VPC_Core::plan_rates'), 'shipping.php does not use the checkout plugin decision code for a cart');
check(str_contains($ship, 'VPC_Core::weight_cost'), 'shipping.php does not use the checkout plugin price calculation');
check(str_contains($ship, 'nofree_gateways'), 'shipping.php does not read the "no free shipping" payment list');

// 2.9.36: the hand-typed fallback amounts are gone — WooCommerce and the
// checkout plugin are the only sources. They must not come back.
foreach (['loader.php', 'admin.php', 'shipping.php', 'chatbot.php', 'ai.php'] as $file) {
    $text = (string) file_get_contents($root . '/modules/smart/' . $file);

    check(!str_contains($text, 'shop_free_ship_min'), "$file still references shop_free_ship_min");
    check(!str_contains($text, 'shop_ship_fee'), "$file still references shop_ship_fee");
}

// Account data and support hand-offs are never rewritten by the AI.
$ai_src = (string) file_get_contents($root . '/modules/smart/ai.php');
check(str_contains($ai_src, "\$result['keep']"), 'ai.php does not honour the keep flag');
check(substr_count($chatbot, "\$result['keep'] = true") >= 6, 'chatbot.php does not flag every account/hand-off reply with keep');

echo "$checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
