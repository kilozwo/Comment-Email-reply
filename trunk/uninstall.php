<?php
/**
 * Remove plugin-specific metadata on uninstall.
 *
 * @package CommentEmailReply
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'comment', 0, '_cer_notify_replies', '', true );
delete_metadata( 'comment', 0, '_cer_reply_notification_sent', '', true );
