# Comment Email Reply

Comment Email Reply is a small WordPress plugin that lets comment authors explicitly opt in to an email when somebody replies to their comment.

Version 2.0.0 modernises the original 2015 plugin for WordPress 7.0 while changing the old automatic-notification behaviour to privacy-conscious, per-comment consent.

## Behaviour

1. The comment form displays an unchecked notification checkbox.
2. Consent is stored as comment metadata only when the author selects that checkbox and supplies a valid email address.
3. An approved direct reply triggers at most one notification to the parent comment's author.
4. The email links to the new reply and includes a signed unsubscribe URL with a confirmation step.
5. Spam, trash, pingbacks, trackbacks, self-replies and comments without explicit consent do not generate mail.

Existing comments are not silently enrolled. This is an intentional breaking change from version 1.x and is why the release is versioned 2.0.0.

## Security design

- Direct file access is blocked with the standard `ABSPATH` guard.
- User-controlled names, titles and comments are converted to plain text.
- Reply excerpts are capped at 1,200 characters.
- Header values are not derived from comment input.
- Recipient addresses are sanitised and validated.
- A unique comment-meta record acts as an atomic delivery lock.
- A failed `wp_mail()` attempt releases the lock so a later approval transition can retry.
- Unsubscribe URLs use `hash_hmac()` with the site's WordPress authentication salt and do not reveal the recipient address.
- Unsubscribing requires a nonce-protected POST, so an email security scanner cannot disable notifications merely by following a link.
- The plugin does not call external services or add tracking.

## Requirements

- WordPress 6.5 or later
- Tested through WordPress 7.0
- PHP 7.4 or later

The current release was integration-tested with WordPress 7.0.2 on PHP 8.4. The WordPress.org `Tested up to` field uses the major/minor form `7.0`.

## Local checks

```bash
find trunk tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/smoke.php
```

## Repository layout

- `trunk/` contains the current WordPress.org plugin source.
- `tags/` contains historical 1.x releases and is not loaded by the current plugin.

The installable plugin directory should contain the contents of `trunk/`.

## Extension hooks

`cer_should_send_notification`
: Filters whether an otherwise valid notification should be sent.

`cer_notification_sent`
: Fires after WordPress reports a successful mail delivery.

`cer_notification_error`
: Fires when the configured mail provider throws an exception.

## Privacy

The plugin stores `_cer_notify_replies` on a comment after explicit consent and `_cer_reply_notification_sent` on a reply after successful delivery. It does not create a separate subscriber table. Deleting the plugin through WordPress removes these plugin-specific metadata keys.

## Licence

GPL-2.0-or-later.
