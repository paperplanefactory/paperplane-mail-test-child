# PaperPlane Mail Test Child

WordPress plugin that exposes a REST endpoint for automated mail function testing. Part of the PaperPlane mail monitoring system — install this on each site to be monitored.

The central site (running the **PaperPlane Mail Test** plugin) periodically calls the endpoint, verifies that `wp_mail()` works correctly, and sends an alert if something goes wrong.

---

## Requirements

- WordPress 5.9+
- PHP 8.0+
- **PaperPlane Mail Test** installed on the central monitoring site

---

## Installation

### 1. Download and upload the plugin

Download the latest release from the [GitHub Releases page](https://github.com/paperplanefactory/paperplane-mail-test-child/releases), extract the zip, upload the `paperplane-mail-test-child` folder to `/wp-content/plugins/`, and activate it from the WordPress dashboard.

### 2. Add the secret key to `wp-config.php`

```php
define( 'PP_MAIL_TEST_SECRET', 'your-random-key-here' );
```

The suggested key is available under **Tools → PaperPlane Mail Test** once the plugin is active.

### 3. Add the site to the central monitor

Go to **Settings → Mail Monitor** on the central site and add this site with its URL, secret key, and check frequency.

---

## How it works

1. The central monitor sends an authenticated `POST` request to `/wp-json/pp-mail-test/v1/check`
2. The plugin runs `wp_mail()` and returns `true` or `false`
3. If the result is KO, the monitor sends an alert email to the configured recipients

Authentication uses the `PP_MAIL_TEST_SECRET` constant defined in `wp-config.php`. The key is never stored in the database.

---

## Updates

The plugin updates automatically from the WordPress dashboard via releases published on this repository.

---

## Related

- [PaperPlane Mail Test](https://github.com/paperplanefactory/paperplane-mail-test) — install on the central monitoring site

---

## Author

[Paper Plane Factory](https://paperplanefactory.com)
