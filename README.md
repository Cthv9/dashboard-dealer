# Dealer Portal — WordPress Plugin

## Descrizione

Dealer Portal è un plugin WordPress per la gestione documentale in una rete di distribuzione (settore nautica/marino). Gli amministratori caricano documenti tecnici (PDF, XLSX, DOCX); i dealer li trovano e li scaricano da un'area riservata che mostra loro **solo** ciò a cui il loro ruolo e le loro linee prodotto danno diritto.

Lo scopo primario non è la ricerca né la dashboard: è il **controllo d'accesso granulare con audit trail**. Ogni documento è filtrato per ruolo e per linea prodotto, ogni download è tracciato, e i documenti scaduti vengono bloccati automaticamente. Ricerca, dashboard e notifiche sono gli strati che rendono usabile quel nucleo.

---

## Funzionalità

### Accesso e sicurezza
- Tre ruoli dealer custom: `dealer`, `top_dealer`, `part_center`
- Doppio controllo di accesso su ogni documento: ruolo **e** linea prodotto assegnata
- Log di ogni download (utente, documento, timestamp, IP)
- Blocco automatico dei documenti scaduti: esclusi dalla ricerca, HTTP 403 al download
- Richieste di accesso self-service con coda di approvazione admin

### Ricerca (dealer)
- Ricerca a faccette: sidebar con filtri a selezione multipla e conteggi live, aggiornamento AJAX senza reload
- Griglia di card con icona per tipo file, badge **NUOVO** e **IN SCADENZA**
- Ordinamento per pertinenza, data, titolo o numero di download
- Chip dei filtri attivi rimovibili singolarmente, URL condivisibile e bookmarkabile
- Funziona **anche senza JavaScript** (fallback a form GET)
- Ricerca full-text via SearchWP 4.x, con fallback alla ricerca nativa WP

### Libreria personale (dealer)
- Preferiti: stella su ogni card, sezione dedicata in dashboard
- Cronologia dei propri download
- Storico versioni consultabile e scaricabile dalla card
- Download aggregato in ZIP dei risultati filtrati o dei preferiti
- Dashboard con documenti recenti, documenti in scadenza e referente commerciale

### Gestione documenti (admin)
- Wizard di caricamento in 5 step con drag-and-drop, rinomina file e validazione MIME
- Versionamento reale: le revisioni formano una catena, con storico e promozione automatica
- Archivio con filtri, azioni singole e **azioni di gruppo** (obsoleto / eliminazione)
- Export CSV dei log, con filtri per periodo, documento e dealer
- Pagina statistiche: top documenti, top dealer, documenti mai scaricati, dealer inattivi

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
4. Due pagine vengono create automaticamente:
   - **Dashboard Dealer** (slug: `dashboard-dealer`, shortcode: `[dealer_dashboard]`)
   - **Cerca Documenti** (slug: `dealer-search`, shortcode: `[dealer_search]`)
5. La tabella custom `{prefisso}_dealer_download_log` viene creata automaticamente.
6. I tre ruoli custom vengono registrati e la capability `manage_dealer_portal` viene assegnata agli amministratori.
7. La cartella protetta `uploads/dealer-docs/` viene creata con `.htaccess` che nega l'accesso HTTP diretto.

Per il modulo richieste di accesso, creare manualmente una pagina pubblica contenente lo shortcode `[dealer_access_request]`. È l'unica pagina non creata automaticamente: la sua collocazione dipende dal sito.

---

## Configurazione

### Assegnare linee prodotto a un dealer

1. **Utenti → Tutti gli utenti**, modificare il profilo del dealer.
2. Sezione **Impostazioni Dealer Portal** → campo **Linee Prodotto Assegnate** (selezione multipla con Ctrl/Cmd).
3. Compilare opzionalmente i campi del referente commerciale.
4. Nella sezione **Notifiche Dealer Portal** si attivano o disattivano le email per quell'utente.

Le linee prodotto usano il formato `Brand|Linea` (es. `Mercury|FourStroke`), confrontato fra il meta utente `_dealer_lines` e il meta documento `_doc_lines`.

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

- **Archivio Documenti**: filtri, log per documento, azioni singole e di gruppo. L'eliminazione rimuove post e file dal server ed è irreversibile.
- **Log Download**: filtri per periodo/documento/dealer, paginazione, **Esporta CSV** (export completo, non limitato alla vista).
- **Statistiche**: riepiloghi, classifiche, documenti mai scaricati, dealer inattivi da oltre 90 giorni.
- **Notifiche**: attivazione dei tre flussi email, mittente, prossimi invii programmati, email di prova.
- **Richieste Accesso**: coda con badge, approvazione con scelta di ruolo e linee definitive, oppure rifiuto.

---

## Workflow Dealer

1. Login da `/wp-login.php` → redirect automatico a `/dashboard-dealer/`.
2. La **dashboard** mostra badge ruolo, ultimo accesso, accessi rapidi, ultimi documenti, documenti in scadenza, preferiti, scaricati di recente e referente commerciale.
3. La **ricerca** (`/dealer-search/`) filtra per brand, linea, tipo e anno tramite la sidebar a faccette, con conteggi che si aggiornano a ogni selezione.
4. Ogni card permette di scaricare il documento, aggiungerlo ai preferiti e — se esiste — consultare lo storico delle versioni precedenti.
5. Il pulsante di download aggregato scarica in un unico ZIP i risultati filtrati o i preferiti.

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
│   ├── class-admin.php             Menu admin, wizard upload, archivio, export, statistiche
│   ├── class-search.php            Ricerca a faccette, download, preferiti, ZIP
│   ├── class-dashboard.php         Dashboard dealer
│   ├── class-searchwp.php          Integrazione SearchWP 4.x
│   ├── class-notifications.php     Email, cron, preferenze, impostazioni
│   └── class-access-request.php    Richieste di accesso self-service
├── templates/
│   ├── admin-upload.php            Wizard upload 5 step
│   ├── admin-archive.php           Archivio con filtri e azioni di gruppo
│   ├── admin-logs.php              Log download con filtri ed export
│   ├── admin-stats.php             Pagina statistiche
│   ├── admin-access-requests.php   Coda di approvazione
│   ├── dealer-dashboard.php        Dashboard front-end
│   ├── dealer-search.php           Contenitore della ricerca
│   ├── dealer-search-facets.php    Sidebar dei filtri
│   ├── dealer-search-filters.php   Chip dei filtri attivi
│   ├── dealer-search-results.php   Griglia risultati e barra ZIP
│   ├── dealer-search-card.php      Card documento
│   ├── dealer-search-card-extras.php  Stella preferiti e storico versioni
│   ├── access-request-form.php     Form pubblico
│   └── email-*.php                 Layout e corpi delle email
└── assets/
    ├── css/{admin,dealer}.css
    └── js/{admin-upload,dealer-search}.js
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

### Tabella DB: `{prefisso}_dealer_download_log`

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Chiave primaria |
| `user_id` | BIGINT UNSIGNED | ID utente WordPress |
| `post_id` | BIGINT UNSIGNED | ID documento |
| `download_date` | DATETIME | Timestamp |
| `ip_address` | VARCHAR(45) | IP client, vuoto se non valido |

Indici su `user_id`, `post_id`, `download_date`.

### User meta

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_dealer_lines` | array | Linee assegnate `Brand\|Linea` |
| `_dealer_last_login` | string | Datetime MySQL dell'ultimo accesso |
| `_dealer_favorites` | array | ID dei documenti preferiti (max 200) |
| `_dealer_notify_new` | string | `'1'`/`'0'` — attiva se il meta è assente |
| `_dealer_notify_expiring` | string | `'1'`/`'0'` — attiva se il meta è assente |
| `_referente_nome` / `_referente_email` / `_referente_telefono` | string | Referente commerciale |
| `_dar_company` / `_dar_vat` | string | Ragione sociale e P. IVA, dalla richiesta di accesso |

### Opzioni WordPress

| Opzione | Descrizione |
|---|---|
| `dealer_portal_version` | Versione installata |
| `dealer_portal_dashboard_page_id` | ID pagina dashboard |
| `dealer_portal_search_page_id` | ID pagina ricerca |
| `dealer_portal_notifications` | Impostazioni del modulo notifiche |
| `dealer_portal_notification_queue` | Coda di invio email |

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
- **Directory protetta**: gli allegati vanno in `uploads/dealer-docs/` con `.htaccess` che nega l'accesso HTTP diretto; i file sono serviti solo via PHP.
- **MIME whitelist**: solo `application/pdf`, `.xlsx` e `.docx`, validati con `wp_check_filetype_and_ext()` sul contenuto reale, non sull'estensione.
- **Isolamento CPT**: `documento_dealer` e `dealer_access_req` hanno `public => false` e `show_in_rest => false`. Nessun URL pubblico o endpoint REST li espone.
- **Doppio controllo accesso**: `user_is_dealer()` e `user_can_access_post()` vengono richiamati prima di servire qualsiasi file. `user_can_download_post()` raccoglie l'intera catena di controlli (tipo, stato, obsolescenza, ruolo, linea, scadenza) ed è usata da download singolo, ZIP, preferiti, cronologia e storico versioni.
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
