# Dealer Portal — WordPress Plugin

## Descrizione

Dealer Portal è un plugin WordPress per la gestione documentale in una rete di distribuzione (settore nautica/marino). Gli amministratori — e, entro il proprio perimetro, gli area manager — caricano documenti tecnici (PDF, XLSX, DOCX); i dealer li trovano e li scaricano da un'area riservata che mostra loro **solo** ciò a cui la propria organizzazione dà diritto.

Lo scopo primario non è la ricerca né la dashboard: è il **controllo d'accesso granulare con audit trail**. Ogni documento è filtrato per livello commerciale e per linea prodotto, ogni download è tracciato con il titolo con cui è avvenuto, e i documenti scaduti vengono bloccati automaticamente. Ricerca, dashboard e notifiche sono gli strati che rendono usabile quel nucleo.

I diritti di accesso appartengono all'**organizzazione** (l'azienda dealer), non al singolo utente: è lei a detenere livello commerciale e linee prodotto, ereditabili lungo una gerarchia gruppo → concessionaria → filiale. Gli utenti vi appartengono con una funzione — titolare o collaboratore — e ereditano un sottoinsieme dei suoi diritti, mai di più. Questo evita che dieci collaboratori della stessa azienda vadano configurati dieci volte con il rischio che divergano fra loro, e fa sì che una revoca sull'azienda si propaghi da sola a tutti i suoi utenti.

---

## Funzionalità

### Accesso e sicurezza
- Tre ruoli dealer custom: `dealer`, `top_dealer`, `part_center`, più il ruolo `area_manager`
- Modello a organizzazioni: livello commerciale e linee prodotto appartengono all'azienda, non all'utente, con ereditarietà gerarchica che **restringe e non amplia mai**
- Doppio controllo di accesso su ogni documento: livello commerciale **e** linea prodotto, risolti sempre dall'organizzazione dell'utente
- Log di ogni download (utente, documento, timestamp, IP, titolo con cui è avvenuto l'accesso)
- Blocco automatico dei documenti scaduti: esclusi dalla ricerca, HTTP 403 al download
- Richieste di accesso self-service con coda di approvazione admin
- Capability separate (`upload_dealer_docs`, `view_dealer_logs`, `manage_dealer_orgs`) invece di un'unica capability amministrativa
- **wp-admin chiuso a tutti gli utenti del portale**, area manager compresi: chi usa il portale lavora sulle pagine del portale, non nel backend di WordPress. Barra di amministrazione nascosta, redirect dopo il login sulla propria area. Unica eccezione `profile.php`, perché la password è dell'utente

### Ricerca (dealer)
- Ricerca a faccette: sidebar con filtri a selezione multipla e conteggi live, aggiornamento AJAX senza reload
- Griglia di card con icona per tipo file, badge **NUOVO** e **IN SCADENZA**
- Pulsante **Vedi** (apre il PDF nel browser, senza scaricarlo) accanto a **Scarica**, ovunque compaia un documento
- Ordinamento per pertinenza, data, titolo o numero di download
- Chip dei filtri attivi rimovibili singolarmente, URL condivisibile e bookmarkabile
- Funziona **anche senza JavaScript** (fallback a form GET)
- Ricerca full-text via SearchWP 4.x, con fallback alla ricerca nativa WP

### Libreria personale (dealer)
- Preferiti: stella su ogni card, anteprima in dashboard e **pagina dedicata** (`[dealer_favorites]`)
- **Etichette personali**: il dealer crea le proprie etichette, le assegna ai preferiti, filtra e ordina la propria raccolta. Sono sue e visibili solo a lui — nessun amministratore le vede né le imposta
- Cronologia dei propri download
- Storico versioni consultabile e scaricabile dalla card
- Download aggregato in ZIP dei risultati filtrati o dei preferiti
- Dashboard con documenti recenti, documenti in scadenza e referente commerciale
- Link di ritorno alla dashboard da ogni pagina del portale: la dashboard è la home dell'area riservata, ogni altra pagina ne è un ramo
- Layout a tutta larghezza: le pagine escono dal contenitore del tema e usano lo schermo, con ripiego automatico sui temi che ritagliano ciò che deborda

### Gestione documenti (admin e area manager)
- Wizard di caricamento in 5 step con drag-and-drop, rinomina file e validazione MIME
- Versionamento reale: le revisioni formano una catena, con storico e promozione automatica
- Archivio con filtri, azioni singole e **azioni di gruppo** (obsoleto / eliminazione)
- Export CSV dei log, con filtri per periodo, documento e dealer
- Pagina statistiche: top documenti, top dealer, documenti mai scaricati, dealer inattivi
- L'**area manager** carica e versiona documenti sulle proprie linee, dentro un perimetro validato lato server; non può eliminare definitivamente né pubblicare senza restrizione di linea

### Organizzazioni e delega
- CPT gerarchico `dealer_org`: livello commerciale, linee concesse, stato, gerarchia gruppo → concessionaria → filiale
- Linee effettive calcolate come intersezione fra quelle proprie e quelle ereditate dalla madre: una sede non può mai ottenere una linea che il gruppo non ha
- Sospensione che si propaga automaticamente alle organizzazioni discendenti
- Interfaccia admin per creare, modificare, assegnare utenti, fondere e sospendere organizzazioni, con l'effetto dell'ereditarietà sempre mostrato esplicitamente
- Interfaccia admin dedicata per assegnare il perimetro di un **area manager** (organizzazioni radice seguite, linee di pubblicazione): un asse separato da quello dei dealer, che non appartiene a nessuna organizzazione
- Migrazione idempotente degli utenti esistenti: ognuno diventa titolare di un'organizzazione che eredita esattamente le sue linee, senza cambiare cosa vede
- Il **titolare** di un'azienda invita, limita alle linee e disattiva i propri collaboratori da un'area dedicata, senza passare dall'amministratore

### Notifiche
- Email al dealer quando esce un documento accessibile alle sue linee
- Digest settimanale dei documenti in scadenza
- Report periodico agli amministratori su documenti in scadenza e scaduti
- Preferenze per utente e pagina impostazioni con invio di prova

---

## Requisiti

| Requisito | Versione |
|---|---|
| WordPress | 5.8 o superiore |
| PHP | 7.4 o superiore |
| WP Customer Area | 8.3 (infrastruttura pagine front-end riservate) |
| SearchWP | 4.x (opzionale — abilita ricerca full-text; senza: ricerca WP nativa) |
| Estensione PHP `zip` | opzionale — richiesta solo dal download aggregato ZIP |

---

## Installazione

1. Scaricare o clonare il repository come ZIP.
2. In WordPress admin, andare in **Plugin → Aggiungi nuovo → Carica plugin** e selezionare il file ZIP.
3. Cliccare **Attiva plugin**.
4. Cinque pagine vengono create automaticamente:
   - **Dashboard Dealer** (slug: `dashboard-dealer`, shortcode: `[dealer_dashboard]`)
   - **Cerca Documenti** (slug: `dealer-search`, shortcode: `[dealer_search]`)
   - **Preferiti** (slug: `dealer-preferiti`, shortcode: `[dealer_favorites]`)
   - **Gestione Collaboratori** (slug: `dealer-team`, shortcode: `[dealer_team]`)
   - **Area Manager** (slug: `dealer-area-manager`, shortcode: `[dealer_area_manager]`)
5. La tabella custom `{prefisso}_dealer_download_log` viene creata automaticamente.
6. I ruoli custom (`dealer`, `top_dealer`, `part_center`, `area_manager`) vengono registrati; le capability (`manage_dealer_portal`, `upload_dealer_docs`, `view_dealer_logs`, `manage_dealer_orgs`) vengono assegnate ai ruoli corretti.
7. La cartella protetta `uploads/dealer-docs/` viene creata con `.htaccess` che nega l'accesso HTTP diretto.

L'unica pagina **non** creata automaticamente è quella delle richieste di accesso: va creata a mano, pubblica, con lo shortcode `[dealer_access_request]`, perché la sua collocazione nel sito è una scelta editoriale.

Le pagine create automaticamente vengono ricontrollate a ogni aggiornamento del plugin, non solo alla prima attivazione: chi aggiorna sostituendo i file trova comunque le pagine nuove. Ogni link interno del portale è risolto dall'ID della pagina, quindi rinominarle o spostarle non rompe nulla.

### Aggiornamento da una versione precedente alla 1.3.0

Gli utenti esistenti continuano a funzionare esattamente come prima: senza un'organizzazione, il plugin ricade sui meta storici (`_dealer_lines`, ruolo WordPress). Per passare al nuovo modello:

1. Andare in **Dealer Portal → Organizzazioni**.
2. Eseguire la **migrazione**: ogni utente dealer non ancora migrato diventa titolare di una propria organizzazione che eredita esattamente le sue linee e il suo livello. È idempotente e produce un rapporto di cosa ha fatto.
3. **Fondere** le organizzazioni che nella realtà sono la stessa azienda (la migrazione ne crea una per utente).
4. Solo a questo punto ha senso costruire gerarchie di gruppo o assegnare la funzione di titolare per abilitare la delega dei collaboratori.

Fino a quando la migrazione non viene eseguita, il portale funziona come nella 1.2.0.

---

## Configurazione

### Assegnare linee prodotto a un'organizzazione (modello corrente)

1. **Dealer Portal → Organizzazioni** → creare o modificare l'organizzazione.
2. Impostare **livello commerciale** e **linee prodotto** (raggruppate per brand, con selezione rapida per brand intero).
3. Se l'organizzazione ha una madre, l'interfaccia mostra sempre le **linee effettive** risultanti dall'intersezione: una linea selezionata che la madre non possiede non avrà effetto, ed è segnalato esplicitamente.
4. **Dealer Portal → Organizzazioni → Utenti** per assegnare un utente con una funzione (titolare o collaboratore) e, facoltativamente, restringerlo a un sottoinsieme delle linee aziendali.

Le linee prodotto usano il formato `Brand|Linea` (es. `Mercury|FourStroke`).

### Assegnare linee prodotto a un utente (modello storico, ancora supportato)

1. **Utenti → Tutti gli utenti**, modificare il profilo del dealer.
2. Sezione **Impostazioni Dealer Portal** → campo **Linee Prodotto Assegnate** (selezione multipla con Ctrl/Cmd).
3. Compilare opzionalmente i campi del referente commerciale.
4. Nella sezione **Notifiche Dealer Portal** si attivano o disattivano le email per quell'utente.

Vale solo per gli utenti **senza** un'organizzazione assegnata: il meta storico `_dealer_lines` è il fallback usato da `Dealer_Identity` quando `_dealer_org` è assente.

### L'area manager

Sono **due passaggi**, e il secondo non è opzionale: un utente con il solo ruolo non può fare niente.

1. **Creare l'utente** con ruolo `area_manager`, da **Utenti → Aggiungi nuovo** (il ruolo si sceglie nel menu a tendina in fondo al modulo), oppure cambiando il ruolo di un utente esistente. Appena creato compare in **Dealer Portal → Area Manager**.
2. **Assegnargli il perimetro** da **Dealer Portal → Area Manager → Assegna perimetro**. I due assi non pesano uguale:
   - le **linee prodotto** (`_am_lines`) sono ciò che lo rende operativo: senza almeno una, `can_publish_to_lines()` rifiuta sempre e non può pubblicare né aggiornare alcun documento;
   - le **organizzazioni** seguite (`_am_orgs`) sono la supervisione (collaboratori, log, statistiche dei dealer che segue) e sono **facoltative**: chi si occupa solo di pubblicare documenti funziona benissimo senza nessuna organizzazione. Solo le radici sono selezionabili, il sottoalbero di ciascuna è incluso automaticamente.

Finché non ha nessuna linea, l'area manager vede "Il tuo perimetro non è ancora stato configurato" nella propria area di lavoro, e l'elenco lo segnala come **non operativo**.

> **Attenzione al campo sbagliato.** Nel profilo WordPress di un utente esiste una sezione *Impostazioni Dealer Portal → Linee Prodotto Assegnate*: appartiene al modello **dealer** (`_dealer_lines`) e per un area manager non viene mai letta. Per questo, sul profilo di un area manager, quella sezione **non compare**: al suo posto c'è il riepilogo del perimetro reale e il pulsante per assegnarlo.

Con il perimetro assegnato può caricare, versionare e marcare obsoleti i documenti sulle sue linee, e vedere log e statistiche limitati al proprio giro. Non può eliminare definitivamente né toccare organizzazioni, utenti o impostazioni.

Tutto questo avviene **sul front-end**, nella sua area di lavoro (`[dealer_area_manager]`): caricamento, aggiornamento delle versioni, archivio e statistiche del perimetro. L'area manager non è un tecnico del sito e non entra mai in wp-admin — dopo il login viene portato direttamente lì.

### Brand, linee e tipi di documento

Sono hardcoded nelle costanti `PRODUCT_LINES` e `DOC_TYPES` di `Dealer_Admin` (`includes/class-admin.php`). La lista attuale copre 28 brand fra motori fuoribordo, elettronica di navigazione, propulsione elettrica e costruttori di imbarcazioni.

| Chiave tipo | Etichetta |
|---|---|
| `scheda_tecnica` | Scheda Tecnica |
| `listino` | Listino |
| `manuale` | Manuale |
| `certificazione` | Certificazione |
| `marketing` | Marketing |
| `altro` | Altro |

---

## Workflow Admin

### Caricare un documento

**Dealer Portal → Carica Documento**

1. **Step 1 — File**: PDF, XLSX o DOCX (max 50 MB), con rinomina facoltativa.
2. **Step 2 — Classificazione**: brand, linea, tipo, anno, versione. Qui si trova anche il campo **"Questo documento sostituisce…"**: selezionando un documento esistente, quello caricato ne diventa la nuova versione corrente.
3. **Step 3 — Visibilità**: ruoli autorizzati, eventuale restrizione per linea, data di scadenza.
4. **Step 4 — Keywords**: parole chiave e note interne (non visibili ai dealer).
5. **Step 5 — Riepilogo**: revisione e salvataggio.

### Versionamento

Le revisioni di uno stesso documento formano una **catena**. Una sola versione per catena è corrente: è quella che i dealer vedono in ricerca. Le versioni superate restano pubblicate e **scaricabili come storico**, ma escono dalla ricerca.

- L'archivio mostra badge di sequenza (`v2`), "versione superata" e numero di versioni, con una riga espandibile per lo storico.
- Il filtro **versioni** è indipendente dal filtro **stato**: obsoleto e superato sono dimensioni diverse.
- Eliminando la versione corrente, la precedente viene **promossa automaticamente**. Marcandola obsoleta accade lo stesso, previa conferma esplicita, e il documento resta nello storico della catena.

### Altre operazioni

- **Archivio Documenti**: filtri, log per documento, azioni singole e di gruppo. L'eliminazione rimuove post e file dal server ed è irreversibile. Per l'area manager, mostra solo i documenti nel suo perimetro; quelli a cavallo con linee altrui restano visibili ma in sola lettura.
- **Log Download**: filtri per periodo/documento/dealer, paginazione, **Esporta CSV** (export completo e ancorato al perimetro di chi lo richiede, non limitato alla vista).
- **Statistiche**: riepiloghi, classifiche, documenti mai scaricati, dealer inattivi da oltre 90 giorni. Filtrate sul perimetro per l'area manager.
- **Notifiche**: attivazione dei tre flussi email, mittente, prossimi invii programmati, email di prova.
- **Richieste Accesso**: coda con badge, approvazione con scelta di ruolo e linee definitive, oppure rifiuto.
- **Organizzazioni**: albero della rete, creazione e modifica, assegnazione utenti, fusione, sospensione, esecuzione della migrazione.

---

## Workflow Dealer

1. Login da `/wp-login.php` → redirect automatico a `/dashboard-dealer/`.
2. La **dashboard** mostra badge ruolo, ultimo accesso, accessi rapidi, ultimi documenti, documenti in scadenza, preferiti, scaricati di recente e referente commerciale.
3. La **ricerca** (`/dealer-search/`) filtra per brand, linea, tipo e anno tramite la sidebar a faccette, con conteggi che si aggiornano a ogni selezione.
4. Ogni card permette di scaricare il documento, aggiungerlo ai preferiti e — se esiste — consultare lo storico delle versioni precedenti.
5. Il pulsante di download aggregato scarica in un unico ZIP i risultati filtrati o i preferiti.
6. La pagina **Preferiti** raccoglie tutto ciò che ha messo da parte e gli permette di organizzarlo a modo suo: etichette che crea lui (fino a un tetto), assegnabili a più documenti, con filtro per etichetta e ordinamento. Da ogni pagina un link riporta alla dashboard.

## Workflow Titolare

Un utente con funzione **titolare** (assegnata dall'amministratore in **Organizzazioni → Utenti**) trova nella dashboard una card **Gestione collaboratori** che porta alla pagina con lo shortcode `[dealer_team]`, dove può:

1. Vedere i membri della propria azienda con funzione, linee effettive e ultimo accesso.
2. **Invitare** un nuovo collaboratore via email (link per impostare la password, mai una password in chiaro).
3. **Limitare** un collaboratore a un sottoinsieme delle linee aziendali.
4. **Disattivare** un collaboratore che ha lasciato l'azienda — l'account WordPress resta, così il suo storico download rimane tracciabile, ma perde ogni ruolo dealer.

Non può in nessun caso creare altri titolari, assegnare ruoli arbitrari, o concedere più linee di quante l'azienda ne possieda.

## Workflow Area Manager

Dopo il login viene portato sulla sua **area di lavoro** (`[dealer_area_manager]`), non in wp-admin — che gli è chiuso come a ogni altro utente del portale. Da lì:

1. **Carica** un documento nuovo sulle proprie linee, con lo stesso wizard dell'amministratore.
2. **Aggiorna** un documento esistente pubblicandone una nuova versione, che entra nella catena e sostituisce la precedente.
3. **Marca obsoleto** un documento che non è più valido. Non lo elimina definitivamente: l'eliminazione dal server resta all'amministratore, perché è irreversibile e cancella il file.
4. Consulta **archivio, log e statistiche** limitati al proprio perimetro.

Ogni scrittura è rivalidata lato server sul doppio perimetro (organizzazioni seguite e linee di pubblicazione): l'interfaccia non mostra ciò che il server rifiuterebbe, ma è il server a decidere.

---

## Struttura file

```
dashboard-dealer/
├── dealer-portal.php               File principale, boot hooks, redirect login
├── uninstall.php                   Pulizia alla disinstallazione
├── includes/
│   ├── class-cpt.php               Custom post type: documento_dealer
│   ├── class-roles.php             Ruoli custom
│   ├── class-db.php                Tabella log, query di lettura e statistiche
│   ├── class-versioning.php        Catena di versioni dei documenti
│   ├── class-organization.php      CPT organizzazione, gerarchia, ereditarietà, migrazione
│   ├── class-identity.php          Risolutore di identità: diritti effettivi, perimetro area manager
│   ├── class-admin.php             Menu admin, wizard upload, archivio, export, statistiche
│   ├── class-search.php            Ricerca a faccette, download, preferiti, ZIP
│   ├── class-dashboard.php         Dashboard dealer
│   ├── class-searchwp.php          Integrazione SearchWP 4.x
│   ├── class-notifications.php     Email, cron, preferenze, impostazioni
│   ├── class-access-request.php    Richieste di accesso self-service
│   ├── class-org-admin.php         Interfaccia amministrativa delle organizzazioni
│   ├── class-team.php              Delega al titolare: gestione dei propri collaboratori
│   ├── class-area-manager.php      Area di lavoro front-end dell'area manager
│   ├── class-favorites.php         Pagina preferiti ed etichette personali del dealer
│   └── class-access-guard.php      wp-admin chiuso agli utenti del portale, redirect e barra admin
├── templates/
│   ├── admin-upload.php            Wizard upload 5 step
│   ├── admin-archive.php           Archivio con filtri e azioni di gruppo
│   ├── admin-logs.php              Log download con filtri ed export
│   ├── admin-stats.php             Pagina statistiche
│   ├── admin-access-requests.php   Coda di approvazione
│   ├── admin-organizations.php     Albero delle organizzazioni
│   ├── admin-org-edit.php          Creazione e modifica organizzazione
│   ├── admin-org-users.php         Assegnazione utenti a un'organizzazione
│   ├── admin-org-merge.php         Fusione di più organizzazioni
│   ├── admin-org-area-managers.php Elenco degli area manager con riepilogo del perimetro
│   ├── admin-org-area-manager-edit.php  Assegnazione del perimetro a un area manager
│   ├── dealer-dashboard.php        Dashboard front-end
│   ├── dealer-search.php           Contenitore della ricerca
│   ├── dealer-search-facets.php    Sidebar dei filtri
│   ├── dealer-search-filters.php   Chip dei filtri attivi
│   ├── dealer-search-results.php   Griglia risultati e barra ZIP
│   ├── dealer-search-card.php      Card documento
│   ├── dealer-search-card-extras.php  Stella preferiti e storico versioni
│   ├── dealer-favorites.php        Pagina preferiti con etichette personali
│   ├── dealer-team.php             Area del titolare per la gestione collaboratori
│   ├── am-workspace.php            Area di lavoro dell'area manager
│   ├── access-request-form.php     Form pubblico
│   └── email-*.php                 Layout e corpi delle email
└── assets/
    ├── css/{admin,dealer}.css
    └── js/{admin-upload,dealer-search,dealer-am,dealer-layout}.js
```

---

## Modello dati

### Custom Post Type: `documento_dealer`

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_doc_brand` | string | Nome brand |
| `_doc_product_line` | string | Nome linea semplice |
| `_doc_type` | string | Chiave tipo documento |
| `_doc_year` | int | Anno di pubblicazione |
| `_doc_version` | string | Etichetta versione libera (`v1`, `rev2`) |
| `_doc_roles` | array | Ruoli autorizzati |
| `_doc_lines` | array | Linee autorizzate `Brand\|Linea`; vuoto = nessuna restrizione per linea |
| `_doc_expiry` | string | Data ISO `YYYY-MM-DD`; vuoto = nessuna scadenza |
| `_doc_keywords` | string | Parole chiave per SearchWP |
| `_doc_internal_notes` | string | Note visibili solo agli admin |
| `_doc_file_id` | int | ID allegato nella Media Library |
| `_doc_filename` | string | Nome file per l'header Content-Disposition |
| `_doc_status` | string | `active` oppure `obsoleto` |
| `_doc_keep_original_name` | int | `1` = mantenere il nome file originale |

**Meta di versionamento** — gestiti esclusivamente da `Dealer_Versioning`, mai a mano:

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_doc_group_id` | int | ID del documento radice della catena |
| `_doc_is_current` | string | `'1'` versione corrente, `'0'` superata |
| `_doc_supersedes` | int | ID del documento sostituito (0 per la radice) |
| `_doc_version_seq` | int | Progressivo della versione nella catena |

I documenti creati prima dell'introduzione del versionamento non hanno questi meta e vengono trattati ovunque come correnti: ogni confronto usa `NOT EXISTS` oltre a `= '1'`.

### CPT privato: `dealer_access_req`

Richieste di accesso self-service. `public`, `show_ui`, `show_in_rest` tutti `false`. Dati in meta `_dar_*`, stato in `_dar_status` (`pending` / `approved` / `rejected`).

### CPT privato e gerarchico: `dealer_org`

L'organizzazione: l'azienda o il gruppo che detiene i diritti di accesso. `public`, `show_in_rest` tutti `false`. Gerarchico (`post_parent`), per la catena gruppo → concessionaria → filiale.

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_org_tier` | string | Livello commerciale (`dealer`, `top_dealer`, `part_center`); se assente, ereditato dalla madre |
| `_org_lines` | array | Linee **proprie** in formato `Brand\|Linea` (non le effettive: vedi sotto) |
| `_org_status` | string | `active` oppure `suspended`; la sospensione si propaga alle discendenti |
| `_org_vat` | string | Partita IVA |
| `_org_referente_nome` / `_org_referente_email` / `_org_referente_telefono` | string | Referente commerciale dell'azienda |

Le **linee effettive** (`Dealer_Organization::get_effective_lines()`) non sono un meta salvato: si calcolano come intersezione fra `_org_lines` e le linee effettive della madre. Un'organizzazione senza madre (radice) ha come effettive esattamente le proprie; una figlia con `_org_lines` vuoto eredita per intero quelle della madre. Questa regola — restringere, mai ampliare — è il motivo per cui una revoca sul gruppo si propaga automaticamente a ogni sede.

### Tabella DB: `{prefisso}_dealer_download_log`

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Chiave primaria |
| `user_id` | BIGINT UNSIGNED | ID utente WordPress |
| `post_id` | BIGINT UNSIGNED | ID documento |
| `download_date` | DATETIME | Timestamp |
| `ip_address` | VARCHAR(45) | IP client, vuoto se non valido |
| `access_context` | VARCHAR(20) | Titolo con cui è avvenuto l'accesso: `admin`, `area_manager`, `dealer` (o vuoto per le righe precedenti alla 1.3.0) |

Indici su `user_id`, `post_id`, `download_date`. `access_context` è stata aggiunta con `dbDelta()` tramite un contatore di revisione dello schema, applicato anche a installazioni già attive: le righe registrate prima non vengono modificate retroattivamente, restano a stringa vuota e sono mostrate come "Dealer".

### User meta

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_dealer_lines` | array | **Modello storico**: linee assegnate `Brand\|Linea`. Fallback usato solo quando l'utente non ha un'organizzazione |
| `_dealer_org` | int | ID dell'organizzazione di appartenenza (modello corrente) |
| `_dealer_function` | string | `titolare` oppure `collaboratore` |
| `_dealer_line_limit` | array | Restrizione individuale, sottoinsieme delle linee effettive dell'organizzazione; vuoto = nessuna restrizione oltre a quella aziendale |
| `_am_orgs` | array | Perimetro dell'area manager: ID delle organizzazioni radice seguite (i sottoalberi sono inclusi automaticamente) |
| `_am_lines` | array | Perimetro dell'area manager: linee su cui può pubblicare |
| `_dealer_last_login` | string | Datetime MySQL dell'ultimo accesso |
| `_dealer_favorites` | array | ID dei documenti preferiti (max 200) |
| `_dealer_fav_tags` | array | Etichette personali del dealer sui preferiti: `[ id => etichetta ]` |
| `_dealer_fav_doc_tags` | array | Assegnazione etichette → documenti preferiti: `[ post_id => [ tag_id ] ]` |
| `_dealer_notify_new` | string | `'1'`/`'0'` — attiva se il meta è assente |
| `_dealer_notify_expiring` | string | `'1'`/`'0'` — attiva se il meta è assente |
| `_referente_nome` / `_referente_email` / `_referente_telefono` | string | Referente commerciale (modello storico; nel modello a organizzazioni il referente sta sull'organizzazione) |
| `_dar_company` / `_dar_vat` | string | Ragione sociale e P. IVA, dalla richiesta di accesso |
| `_dealer_invited_by` / `_dealer_invited_at` | int / string | Chi e quando ha invitato un collaboratore |
| `_dealer_deactivated_by` / `_dealer_deactivated_at` | int / string | Chi e quando ha disattivato un collaboratore |

`Dealer_Identity::get_effective_lines()` è l'unico punto che va interrogato per sapere cosa un utente può vedere: risolve `_dealer_org` quando presente (organizzazione ∩ `_dealer_line_limit`) e ricade su `_dealer_lines` altrimenti. Nessun altro codice dovrebbe leggere questi meta direttamente per decidere un accesso.

### Opzioni WordPress

| Opzione | Descrizione |
|---|---|
| `dealer_portal_version` | Versione installata |
| `dealer_portal_dashboard_page_id` | ID pagina dashboard |
| `dealer_portal_search_page_id` | ID pagina ricerca |
| `dealer_portal_fav_page_id` | ID pagina preferiti |
| `dealer_portal_team_page_id` | ID pagina gestione collaboratori |
| `dealer_portal_am_page_id` | ID pagina area manager |
| `dealer_portal_notifications` | Impostazioni del modulo notifiche |
| `dealer_portal_notification_queue` | Coda di invio email |
| `dealer_portal_caps_revision` | Contatore di revisione della mappa capability → ruoli, per riparare le assegnazioni senza richiedere la riattivazione |
| `dealer_portal_schema_revision` | Contatore di revisione dello schema della tabella log, stesso meccanismo delle capability |
| `dealer_portal_pages_revision` | Contatore di revisione dell'elenco delle pagine del portale: crea quelle mancanti su un'installazione già attiva, senza richiedere la riattivazione |

### Capability

| Capability | Amministratore | Area manager | A cosa serve |
|---|---|---|---|
| `manage_dealer_portal` | ✓ | — | Controllo completo: eliminazione documenti, richieste di accesso, notifiche |
| `upload_dealer_docs` | ✓ | ✓ | Wizard di caricamento, salvataggio, versionamento, archivio |
| `view_dealer_logs` | ✓ | ✓ | Log download, export CSV, statistiche |
| `manage_dealer_orgs` | ✓ | — | Organizzazioni e assegnazione utenti |

Nessuna capability ne implica un'altra: la mappa in `Dealer_DB::capability_map()` è l'unico punto in cui guardare per sapere chi ha cosa.

### Eventi cron

| Hook | Frequenza | Scopo |
|---|---|---|
| `dealer_portal_process_mail_queue` | evento singolo, si riprogramma | Worker della coda email |
| `dealer_portal_dealer_expiry_digest` | settimanale | Digest scadenze ai dealer |
| `dealer_portal_admin_expiry_report` | giornaliero | Report scadenze agli admin |

Gli eventi sono auto-riparanti (ripianificati su `init` se mancanti) e vengono rimossi alla disattivazione e alla disinstallazione.

---

## Note di sicurezza

- **Nonce sui download**: ogni URL di download è limitato nel tempo da un nonce (`dealer_download_{post_id}`). URL diretti o indovinati vengono rifiutati con HTTP 403.
- **Path traversal prevention**: sia il download singolo sia lo ZIP verificano con `realpath()` che il file si trovi nella cartella uploads prima di servirlo.
- **Nomi file non indovinabili**: sul disco ogni allegato porta un token casuale nel nome (il dealer scarica comunque il nome pulito, grazie all'header `Content-Disposition`). È la protezione che regge **su qualunque server**: i nomi derivano da brand, linea e tipo documento, quindi senza token sarebbero prevedibili e tentabili via URL diretto.
- **Directory protetta, per quanto il server lo consenta**: `uploads/dealer-docs/` riceve un `.htaccess` (Apache), un `web.config` (IIS) e un `index.php` che impedisce il listing. Sono difese in profondità: nginx ignora `.htaccess`, Apache ignora `web.config`, e il plugin viene consegnato senza sapere su cosa girerà — per questo la protezione portante è il token nei nomi, non queste regole. Quando il server non onora `.htaccess`, il plugin lo rileva e mostra in admin la regola da aggiungere.
- **CPT con capability proprie**: `documento_dealer` non usa `capability_type => 'post'`. Con quelle generiche, qualunque Editor — un ruolo che sui siti aziendali esiste quasi sempre per il sito pubblico — avrebbe potuto elencare, modificare ed eliminare i documenti da `edit.php?post_type=documento_dealer`, note interne comprese, scavalcando l'intero impianto di permessi. Le capability del CPT sono assegnate al solo amministratore: l'area manager opera dalle schermate del plugin, che applicano il controllo di perimetro assente nell'editor nativo. Stesso principio per `dealer_org` e `dealer_access_req`, mappati sulle capability del plugin.
- **MIME whitelist**: solo `application/pdf`, `.xlsx` e `.docx`, validati con `wp_check_filetype_and_ext()` sul contenuto reale, non sull'estensione.
- **Isolamento CPT**: `documento_dealer` e `dealer_access_req` hanno `public => false` e `show_in_rest => false`. Nessun URL pubblico o endpoint REST li espone.
- **Doppio controllo accesso**: `user_is_dealer()` e `user_can_access_post()` vengono richiamati prima di servire qualsiasi file. `user_can_download_post()` raccoglie l'intera catena di controlli (tipo, stato, obsolescenza, livello, linea, scadenza) ed è usata da download singolo, ZIP, preferiti, cronologia e storico versioni.
- **Le linee sono sempre risolte internamente**: `user_can_access_post()` ignora deliberatamente il parametro `$user_lines` che riceve e richiama `Dealer_Identity::get_effective_lines()`. È una scelta esplicita: un chiamante che passasse un elenco non aggiornato non può allargare l'accesso, perché quell'elenco non viene mai usato per decidere.
- **L'ereditarietà delle organizzazioni restringe, mai amplia**: le linee effettive sono sempre un'intersezione con quelle della madre, mai un'unione. Nessuna sede può ottenere una linea che il gruppo non ha, indipendentemente da cosa venga configurato sulla sede stessa.
- **Perimetro dell'area manager, non ruolo**: legge in sovrapposizione (basta una linea del documento nel suo perimetro) ma scrive in contenimento (`can_edit_document()`: tutte le linee del documento devono starci). Un documento senza restrizione di linea non è mai pubblicabile né visibile a un area manager: è visibile a tutta la rete, e sarebbe la scorciatoia per uscire dal proprio perimetro.
- **Perimetro vuoto = accesso nullo, non accesso pieno**: sia nel controllo dei singoli documenti sia nelle query di log e statistiche, un insieme di linee o di documenti vuoto produce una condizione sempre falsa (`AND 1=0`), non l'assenza di filtro. È il punto in cui un filtro mal scritto diventerebbe silenziosamente un buco.
- **La delega non si propaga**: un titolare può invitare solo collaboratori (mai altri titolari), non può assegnare un ruolo diverso da quello coerente con la sua organizzazione, e le linee che concede sono sempre filtrate contro quelle che l'azienda possiede — anche se il POST ne chiedesse di più.
- **Organizzazione sospesa = nessun accesso**, nemmeno ai documenti senza restrizione di linea; la sospensione si propaga automaticamente a tutte le organizzazioni discendenti.
- **Conteggi dei facet calcolati dopo il filtro di accesso**: calcolarli prima avrebbe rivelato quanti documenti esistono per brand e linee non assegnate al dealer.
- **ZIP senza ID nell'URL**: il download aggregato riceve solo l'insieme richiesto (risultati o preferiti) e i filtri; la lista dei documenti è ricostruita lato server. Un client non può imporre cosa finisce nell'archivio nemmeno con un nonce valido.
- **Permessi rivalutati sempre al momento dell'uso**: preferiti, cronologia e destinatari delle email non sono mai serviti da liste precalcolate. Un documento revocato dopo il download sparisce dalla cronologia senza rivelarne il titolo.
- **CSV injection**: nell'export, i campi che iniziano con `=`, `+`, `-` o `@` sono prefissati con un apostrofo, così non vengono eseguiti come formule da Excel o LibreOffice.
- **Form pubblico protetto**: la richiesta di accesso usa nonce, honeypot, timestamp firmato con `wp_hash`, rate limiting per IP validato, e non consente user enumeration (email esistente e richiesta nuova producono lo stesso messaggio).
- **Nessuna creazione utente senza capability**: `wp_insert_user()` compare in un solo punto, dopo nonce, `manage_dealer_portal` e validazione del ruolo contro una whitelist. Nessuna password in chiaro viene generata o inviata.
- **Nonce e capability su tutti gli endpoint**: ogni handler AJAX e `admin_post` verifica nonce e capability prima di elaborare.
- **Validazione IP**: gli IP vengono validati con `FILTER_VALIDATE_IP`; i valori non validi non finiscono nel database.

---

## Changelog

### 1.4.1

- Fix: sul profilo di un area manager la sezione del plugin cambiava anche titolo (`Dealer Portal — Area Manager`), e il risultato era che sembrava **sparita** invece che sostituita — un effetto collaterale della 1.4.0 segnalato subito al primo collaudo. L'intestazione torna a essere `Impostazioni Dealer Portal`, la stessa dei dealer, con sotto la spiegazione di perché per questo ruolo il campo *Linee Prodotto Assegnate* non compare e dove si imposta invece il perimetro.

### 1.4.0

Il flusso per mettere in piedi un area manager era un vicolo cieco: si creava l'utente e da lì non si arrivava da nessuna parte. Non mancava una funzione — mancava che il percorso fosse percorribile da chi non ha scritto il codice.

**Il difetto peggiore era un campo che dava ragione e non faceva niente.** Aprendo un area manager in **Utenti**, WordPress mostrava la sezione *Impostazioni Dealer Portal → Linee Prodotto Assegnate*: il posto più ovvio dove cercare, che accettava la selezione, la salvava senza errori e non produceva alcun effetto — perché scrive `_dealer_lines`, il meta del modello dealer, che per un area manager non viene **mai** letto (il suo perimetro vive in `_am_lines`/`_am_orgs`). Ora, sul profilo di un area manager, quella sezione non compare: al suo posto c'è il riepilogo del perimetro reale e il pulsante che porta dove si assegna.

- **Voce di menu propria**: `Dealer Portal → Area Manager`, non più una vista nascosta dietro un pulsante nella schermata Organizzazioni. Assegnare il perimetro è il primo compito dopo aver creato l'utente, e chi lo cerca non ha motivo di cercarlo sotto "Organizzazioni".
- **Il percorso è scritto nella pagina**: l'elenco spiega i due passaggi (crea l'utente → assegna il perimetro), ha il pulsante per creare l'utente, e quando non c'è ancora nessun area manager lo dice invece di mostrare una tabella vuota.
- **Linee e organizzazioni non pesano uguale, e ora si vede.** Le **linee** rendono operativo l'area manager: senza almeno una non pubblica niente. Le **organizzazioni** sono la supervisione dei dealer e sono **facoltative**. Prima la schermata le trattava alla pari e, senza organizzazioni create, diceva «creane una prima di assegnare un perimetro» — falso, e mandava a costruire l'intero modello organizzativo per niente.
- **Lo stato è visibile**: ogni area manager è marcato *Operativo* o *Non operativo*, con l'avviso in cima che conta quanti non lo sono; salvare un perimetro senza linee avvisa subito invece di lasciarlo scoprire all'interessato.
- Corretto anche il controllo lato area manager: era `nessuna linea E nessuna organizzazione`, quindi chi aveva solo organizzazioni entrava in un'area dove non poteva fare nulla. Ora la condizione guarda le linee, che sono ciò che serve davvero.

### 1.3.7

Revisione del lavoro dalla 1.3.2 alla 1.3.6: sei difetti, tre dei quali introdotti proprio dalle correzioni precedenti.

- Fix: **il redirect dell'area manager dalla dashboard troncava la pagina** invece di reindirizzare. Uno shortcode gira dentro `the_content`, quando gli header sono già partiti e mezza pagina è già stata stampata: lì `wp_safe_redirect()` non reindirizza nulla e l'`exit` che lo accompagna interrompe la pagina a metà — senza footer e senza il link di logout aggiunto in 1.3.3 proprio per non lasciare nessuno bloccato. Cioè esattamente il vicolo cieco che quella versione doveva chiudere. Il redirect vive ora su `template_redirect` (`Dealer_Access_Guard::route_dashboard()`), prima di qualunque output; lo shortcode restituisce solo avvisi, mai un `exit`. Stesso difetto, preesistente, sul redirect al login della dashboard: corretto insieme. Tutti gli altri moduli del plugin già facevano i propri redirect da `template_redirect`, la dashboard era l'unica fuori schema.
- Fix: **la schermata del perimetro cancellava in silenzio le organizzazioni non-radice.** Elencava come caselle solo le radici, ma il form riscrive `_am_orgs` per intero: un'organizzazione assegnata che nel frattempo era diventata figlia di un'altra (operazione normale mentre si costruisce la gerarchia) spariva dal perimetro al primo salvataggio, con tanto di messaggio "Perimetro salvato". Ora resta in elenco, segnalata come sotto-organizzazione, e si toglie solo deselezionandola.
- Fix: il pulsante "Vedi" nell'area di lavoro dell'area manager usava una dashicon, ma quel modulo non carica dashicons (per scelta: il suo CSS è autonomo) e agli utenti del portale la barra di amministrazione — che sul front-end le porterebbe con sé — è nascosta. Era un quadratino vuoto: ora è un link di testo.
- Fix: dopo aver reso più severa la scelta del successore (1.3.2), eliminare un documento poteva annunciare "la versione precedente è tornata corrente" quando la catena era rimasta senza nessuna versione corrente — le altre erano tutte in bozza o obsolete. `Dealer_Versioning::detach()` restituisce ora la versione promossa e il messaggio segue quello che è successo davvero, invece di prevederlo. Corretto sia nell'eliminazione singola sia in quella di gruppo.
- Fix: l'`!important` introdotto in 1.3.4 sulla larghezza rendeva inerti le regole di spaziatura sotto i 480px — una media query non aggiunge né specificità né importanza — e sul telefono restava la spaziatura da desktop.
- Fix: un errore nelle schermate di amministrazione (organizzazione o area manager inesistente, da un link vecchio) veniva gestito con un redirect che non può funzionare, per lo stesso motivo del primo punto: il callback di una pagina admin gira dopo `admin-header.php`. Al posto della mezza schermata troncata c'è ora un avviso con il link per tornare indietro.

### 1.3.6

Due richieste dal collaudo, entrambe sulla coerenza visiva dell'area riservata: la dashboard è l'unica pagina con un link visibile sul sito (chi lo apre fa login e finisce sulla propria area, in base al ruolo), quindi le cinque pagine del portale devono leggersi come un'unica vista, non come pagine scollegate.

- **Titolo di pagina centrato.** Con il layout a tutta larghezza (1.3.4) il titolo reso dal tema sopra il nostro box — "Cerca Documenti", "Preferiti", … — restava allineato a sinistra come in un articolo, mentre il contenuto sotto è ora largo quanto lo schermo: il risultato sembrava due viste scollegate. Le cinque pagine ricevono ora una classe `dealer-portal-page` sul `<body>` (riconosciute dall'ID pagina salvato in opzione, mai da slug o titolo) e il titolo viene centrato lì — copre sia i temi a blocchi (`.wp-block-post-title`, il blocco "Titolo Articolo" nativo di WordPress) sia i temi classici (`.entry-title`, `.page-title`). Il tema non viene toccato.
- **Pulsante "Vedi" accanto a "Scarica".** Apre il documento nel browser (nuova scheda) invece di forzarne il download. Compare solo per i PDF — l'unico formato, fra quelli caricabili (whitelist PDF/XLSX/DOCX), che ogni browser sa aprire da solo; per gli altri l'anteprima non avrebbe funzionato comunque. La decisione resta sempre server-side sul mime type reale del file, mai sul solo parametro in URL. Presente ovunque compaia "Scarica": griglia di ricerca, dashboard, preferiti, storico versioni, area di lavoro dell'area manager. `Dealer_Search::render_document_actions()` è l'unico punto che genera la coppia di pulsanti, per non dover aggiornare il markup in sei posti diversi a ogni ritocco. L'apertura resta comunque un accesso al documento: finisce nel log dei download come un download vero e proprio.

### 1.3.5

Fix di una falla progettuale, non di un bug isolato: **non esisteva alcuna interfaccia per assegnare il perimetro di un area manager.**

Il README parlava di "Dealer Portal → Organizzazioni → Utenti, o direttamente sui meta utente" per impostare `_am_orgs`/`_am_lines`, ma quella schermata non li ha mai gestiti — è costruita per l'assegnazione dei dealer a un'organizzazione (`_dealer_org`, `_dealer_function`), un modello diverso: l'area manager non appartiene a un'organizzazione, *segue* delle organizzazioni. Il risultato: un area manager restava per sempre bloccato su "il tuo perimetro non è ancora stato configurato", perché letteralmente nessun punto del plugin scriveva mai quei due meta — solo `Dealer_Identity` (lettura) e `Dealer_Area_Manager` (applicazione) ne sapevano qualcosa.

- Nuova schermata **Dealer Portal → Organizzazioni → Area Manager**: elenca gli area manager con un riepilogo del perimetro (organizzazioni seguite, numero di linee, configurato o no) e un link "Assegna perimetro" per ciascuno.
- Nuova schermata di assegnazione: organizzazioni radice selezionabili (i sottoalberi sono inclusi da soli, come già fa `get_scope_orgs()`) e linee prodotto raggruppate per brand, con filtro e "deseleziona tutto" — stesso linguaggio visivo delle altre schermate organizzazioni.
- `Dealer_Identity::set_am_scope()`: unico punto di scrittura per `_am_orgs`/`_am_lines`, sullo stesso principio di `set_line_limit()` — valida organizzazioni esistenti e linee dalla whitelist, non fida mai del POST.
- Il messaggio che un amministratore vede collaudando l'area di lavoro (introdotto in 1.3.3) ora punta direttamente a questa schermata invece di suggerire una modifica manuale ai meta utente che nessuna interfaccia rendeva possibile.
- README corretto: la sezione "L'area manager" indicava un percorso che non esisteva.

### 1.3.4

Fix dallo screenshot del collaudo: sul tema in prova la larghezza a tutta pagina introdotta in 1.3.2 non aveva alcun effetto visibile — la pagina di ricerca restava esattamente larga quanto il titolo della pagina del tema.

Causa: i temi a blocchi (Twenty Twenty-Tre/Quattro e qualunque tema che usi il layout "constrained" di theme.json) applicano ai figli diretti dell'area di contenuto una regola di WordPress stesso (non del tema):
```
.is-layout-constrained > * {
    max-width: var(--wp--style--global--content-size);
    margin-left: auto !important;
    margin-right: auto !important;
}
```
Il nostro shortcode finisce spesso proprio lì (un blocco Paragrafo o HTML personalizzato è un figlio diretto di quell'area). Il nostro margine calcolato, senza `!important`, perdeva sempre contro quella regola — e i fogli di stile dei temi a blocchi vengono in genere iniettati più tardi nell'head, quindi a parità di importanza avrebbero comunque vinto anche sulle proprietà non marcate `!important`.

Corretto in tutte e cinque le pagine del portale: le regole di larghezza ora usano `!important` e un selettore con specificità più alta (`body .dealer-search-wrap` invece di `.dealer-search-wrap`), così battiamo quella regola sia per importanza sia per specificità, indipendentemente dall'ordine di caricamento dei fogli di stile. Lo stesso trattamento è stato applicato al ripiego "dp-no-bleed" (temi che ritagliano ciò che deborda), che deve poter annullare una regola ora essa stessa `!important`.

### 1.3.3

Correzioni dal primo collaudo reale con cambi di ruolo frequenti (dealer, area manager, titolare, admin).

- Fix: **nessun modo di disconnettersi** su qualunque pagina del portale diversa dalla dashboard, incluse tutte le schermate di cortesia ("quest'area è riservata a...", perimetro non configurato, organizzazione sospesa). wp-admin è bloccato e la barra di amministrazione nascosta a ogni utente del portale: l'unico logout esistente era il link nell'header della dashboard. Cambiare ruolo durante un collaudo e finire su una qualunque altra pagina significava restare bloccati, senza alternativa al tasto "indietro" del browser o alla cancellazione dei cookie. Un link di logout è ora sempre visibile ovunque manchi la barra di amministrazione — un solo punto (`Dealer_Access_Guard`), non uno per pagina.
- Fix: un **area manager** che finiva sulla dashboard dealer (link salvato, vecchia scheda) vedeva un vicolo cieco ("Accesso non autorizzato"). Ora viene rimandato alla propria area di lavoro, lo stesso posto dove lo manda già il login.
- Fix: un **amministratore** che visitava le pagine di Gestione Collaboratori o Area Manager per collaudarle (come già poteva fare con la dashboard) trovava lo stesso muro riservato ai dealer, senza indicazioni. Ora gli viene spiegato perché non c'è nulla da mostrargli in anteprima (non appartiene a un'organizzazione né ha un perimetro) e come assegnarsi temporaneamente il ruolo giusto per collaudare davvero la pagina, restando amministratore.
- Descrizione del plugin resa più generica (adatta anche a una futura pubblicazione pubblica) e campo Autore valorizzato.

### 1.3.2

Chiusura del backend agli utenti del portale, pagina dei preferiti, e una revisione dell'intero lavoro riga per riga.

**Novità**

- L'**area manager lavora sul front-end**: caricamento, versioni, archivio e statistiche del perimetro nella sua area di lavoro (`[dealer_area_manager]`). Il presupposto era sbagliato: gli era stata data la capability di caricare ma l'unica interfaccia per farlo stava in wp-admin, dove non deve entrare.
- **wp-admin chiuso** a dealer, titolari e area manager: redirect all'area di competenza, barra di amministrazione nascosta, `admin-ajax.php` e `admin-post.php` liberi perché sono endpoint e non schermate, `profile.php` consentita perché la password è dell'utente.
- **Pagina dei preferiti** dedicata (`[dealer_favorites]`) con **etichette personali**: il dealer crea le proprie etichette, le assegna, filtra e ordina. Nessun JavaScript: form POST con nonce e pattern PRG.
- **Ritorno alla dashboard** da ogni pagina del portale.
- **Layout a tutta larghezza**: le pagine escono dal contenitore del tema — tarato sulla lunghezza di riga di un articolo, non su un'applicazione — e rimettono il contenuto entro 1440px. Nessuna modifica al tema. `assets/js/dealer-layout.js` misura la finestra senza la barra di scorrimento (in CSS `100vw` la include, e su Windows produrrebbe uno scorrimento orizzontale) e, se un contenitore del tema ritaglia ciò che deborda, riporta le pagine dentro invece di lasciarle tagliate.
- Corretta l'etichetta del ruolo **"Parts Center"**, anche sulle installazioni dove il ruolo era già stato creato con il nome sbagliato.

**Correzioni**

- Le pagine del portale ora vengono create anche su un'installazione già attiva, non solo alla prima attivazione: chi aggiornava sostituendo i file trovava 404 sulle pagine nuove.
- La dashboard non verificava la sospensione dell'organizzazione. Costruiva comunque le sue liste e, non trovando più accessibile nessun preferito, li cancellava tutti: una sospensione temporanea distruggeva in modo irreversibile la libreria personale del dealer.
- Il menu "dealer" dei log download elencava nome ed email di **tutta la rete** anche a un area manager, mentre le righe di log erano correttamente filtrate. Ora segue lo stesso perimetro delle statistiche.
- Marcare obsoleto un documento poteva promuovere a "versione corrente" un documento a sua volta obsoleto o in bozza, lasciando la catena senza nulla di visibile in ricerca. Il criterio di scelta del successore ora vive in un solo punto e vale per ogni percorso.
- Il pulsante "Scarica i preferiti in ZIP" della dashboard confrontava il limite con i preferiti *mostrati* (al massimo 8) invece che con il totale: oltre il tetto compariva un pulsante che il download poi rifiutava.
- Correggere l'etichetta di un ruolo azzerava il **ruolo predefinito del sito** (WordPress lo riporta a `subscriber` quando si rimuove il ruolo predefinito).
- La risalita della gerarchia delle organizzazioni non aveva il tetto di profondità dichiarato: un ciclo in `post_parent` mandava la ricorsione in stack overflow, cioè schermata bianca su ogni pagina del portale.

### 1.3.1
Correzioni a difetti strutturali emersi al primo collaudo su un'installazione reale. Tutte progettate per reggere senza richiedere configurazioni al server o al tema: il plugin viene consegnato a chi amministra il sito, non installato da chi lo ha scritto.

- Fix: i link a dashboard e ricerca erano percorsi fissi (`/dashboard-dealer/`, `/dealer-search/`) invece che permalink risolti dall'ID pagina. Davano 404 con permalink diversi da "nome articolo", con il sito in sottocartella o con la pagina rinominata — cioè al primo dealer che faceva login.
- Fix: una pagina in bozza o nel cestino veniva adottata come pagina ufficiale del portale, riaprendo lo stesso 404 da un'altra strada.
- Fix: `documento_dealer` usava le capability generiche dei post, quindi qualunque Editor poteva gestire i documenti dal backend WordPress aggirando tutti i permessi del plugin. Ora il CPT ha capability proprie, assegnate al solo amministratore. Stesso trattamento per organizzazioni e richieste di accesso.
- Fix: la protezione dei file era il solo `.htaccess`, ignorato da nginx e da altri server, con nomi file prevedibili. Ora ogni file porta un token casuale nel nome — l'URL diretto non è indovinabile su nessun server — affiancato da `web.config` per IIS e da un avviso in admin quando il server non onora `.htaccess`.
- Fix: CSS e JavaScript non venivano caricati con Elementor, Divi, WPBakery e simili, che salvano il contenuto nei postmeta e non in `post_content`: la pagina si apriva senza stile e la ricerca a faccette degradava in silenzio. Gli asset ora vengono richiesti anche dallo shortcode durante il rendering, quindi partono qualunque sia il sistema che costruisce la pagina.

### 1.3.0
- Modello a organizzazioni: i diritti di accesso (livello commerciale, linee prodotto) appartengono all'azienda dealer, non più al singolo utente. CPT gerarchico `dealer_org` con ereditarietà che restringe e mai amplia (intersezione con la madre, mai unione).
- Nuovo ruolo `area_manager`: carica, versiona e marca obsoleti i documenti sulle proprie linee, entro un perimetro su due assi (organizzazioni seguite e linee di pubblicazione) validato lato server a ogni scrittura. Non elimina definitivamente, non tocca organizzazioni o utenti.
- Archivio, log, statistiche ed export CSV filtrati sul perimetro dell'area manager, con lo stesso criterio ovunque (`user_can_view_document()` in lettura, `can_edit_document()` in scrittura) e un perimetro vuoto che nega l'accesso invece di ricadere su "nessun filtro".
- Nuova colonna `access_context` nel registro download: distingue un accesso da amministratore, area manager o dealer, così la delega non annacqua il valore di audit del registro.
- Capability separate (`upload_dealer_docs`, `view_dealer_logs`, `manage_dealer_orgs`) al posto dell'unica `manage_dealer_portal`, assegnate esplicitamente e senza che una implichi l'altra.
- Delega al titolare: shortcode `[dealer_team]` per invitare, limitare alle linee e disattivare i propri collaboratori senza passare dall'amministratore. La disattivazione revoca il ruolo ma non elimina l'account, per non perdere lo storico dei download.
- Interfaccia amministrativa delle organizzazioni: albero della rete con linee proprie ed ereditate distinte, creazione, modifica, assegnazione utenti, fusione, sospensione (che si propaga alle discendenti) ed esecuzione della migrazione.
- Migrazione idempotente degli utenti dal modello storico: ogni utente non ancora migrato diventa titolare di un'organizzazione che eredita esattamente le sue linee e il suo livello, senza cambiare cosa vede il giorno dell'aggiornamento. Il modello storico resta un percorso valido per chi non migra.
- Corretta di conseguenza la paginazione dell'archivio documenti, già rotta in precedenza quando si filtrava per ruolo o linea dopo la query principale.
- La disinstallazione rimuove anche le organizzazioni e i meta utente introdotti da questa versione.

### 1.2.0
- Ricerca a faccette con conteggi live, aggiornamento AJAX e griglia a card; fallback senza JavaScript mantenuto.
- Versionamento reale dei documenti: catena di revisioni, storico scaricabile, promozione automatica della versione precedente.
- Libreria personale del dealer: preferiti, cronologia dei download, storico versioni nella card.
- Download aggregato in ZIP di risultati filtrati o preferiti.
- Notifiche email: nuovo documento, digest scadenze ai dealer, report scadenze agli admin; preferenze per utente e pagina impostazioni. Invio accodato e processato via cron, non dentro la richiesta di salvataggio.
- Export CSV dei log con filtri e paginazione; protezione contro CSV injection ed export ancorato a uno snapshot stabile.
- Azioni di gruppo sull'archivio documenti, coerenti con la catena di versioni.
- Pagina statistiche: classifiche, documenti mai scaricati, dealer inattivi.
- Richieste di accesso self-service con coda di approvazione.
- Fix: gli handler AJAX non venivano registrati, perché `Dealer_Search` era istanziata solo con `! is_admin()` mentre `admin-ajax.php` gira in contesto admin.
- Fix: un documento senza restrizione di linea non soddisfa più qualsiasi filtro di linea.
- La disinstallazione rimuove anche cron, opzioni, richieste di accesso e meta utente. I documenti e i loro file restano.

### 1.1.0
- Hardening: directory upload protetta da `.htaccess`, capability custom `manage_dealer_portal` al posto di `manage_options`, validazioni di input irrigidite.
- Upgrade idempotente applicato su `plugins_loaded` senza richiedere la riattivazione.

### 1.0.1
- Fix: rimosso il doppio inserimento nel log download.
- Fix: i documenti scaduti sono esclusi dalla ricerca e bloccati al download con HTTP 403.
- Fix: `Dealer_DB::log_download()` segnala i fallimenti di insert via `error_log()`.

### 1.0.0
- Release iniziale: CPT `documento_dealer`, ruoli custom, wizard upload, controllo accessi per ruolo e linea, ricerca SearchWP con fallback, log download, archivio admin, dashboard dealer, creazione automatica pagine, redirect login.
