# PaperPlane Mail Test Child

WordPress plugin that exposes a REST endpoint for automated mail function testing. Part of the PaperPlane mail monitoring system — install this on each site to be monitored.

The central site (running the **PaperPlane Mail Test** plugin) periodically calls the endpoint, verifies that `wp_mail()` works correctly, and sends an alert if something goes wrong.

---

## Requirements

- WordPress 5.9+
- PHP 8.0+
- PHP OpenSSL extension (required for secret key encryption)
- **PaperPlane Mail Test** installed on the central monitoring site

## Multisite

The plugin is compatible with WordPress Multisite. Install and activate it on each subsite you want to monitor. Each subsite gets its own secret key, stored encrypted in its own `wp_options` table — no shared configuration across the network.

---

## Installation

### 1. Download and upload the plugin

Download the latest release zip from the [GitHub Releases page](https://github.com/paperplanefactory/paperplane-mail-test-child/releases).

You can install it in two ways:

- **Via WordPress dashboard** — go to **Plugins → Add New → Upload Plugin**, select the zip file, and click Install Now.
- **Via FTP** — extract the zip and upload the `paperplane-mail-test-child` folder to `/wp-content/plugins/`.

Then activate the plugin from the WordPress dashboard.

### 2. Copy the secret key

Once the plugin is active, go to **Tools → PaperPlane Mail Test**. A secret key is generated automatically — copy it from there.

### 3. Add the site to the central monitor

Go to **Mail Monitor → Monitored Sites** on the central site and add this site with its URL, secret key, and check frequency.

---

## How it works

1. The central monitor sends an authenticated `POST` request to `/wp-json/pp-mail-test/v1/check`
2. The plugin runs `wp_mail()` and returns `true` or `false`
3. If the result is KO, the monitor sends an alert email to the configured recipients

Authentication uses a secret key generated automatically on first activation and stored encrypted (AES-256-CBC) in `wp_options`. On existing installations that already have a `PP_MAIL_TEST_SECRET` constant in `wp-config.php`, that constant takes priority and continues to work without changes.

## What it does not check

The endpoint verifies only that `wp_mail()` returns `true` — WordPress and its mailer accepted the message. An OK result does **not** mean the email reached the recipient's inbox.

The plugin does **not** check email deliverability: actual delivery (inbox, bounce, spam), SPF / DKIM / DMARC records, MX and PTR / reverse DNS, IP or domain blacklists, or message spam score. Use dedicated tools such as [mail-tester.com](https://www.mail-tester.com), [MXToolbox](https://mxtoolbox.com) or [Google Postmaster Tools](https://postmaster.google.com) for those checks.

---

## Updates

The plugin updates automatically from the WordPress dashboard via releases published on this repository.

---

## Related

- [PaperPlane Mail Test](https://github.com/paperplanefactory/paperplane-mail-test) — install on the central monitoring site

---

## Author

[Paper Plane Factory](https://paperplanefactory.com)
