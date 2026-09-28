## Email (Mailer)

Mail is a CONNECTION, not config. `lib/Mailer.php`, the comms inbox (`services/NotifyService.php`)
and `/webhook/mailgun` all read `Mailer::settings()`: the install's Mailgun connection bound to
core's `mail` role (`ConnectionBindings::for('core', 'mail')`). Connect one under Connections →
Mailgun (private API key + sending domain; optional from-address, inbound domain, webhook
signing key as the connection's webhook secret). One connection binds itself install-wide; a
franchise site may bind its own. `conf/mailgun.ini` is NOT read any more — seed
`23_MailConnection` migrated it into a connection once. The `[mail]` block in `conf/config.ini`
is the SMTP example and is never consulted either; a site's own `conf/sites/<slug>.ini`
`[mail] from_email` is the one config value that overrides the connection's from-address.

No connection is a legitimate state: `Mailer::isConfigured()` is false, `send()` logs an ERROR
naming the fix and returns false, and `Mailer::settings()` throws `MissingConnectorException`
("connect one under Connections → Mailgun"). Never write a mail path that reads an ini or
config.ini instead.

**Available methods:**
```php
Mailer::settings();                    // ['key','domain','endpoint','from_email','from_name','inbound_domain','signing_key', …] or throws
Mailer::sendPasswordReset($email, $name, $resetUrl);
Mailer::sendContactResponse($toEmail, $toName, $subject, $message, $response, $adminName);
Mailer::sendTeamInvite($email, $teamName, $inviterName, $role, $acceptUrl);
Mailer::sendWelcome($email, $username);
Mailer::create()->to($email, $name)->subject($s)->send($html);
```
