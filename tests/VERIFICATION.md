# Verifica versione 1.5.56

- WordPress 7.1.4, PHP 8.3.6 e SQLite Database Integration.
- **70/70 scenari superati**, 976 controlli.
- Riprodotto prima della correzione l’errore `Undefined constant "AM_CHANGELOG"` nella pagina Info / Versione.
- Verificati accesso di Amministratore ed Editore, diniego dell’Autore, visualizzazione del solo changelog corrente, fallback con file mancante, parsing dei formati storico e corrente.
- Verificata l’assenza di riferimenti PHP a costanti AM non definite.
- Tutti i precedenti scenari sui permessi e sui flussi di gestione continuano a passare.
- Controllo sintattico PHP superato. ZIP: 24 file runtime, CRC e corrispondenza con i sorgenti verificati.

Ambiente locale: nessuna operazione sul sito reale. Restano i limiti e gli altri rilievi di sicurezza indicati nel README.
