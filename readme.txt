=== PaperPlane Mail Test Child ===
Contributors: paperplanefactory
Requires at least: 5.9
Tested up to: 6.7
Stable tag: 1.4.1
License: GPLv2 or later

Exposes a REST endpoint for automated mail function testing. Install on each site to monitor.

== Description ==

Part of the PaperPlane mail monitoring system. Install this plugin on each client site to be monitored.

The central site (running the PaperPlane Mail Test plugin) periodically calls the endpoint, verifies that `wp_mail()` works correctly, and sends an alert if something goes wrong.

== Changelog ==

= 1.4.1 =
* Fix: il bottone "Rigenera chiave" nello stato wpconfig_active ora genera correttamente una nuova chiave suggerita
* Fix: rimossa assegnazione ridondante della variabile `$auth` in `pp_mt_auth()`
* Aggiunto `uninstall.php`: rimuove l'opzione `pp_mt_secret_key` da `wp_options` alla disinstallazione, con supporto multisite

= 1.4.0 =
* Supporto multisite: la chiave segreta viene generata automaticamente e salvata cifrata (AES-256-CBC) in `wp_options` per ogni sito
* In una rete multisite ogni sottosito ha la propria chiave univoca
* Retrocompatibilità: la costante `PP_MAIL_TEST_SECRET` in `wp-config.php` ha ancora la priorità
* Nuova UI: mostra/nascondi chiave, copia, rigenera

= 1.3.6 =
* Soggetto email aggiornato al formato `[PaperPlane Mail Test child site]`

= 1.3.5 =
* Fix: la chiave in wp-config.php già configurata non richiede più una nuova verifica dopo l'aggiornamento del plugin

= 1.3.4 =
* Chiave mostrata solo durante la configurazione iniziale; nascosta dopo la verifica
* Pulsante per rigenerare la chiave suggerita
* Nota all'attivazione: copia la chiave prima di chiudere la pagina
