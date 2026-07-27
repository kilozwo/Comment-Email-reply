<?php
/**
 * Comment-level consent handling.
 *
 * @package CommentEmailReply
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and stores explicit reply-notification consent.
 */
final class CER_Consent {

	/**
	 * Comment meta key recording explicit notification consent.
	 */
	const META_OPT_IN = '_cer_notify_replies';

	/**
	 * Register consent-related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'comment_form_after_fields', array( __CLASS__, 'render_field' ) );
		add_action( 'comment_form_logged_in_after', array( __CLASS__, 'render_field' ) );
		add_action( 'comment_post', array( __CLASS__, 'save' ), 10, 3 );
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
	public static function render_field() {
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
	public static function save( $comment_id, $comment_approved, $comment_data ) {
		unset( $comment_approved );

		$comment = get_comment( $comment_id );

		if ( ! self::is_standard_comment( $comment ) ) {
			return;
		}

		$email = isset( $comment_data['comment_author_email'] )
			? sanitize_email( $comment_data['comment_author_email'] )
			: '';

		if ( self::request_has_opt_in() && is_email( $email ) ) {
			update_comment_meta( $comment_id, self::META_OPT_IN, '1' );
			return;
		}

		delete_comment_meta( $comment_id, self::META_OPT_IN );
	}

	/**
	 * Determine whether a comment contains explicit stored consent.
	 *
	 * @param WP_Comment $comment Comment object.
	 * @return bool
	 */
	public static function has_opted_in( $comment ) {
		return self::is_standard_comment( $comment )
			&& '1' === (string) get_comment_meta( $comment->comment_ID, self::META_OPT_IN, true );
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
}
