# MCPDB_Pg

Wrapper PHP leggero per **PostgreSQL** con un'API ispirata a [Zebra_Database](https://github.com/stefangabos/Zebra_Database), la libreria per MySQLi di Stefan Gabos.

Query con segnaposto `?` automaticamente escapati, metodi di comodo per `INSERT`/`UPDATE`/`DELETE`, funzioni `dcount`/`dlookup`, transazioni e log delle query, in un unico file senza dipendenze.

Usa l'estensione nativa `pgsql` (funzioni `pg_*`): **non** è un wrapper di PDO.

## Requisiti

- PHP 7.4 o superiore
- estensione `pgsql` attiva (`php -m | grep pgsql`)
- PostgreSQL 9.5 o superiore (serve `ON CONFLICT` per `insert_update` e `insert(..., $ignore)`)

## Installazione

Copia `MCPDB_Pg.php` nel tuo progetto e includilo:

```php
require 'MCPDB_Pg.php';
```

## Uso rapido

```php
$db = new MCPDB_Pg();
$db->connect('localhost', 'utente', 'password', 'database');

// SELECT con segnaposto: i valori sono escapati automaticamente
$db->query('SELECT id, nome FROM utenti WHERE eta > ? AND id IN (?)', [18, [1, 2, 3]]);

while ($riga = $db->fetch_assoc()) {
    echo $riga['nome'], "\n";
}

// oppure tutte le righe insieme, indicizzate per una colonna
$utenti = $db->fetch_assoc_all('id');

// scrittura
$db->insert('utenti', ['email' => 'a@b.it', 'nome' => 'Anna', 'creato_il' => $db->raw('NOW()')]);
$id = $db->insert_id();

$db->update('utenti', ['nome' => 'Anna Rossi'], 'id = ?', [$id]);
$db->delete('utenti', 'id = ?', [$id]);

// valori singoli
$totale = $db->dcount('*', 'utenti', 'attivo = ?', [true]);

// transazioni
$db->transaction_start();
// ... query ...
$db->transaction_complete();        // COMMIT (con false: ROLLBACK)
```

## Funzionalità

| Area | Metodi |
|---|---|
| Connessione | `connect`, `close`, `get_link` |
| Query | `query`, `select` |
| Lettura | `fetch_assoc`, `fetch_obj`, `fetch_row`, `fetch_assoc_all`, `fetch_obj_all`, `fetch_column`, `seek`, `returned_rows`, `affected_rows`, `free_result` |
| Scrittura | `insert`, `insert_bulk`, `insert_update` (upsert), `update`, `delete`, `insert_id` |
| Valore singolo | `dlookup`, `dcount`, `dmax`, `dmin`, `dsum` |
| Transazioni | `transaction_start`, `transaction_complete` |
| Utilità | `escape`, `value`, `raw`, `identifier`, `get_last_query`, `get_queries` |

Altre caratteristiche:

- array nei segnaposto espansi per `IN (?)`
- gestione corretta di `null`, booleani e `DateTime`
- `halt_on_errors`: eccezioni `RuntimeException` oppure `false` + `last_error`
- `debug`: log di ogni query con tempo di esecuzione e righe coinvolte

## Differenze rispetto a Zebra_Database

- Identificatori quotati con `"doppi apici"` invece dei backtick.
- `insert_id()` usa `lastval()` (colonne `serial`/identity); in alternativa `insert(..., false, 'id')` aggiunge `RETURNING id`.
- `insert_update()` richiede le colonne del conflitto: `insert_update('utenti', $dati, ['email'])`.
- `INSERT IGNORE` diventa `ON CONFLICT DO NOTHING`.
- L'operatore JSONB `?` non è usabile direttamente nelle query (è il segnaposto): usa `jsonb_exists()`.
- **Non implementati:** cache dei risultati, console di debug grafica, `EXPLAIN` automatico, query unbuffered, metodi minori come `table_exists` o `truncate`.

## Contenuto del pacchetto

| File | Descrizione |
|---|---|
| `MCPDB_Pg.php` | la classe |
| `MANUALE.md` | manuale completo, metodo per metodo |
| `demo/` | pagina web che esegue e mostra tutte le funzionalità (codice, SQL generato, risultato) |
| `tests/library_test.php` | test minimi senza dipendenze |
| `CHANGELOG.md` | storico delle versioni e delle modifiche |
| `LICENSE` | testo della licenza GNU GPL v3 |

Con DDEV avviato la demo è su `https://mcpdb-pg.ddev.site/demo/` e ricrea a ogni caricamento lo schema `mcpdb_demo`. I test si lanciano con:

```bash
ddev exec php tests/library_test.php
```

Fuori da DDEV, sia la demo sia i test leggono la connessione dalle variabili `PGHOST`, `PGUSER`, `PGPASSWORD`, `PGDATABASE`, `PGPORT`.

## Sicurezza

- Passa sempre i valori dall'input utente tramite `?`, mai concatenati nella SQL.
- Nomi di tabelle e colonne non sono parametrizzabili: se arrivano dall'utente, validali con una lista di valori ammessi.
- `$where` e `$order` sono SQL scritto da te: non inserirci input non filtrato.
- Le stringhe vengono sempre quotate. Le espressioni SQL (`NOW()`, `hits + 1`, ...) passano solo se dichiarate con `$db->raw()`, il cui contenuto non viene escapato: mai input dell'utente lì dentro.

## Licenza

Copyright (C) 2026 Giuseppe Solla.

Distribuito con licenza **[GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html)** o, a tua scelta, successiva (`GPL-3.0-or-later`). Il testo completo è nel file `LICENSE`.

In pratica puoi usare, modificare e ridistribuire la libreria liberamente. Però un programma che la include e viene distribuito deve essere rilasciato anch'esso con licenza GPL, insieme al codice sorgente.

## Ringraziamenti

L'API è ispirata a [Zebra_Database](https://github.com/stefangabos/Zebra_Database) di Stefan Gabos.
