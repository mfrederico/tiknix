## CLI Tool

```bash
php scripts/clitool.php --list                       # Tables + row counts
php scripts/clitool.php --describe=member            # Columns and types
php scripts/clitool.php --sql='SELECT ...'           # Read-only query
php scripts/clitool.php --exec='UPDATE ...' --yes    # Write query (guarded)
php scripts/clitool.php --build                      # Run services/Schema/Seeds
php scripts/clitool.php --bean=TYPE --getall [--where='col = ?' --data=VAL]
php scripts/clitool.php --adduser=EMAIL --password=PW --level=50
php scripts/clitool.php --user=IDENT --reset-2fa     # The 2FA lockout fix
php scripts/clitool.php --scaffold=all --bean=product  # Generate model/controller/view/api
```
