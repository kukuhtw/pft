# Ubuntu Deployment (Phase 1)

Target: Ubuntu 24.04 LTS VPS · Nginx · PHP 8.5-FPM · MySQL 8.4 LTS · systemd. No Docker.

## 1. Packages

```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y nginx mysql-server-8.4 unzip git \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-mbstring php8.5-xml php8.5-bcmath php8.5-curl php8.5-zip php8.5-intl
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php   # verify the hash from getcomposer.org before running
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

`bcmath` is **required**: all money arithmetic uses it.

## 2. Database (least privilege)

```sql
CREATE DATABASE pft CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pft'@'localhost' IDENTIFIED BY '<generated-strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES ON pft.* TO 'pft'@'localhost';
```

Use InnoDB (default) with `transaction_isolation = REPEATABLE-READ` (default). The ledger relies on `SELECT … FOR UPDATE`.

## 3. Application

```bash
sudo mkdir -p /var/www/pft && sudo chown deploy:www-data /var/www/pft
git clone <repo> /var/www/pft && cd /var/www/pft
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://…, DB_*, SESSION_SECURE_COOKIE=true
chmod 640 .env
php artisan migrate --force
php artisan config:cache && php artisan route:cache
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache
```

## 4. Nginx (HTTPS only)

```nginx
server { listen 80; server_name pft.example.com; return 301 https://$host$request_uri; }
server {
    listen 443 ssl http2;
    server_name pft.example.com;
    root /var/www/pft/public;
    ssl_certificate     /etc/letsencrypt/live/pft.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pft.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    client_max_body_size 10m;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.5-fpm.sock; }
    location ~ /\.(?!well-known) { deny all; }
}
```

Certificates: `sudo apt install certbot python3-certbot-nginx && sudo certbot --nginx -d pft.example.com`.

## 5. Scheduler and queue (systemd)

`/etc/systemd/system/pft-scheduler.service` + `.timer` (every minute):

```ini
# pft-scheduler.service
[Service]
Type=oneshot
User=deploy
WorkingDirectory=/var/www/pft
ExecStart=/usr/bin/php artisan schedule:run

# pft-scheduler.timer
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
[Install]
WantedBy=timers.target
```

`/etc/systemd/system/pft-queue.service`:

```ini
[Service]
User=deploy
WorkingDirectory=/var/www/pft
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
[Install]
WantedBy=multi-user.target
```

`sudo systemctl enable --now pft-scheduler.timer pft-queue.service`

Scheduled jobs: `ledger:verify` 02:00 WIB (alert on CRITICAL log), `finance:prune` 03:00 WIB.

## 6. Backups

```bash
mysqldump --single-transaction --routines pft | gzip | gpg --encrypt -r backup@… > /backup/pft-$(date +%F).sql.gz.gpg
```

Run daily, keep an off-site copy, and restore into a scratch database each quarter, then run `php artisan ledger:verify` against it.

## 7. Release checklist

- [ ] `php artisan test` green against **MySQL 8.4** (`.env.testing` → `DB_CONNECTION=mysql`, including `ConcurrentPostingTest`)
- [ ] `composer audit` clean
- [ ] `php artisan ledger:verify` OK after migration
