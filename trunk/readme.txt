=== Comment Email Reply ===
Contributors: kilozwo
Tags: comments, replies, notifications, email, opt-in
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let comment authors explicitly opt in to a secure email notification when somebody replies.

== Description ==

Comment Email Reply adds an unchecked opt-in checkbox to the WordPress comment form. When an author chooses it, the plugin can send one plain-text email after an approved reply is published.

The notification links directly to the new reply and includes a signed unsubscribe URL. The author's email address is never placed in that URL.

= Privacy and security =

* Notifications are opt-in. The checkbox is never preselected.
* Existing comments without recorded consent do not receive notifications.
* Notifications are sent only for approved standard comments, not pingbacks, trackbacks, spam or trash.
* Invalid recipients and replies from the same email address are ignored.
* An atomic comment-meta lock prevents duplicate notifications.
* User-controlled content is converted to plain text and the excerpt is limited before it reaches an email.
* Unsubscribe requests use a site-specific HMAC signature and require confirmation, preventing email scanners from silently unsubscribing authors.
* The plugin does not use external services, tracking pixels or analytics.

The plugin stores a consent flag on comments that opted in and a delivery marker on replies that generated a notification. WordPress already stores the email address supplied with each comment; this plugin does not create a separate address list.

== Installation ==

1. Upload the `comment-email-reply` folder to `/wp-content/plugins/`, or install the plugin through WordPress.
2. Activate **Comment Email Reply**.
3. Confirm that your WordPress installation can send email.

No configuration is required. The opt-in checkbox appears with the comment form.

== Frequently Asked Questions ==

= Are existing commenters subscribed automatically? =

No. Version 2.0.0 deliberately requires explicit consent. Existing comments do not contain that consent and therefore do not trigger notifications.

= Is the checkbox selected by default? =

No.

= What happens when a reply is held for moderation? =

The email is sent only after the reply is approved. Repeated status hooks cannot send the same notification twice.

= Can a commenter unsubscribe? =

Yes. Every notification contains a signed unsubscribe link for the original comment. The author must confirm the action before notifications are disabled.

= Does it send HTML from comments by email? =

No. Names, titles and comment content are reduced to plain text. Long reply content is truncated.

== Upgrade Notice ==

= 2.0.0 =

Notifications now require explicit per-comment opt-in. Existing comments will no longer receive automatic reply emails.

== Changelog ==

= 2.0.0 =

* Added an unchecked per-comment notification opt-in.
* Added signed unsubscribe links with deliberate confirmation.
* Added duplicate-delivery locking and retry behaviour after failed mail delivery.
* Limited delivery to approved standard comments with valid recipients.
* Prevented self-notifications based on matching email addresses.
* Switched to escaped plain-text email content and limited reply excerpts.
* Fixed the notification URL so it points to the new reply.
* Added support declarations for WordPress 7.0 and PHP 7.4 or later.
* Removed the custom From header and now relies on WordPress mail configuration.
* Removed obsolete bundled translations that used the retired 1.x text domain.
* Added privacy, security and upgrade documentation.
* Split consent, notification delivery and unsubscribe handling into focused runtime components.

= 1.0.4 =

* Added spam-handling checks.
* Added the site name and administrator address to the From header.

= 1.0.3 =

* Switched the email character set to UTF-8.

= 1.0.2 =

* Fixed HTML encoding.

= 1.0.1 =

* Fixed the email text.

= 1.0 =

* First stable version.
