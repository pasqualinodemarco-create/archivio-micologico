=== Archivio Micologico ===
Contributors: micologiabf
Donate link: https://www.micologiabf.org/
Tags: fungi, mycology, taxonomy, scientific archive, biodiversity, search
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.6.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Archivio Micologico è un plugin WordPress per la gestione e consultazione di un archivio micologico scientifico strutturato per Famiglia e Genere, con ricerca avanzata e anteprime dinamiche.

== Description ==

**Archivio Micologico** è un plugin WordPress progettato per associazioni micologiche, ricercatori e appassionati che desiderano gestire un archivio fungino strutturato, controllato e scientificamente coerente.

Il plugin consente di:
- organizzare le schede per **Famiglia → Genere**
- trattare ogni **specie come una scheda scientifica**
- associare **Forma/Varietà** e **Autore** come attributi (meta)
- offrire una consultazione moderna con **ricerca live**, **anteprime**, **highlight** e **scroll infinito**

La UX è pensata per evitare errori tassonomici e garantire qualità dei dati.

### Funzionalità principali
- Archivio interattivo con shortcode `[am_archive]`
- Ricerca avanzata con anteprime live e miniature
- Evidenziazione del testo cercato (match highlight)
- Navigazione ad albero senza cambio pagina
- Compatibilità PHP 8+ e hosting condivisi (es. Altervista)
- Widget nativo WordPress per mostrare 3 schede fungo casuali

Sviluppato e mantenuto da **Associazione Micologica Bassa Friulana**.

== Installation ==

1. Carica la cartella `archivio-micologico` nella directory `/wp-content/plugins/`
2. Attiva il plugin dal menu **Plugin** in WordPress
3. Inserisci gli shortcode desiderati nelle pagine:
   - `[am_archive]` per l’archivio
   - `[am_search_page]` per la ricerca avanzata
   - `[am_random_funghi]` per mostrare 3 schede fungo casuali

== Frequently Asked Questions ==

= Il plugin crea tassonomie personalizzate? =
Sì. Il plugin utilizza tassonomie gerarchiche per Famiglia e Genere.

= Le specie sono tassonomie? =
No. Ogni specie è una **scheda (post)**. Questo garantisce flessibilità, metadati avanzati e controllo editoriale.

= È possibile creare nuove famiglie o generi dal frontend? =
No. La creazione e modifica delle tassonomie è riservata ai ruoli autorizzati.

= È compatibile con PHP 8? =
Sì. Il plugin è sviluppato e testato con PHP 8+.

== Screenshots ==

1. Archivio fungino ad albero con Famiglia e Genere
2. Ricerca live con anteprime e miniature
3. Evidenziazione del testo cercato
4. Tooltip informativo con logo e crediti

== Changelog ==

= 1.6.9 =
* Ultime schede pubblicate sotto l’albero tassonomico anche su desktop.

= 1.6.8 =
* Nuova presentazione della pagina Archivio, con indice tassonomico e ultime schede affiancati su desktop.

= 1.6.7 =
* Aggiornata la pagina Istruzioni per la configurazione delle pagine e dei moduli con Anna Weather.

= 1.6.6 =
* Ricerca uniforme per contenuti, autore, forma e tassonomie. Rimossi blocchi e variabili inutilizzati.

= 1.6.5 =
* Corretto l’errore fatale nella scheda singola con note o descrizione microscopica.

= 1.6.4 =
* Estrazione dei moduli e delle azioni di gestione in una classe dedicata.

= 1.6.3 =
* Estrazione della visualizzazione e della ricerca dell’archivio in una classe dedicata.

= 1.6.2 =
* Estrazione del carosello delle schede casuali in una classe dedicata. Widget e shortcode condividono i valori predefiniti.

= 1.6.1 =
* Rimossi integrazione, hook e selettori specifici per Elementor.

= 1.6.0 =
* Migrazione delle classi al namespace ArchivioMicologico e autoload PSR-4 incluso nel plugin. I nomi storici rimangono disponibili tramite alias.

= 1.5.64 =
* Pannello delle ultime schede pubblicate con quantità configurabile e collegamento diretto #ultime-schede.

= 1.5.63 =
* Sincronizza gli editor nei moduli guidati e mostra la conferma di inserimento solo per il salvataggio appena concluso.

= 1.5.62 =
* Consente lo stesso epiteto specifico in generi diversi, evitando duplicati nello stesso genere.

= 1.5.61 =
* Corretto il layout del carosello quando il numero di schede è inferiore a righe × colonne. Caricati gli asset anche nelle pagine con il solo widget o shortcode.

= 1.5.60 =
* Il carosello delle schede casuali supporta da una a quattro righe. Lo shortcode accetta il parametro righe.

= 1.5.59 =
* Aggiunta l’impostazione per mostrare o nascondere il titolo interno delle schede fungo.

= 1.5.58 =
* Percorso guidato anche per la modifica delle schede, con anteprima del titolo e riepilogo.

= 1.5.57 =
* Modulo nuova scheda con quattro passaggi, riepilogo unico e layout adattabile.

= 1.5.56 =
* Corretta la pagina Info / Versione: il changelog viene letto dal file incluso, senza costanti obsolete.
* Gestiti file mancanti e formati storici del changelog.

= 1.5.55 =
* Allineati interfaccia e server per la revisione scientifica di Amministratore ed Editore.
* Ripristinati classificazione, PDF e allegati facoltativi nelle modifiche frontend.
* Unificata la cancellazione con controllo del contenuto, nonce e cestino recuperabile.
* Corretti salvataggio SEO, ordinamento articoli e validazione delle impostazioni.
* Preservate le capability WordPress degli altri ruoli; nessun manage_options aggiunto all’Editore.
* Corretti messaggi di errore, fallback pagina inserimento e debug AM.
* Applicato il limite immagini ai nuovi upload dai moduli AM.


= 1.5.54 =
* Archivio: rimosso il conteggio “Specie presenti” dal campo di ricerca.
* Archivio: aggiunta card riepilogativa con icona fungo sotto i menu collassabili.

= 1.5.52 =
* Nasconde il blocco Articoli in archivio quando non sono presenti articoli, evitando il messaggio “Nessun articolo”.
* Navigazione schede: ripristino più completo dei filtri tassonomici con am_division e catena Divisione → Famiglia → Genere → Specie.


= 1.5.49 =
* Navigazione schede: quando si torna ai risultati con am_restore=1, archivio e ricerca provano a riapplicare ricerca/filtri dall’URL.


= 1.5.48 =
* Hotfix “Torna ai risultati”: il link di ritorno conserva anche stato ricerca/filtro quando la pagina archivio lavora in AJAX.


= 1.5.47 =
* Navigazione schede: rimossa la barra azioni nella parte alta della scheda, mantenuta intatta quella in fondo pagina.


= 1.5.46 =
* Hotfix navigazione: il link “Torna ai risultati” viene applicato anche ai link scheda caricati dinamicamente da archivio/ricerca.


= 1.5.45 =
* Navigazione schede fase 3: aggiunto link contestuale “Torna ai risultati” quando la scheda viene aperta da archivio/ricerca.


= 1.5.44 =
* Navigazione schede fase 2: aggiunta barra azioni sopra e sotto la scheda con link Archivio, Famiglia, Genere e Cerca altra scheda.


= 1.5.43 =
* Navigazione schede fase 1: breadcrumb tassonomica cliccabile Archivio → Famiglia → Genere → Specie.


= 1.5.42 =
* Hotfix immagine principale scheda: rendering con dimensione full e stile inline per occupare tutta la larghezza disponibile.


= 1.5.41 =
* Hotfix CSS: l’immagine principale delle schede fungo occupa tutta la larghezza disponibile del contenitore.


= 1.5.40 =
* Hotfix social preview: aggiunto fallback con output buffer che inserisce i tag Open Graph/Twitter direttamente nel <head> finale se wp_head non li stampa.


= 1.5.39 =
* Hotfix social preview reale: risoluzione robusta della scheda AM nel wp_head e descrizione social dalla descrizione macroscopica pubblica prima dei meta fallback.


= 1.5.38 =
* Hotfix minimale social preview: Open Graph/Twitter Card indipendenti dalla modalità SEO, senza nuove opzioni admin.
* SEO base compatibile con plugin SEO per evitare duplicati.


= 1.5.36 =
* Riallineamento documentazione e stato progetto dopo il ciclo UX Nuovo Inserimento 1.5.25–1.5.35.
* Nessuna modifica funzionale al runtime.


= 1.5.35 =
* Hotfix Nuovo Inserimento: gli errori di validazione vengono mostrati solo dopo un invio reale e non restano bloccati su una nuova scheda pulita.


= 1.5.34 =
* Hotfix classificazione: ricostruzione automatica Divisione/Famiglia/Genere dalla Specie selezionata per evitare falsi errori di classificazione mancante.


= 1.5.33 =
* Hotfix galleria: deduplicati gli ID immagini prima del rendering pubblico per evitare didascalie duplicate.


= 1.5.32 =
* UX Nuovo Inserimento: controllo qualità finale più leggibile con riepilogo dinamico e PDF facoltativo.


= 1.5.31 =
* Hotfix: il pulsante Modifica nel messaggio post-creazione ora punta alla modifica frontend e non al backend WordPress.


= 1.5.30 =
* Hotfix: rimosso residuo HTML/PHP visibile in fondo al form Nuovo inserimento dopo lo spostamento dei messaggi in alto.


= 1.5.29 =
* UX Nuovo Inserimento fase 5: messaggi successo, errori e tassonomie spostati in alto nel form.


= 1.5.28 =
* UX Nuovo Inserimento fase 4: messaggio post-salvataggio più utile con link rapidi alla scheda.


= 1.5.27 =
* UX Nuovo Inserimento fase 3: anteprime e stati più chiari per immagine, galleria e PDF.


= 1.5.26 =
* UX Nuovo Inserimento fase 2: pulsante per inserire una traccia descrittiva modificabile nella descrizione macroscopica.


= 1.5.25 =
* Miglioramento UX fase 1 per [am_submit_form]: guida iniziale, sezioni più chiare, anteprima titolo e controllo finale visivo.


= 1.5.12 =
* Fase 3 SEO: meta SEO leggeri per pagine contenenti shortcode Archivio Micologico.


= 1.5.11 =
* Fase 2 SEO: compatibilità plugin SEO, modalità automatica/forzata/disattiva.


= 1.5.10 =
* Fase 1 SEO base schede fungo: meta description, canonical e JSON-LD Schema.org.


= 1.5.9 =
* Anteprima social per schede fungo: Open Graph e Twitter Card con immagine in evidenza e descrizione macroscopica.


= 1.5.8 =
* Aggiunta tabella permessi ruoli nella pagina Istruzioni.


= 1.5.7 =
* Fix doppio pulsante Cerca nello shortcode [am_search_page].


= 1.5.6 =
* Compatibilità leggera Elementor Pro: condizioni template per Schede Funghi e Tutto AM, senza cambiare il rendering del plugin.


= 1.5.5 =
* Audit shortcode/widget in Impostazioni per verificare gli elementi mantenuti dopo la semplificazione.


= 1.5.4 =
* Hardening runtime capability Admin/Editore e pulizia automatica residui ruoli AM.


= 1.5.3 =
* Pulizia residui legacy ruoli AM e consolidamento strumenti admin.


= 1.5.2 =
* Pulizia Impostazioni: rimossa la matrice ruoli configurabile, permessi mostrati solo come Admin/Editore.


= 1.5.1 =
* Consolidamento permessi: helper centrale Admin/Editore e normalizzazione opzioni legacy.


= 1.5.0 =
* Regressione ragionata dalla 1.3.46: permessi semplificati su ruoli WordPress Amministratore/Editore, rimossi ruoli AM personalizzati, shortcode e widget carosello mantenuti.


= 1.3.44 =
* Corretta davvero la colonna Azioni dei Vocabolari: pulsanti Modifica, Unisci ed Elimina sulla stessa riga.


= 1.3.43 =
* Sistemato il layout dei pulsanti nella tabella Vocabolari: Modifica, Unisci ed Elimina ora stanno sulla stessa riga.


= 1.3.42 =
* Corretta la gestione delle voci nei Vocabolari con permessi più coerenti, validazione nome più robusta e fusione termini più affidabile.


= 1.3.41 =
* Risolto l'errore fatale delle build precedenti ripartendo dalla base stabile 1.3.39b.
* Numero di versione della Dashboard spostato sotto il logo dell’archivio.


= 1.3.39b =
* Build corretta: ordine delle card statistiche in Dashboard impostato su Schede totali, Basidiomycota, Ascomycota, Articoli totali.


= 1.3.39 =
* Corretto l'ordine delle card statistiche in Dashboard: Schede totali, Basidiomycota, Ascomycota, Articoli totali.


= 1.3.38 =
* Dashboard: nel riquadro Ultime schede il link principale apre ora la visualizzazione front-end in una nuova scheda browser, con link separato alla modifica admin.


= 1.3.36 =
* Aggiunti meta tag Open Graph e Twitter/X per mostrare anteprima con immagine, titolo e descrizione quando una scheda viene condivisa sui social.


= 1.3.35 =
* Il carosello delle schede casuali può mostrare fino a 3 immagini contemporaneamente.
* Aggiunta in Impostazioni la nuova opzione per scegliere quante schede visibili mostrare insieme.


= 1.3.34 =
* Corretto un errore JavaScript che impediva il funzionamento di pallini, frecce e autoplay del carosello nel front-end.
* Inizializzazione resa più robusta anche con Elementor.


= 1.3.33 =
* Aggiornata la pagina Istruzioni del widget/carosello.
* Aggiunta in Impostazioni la personalizzazione del widget: titolo, numero schede, autoplay e intervallo.


= 1.3.32 =
* Il widget e lo shortcode delle schede casuali ora usano un carosello orizzontale con movimento automatico.
* Il numero predefinito sale a 5 schede con immagini, pulsanti di navigazione e indicatori.


= 1.3.29 =
* Aggiunto widget WordPress nativo per mostrare 3 schede fungo casuali nelle aree widget del tema.
* Il widget è integrato nel plugin Archivio Micologico esistente e non crea un plugin separato.
* Aggiunto shortcode `[am_random_funghi]` come fallback per Elementor o builder compatibili.


= 1.2.15 =
* [am_submit_form] UI: pulsanti uniformati e coerenti con lo stile Elementor (colori/forme), inclusi i pulsanti di creazione rapida termini.

= 0.50.34 =
* Modifica scheda: migliorata visibilità pulsante rimozione immagini (X) e rimosso asterisco da "Immagini".
* Lightbox galleria: aggiunta navigazione (prev/next) e bordo grigio chiaro alle immagini in popup.

= 0.50.33 =
* Scheda fungo: autore WordPress (Nome Cognome) mostrato sotto il titolo.
* Immagini: visualizzazione didascalia (caption) per immagine in evidenza e galleria.

= 0.50.32 =
* Fix: risultati ricerca in [am_search_page] compatibili con widget esterni (supporto parametro URL "s", es. Elementor).
* Fix: markup risultati più robusto per evitare effetti di layout su colonne laterali.

= 0.50.30 =
* Pagina Vocabolari: ricerca rapida e funzione Unisci (merge) per gestire duplicati.

= 0.50.23 =
* Rimosso badge PDF e Microscopia in scheda fungo.
* Aggiunto checkbox "Specie sequenziata" nel form di inserimento/modifica e badge in scheda.


= 0.50.20 =
* Fix: visualizzazione Allegato PDF nella scheda (rilevamento PDF più robusto)
* Aggiunta icona PDF accanto al nome file

= 0.50.17 =
* Nuovo inserimento: aggiunto allegato PDF facoltativo (selezione da Media Library).

= 0.50.16 =
* UX invio scheda: ridotti i messaggi di conferma duplicati; ora viene mostrata una sola conferma sintetica.

= 0.50.14 =
* Aggiunto readme.txt (formato WordPress) per futura pubblicazione.

= 0.50.13 =

== Upgrade Notice ==

= 0.50.17 =
Aggiornamento consigliato: aggiunge l’allegato PDF facoltativo nel form di nuovo inserimento.

== License ==

Archivio Micologico è rilasciato sotto licenza GPLv2 o successiva.