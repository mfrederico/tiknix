# TikNix PHP Framework

A modern, production-ready PHP framework featuring automatic routing, authentication, role-based permissions, and a Bootstrap 5 UI.

## Quick Start

1. Clone the repository
```bash
git clone https://github.com/yourusername/tiknix.git
cd tiknix
```

2. Install dependencies
```bash
composer install
```

3. Configure the application
```bash
cp conf/config.example.ini conf/config.ini
# Edit conf/config.ini with your database credentials
```

4. Create database and initialize
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS tiknix"
php database/init_users.php
php database/init_contact.php
```

5. Start development server
```bash
php -S localhost:8000 -t public/
```

6. Access the application
- Open http://localhost:8000
- Register a new account or login with admin/admin123

## Features

- ✅ Simple registration (no email verification)
- ✅ Auto-routing system
- ✅ Role-based permissions
- ✅ Admin panel
- ✅ Contact form system
- ✅ CLI support for cron jobs
- ✅ Built-in documentation viewer
- ✅ RedBeanPHP ORM
- ✅ Bootstrap 5 UI

## Documentation

Full documentation is available in [README.md](README.md) or by visiting `/docs` in the running application.

## License

MIT License - see LICENSE file for details