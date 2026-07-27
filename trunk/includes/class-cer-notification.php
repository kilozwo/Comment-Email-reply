<?php
/**
 * Reply-notification delivery.
 *
 * @package CommentEmailReply
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates reply events and sends one plain-text notification.
 */
final class CER_Notification {

	/**
	 * Comment meta key used as an atomic notification lock.
	 */
	const META_SENT = '_cer_reply_notification_sent';

	/**
	 * Register notification-related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'wp_insert_comment', array( __CLASS__, 'handle_inserted_comment' ), 99, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'handle_status_transition' ), 99, 3 );
	}

	/**
	 * Handle a comment that was inserted as already approved.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 * @return void
	 */
	public static function handle_inserted_comment( $comment_id, $comment ) {
		if ( 'approved' !== wp_get_comment_status( $comment_id ) ) {
			return;
		}

		self::maybe_send( $comment );
	}

	/**
	 * Handle a comment that is approved after moderation.
	 *
	 * @param string     $new_status New comment status.
	 * @param string     $old_status Previous comment status.
	 * @param WP_Comment $comment    Comment object.
	 * @return void
	 */
	public static function handle_status_transition( $new_status, $old_status, $comment ) {
		if ( 'approved' !== $new_status || 'approved' === $old_status ) {
			return;
		}

		self::maybe_send( $comment );
	}

	/**
	 * Send one notification for an approved reply when its parent opted in.
	 *
	 * @param WP_Comment|int|null $reply Reply comment object or ID.
	 * @return bool Whether the message was sent.
	 */
	public static function maybe_send( $reply ) {
		$reply = get_comment( $reply );

		if ( ! self::is_standard_comment( $reply ) || $reply->comment_parent <= 0 ) {
			return false;
		}

		if ( 'approved' !== wp_get_comment_status( $reply->comment_ID ) ) {
			return false;
		}

		$parent = get_comment( $reply->comment_parent );

		if ( ! self::is_standard_comment( $parent ) || 'approved' !== wp_get_comment_status( $parent->comment_ID ) ) {
			return false;
		}

		if ( ! CER_Consent::has_opted_in( $parent ) ) {
			return false;
		}

		$recipient = sanitize_email( $parent->comment_author_email );
		$reply_email = sanitize_email( $reply->comment_author_email );

		if ( ! is_email( $recipient ) || ( $reply_email && strtolower( $recipient ) === strtolower( $reply_email ) ) ) {
			return false;
		}

		/**
		 * Filter whether a reply notification should be sent.
		 *
		 * @param bool       $should_send Whether to send.
		 * @param WP_Comment $reply       Reply comment.
		 * @param WP_Comment $parent      Parent comment.
		 */
		if ( ! apply_filters( 'cer_should_send_notification', true, $reply, $parent ) ) {
			return false;
		}

		/*
		 * Adding unique comment meta is an atomic lock. It prevents duplicate
		 * messages when insert and status-transition hooks run close together.
		 */
		if ( ! add_comment_meta( $reply->comment_ID, self::META_SENT, gmdate( 'c' ), true ) ) {
			return false;
		}

		$message = self::build_message( $reply, $parent );
		$subject = self::build_subject();
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		try {
			$sent = wp_mail( $recipient, $subject, $message, $headers );
		} catch ( Throwable $error ) {
			$sent = false;

			/**
			 * Fires when the mail provider throws while sending a notification.
			 *
			 * @param Throwable  $error  Mail error.
			 * @param WP_Comment $reply  Reply comment.
			 * @param WP_Comment $parent Parent comment.
			 */
			do_action( 'cer_notification_error', $error, $reply, $parent );
		}

		if ( ! $sent ) {
			delete_comment_meta( $reply->comment_ID, self::META_SENT );
			return false;
		}

		/**
		 * Fires after a reply notification has been sent.
		 *
		 * @param WP_Comment $reply     Reply comment.
		 * @param WP_Comment $parent    Parent comment.
		 * @param string     $recipient Recipient email address.
		 */
		do_action( 'cer_notification_sent', $reply, $parent, $recipient );

		return true;
	}

	/**
	 * Build a plain-text notification message.
	 *
	 * @param WP_Comment $reply  Reply comment.
	 * @param WP_Comment $parent Parent comment.
	 * @return string
	 */
	private static function build_message( $reply, $parent ) {
		$parent_author = self::plain_text( $parent->comment_author );
		$reply_author = self::plain_text( $reply->comment_author );
		$post_title = self::plain_text( get_the_title( $parent->comment_post_ID ) );
		$reply_text = self::plain_text( $reply->comment_content );
		$reply_text = wp_html_excerpt( $reply_text, 1200, '…' );
		$reply_link = get_comment_link( $reply );
		$unsubscribe_link = CER_Unsubscribe::get_url( $parent );

		if ( '' === $parent_author ) {
			$parent_author = __( 'there', 'comment-email-reply' );
		}

		if ( '' === $reply_author ) {
			$reply_author = __( 'Someone', 'comment-email-reply' );
		}

		return sprintf(
			/* translators: 1: parent author, 2: reply author, 3: post title, 4: reply excerpt, 5: reply URL, 6: unsubscribe URL. */
			__( "Hello %1\$s,\n\n%2\$s replied to your comment on “%3\$s”:\n\n%4\$s\n\nView the reply:\n%5\$s\n\nStop notifications for this comment:\n%6\$s", 'comment-email-reply' ),
			$parent_author,
			$reply_author,
			$post_title,
			$reply_text,
			$reply_link,
			$unsubscribe_link
		);
	}

	/**
	 * Build a mail-safe subject.
	 *
	 * @return string
	 */
	private static function build_subject() {
		$site_name = self::plain_text( get_bloginfo( 'name' ) );
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] New reply to your comment', 'comment-email-reply' ),
			$site_name
		);

		return str_replace( array( "\r", "\n" ), '', $subject );
	}

	/**
	 * Check for a normal comment rather than a pingback or trackback.
	 *
	 * @param WP_Comment|false|null $comment Comment object.
	 * @return bool
	 */
	private static function is_standard_comment( $comment ) {
		return $comment instanceof WP_Comment && in_array( $comment->comment_type, array( '', 'comment' ), true );
	}

	/**
	 * Convert untrusted text to a compact, single-purpose plain-text value.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private static function plain_text( $value ) {
		$value = wp_strip_all_tags( (string) $value, true );
		$value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$value = preg_replace( '/[^\S\r\n]+/u', ' ', $value );
		$value = preg_replace( '/(?:\r\n|\r|\n){3,}/', "\n\n", $value );

		return trim( (string) $value );
	}
}
