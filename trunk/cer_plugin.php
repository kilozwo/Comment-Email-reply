<?php
/**
 * Plugin Name:       Comment Email Reply
 * Plugin URI:        https://wordpress.org/plugins/comment-email-reply/
 * Description:       Lets comment authors opt in to secure email notifications when somebody replies to their comment.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Jan Eichhorn
 * Author URI:        https://github.com/kilozwo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       comment-email-reply
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-cer-consent.php';
require_once __DIR__ . '/includes/class-cer-unsubscribe.php';
require_once __DIR__ . '/includes/class-cer-notification.php';

/**
 * Boots the plugin's purpose-specific components.
 */
final class CER_Plugin {

	/**
	 * Plugin version.
	 */
	const VERSION = '2.0.0';

	/**
	 * Register all runtime hooks.
	 *
	 * @return void
	 */
	public static function init() {
		CER_Consent::register_hooks();
		CER_Unsubscribe::register_hooks();
		CER_Notification::register_hooks();
	}
}

CER_Plugin::init();
