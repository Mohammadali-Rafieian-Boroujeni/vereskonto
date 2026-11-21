<?php
/**
 * Plugin Name: VK Search Tap Fix
 * Description: Stops WoodMart's closed full-screen search from swallowing taps (it opened the "add to wishlist" popup when tapping the mobile account bar).
 * Author:      Varsakala
 *
 * Why: the full-screen search panel (.wd-search-full-screen / -2) is a
 * fixed layer with z-index 400 that stays on the page while closed,
 * hidden only with visibility:hidden + opacity:0. Inside it, the product
 * cards' action buttons (wishlist, quick view, compare) set their own
 * visibility:visible, so they stay invisible but still receive taps. On
 * phones they land exactly where the floating account bar sits (and can
 * block taps elsewhere), so a tap on a bar icon "added to wishlist".
 *
 * Fix: while the panel is NOT open (.wd-opened is WoodMart's own open
 * class), nothing inside it is visible or tappable. The open panel is
 * untouched.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_head',
	function () {
		?>
		<style id="vk-search-tap-fix">
			.wd-search-full-screen:not(.wd-opened),
			.wd-search-full-screen:not(.wd-opened) *,
			.wd-search-full-screen-2:not(.wd-opened),
			.wd-search-full-screen-2:not(.wd-opened) * {
				visibility: hidden !important;
				pointer-events: none !important;
			}
		</style>
		<?php
	},
	100
);
