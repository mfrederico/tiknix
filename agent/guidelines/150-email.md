## Email (Mailer)

Mailgun integration via `lib/Mailer.php`. Configure in `conf/config.ini`:

```ini
[mail]
enabled = true
driver = "mailgun"
mailgun_domain = "your-domain.com"
mailgun_api_key = "key-xxx"
from_email = "noreply@example.com"
from_name = "App Name"
```

**Available methods:**
```php
Mailer::sendPasswordReset($email, $resetUrl);
Mailer::sendContactResponse($email, $subject, $message);
Mailer::sendTeamInvite($email, $teamName, $inviterName, $acceptUrl);
Mailer::sendWelcome($email, $username);
```
