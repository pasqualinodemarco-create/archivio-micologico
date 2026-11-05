# Archivio Micologico

Plugin WordPress per la gestione di schede micologiche, classificazioni gerarchiche, articoli, ricerca e moduli frontend.

**Versione: 1.5.56** — WordPress 6.0+ e PHP 8.0+.

## Installazione

1. Scarica [archivio-micologico-1.5.56.zip](downloads/archivio-micologico-1.5.56.zip?raw=true).
2. In WordPress apri **Plugin → Aggiungi nuovo → Carica plugin**.
3. Seleziona lo ZIP e conferma l’aggiornamento di Archivio Micologico.

Lo ZIP in `downloads` contiene soltanto il plugin nella cartella `archivio-micologico`. **Code → Download ZIP** scarica invece l’intero repository, compresi i test.

## Permessi

- **Amministratore ed Editore:** gestione AM di schede, articoli, classificazioni, vocabolari, media, impostazioni, revisioni scientifiche e cestino.
- **Altri ruoli:** consultazione pubblica; nessuna gestione AM.
- L’Editore conserva il normale ruolo WordPress e non riceve `manage_options`.
- La sincronizzazione rimuove agli altri ruoli soltanto capability specifiche AM; conserva i permessi generali già presenti. Non può ricostruire permessi eventualmente rimossi dalle versioni precedenti o da altri strumenti.

## Correzione 1.5.56

Ripristinata la pagina **Info / Versione**: legge le modifiche della versione corrente da `CHANGELOG.txt`, gestisce un file mancante senza errore fatale e riconosce anche le intestazioni storiche.

## Correzioni 1.5.55

- Revisione scientifica allineata tra pulsanti, JavaScript e server, con controllo del singolo contenuto.
- Corrette classificazione, gestione dei PDF e conservazione degli allegati nei salvataggi frontend parziali.
- Cancellazione personalizzata unificata, protetta da nonce e permesso sul contenuto, con spostamento nel cestino. Se il cestino WordPress è disattivato, l’operazione viene bloccata.
- Salvataggio corretto di SEO e ordinamento articoli, limiti dei campi coerenti e rispetto dello stato predefinito delle nuove schede.
- Limite immagini applicato ai nuovi upload dai moduli AM; restano validi i limiti del server. I file già presenti non vengono modificati.
- Corretti messaggi di errore, pagina di inserimento di riserva e chiamata al log debug.

## Shortcode

| Shortcode | Funzione |
|---|---|
| `[am_archive]` | Archivio |
| `[am_search_page]` | Ricerca |
| `[am_random_funghi]` | Schede casuali |
| `[am_submit_form]` | Inserimento schede |
| `[am_edit_form]` | Modifica schede |
| `[am_article_form]` | Inserimento articoli |
| `[am_archive_filters]` | Filtri |

## Verifica

Test di integrazione su WordPress 7.1.4, PHP 8.3.6 e SQLite Database Integration. I test usano il core WordPress e account con ruoli diversi; i dettagli sono in [tests/README.md](tests/README.md). Non sostituiscono una prova sul sito di destinazione con i suoi temi, cache e plugin.

Questo aggiornamento riguarda i quattro interventi sui permessi dell’Editore e sui relativi flussi. Non chiude l’intero audit precedente: restano da affrontare la riservatezza di anteprime e schede protette, le protezioni aggiuntive delle tassonomie e i limiti delle richieste pubbliche.

## Documentazione e licenza

Consulta [readme.txt](readme.txt) e [CHANGELOG.txt](CHANGELOG.txt). Licenza GPLv2 o successiva, come indicato nel pacchetto originale.

## GitHub Actions

Il workflow **Test e pacchetto installabile** parte sui push a `main`, sulle pull request verso `main` e manualmente da **Actions → Test e pacchetto installabile → Run workflow**. Esegue i controlli sintattici e tutti i 70 scenari WordPress in un database sacrificabile, poi genera lo ZIP installabile.

Apri l’esecuzione completata e scarica **Artifacts → archivio-micologico-installabile**. Estrai l’archivio GitHub: al suo interno trovi lo ZIP del plugin da caricare in WordPress e il checksum SHA-256. I risultati JSON sono nell’Artifact **risultati-test-wordpress**. Gli artifact vengono conservati per 14 giorni.

Il workflow usa permessi di lettura del repository; non modifica il sito o le release. WordPress, SQLite Integration e WP-CLI hanno versioni e checksum fissati.
