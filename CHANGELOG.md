# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it-IT/1.1.0/) e il progetto adotta il
[Versionamento Semantico](https://semver.org/lang/it/).

## [Non rilasciato]

## [1.0.0] - 2026-10-28

Prima versione numerata. Le modifiche sono descritte rispetto alla versione precedente, che non aveva un numero.

### Aggiunto

- Metodo `raw($sql)` e classe `MCPDB_Pg_Raw`: dichiarano esplicitamente un'espressione SQL da usare come valore
  in `insert`, `insert_bulk`, `insert_update`, `update` e nei segnaposto `?`
  (es. `$db->raw('NOW()')`, `$db->raw('hits + 1')`).
- Pagina demo in `demo/`, che esegue ogni funzionalità e mostra codice PHP, SQL generato e risultato.
- Test senza dipendenze in `tests/library_test.php` (`ddev exec php tests/library_test.php`).
- Configurazione della connessione in `demo/db.php` e `tests/db.php`, sovrascrivibile con le variabili `PG*`.
- Configurazione DDEV (PHP 8.3, PostgreSQL 17).

### Modificato

- **Non retrocompatibile:** le stringhe vengono sempre quotate. Prima `'NOW()'`, `'NULL'`, `'DEFAULT'` e altre
  espressioni di una lista chiusa venivano inserite come SQL; ora serve `$db->raw('NOW()')`.
- **Non retrocompatibile:** la licenza passa da GNU LGPL v3 a **GNU GPL v3 o successiva** (`GPL-3.0-or-later`).
  Il file `COPYING` è stato rimosso: il testo della licenza è in `LICENSE`.
- `dcount()` restituisce `false` in caso di errore invece di `0`.
- `insert_bulk()` accetta righe con le stesse chiavi in ordine diverso.
- `insert_id()` compare nel log di debug, non lancia mai eccezioni e non modifica l'ultimo risultato.
- Query più veloci con molti valori: `bind()` copia in blocco il testo senza segnaposto, e le INSERT (anche
  `insert_bulk()` con migliaia di righe) non vengono più riesaminate alla ricerca di `?`.

### Rimosso

- Riconoscimento automatico delle espressioni SQL nelle stringhe (sostituito da `raw()`).
- Riferimenti a `esempio.php`, file mai esistito, nel README.

### Corretto

- **Stringa scambiata per SQL:** l'input "NULL" di un utente non viene più salvato come `NULL` SQL.
- **Errore perso con `halt_on_errors = false`:** se il numero di `?` non corrispondeva ai valori, la libreria
  eseguiva una query vuota e svuotava `last_error`. Ora restituisce `false`, conserva il messaggio e non lascia
  leggere ai `fetch_*` il risultato di una query precedente.
- **Valori in più non segnalati:** se si passavano valori a una query senza `?`, venivano ignorati in silenzio.
- **Transazione bloccata da `insert_id()`:** senza una sequenza usata, il `lastval()` fallito dentro una
  transazione bloccava tutte le query successive.
- **`?` dentro `$$…$$`:** i `?` nelle stringhe dollar-quoted (`$$…$$`, `$tag$…$tag$`) venivano presi per segnaposto.
- **Float sbagliati:** `1e-20` diventava `0` e i numeri grandi ricevevano cifre inventate.
- **`raw()` con `?` nelle INSERT:** un `?` dentro `raw()` (es. l'operatore JSONB) faceva fallire le INSERT.
- **Righe diverse in `insert_bulk()`:** se una riga aveva colonne in più o in meno, la libreria inseriva `NULL`
  in silenzio. Ora segnala un errore.
- **Uso senza connessione:** con `halt_on_errors = false` i metodi chiamati senza connessione causavano un
  `TypeError`. Ora restituiscono `false`.

[Non rilasciato]: https://github.com/zeroviger/mcpDB_Pg/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/zeroviger/mcpDB_Pg/releases/tag/v1.0.0
