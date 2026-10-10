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

### 1. Download the installable zip

Use **`paperplane-mail-test-child.zip`** — never GitHub's "Source code" archives.

**Direct download (always the latest version):**
[paperplane-mail-test-child.zip](https://github.com/paperplanefactory/paperplane-mail-test-child/releases/latest/download/paperplane-mail-test-child.zip)

```
https://github.com/paperplanefactory/paperplane-mail-test-child/releases/latest/download/paperplane-mail-test-child.zip
```

**Or from the releases page:**

1. Open the [latest release](https://github.com/paperplanefactory/paperplane-mail-test-child/releases/latest)
2. Scroll down and expand **Assets**
3. Download **`paperplane-mail-test-child.zip`**
4. Do **not** download "Source code (zip)" or "Source code (tar.gz)"

**How to recognise the right file:** it is named exactly `paperplane-mail-test-child.zip` (no version number) and contains a single folder named `paperplane-mail-test-child/`.

> **Why it matters.** The "Source code" archive contains a folder named after the version (e.g. `paperplane-mail-test-child-1.4.8`). WordPress identifies a plugin by its folder name, so each installation would end up in a differently named folder, and tools like MainWP would list it as a separate plugin. Updates keep the original folder name, so the problem never fixes itself.
>
> **Already installed from "Source code"?** Rename the folder via FTP to `paperplane-mail-test-child` (e.g. `paperplane-mail-test-child-1.4.8` → `paperplane-mail-test-child`), then reactivate the plugin from **Plugins**. Settings are stored in the database and are kept. Do not delete the old copy from the WordPress dashboard: deleting runs the uninstall routine, which removes the plugin's data.

### 2. Install and activate

- **Via WordPress dashboard** — go to **Plugins → Add New → Upload Plugin**, select `paperplane-mail-test-child.zip`, click **Install Now**, then **Activate**.
- **Via FTP** — extract the zip and upload the `paperplane-mail-test-child` folder to `/wp-content/plugins/`, then activate the plugin from **Plugins**.

### 3. Copy the secret key

Once the plugin is active, go to **Tools → PaperPlane Mail Test**. A secret key is generated automatically — copy it from there.

### 4. Add the site to the central monitor

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
