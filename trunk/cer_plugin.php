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

/**
 * Handles reply notification consent and delivery.
 */
final class CER_Plugin {

	/**
	 * Plugin version.
	 */
	const VERSION = '2.0.0';

	/**
	 * Comment meta key recording explicit notification consent.
	 */
	const META_OPT_IN = '_cer_notify_replies';

	/**
	 * Comment meta key used as an atomic notification lock.
	 */
	const META_SENT = '_cer_reply_notification_sent';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'comment_form_after_fields', array( __CLASS__, 'render_opt_in' ) );
		add_action( 'comment_form_logged_in_after', array( __CLASS__, 'render_opt_in' ) );
		add_action( 'comment_post', array( __CLASS__, 'save_opt_in' ), 10, 3 );

		add_action( 'wp_insert_comment', array( __CLASS__, 'handle_inserted_comment' ), 99, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'handle_status_transition' ), 99, 3 );

		add_action( 'admin_post_cer_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_nopriv_cer_unsubscribe', array( __CLASS__, 'handle_unsubscribe' ) );
	}

	/**
	 * Register plugin comment metadata.
	 *
	 * @return void
	 */
	public static function register_meta() {
		register_meta(
			'comment',
			self::META_OPT_IN,
			array(
				'type'              => 'boolean',
				'description'       => __( 'Whether the comment author opted in to reply notifications.', 'comment-email-reply' ),
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_boolean' ),
				'auth_callback'     => static function () {
					return current_user_can( 'moderate_comments' );
				},
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize a metadata boolean.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return bool
	 */
	public static function sanitize_boolean( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Render an unchecked, explicit consent checkbox.
	 *
	 * @return void
	 */
	public static function render_opt_in() {
		?>
		<p class="comment-form-cer-notify">
			<label for="cer-notify-replies">
				<input
					id="cer-notify-replies"
					name="cer_notify_replies"
					type="checkbox"
					value="1"
					<?php checked( self::request_has_opt_in() ); ?>
				/>
				<?php esc_html_e( 'Notify me by email when somebody replies to my comment. I can unsubscribe at any time.', 'comment-email-reply' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Store consent against the newly submitted comment.
	 *
	 * Missing or unchecked input is deliberately treated as no consent.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status.
	 * @param array      $comment_data     Submitted comment data.
	 * @return void
	 */
	public static function save_opt_in( $comment_id, $comment_approved, $comment_data ) {
		unset( $comment_approved );

		$comment = get_comment( $comment_id );

		if ( ! self::is_standard_comment( $comment ) ) {
			return;
		}

		if ( self::request_has_opt_in() && ! empty( $comment_data['comment_author_email'] ) && is_email( $comment_data['comment_author_email'] ) ) {
			update_comment_meta( $comment_id, self::META_OPT_IN, '1' );
			return;
		}

		delete_comment_meta( $comment_id, self::META_OPT_IN );
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

		self::maybe_send_notification( $comment );
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

		self::maybe_send_notification( $comment );
	}

	/**
	 * Send one notification for an approved reply when its parent opted in.
	 *
	 * @param WP_Comment|int|null $reply Reply comment object or ID.
	 * @return bool Whether the message was sent.
	 */
	public static function maybe_send_notification( $reply ) {
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

		if ( '1' !== (string) get_comment_meta( $parent->comment_ID, self::META_OPT_IN, true ) ) {
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
		 * @param WP_Comment $reply  Reply comment.
		 * @param WP_Comment $parent Parent comment.
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
		$unsubscribe_link = self::get_unsubscribe_url( $parent );

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
	 * Create a signed unsubscribe URL without exposing the email address.
	 *
	 * @param WP_Comment $comment Parent comment.
	 * @return string
	 */
	private static function get_unsubscribe_url( $comment ) {
		return add_query_arg(
			array(
				'action'     => 'cer_unsubscribe',
				'comment_id' => (int) $comment->comment_ID,
				'token'      => self::get_unsubscribe_token( $comment ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Generate an HMAC token bound to a comment and its current email address.
	 *
	 * @param WP_Comment $comment Comment object.
	 * @return string
	 */
	private static function get_unsubscribe_token( $comment ) {
		$payload = (int) $comment->comment_ID . '|' . strtolower( sanitize_email( $comment->comment_author_email ) );

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Process a signed unsubscribe request.
	 *
	 * @return void
	 */
	public static function handle_unsubscribe() {
		$comment_id = isset( $_POST['comment_id'] )
			? absint( wp_unslash( $_POST['comment_id'] ) )
			: ( isset( $_GET['comment_id'] ) ? absint( wp_unslash( $_GET['comment_id'] ) ) : 0 );
		$token = isset( $_POST['token'] )
			? sanitize_text_field( wp_unslash( $_POST['token'] ) )
			: ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '' );
		$comment = get_comment( $comment_id );

		if ( ! $comment || ! $token || ! hash_equals( self::get_unsubscribe_token( $comment ), $token ) ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid or has expired.', 'comment-email-reply' ),
				esc_html__( 'Unable to unsubscribe', 'comment-email-reply' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		/*
		 * Mail security scanners commonly follow links automatically. Requiring
		 * a deliberate POST prevents those scanners from silently unsubscribing
		 * a commenter while the HMAC still authenticates the original link.
		 */
		if ( 'POST' !== $request_method ) {
			$nonce = wp_nonce_field( 'cer_unsubscribe_' . $comment->comment_ID, 'cer_nonce', true, false );
			$form = sprintf(
				'<p>%1$s</p><form method="post" action="%2$s">%3$s<input type="hidden" name="action" value="cer_unsubscribe"><input type="hidden" name="comment_id" value="%4$d"><input type="hidden" name="token" value="%5$s"><button type="submit">%6$s</button></form>',
				esc_html__( 'Do you want to stop reply notifications for this comment?', 'comment-email-reply' ),
				esc_url( admin_url( 'admin-post.php' ) ),
				$nonce,
				(int) $comment->comment_ID,
				esc_attr( $token ),
				esc_html__( 'Stop notifications', 'comment-email-reply' )
			);

			wp_die(
				$form,
				esc_html__( 'Confirm unsubscribe', 'comment-email-reply' ),
				array( 'response' => 200 )
			);
		}

		$nonce = isset( $_POST['cer_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cer_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'cer_unsubscribe_' . $comment->comment_ID ) ) {
			wp_die(
				esc_html__( 'The unsubscribe confirmation expired. Please open the link from the email again.', 'comment-email-reply' ),
				esc_html__( 'Unable to unsubscribe', 'comment-email-reply' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		delete_comment_meta( $comment->comment_ID, self::META_OPT_IN );

		wp_die(
			esc_html__( 'Reply notifications for this comment have been disabled.', 'comment-email-reply' ),
			esc_html__( 'Notifications disabled', 'comment-email-reply' ),
			array(
				'response'  => 200,
				'back_link' => true,
			)
		);
	}

	/**
	 * Check whether the current comment request contains explicit consent.
	 *
	 * @return bool
	 */
	private static function request_has_opt_in() {
		if ( ! isset( $_POST['cer_notify_replies'] ) ) {
			return false;
		}

		return '1' === sanitize_text_field( wp_unslash( $_POST['cer_notify_replies'] ) );
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

CER_Plugin::init();
