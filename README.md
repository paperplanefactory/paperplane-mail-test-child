# PaperPlane Mail Test Child

Plugin WordPress che espone un endpoint REST per il test automatico della funzione mail. Fa parte del sistema di monitoraggio mail PaperPlane e va installato su ogni sito da monitorare.

Il sito centrale (con il plugin **PaperPlane Mail Test**) chiama periodicamente l'endpoint, verifica che `wp_mail()` funzioni correttamente e invia una notifica in caso di anomalia.

---

## Requisiti

- WordPress 5.9+
- PHP 8.0+
- Plugin **PaperPlane Mail Test** installato sul sito di monitoraggio centrale

---

## Installazione

### 1. Installa il plugin

Carica la cartella `paperplane-mail-test-child` nella directory `/wp-content/plugins/` e attiva il plugin dalla dashboard WordPress.

### 2. Aggiungi la chiave segreta in `wp-config.php`

```php
define( 'PP_MAIL_TEST_SECRET', 'chiave-casuale-qui' );
```

La chiave suggerita è disponibile in **Strumenti → PaperPlane Mail Test** una volta attivato il plugin.

### 3. Configura il sito nel monitor centrale

Vai su **Impostazioni → Monitor Mail** sul sito centrale e aggiungi il sito con URL, chiave segreta e frequenza di controllo.

---

## Come funziona

1. Il monitor centrale invia una richiesta `POST` autenticata all'endpoint `/wp-json/pp-mail-test/v1/check`
2. Il plugin esegue `wp_mail()` e restituisce `true` o `false`
3. Se il risultato è KO, il monitor invia una notifica email agli indirizzi configurati

L'autenticazione avviene tramite la costante `PP_MAIL_TEST_SECRET` definita in `wp-config.php`. La chiave non viene mai salvata nel database.

---

## Aggiornamenti

Il plugin si aggiorna automaticamente dalla dashboard WordPress tramite le release pubblicate su questo repository.

---

## Autore

[Paper Plane Factory](https://paperplanefactory.com)
