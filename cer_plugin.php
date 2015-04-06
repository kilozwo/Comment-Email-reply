<?php
/**
 * Plugin Name: Comment Email Reply
 * Plugin URI:  http://kilozwo.de/wordpress-comment-email-reply-plugin
 * Description: Simply notifies comment-author via email if someone replies to his comment. Zero Configuration. Available in English and German. More languages welcome.
 * Version:     1.0.4
 * Author:      Jan Eichhorn
 * Author URI:  http://kilozwo.de
 * License:     GPLv2
 */

load_plugin_textdomain('cer_plugin', false, basename( dirname( __FILE__ ) ) . '/languages' );

# Fire Email when comments is inserted and is already approved.
add_action('wp_insert_comment','cer_comment_notification',99,2);

function cer_comment_notification($comment_id, $comment_object) {
    if ($comment_object->comment_approved == 1 && $comment_object->comment_parent > 0) {
        $comment_parent = get_comment($comment_object->comment_parent);

        $mailcontent = __('Hello ','cer_plugin').' '.$comment_parent->comment_author.
                ',<br>'.$comment_object->comment_author.' '.__(' replied to your comment on','cer_plugin').
                ' <a href="'.get_permalink($comment_parent->comment_post_ID).'">'.get_the_title($comment_parent->comment_post_ID).
                '</a>:<br><br>'.$comment_object->comment_content.'<br><br>'.__('Go to it or reply:','cer_plugin').
                ' <a href="'.get_comment_link( $comment_parent->comment_ID ).'">'.get_comment_link( $comment_parent->comment_ID ).'</a>';

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: '.get_option('blogname').'<'.get_option('admin_email').'>' . "\r\n";

        wp_mail($comment_parent->comment_author_email,'['.get_option('blogname').'] '.__('New reply to your Comment','cer_plugin'),$mailcontent,$headers);
    }
}

# Fire Email when comments gets approved later.
add_action('wp_set_comment_status','cer_comment_status_changed',99,2);

function cer_comment_status_changed($comment_id, $comment_status) {
    $comment_object = get_comment( $comment_id );
    if ($comment_object->comment_approved == 1) {
        cer_comment_notification($comment_object->comment_ID, $comment_object);        
    } 
}
?>