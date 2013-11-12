<?php
/**
 * Plugin Name: Comment Email Reply
 * Plugin URI:  http://kilozwo.de/wordpress-comment-email-reply-plugin
 * Description: Simply notifies comment-author via email if someone replies to his comment. Zero Configuration. Available in English and German.
 * Version:     1.0.2
 * Author:      Jan Eichhorn
 * Author URI:  http://kilozwo.de
 * License:     GPLv2
 */

load_plugin_textdomain('cer_plugin', false, basename( dirname( __FILE__ ) ) . '/languages' );

add_action('wp_insert_comment','cer_comment_inserted',99,2);

function cer_comment_inserted($comment_id, $comment_object) {
    if ($comment_object->comment_parent > 0) {
        $comment_parent = get_comment($comment_object->comment_parent);

        $mailcontent = __('Hello ','cer_plugin').' '.$comment_parent->comment_author.',<br>'.$comment_object->comment_author.' '.__(' replied to your comment on','cer_plugin').' <a href="'.get_permalink($comment_parent->comment_post_ID).'">'.get_the_title($comment_parent->comment_post_ID).'</a>:<br><br>'.$comment_object->comment_content.'<br><br>'.__('Go to it or reply:','cer_plugin').' <a href="'.get_comment_link( $comment_parent->comment_ID ).'">'.get_comment_link( $comment_parent->comment_ID ).'</a>';

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

        wp_mail($comment_parent->comment_author_email,'['.get_option('blogname').'] '.__('New reply to your Comment','cer_plugin'),$mailcontent,$headers);
    }
}

?>