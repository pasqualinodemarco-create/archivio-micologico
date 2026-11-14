# Archivio Micologico

Plugin WordPress per la gestione di schede micologiche, classificazioni gerarchiche, articoli, ricerca e moduli frontend.

**Versione corrente: 1.6.7**. Richiede WordPress 6.0+ e PHP 8.0+.

## Installazione

1. Apri [GitHub Actions → Test e pacchetto installabile](../../actions/workflows/ci.yml?query=branch%3Amain) e seleziona l’ultima esecuzione riuscita su `main`.
2. Scarica l’artifact **archivio-micologico-installabile** ed estrai l’archivio. Al suo interno trovi lo ZIP del plugin e il checksum SHA-256.
3. In WordPress apri **Plugin → Aggiungi nuovo → Carica plugin**, seleziona lo ZIP interno e conferma l’installazione o l’aggiornamento.

Per configurare il plugin con Anna Weather, consulta **Archivio Micologico → Istruzioni** in WordPress.

## Permessi

- **Amministratore ed Editore:** gestione AM di schede, articoli, classificazioni, vocabolari, media, impostazioni, revisioni scientifiche e cestino.
- **Altri ruoli:** consultazione pubblica; nessuna gestione AM.
- L’Editore conserva il normale ruolo WordPress e non riceve `manage_options`.
- La sincronizzazione rimuove agli altri ruoli soltanto capability specifiche AM; conserva i permessi generali già presenti. Non può ricostruire permessi eventualmente rimossi dalle versioni precedenti o da altri strumenti.

## Shortcode

| Shortcode | Funzione |
|---|---|
| `[am_archive]` | Albero tassonomico, ricerca live e ultime schede |
| `[am_search_page]` | Ricerca dedicata e paginata |
| `[am_random_funghi]` | Carosello delle schede casuali, con 1–4 righe |
| `[am_submit_form]` | Inserimento schede |
| `[am_edit_form]` | Modifica schede |
| `[am_article_form]` | Inserimento articoli |
| `[am_archive_filters]` | Filtri |

## Verifica

Test di integrazione su WordPress 7.1.4, PHP 8.3.6 e SQLite Database Integration. I test usano il core WordPress e account con ruoli diversi; i dettagli sono in [tests/README.md](tests/README.md). Non sostituiscono una prova sul sito di destinazione con i suoi temi, cache e plugin.

## Documentazione e licenza

Consulta [readme.txt](readme.txt) e [CHANGELOG.txt](CHANGELOG.txt). Licenza GPLv2 o successiva, come indicato nel pacchetto originale.

## GitHub Actions

Il workflow **Test e pacchetto installabile** parte sui push a `main`, sulle pull request verso `main` e manualmente da **Actions → Test e pacchetto installabile → Run workflow**. Esegue i controlli sintattici e tutti i 70 scenari WordPress in un database sacrificabile, poi genera lo ZIP installabile.

Apri l’esecuzione completata e scarica **Artifacts → archivio-micologico-installabile**. Estrai l’archivio GitHub: al suo interno trovi lo ZIP del plugin da caricare in WordPress e il checksum SHA-256. I risultati JSON sono nell’Artifact **risultati-test-wordpress**. Gli artifact vengono conservati per 14 giorni.

Il workflow usa permessi di lettura del repository; non modifica il sito o le release. WordPress, SQLite Integration e WP-CLI hanno versioni e checksum fissati.
