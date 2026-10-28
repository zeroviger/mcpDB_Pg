# MCPDB_Pg – Manuale

Wrapper PHP leggero per **PostgreSQL**.
Usa l'estensione nativa `pgsql` (funzioni `pg_*`), non PDO.

**Requisiti:** PHP 7.4+ con estensione `pgsql` attiva (`php -m | grep pgsql`).

**Installazione:** copia `MCPDB_Pg.php` nel progetto e includilo.

```php
require 'MCPDB_Pg.php';

$db = new MCPDB_Pg();
$db->connect('localhost', 'utente', 'password', 'database');   // porta 5432 di default
```

---

## 1. Concetti base

### Segnaposto `?`
I valori non vanno mai concatenati nella query: si usano i `?` e si passa un array di valori, che vengono escapati e quotati automaticamente.

```php
$db->query('SELECT * FROM utenti WHERE eta > ? AND citta = ?', [18, 'Napoli']);
```

- I `?` dentro stringhe (`'...'`), stringhe dollar-quoted (`$$...$$`, `$tag$...$tag$`), identificatori (`"..."`) e commenti (`--`, `/* */`) vengono ignorati.
- Un **array** diventa una lista separata da virgole, utile per `IN`:
  `WHERE id IN (?)` con `[[1, 2, 3]]` produce `IN (1, 2, 3)`.
- Se i valori sono un solo scalare, puoi passarlo senza array: `query('... WHERE id = ?', 5)`.
- Se il numero di `?` e di valori non coincide, viene segnalato un errore.

### Conversione dei tipi PHP

| PHP | SQL |
|---|---|
| `null` | `NULL` |
| `true` / `false` | `TRUE` / `FALSE` |
| `int`, `float` | numero |
| `string` | stringa quotata |
| `DateTime` | timestamp quotato |
| `array` | lista di valori |

### Espressioni SQL come valori: `raw()`
Una stringa PHP viene **sempre** quotata, anche se ha l'aspetto di SQL: `'NULL'`, `'DEFAULT'` o `'NOW()'` diventano il testo `'NULL'`, `'DEFAULT'`, `'NOW()'`.
Per usare un'espressione SQL al posto di un valore va dichiarata con `$db->raw()`. Funziona in `insert`, `insert_bulk`, `insert_update`, `update` e nei segnaposto `?`.

```php
$db->insert('log', ['messaggio' => 'ciao', 'creato_il' => $db->raw('NOW()')]);

// espressioni che usano altre colonne
$db->update('pagine', ['visite' => $db->raw('visite + 1')], 'id = ?', [5]);
```

> **Attenzione:** il contenuto di `raw()` non viene escapato. Non passarci mai dati che arrivano dall'utente.

> **Cambiamento rispetto alle versioni precedenti:** prima alcune stringhe (`'NOW()'`, `'NULL'`, `'DEFAULT'`, …) venivano riconosciute e inserite come SQL. Questo trasformava anche l'input di un utente che scriveva "NULL" in un vero `NULL`. Il codice che usava `'NOW()'` va aggiornato a `$db->raw('NOW()')`.

### Gestione degli errori

| `halt_on_errors` | Comportamento |
|---|---|
| `true` (default) | lancia `RuntimeException` con messaggio e query |
| `false` | il metodo restituisce `false` e l'errore è in `$db->last_error` |

Questo vale anche in due casi particolari, in cui la query non viene inviata e i `fetch_*` successivi restituiscono `false` (non le righe di una query precedente):
- quando `?` e valori non corrispondono;
- quando si usa un metodo prima di `connect()` o dopo una connessione fallita.

```php
$db->halt_on_errors = false;
if ($db->query('SELECT * FROM inesistente') === false) {
    echo $db->last_error;
}
```

---

## 2. Connessione

### `connect($host, $user, $password, $database, $port = 5432, $charset = 'UTF8')`
Apre la connessione. Restituisce `$this` (o lancia eccezione/`false` in caso di errore).

### `close()`
Chiude la connessione.

### `get_link()`
Restituisce la risorsa/oggetto di connessione nativo, per chiamare direttamente funzioni `pg_*`.

---

## 3. Eseguire query

### `query($sql, $replacements = [])`
Esegue una query e restituisce il risultato (che diventa "l'ultimo risultato" usato dai metodi `fetch_*`).

```php
$db->query('
    SELECT id, nome
    FROM utenti
    WHERE attivo = ?
    ORDER BY nome
', [true]);
```

### `select($columns, $table, $where = '', $replacements = [], $order = '', $limit = null)`
Scorciatoia per un `SELECT` semplice. `$columns` vuoto significa `*`.

```php
$db->select('id, nome', 'utenti', 'eta > ?', [18], 'nome', 10);
```

---

## 4. Leggere i risultati

Tutti i metodi `fetch_*` lavorano sull'**ultimo risultato**; in alternativa si può passare una risorsa come ultimo argomento.

| Metodo | Restituisce |
|---|---|
| `fetch_assoc()` | una riga come array associativo, `false` alla fine |
| `fetch_obj()` | una riga come oggetto |
| `fetch_row()` | una riga come array numerico |
| `fetch_assoc_all($index = '')` | tutte le righe; se `$index` è il nome di una colonna, i suoi valori diventano le chiavi |
| `fetch_obj_all($index = '')` | come sopra, ma con oggetti |
| `fetch_column($column = 0)` | array piatto con i valori di una colonna |
| `seek($row)` | sposta il puntatore alla riga indicata |
| `returned_rows()` | numero di righe restituite da una `SELECT` |
| `affected_rows()` | righe toccate dall'ultimo `INSERT`/`UPDATE`/`DELETE` |
| `free_result()` | libera la memoria del risultato |

```php
$db->query('SELECT id, email, nome FROM utenti');

// riga per riga
while ($r = $db->fetch_assoc()) { echo $r['nome']; }

// oppure tutto insieme, indicizzato per email
$db->seek(0);
$utenti = $db->fetch_assoc_all('email');
```

> Nota: PostgreSQL restituisce tutti i valori come **stringhe** (anche gli interi), quindi `$r['eta']` è `"34"`, non `34`. Converti con `(int)` dove serve.

---

## 5. Scrivere dati

### `insert($table, $columns, $ignore = false, $returning = '')`
```php
$db->insert('utenti', ['email' => 'a@b.it', 'nome' => 'Anna', 'creato_il' => $db->raw('NOW()')]);

$db->insert('utenti', ['email' => 'a@b.it'], true);        // ignora i duplicati (ON CONFLICT DO NOTHING)

$r  = $db->insert('utenti', ['email' => 'c@d.it'], false, 'id');   // RETURNING id
$id = $db->fetch_assoc($r)['id'];
```

### `insert_bulk($table, $rows, $ignore = false)`
Inserisce più righe con una sola query. Tutte le righe devono avere le stesse chiavi, anche in ordine diverso. Se una riga ha una colonna in più o in meno rispetto alla prima, la query non parte e viene segnalato un errore.
```php
$db->insert_bulk('utenti', [
    ['email' => 'x@x.it', 'nome' => 'X'],
    ['email' => 'y@y.it', 'nome' => 'Y'],
]);
```

### `insert_update($table, $columns, $conflict_columns, $update = [])`
Upsert (`INSERT ... ON CONFLICT ... DO UPDATE`). A differenza di MySQL, PostgreSQL deve sapere **quali colonne** definiscono il conflitto (devono avere un indice univoco o essere chiave primaria).

```php
// se l'email esiste già, aggiorna nome ed età
$db->insert_update('utenti', ['email' => 'a@b.it', 'nome' => 'Anna', 'eta' => 30], ['email']);

// aggiornando solo alcune colonne, con valori diversi
$db->insert_update('contatori', ['chiave' => 'visite', 'n' => 1], ['chiave'], ['n' => 5]);

// contatore: inserisce 1 la prima volta, poi incrementa
$db->insert_update('contatori', ['chiave' => 'visite', 'n' => 1], ['chiave'], ['n' => $db->raw('contatori.n + 1')]);
```
Se `$update` è vuoto vengono aggiornate tutte le colonne tranne quelle di conflitto.

### `update($table, $columns, $where = '', $replacements = [])`
```php
$db->update('utenti', ['nome' => 'Anna Rossi', 'modificato' => $db->raw('NOW()')], 'id = ?', [5]);
echo $db->affected_rows();
```
> Senza `$where` l'aggiornamento riguarda **tutte** le righe.

### `delete($table, $where = '', $replacements = [])`
```php
$db->delete('utenti', 'attivo = ? AND creato_il < ?', [false, '2020-01-01']);
```
> Senza `$where` cancella **tutte** le righe.

### `insert_id()`
Restituisce l'ultimo id generato da una colonna `serial`/identity in questa sessione (usa `lastval()`), oppure `false` se non ce ne sono. Se la tabella non ha sequenze, usa `insert(..., false, 'id')` con `RETURNING`.

`insert_id()` non lancia mai eccezioni e non cambia l'ultimo risultato: `affected_rows()` chiamato dopo si riferisce ancora alla `INSERT`. Si può usare anche dentro una transazione: se non c'è un id da restituire, la transazione resta valida.

---

## 6. Funzioni "d" (valore singolo)

Restituiscono direttamente un valore, senza dover scrivere la query.

| Metodo | Equivale a |
|---|---|
| `dlookup($col, $table, $where, $repl)` | primo valore trovato, o `false` |
| `dcount($col, $table, $where, $repl)` | `COUNT($col)` come intero, o `false` in caso di errore |
| `dmax(...)` | `MAX($col)` |
| `dmin(...)` | `MIN($col)` |
| `dsum(...)` | `SUM($col)` |

```php
$totale = $db->dcount('*', 'utenti', 'attivo = ?', [true]);
$nome   = $db->dlookup('nome', 'utenti', 'id = ?', [5]);
```

---

## 7. Transazioni

```php
$db->transaction_start();

$db->query('UPDATE conti SET saldo = saldo - ? WHERE id = ?', [100, 1]);
$db->query('UPDATE conti SET saldo = saldo + ? WHERE id = ?', [100, 2]);

$db->transaction_complete();        // COMMIT
// $db->transaction_complete(false);   // ROLLBACK
```
> **Nota:** lo stesso trasferimento si può scrivere con `update()` usando `raw()` per l'espressione e un segnaposto nella WHERE: `$db->update('conti', ['saldo' => $db->raw('saldo - 100')], 'id = ?', [1])`. L'importo, se arriva dall'utente, va però castato a numero prima di finire dentro `raw()`. Con `query()`, come sopra, lo passi come segnaposto.

Con `halt_on_errors = true` conviene racchiudere la transazione in `try/catch` e fare il rollback nel `catch`:

```php
try {
    $db->transaction_start();
    // ... query ...
    $db->transaction_complete();
} catch (RuntimeException $e) {
    $db->transaction_complete(false);
    throw $e;
}
```

---

## 8. Utilità

| Metodo | Descrizione |
|---|---|
| `escape($stringa)` | escapa una stringa (senza apici attorno) |
| `value($v)` | trasforma un valore PHP in un letterale SQL sicuro |
| `raw($sql)` | marca un'espressione SQL da usare senza quotarla (vedi §1) |
| `identifier($nome)` | quota un identificatore: `schema.tabella.colonna` → `"schema"."tabella"."colonna"` |
| `get_last_query()` | ultima query eseguita, con i valori già inseriti |
| `get_queries()` | log delle query (richiede `$db->debug = true`) |

Il log contiene per ogni query: `query`, `time` (secondi), `rows` ed `error`.

```php
$db->debug = true;
// ... query ...
foreach ($db->get_queries() as $q) {
    printf("[%.2f ms] %s\n", $q['time'] * 1000, $q['query']);
}
```

---

## 9. Note e limitazioni

**Specifico di PostgreSQL**
- Gli identificatori sono quotati con `"doppi apici"`.
- `insert_id()` si basa su `lastval()`; in alternativa c'è `RETURNING`.
- `insert_update()` richiede le colonne di conflitto.
- L'inserimento che ignora i duplicati usa `ON CONFLICT DO NOTHING`.
- L'operatore JSONB `?` di PostgreSQL non è utilizzabile direttamente nella query, perché è riservato ai segnaposto; usa al suo posto `jsonb_exists(colonna, chiave)`.

**Non implementato**
- Cache dei risultati.
- Console di debug grafica ed `EXPLAIN` automatico (c'è solo il log delle query).
- Query unbuffered.
- Metodi di ispezione dello schema o di manutenzione (`table_exists`, `truncate`, ecc.).

---

## 10. Sicurezza: promemoria

- Passa **sempre** i valori dall'input utente tramite `?`, mai concatenati nella stringa SQL.
- Nomi di tabelle e colonne **non** sono parametrizzabili con `?`: se provengono dall'utente, validali con una lista di valori ammessi.
- `$where` e `$order` sono SQL scritto da te: non inserirci mai input non filtrato.
- Lo stesso vale per `raw()`: il suo contenuto finisce nella query così com'è.

---

## 11. Licenza

MCPDB_Pg è distribuito con licenza GNU GPL v3 o successiva (`GPL-3.0-or-later`); vedi `LICENSE`. Copyright (C) 2026 Giuseppe Solla.
