<?php
/**
 * Signed unsubscribe flow.
 *
 * @package CommentEmailReply
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and processes per-comment unsubscribe links.
 */
final class CER_Unsubscribe {

	/**
	 * Register unsubscribe endpoints.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_post_cer_unsubscribe', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_cer_unsubscribe', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Create a signed unsubscribe URL without exposing the email address.
	 *
	 * @param WP_Comment $comment Parent comment.
	 * @return string
	 */
	public static function get_url( $comment ) {
		return add_query_arg(
			array(
				'action'     => 'cer_unsubscribe',
				'comment_id' => (int) $comment->comment_ID,
				'token'      => self::get_token( $comment ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Process a signed unsubscribe request.
	 *
	 * @return void
	 */
	public static function handle() {
		$comment_id = isset( $_POST['comment_id'] )
			? absint( wp_unslash( $_POST['comment_id'] ) )
			: ( isset( $_GET['comment_id'] ) ? absint( wp_unslash( $_GET['comment_id'] ) ) : 0 );
		$token = isset( $_POST['token'] )
			? sanitize_text_field( wp_unslash( $_POST['token'] ) )
			: ( isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '' );
		$comment = get_comment( $comment_id );

		if ( ! $comment || ! $token || ! hash_equals( self::get_token( $comment ), $token ) ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid or has expired.', 'comment-email-reply' ),
				esc_html__( 'Unable to unsubscribe', 'comment-email-reply' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		/*
		 * Mail security scanners commonly follow links automatically. Requiring
		 * a deliberate POST prevents those scanners from silently unsubscribing
		 * a commenter while the HMAC still authenticates the original link.
		 */
		if ( 'POST' !== $request_method ) {
			self::render_confirmation( $comment, $token );
			return;
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

		delete_comment_meta( $comment->comment_ID, CER_Consent::META_OPT_IN );

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
	 * Generate an HMAC token bound to a comment and its current email address.
	 *
	 * @param WP_Comment $comment Comment object.
	 * @return string
	 */
	private static function get_token( $comment ) {
		$payload = (int) $comment->comment_ID . '|' . strtolower( sanitize_email( $comment->comment_author_email ) );

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Display the deliberate unsubscribe confirmation form.
	 *
	 * @param WP_Comment $comment Parent comment.
	 * @param string     $token   Validated unsubscribe token.
	 * @return void
	 */
	private static function render_confirmation( $comment, $token ) {
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
}
