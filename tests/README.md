# Test di integrazione AM 1.5.55

Eseguire **solo su un’installazione WordPress locale e sacrificabile**, mai sul sito reale. Il test crea utenti, contenuti e termini e modifica impostazioni.

Ambiente usato: WordPress 7.1.4, PHP 8.3.6, SQLite Database Integration. Il plugin da verificare deve essere attivo e deve esistere l’amministratore `test_admin`.

`integration.php` usa il core WordPress per ruoli, capability, nonce, salvataggi e cestino. Non usa simulazioni delle API WordPress. Il runner esegue ogni scenario in un processo e in una copia separata del database SQLite iniziale.

Nel `wp-config.php` dell’ambiente di prova usare:

```php
define('DB_PATH', getenv('AM_TEST_DB') ?: '/tmp/am-editor-baseline.sqlite');
```

Configurare queste variabili d’ambiente prima dell’esecuzione:

- `AM_WP_ROOT`: directory WordPress installata.
- `AM_TEST_PHP`: percorso dell’eseguibile PHP o di un wrapper che carichi le estensioni necessarie.
- `AM_TEST_DB_BASE`: copia SQLite iniziale dell’installazione di prova, con plugin attivo e amministratore `test_admin`.
- `AM_TEST_RESULTS`: percorso facoltativo del JSON dei risultati.

Eseguire `python3 tests/run_integration.py` dalla radice del repository.

Copertura: Amministratore, Editore, Autore, Collaboratore, Sottoscrittore e visitatore; revisioni; modifica di contenuti altrui; nonce errati; permesso sul singolo contenuto revocato; conservazione/rimozione PDF e galleria; testo con apostrofi; stati draft/pending/publish/private; cestino e ripristino; rifiuto di tipo errato e richieste GET; cestino disattivato; campi impostazioni e limiti; nessun `manage_options` aggiunto all’Editore; permessi generali preservati; interfaccia dei pulsanti; upload AM e upload WordPress ordinari; vocabolari; debug e fallback inserimento.

Limiti: database di prova SQLite; nessuna verifica end-to-end con temi, cache, Elementor Pro o configurazione del sito reale. La suite non rappresenta la chiusura degli altri rilievi di sicurezza dell’audit.
