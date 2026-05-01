# Dealer Portal — WordPress Plugin

## Descrizione

Dealer Portal è un plugin WordPress per la gestione documentale in una rete di distribuzione (settore nautica/marino). Permette agli amministratori di caricare documenti tecnici (PDF, XLSX, DOCX) e ai dealer di cercarli e scaricarli tramite un'area riservata. L'accesso è controllato per ruolo e per linea prodotto assegnata.

---

## Funzionalità

- Tre ruoli dealer custom: `dealer`, `top_dealer`, `part_center`
- Controllo accesso per ruolo e per linea prodotto (es. un dealer assegnato a Mercury FourStroke vede solo i documenti di quella linea)
- Wizard di caricamento admin in 5 step con drag-and-drop, rinomina file e validazione MIME
- Ricerca full-text tramite SearchWP 4.x (fallback alla ricerca nativa WP se SearchWP non è attivo)
- Attributi di ricerca: keywords, brand, linea prodotto, tipo documento, anno, versione
- Log di ogni download: utente, documento, timestamp, IP
- Viewer log per admin (per documento e globale, ultimi 500 record)
- Dashboard dealer personalizzata: documenti recenti, documenti in scadenza, contatto commerciale
- Badge **NUOVO** per documenti caricati negli ultimi 30 giorni
- Badge **IN SCADENZA** per documenti in scadenza nei prossimi 30 giorni
- Blocco automatico dei documenti scaduti: esclusi dalla ricerca e bloccati al download con HTTP 403
- Azioni admin: segna come obsoleto, elimina definitivamente (post + file media)
- Creazione automatica delle pagine WordPress all'attivazione (Dashboard Dealer, Cerca Documenti)
- Redirect login: i ruoli dealer vanno a `/dashboard-dealer/` invece che a wp-admin

---

## Requisiti

| Requisito | Versione |
|---|---|
| WordPress | 5.8 o superiore |
| PHP | 7.4 o superiore |
| WP Customer Area | 8.3 (infrastruttura pagine front-end riservate) |
| SearchWP | 4.x (opzionale — abilita ricerca full-text; senza: ricerca WP nativa) |

---

## Installazione

1. Scaricare o clonare il repository come ZIP.
2. In WordPress admin, andare in **Plugin → Aggiungi nuovo → Carica plugin** e selezionare il file ZIP.
3. Cliccare **Attiva plugin**.
4. Due pagine vengono create automaticamente:
   - **Dashboard Dealer** (slug: `dashboard-dealer`, shortcode: `[dealer_dashboard]`)
   - **Cerca Documenti** (slug: `dealer-search`, shortcode: `[dealer_search]`)
5. La tabella custom `{prefisso}_dealer_download_log` viene creata automaticamente.
6. I tre ruoli custom vengono registrati: `dealer`, `top_dealer`, `part_center`.

Non è necessaria alcuna creazione manuale di pagine o query SQL.

---

## Configurazione

### Assegnare linee prodotto a un dealer

1. Andare in **Utenti → Tutti gli utenti** e modificare il profilo del dealer.
2. Scorrere fino alla sezione **Impostazioni Dealer Portal**.
3. Nel campo multi-selezione **Linee Prodotto Assegnate**, tenere premuto Ctrl (Windows) o Cmd (Mac) e selezionare tutte le linee pertinenti.
4. Compilare opzionalmente i campi del Referente commerciale.
5. Cliccare **Aggiorna utente**.

Le linee prodotto usano il formato `Brand|Linea` (es. `Mercury|FourStroke`). Questo formato è confrontato sia nel meta utente `_dealer_lines` che nel meta documento `_doc_lines`.

### Brand e linee prodotto

Brand e linee sono hardcoded nella costante `PRODUCT_LINES` di `Dealer_Admin`. Per aggiungere o rimuovere brand o linee, modificare quella costante in `includes/class-admin.php`. La lista attuale copre 28 brand tra motori fuoribordo, elettronica di navigazione, propulsione elettrica e costruttori di imbarcazioni.

### Tipi di documento

I tipi di documento sono hardcoded nella costante `DOC_TYPES` di `Dealer_Admin`:

| Chiave | Etichetta |
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

1. Andare in **Dealer Portal → Carica Documento** nel menu admin WordPress.
2. **Step 1 — File**: trascinare o selezionare un file PDF, XLSX o DOCX (max 50 MB). Rinominare facoltativamente tramite il campo nome personalizzato.
3. **Step 2 — Classificazione**: selezionare brand, linea prodotto, tipo documento, anno e versione.
4. **Step 3 — Visibilità**: selezionare i ruoli che possono accedere al documento. Facoltativamente limitare a linee prodotto specifiche e impostare una data di scadenza.
5. **Step 4 — Keywords**: aggiungere parole chiave separate da virgola e note interne (non visibili ai dealer).
6. **Step 5 — Riepilogo**: rivedere e cliccare **Salva Documento**.

Il documento viene salvato come CPT `documento_dealer` con tutti i metadati come post meta. Il file viene aggiunto alla Media Library di WordPress. SearchWP viene notificato per la re-indicizzazione.

### Gestire i documenti

Andare in **Dealer Portal → Archivio Documenti**. Da qui è possibile:

- Filtrare per brand, tipo, ruolo o stato
- Visualizzare i log di download per documento (cliccare **Log** per espandere)
- Segnare un documento come obsoleto (nascosto ai dealer, passa a stato bozza)
- Eliminare definitivamente un documento (rimuove il post e il file media associato)
- Modificare direttamente il post WordPress del documento (per correzioni al titolo)

### Visualizzare i log di download

Andare in **Dealer Portal → Log Download** per vedere gli ultimi 500 eventi di download su tutti i documenti, con colonne per nome documento, nome utente, timestamp e indirizzo IP.

---

## Workflow Dealer

1. Accedere tramite `/wp-login.php`. I dealer vengono reindirizzati automaticamente a `/dashboard-dealer/`.
2. La **dashboard** mostra:
   - Badge ruolo (Dealer / Top Dealer / Part Center)
   - Timestamp ultimo accesso
   - Link rapidi a ricerca, documenti recenti e documenti in scadenza
   - Feed degli ultimi 5 documenti pubblicati accessibili al dealer
   - Feed dei documenti in scadenza nei prossimi 30 giorni
   - Dati del referente commerciale (se configurato dall'admin)
3. Dalla dashboard, cliccare **Cerca Documenti** oppure usare la pagina di ricerca a `/dealer-search/`.
4. Usare il campo di ricerca per keyword e/o i filtri (brand, tipo, anno, linea prodotto).
5. Cliccare **Scarica** nella card risultato per scaricare il file. Il download viene loggato lato server.

---

## Struttura file

```
dashboard-dealer/
├── dealer-portal.php              File principale plugin, boot hooks, redirect login
├── uninstall.php                  Pulizia alla disinstallazione del plugin
├── includes/
│   ├── class-cpt.php              Custom post type: documento_dealer
│   ├── class-roles.php            Ruoli custom: dealer, top_dealer, part_center
│   ├── class-db.php               Creazione tabella DB, scrittura/lettura log download
│   ├── class-admin.php            Menu admin, AJAX wizard upload, campi user meta
│   ├── class-search.php           Shortcode ricerca dealer, handler download, AJAX
│   ├── class-dashboard.php        Shortcode dashboard dealer
│   └── class-searchwp.php         Registrazione sorgente e attributi SearchWP 4.x
├── templates/
│   ├── admin-upload.php           HTML wizard upload 5 step
│   ├── admin-archive.php          Tabella archivio documenti con filtri
│   ├── admin-logs.php             Tabella log download globale
│   ├── dealer-dashboard.php       Dashboard front-end dealer
│   └── dealer-search.php          Form di ricerca e cards risultati
└── assets/
    ├── css/
    │   ├── admin.css              Stili pannello admin
    │   └── dealer.css             Stili front-end dealer
    └── js/
        ├── admin-upload.js        Navigazione wizard, validazione, AJAX
        └── dealer-search.js       Auto-submit filtri, chiamata AJAX download
```

---

## Modello dati

### Custom Post Type: `documento_dealer`

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_doc_brand` | string | Nome brand (es. `Mercury`) |
| `_doc_product_line` | string | Nome linea semplice (es. `FourStroke`) |
| `_doc_type` | string | Chiave tipo documento (es. `manuale`) |
| `_doc_year` | int | Anno di pubblicazione |
| `_doc_version` | string | Stringa versione (es. `v1`, `rev2`) |
| `_doc_roles` | array | Ruoli autorizzati (es. `['dealer','top_dealer']`) |
| `_doc_lines` | array | Linee autorizzate in formato `Brand\|Linea`; vuoto = tutte le linee |
| `_doc_expiry` | string | Data ISO `YYYY-MM-DD`; vuoto = nessuna scadenza |
| `_doc_keywords` | string | Parole chiave per SearchWP |
| `_doc_internal_notes` | string | Note visibili solo agli admin |
| `_doc_file_id` | int | ID allegato nella Media Library |
| `_doc_filename` | string | Nome file usato nell'header Content-Disposition |
| `_doc_status` | string | `active` oppure `obsoleto` |
| `_doc_keep_original_name` | int | `1` = mantenere nome file originale all'upload |

### Ruoli custom

| Ruolo | Capacità |
|---|---|
| `dealer` | solo `read` |
| `top_dealer` | solo `read` |
| `part_center` | solo `read` |

### Tabella DB custom: `{prefisso}_dealer_download_log`

| Colonna | Tipo | Descrizione |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Chiave primaria |
| `user_id` | BIGINT UNSIGNED | ID utente WordPress |
| `post_id` | BIGINT UNSIGNED | ID post `documento_dealer` |
| `download_date` | DATETIME DEFAULT CURRENT_TIMESTAMP | Timestamp UTC |
| `ip_address` | VARCHAR(45) | IP client (IPv4 o IPv6), vuoto se non valido |

Indici: `user_id`, `post_id`, `download_date`.

### User meta (sui profili dealer)

| Meta Key | Tipo | Descrizione |
|---|---|---|
| `_dealer_lines` | array | Linee prodotto assegnate in formato `Brand\|Linea` |
| `_dealer_last_login` | string | Datetime MySQL dell'ultimo accesso |
| `_referente_nome` | string | Nome referente commerciale |
| `_referente_email` | string | Email referente commerciale |
| `_referente_telefono` | string | Telefono referente commerciale |

### Opzioni WordPress

| Opzione | Descrizione |
|---|---|
| `dealer_portal_version` | Versione plugin installata |
| `dealer_portal_dashboard_page_id` | ID post della pagina dashboard creata automaticamente |
| `dealer_portal_search_page_id` | ID post della pagina ricerca creata automaticamente |

---

## Note di sicurezza

- **Nonce sui download**: ogni URL di download è limitato nel tempo da un nonce WordPress (`dealer_download_{post_id}`). URL diretti o indovinati senza nonce valido vengono rifiutati con HTTP 403.
- **Path traversal prevention**: `handle_download()` usa `realpath()` per verificare che il file si trovi nella cartella uploads di WordPress prima di servirlo.
- **MIME whitelist**: il gestore upload usa `wp_handle_upload()` con una whitelist MIME esplicita (`application/pdf`, `.xlsx`, `.docx`). Altri tipi di file vengono rifiutati.
- **Isolamento CPT**: il CPT `documento_dealer` ha `'public' => false` e `'show_in_rest' => false`. Nessun URL pubblico o endpoint REST espone i dati dei documenti.
- **Doppio controllo accesso**: sia `user_is_dealer()` (verifica ruolo) che `user_can_access_post()` (verifica ruolo + linea prodotto) vengono chiamati in `handle_download()` prima di servire qualsiasi file.
- **Validazione IP**: l'IP nel log download viene validato con `FILTER_VALIDATE_IP`. Valori non validi vengono salvati come stringa vuota invece di essere iniettati nel DB.
- **Nonce su tutti gli endpoint AJAX**: ogni handler AJAX chiama `check_ajax_referer()` o `wp_verify_nonce()` prima di elaborare la richiesta.
- **Verifica capacità**: tutti gli handler AJAX admin verificano `current_user_can('manage_options')`.
- **Blocco scadenza**: i documenti con `_doc_expiry` nel passato sono esclusi dai risultati di ricerca e bloccati al download con HTTP 403.

---

## Changelog

### 1.0.1 (2026-05-01)
- Fix: rimosso il doppio inserimento nel log download causato da `ajax_log_download()` che chiamava `Dealer_DB::log_download()` in aggiunta alla chiamata server-side già presente in `handle_download()`.
- Fix: i documenti con `_doc_expiry` nel passato sono ora esclusi dalla ricerca e bloccati al download con HTTP 403.
- Fix: aggiunto helper statico `is_expired_doc()` su `Dealer_Search`.
- Fix: `Dealer_DB::log_download()` ora scrive un errore PHP via `error_log()` quando l'insert DB fallisce, invece di ignorare silenziosamente il fallimento.

### 1.0.0
- Release iniziale.
- CPT `documento_dealer` con schema meta completo.
- Ruoli custom: `dealer`, `top_dealer`, `part_center`.
- Wizard upload admin in 5 step (PDF, XLSX, DOCX; max 50 MB).
- Controllo accesso per ruolo e linea prodotto.
- Ricerca full-text via SearchWP 4.x con fallback ricerca WP nativa.
- Log download (`{prefisso}_dealer_download_log`).
- Archivio admin con segna-obsoleto ed eliminazione definitiva.
- Dashboard dealer personalizzata con feed documenti recenti e in scadenza.
- Creazione automatica pagine dashboard e ricerca all'attivazione.
- Redirect login per i ruoli dealer.
