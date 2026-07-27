<?php
/**
 * Dependency-free behavioural smoke tests for Comment Email Reply.
 *
 * Run with: php tests/smoke.php
 */

define( 'ABSPATH', __DIR__ );

$cer_comments = array();
$cer_meta = array();
$cer_mails = array();
$cer_mail_result = true;
$cer_actions = array();

class WP_Comment {
	public $comment_ID;
	public $comment_parent = 0;
	public $comment_post_ID = 10;
	public $comment_author = '';
	public $comment_author_email = '';
	public $comment_content = '';
	public $comment_type = '';
	public $comment_approved = '1';

	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->{$key} = $value;
		}
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $cer_actions;

	$cer_actions[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
}
function register_meta() {}
function current_user_can() {
	return true;
}
function __( $text ) {
	return $text;
}
function checked() {}
function esc_html_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function get_comment( $comment ) {
	global $cer_comments;

	return $comment instanceof WP_Comment ? $comment : ( $cer_comments[ $comment ] ?? null );
}
function wp_get_comment_status( $comment_id ) {
	$comment = get_comment( $comment_id );

	if ( ! $comment ) {
		return false;
	}

	return in_array( $comment->comment_approved, array( 1, '1', 'approved', 'approve' ), true ) ? 'approved' : (string) $comment->comment_approved;
}
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_SANITIZE_EMAIL );
}
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function update_comment_meta( $comment_id, $key, $value ) {
	global $cer_meta;
	$cer_meta[ $comment_id ][ $key ] = $value;

	return true;
}
function get_comment_meta( $comment_id, $key ) {
	global $cer_meta;

	return $cer_meta[ $comment_id ][ $key ] ?? '';
}
function add_comment_meta( $comment_id, $key, $value, $unique = false ) {
	global $cer_meta;

	if ( $unique && isset( $cer_meta[ $comment_id ][ $key ] ) ) {
		return false;
	}

	$cer_meta[ $comment_id ][ $key ] = $value;

	return true;
}
function delete_comment_meta( $comment_id, $key ) {
	global $cer_meta;
	unset( $cer_meta[ $comment_id ][ $key ] );

	return true;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function do_action() {}
function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}
function wp_html_excerpt( $value, $length, $more ) {
	return strlen( $value ) > $length ? substr( $value, 0, $length ) . $more : $value;
}
function get_the_title() {
	return 'A <b>post</b>';
}
function get_comment_link( $comment ) {
	$comment = get_comment( $comment );

	return 'https://example.test/article/#comment-' . $comment->comment_ID;
}
function get_bloginfo( $field ) {
	return 'charset' === $field ? 'UTF-8' : "Example\nSite";
}
function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function add_query_arg( array $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function wp_salt() {
	return 'test-only-site-secret';
}
function wp_mail( $recipient, $subject, $message, $headers ) {
	global $cer_mails, $cer_mail_result;

	if ( $cer_mail_result ) {
		$cer_mails[] = compact( 'recipient', 'subject', 'message', 'headers' );
	}

	return $cer_mail_result;
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function esc_html__( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return $url;
}
function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function wp_nonce_field() {
	return '<input type="hidden" name="cer_nonce" value="test-nonce">';
}
function wp_verify_nonce( $nonce ) {
	return 'test-nonce' === $nonce;
}
function wp_die( $message ) {
	throw new RuntimeException( $message );
}

require dirname( __DIR__ ) . '/trunk/cer_plugin.php';

function cer_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cer_comment( $id, array $overrides = array() ) {
	global $cer_comments;

	$comment = new WP_Comment(
		array_merge(
			array(
				'comment_ID'           => $id,
				'comment_author'       => 'Author ' . $id,
				'comment_author_email' => 'author' . $id . '@example.test',
				'comment_content'      => 'Comment ' . $id,
			),
			$overrides
		)
	);
	$cer_comments[ $id ] = $comment;

	return $comment;
}

$expected_hooks = array(
	'init',
	'comment_form_after_fields',
	'comment_form_logged_in_after',
	'comment_post',
	'admin_post_cer_unsubscribe',
	'admin_post_nopriv_cer_unsubscribe',
	'wp_insert_comment',
	'transition_comment_status',
);
$registered_hooks = array_column( $cer_actions, 'hook' );

cer_assert( $expected_hooks === $registered_hooks, 'Components should register only the expected hooks in a deterministic order.' );
cer_assert( array( 'CER_Consent', 'register_meta' ) === $cer_actions[0]['callback'], 'The consent component should own metadata registration.' );
cer_assert( array( 'CER_Unsubscribe', 'handle' ) === $cer_actions[4]['callback'], 'The unsubscribe component should own the public endpoint.' );
cer_assert( array( 'CER_Notification', 'handle_inserted_comment' ) === $cer_actions[6]['callback'], 'The notification component should own reply insertion.' );

$consenting_comment = cer_comment(
	8,
	array(
		'comment_author_email' => 'subscriber@example.test',
	)
);
$_POST = array( 'cer_notify_replies' => '1' );
CER_Consent::save(
	$consenting_comment->comment_ID,
	'1',
	array( 'comment_author_email' => $consenting_comment->comment_author_email )
);
cer_assert( '1' === get_comment_meta( 8, CER_Consent::META_OPT_IN ), 'Checked consent with a valid email should be stored.' );

$_POST = array();
CER_Consent::save(
	$consenting_comment->comment_ID,
	'1',
	array( 'comment_author_email' => $consenting_comment->comment_author_email )
);
cer_assert( '' === get_comment_meta( 8, CER_Consent::META_OPT_IN ), 'Unchecked consent should remove the consent flag.' );

$parent = cer_comment(
	1,
	array(
		'comment_author'       => 'Parent <b>Author</b>',
		'comment_author_email' => 'parent@example.test',
	)
);
update_comment_meta( 1, CER_Consent::META_OPT_IN, '1' );

$reply = cer_comment(
	2,
	array(
		'comment_parent'  => 1,
		'comment_content' => '<script>alert(1)</script><b>Hello</b>',
	)
);

cer_assert( CER_Notification::maybe_send( $reply ), 'An opted-in parent should receive a notification.' );
cer_assert( 1 === count( $cer_mails ), 'Exactly one message should be sent.' );
cer_assert( false === CER_Notification::maybe_send( $reply ), 'The delivery lock should prevent duplicates.' );
cer_assert( 1 === count( $cer_mails ), 'A duplicate hook must not send a second message.' );
cer_assert( false === strpos( $cer_mails[0]['message'], '<script>' ), 'Reply HTML must not reach the email.' );
cer_assert( false !== strpos( $cer_mails[0]['message'], '#comment-2' ), 'The message must link to the reply.' );
cer_assert( false === strpos( $cer_mails[0]['subject'], "\n" ), 'The subject must not contain a newline.' );
cer_assert( false !== strpos( $cer_mails[0]['message'], 'cer_unsubscribe' ), 'The message must include an unsubscribe URL.' );

$self_reply = cer_comment(
	3,
	array(
		'comment_parent'       => 1,
		'comment_author_email' => 'PARENT@example.test',
	)
);
cer_assert( false === CER_Notification::maybe_send( $self_reply ), 'Matching email addresses should not receive self-notifications.' );

$unapproved_reply = cer_comment(
	4,
	array(
		'comment_parent'   => 1,
		'comment_approved' => '0',
	)
);
cer_assert( false === CER_Notification::maybe_send( $unapproved_reply ), 'Unapproved replies must not generate mail.' );

$parent_without_consent = cer_comment( 5 );
$reply_without_consent = cer_comment(
	6,
	array(
		'comment_parent' => $parent_without_consent->comment_ID,
	)
);
cer_assert( false === CER_Notification::maybe_send( $reply_without_consent ), 'Missing consent must prevent notification.' );

$retry_reply = cer_comment(
	7,
	array(
		'comment_parent' => 1,
	)
);
$cer_mail_result = false;
cer_assert( false === CER_Notification::maybe_send( $retry_reply ), 'A failed mail delivery should report failure.' );
cer_assert( '' === get_comment_meta( 7, CER_Notification::META_SENT ), 'A failed delivery must release the lock.' );

$cer_mail_result = true;
cer_assert( CER_Notification::maybe_send( $retry_reply ), 'A later attempt should be able to retry.' );

$unsubscribe_url = '';
preg_match( '#https://example\.test/wp-admin/admin-post\.php\?[^\s]+#', $cer_mails[0]['message'], $unsubscribe_match );
$unsubscribe_url = $unsubscribe_match[0] ?? '';
parse_str( (string) parse_url( $unsubscribe_url, PHP_URL_QUERY ), $unsubscribe_query );

$_GET = $unsubscribe_query;
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
	CER_Unsubscribe::handle();
} catch ( RuntimeException $error ) {
	cer_assert( false !== strpos( $error->getMessage(), '<form' ), 'A GET request should display a confirmation form.' );
}

cer_assert( '1' === get_comment_meta( 1, CER_Consent::META_OPT_IN ), 'Following the email link alone must not unsubscribe.' );

$_GET = array();
$_POST = array(
	'action'     => 'cer_unsubscribe',
	'comment_id' => 1,
	'token'      => $unsubscribe_query['token'] ?? '',
	'cer_nonce'  => 'test-nonce',
);
$_SERVER['REQUEST_METHOD'] = 'POST';

try {
	CER_Unsubscribe::handle();
} catch ( RuntimeException $error ) {
	cer_assert( false !== strpos( $error->getMessage(), 'disabled' ), 'A confirmed POST should report success.' );
}

cer_assert( '' === get_comment_meta( 1, CER_Consent::META_OPT_IN ), 'A confirmed unsubscribe should remove consent.' );

echo "All Comment Email Reply smoke tests passed.\n";
