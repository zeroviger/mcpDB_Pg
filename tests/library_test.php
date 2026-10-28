<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Giuseppe Solla
 */
declare(strict_types=1);

/**
 * Test minimi della libreria, senza dipendenze. Da eseguire nel container DDEV:
 *   ddev exec php tests/library_test.php
 * Esce con codice 1 se almeno un controllo fallisce.
 */

require_once __DIR__ . '/../MCPDB_Pg.php';
require_once __DIR__ . '/db.php';

$failures = 0;

/**
 * Registra l'esito di un controllo e lo stampa.
 *
 * @param string $label     Descrizione del controllo
 * @param bool   $condition true se il controllo è superato
 * @return void
 */
function check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
}

/**
 * Esegue una funzione e restituisce il messaggio dell'eccezione lanciata, o null se non ne lancia.
 *
 * @param callable $action Codice da eseguire
 * @return string|null Prima riga del messaggio d'errore
 */
function thrownMessage(callable $action): ?string
{
    try {
        $action();
    } catch (Throwable $e) {
        return get_class($e) . ': ' . strtok($e->getMessage(), "\n");
    }
    return null;
}

$db = new MCPDB_Pg();
$db->connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

$db->query('CREATE TEMPORARY TABLE t (id serial PRIMARY KEY, name text, hits int NOT NULL DEFAULT 0, seen_at timestamptz)');

echo "Segnaposto non corrispondenti con halt_on_errors = false\n";
$db->halt_on_errors = false;
$db->query('SELECT 1 AS old_row');
check('query(): restituisce false', $db->query('SELECT 1 WHERE 1 = ? AND 2 = ?', [1]) === false);
check('query(): last_error contiene il messaggio', str_contains((string) $db->last_error, 'placeholders'));
check('query(): nessuna query vuota eseguita', $db->get_last_query() === 'SELECT 1 AS old_row');
check('query(): fetch non legge il risultato precedente', $db->fetch_assoc() === false);
check('query(): valori in eccesso senza "?"', $db->query('SELECT 1', [1]) === false);
check('update(): restituisce false', $db->update('t', ['name' => 'x'], 'id = ? AND id = ?', [1]) === false);
check('delete(): restituisce false', $db->delete('t', 'id = ?', [1, 2]) === false);
$db->halt_on_errors = true;

$thrown = false;
try {
    $db->query('SELECT ?', []);
} catch (RuntimeException $e) {
    $thrown = true;
}
check('halt_on_errors = true: lancia RuntimeException', $thrown);

echo "Stringhe sempre quotate, SQL solo con raw()\n";
foreach (['NULL', 'DEFAULT', 'NOW()', 'random()'] as $word) {
    $db->query('SELECT ?::text AS v', [$word]);
    check("'$word' via segnaposto resta una stringa", $db->fetch_assoc()['v'] === $word);
}

$db->insert('t', ['name' => 'NULL']);
check("insert(): 'NULL' salvato come testo", $db->dlookup('name', 't', 'id = ?', [1]) === 'NULL');

$db->insert('t', ['name' => 'raw', 'seen_at' => $db->raw('NOW()')]);
check('insert(): raw(NOW()) valorizza il timestamp', $db->dcount('*', 't', 'seen_at IS NOT NULL') === 1);

$db->update('t', ['hits' => $db->raw('hits + 1')], 'name = ?', ['raw']);
$db->update('t', ['hits' => $db->raw('hits + 1')], 'name = ?', ['raw']);
check('update(): raw(hits + 1) incrementa', $db->dlookup('hits', 't', 'name = ?', ['raw']) === '2');

$db->query('SELECT ? AS v', [$db->raw('1 + 1')]);
check('segnaposto: raw() inserisce SQL', $db->fetch_assoc()['v'] === '2');

echo "Comportamento invariato\n";
$db->query("SELECT ? AS a, '?' AS b -- ?\n", ['x']);
check('"?" tra apici e nei commenti ignorati', $db->fetch_assoc() === ['a' => 'x', 'b' => '?']);
$db->query('SELECT count(*) FROM t WHERE id IN (?)', [[1, 2, 3]]);
check('array espanso per IN', $db->fetch_row()[0] === '2');
check("apostrofo escapato", $db->value("l'a") === "'l''a'");

$r = $db->insert('t', ['name' => 'ret'], false, 'id, name');
check('insert() con RETURNING', $db->fetch_assoc($r)['name'] === 'ret');
$db->insert('t', ['id' => 1, 'name' => 'doppione'], true);
check('insert() con ignore: nessuna riga', $db->affected_rows() === 0);
$db->insert_bulk('t', [['name' => 'b1', 'hits' => 1], ['hits' => 2, 'name' => 'b2']]);
check('insert_bulk(): chiavi in ordine diverso accettate', $db->dlookup('hits', 't', 'name = ?', ['b2']) === '2');
$db->query('CREATE TEMPORARY TABLE kv (k text PRIMARY KEY, v int, note text)');
$db->insert_update('kv', ['k' => 'a', 'v' => 1, 'note' => 'x'], ['k']);
$db->insert_update('kv', ['k' => 'a', 'v' => 2, 'note' => 'y'], ['k']);
check('insert_update(): aggiorna tutte le colonne non di conflitto', $db->dlookup('v || note', 'kv', 'k = ?', ['a']) === '2y');
$db->insert_update('kv', ['k' => 'a', 'v' => 9, 'note' => 'z'], ['k'], ['note' => 'solo']);
check('insert_update(): aggiorna solo le colonne indicate', $db->dlookup('v || note', 'kv', 'k = ?', ['a']) === '2solo');
$db->update('kv', ['v' => 0]);
check('update() senza WHERE', $db->affected_rows() === 1);
$db->delete('kv', 'k = ?', 'a');
check('delete() con un solo valore non in array', $db->dcount('*', 'kv') === 0);

echo "A1: insert_id() dentro una transazione\n";
// lastval() vale per tutta la sessione: serve una connessione che non abbia mai usato una sequenza
$fresh = (new MCPDB_Pg())->connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$fresh->query('CREATE TEMPORARY TABLE noseq (name text)');
$fresh->transaction_start();
$fresh->insert('noseq', ['name' => 'x']);
check('insert_id() senza sequenza: false', $fresh->insert_id() === false);
check('la transazione resta utilizzabile', thrownMessage(fn () => $fresh->query('SELECT 1')) === null);
$fresh->transaction_complete();
check('e il COMMIT salva la riga', $fresh->dcount('*', 'noseq') === 1);
$fresh->close();

$db->transaction_start();
$db->insert('t', ['name' => 'in-tx']);
$id = $db->insert_id();
check('insert_id() con sequenza: id corretto', $id === (int) $db->dlookup('id', 't', 'name = ?', ['in-tx']));
$db->insert('t', ['name' => 'in-tx-2']);
$db->insert_id();
check('affected_rows() dopo insert_id() si riferisce ancora alla INSERT', $db->affected_rows() === 1);
check('get_last_query() dopo insert_id() è ancora la INSERT', str_starts_with((string) $db->get_last_query(), 'INSERT'));
$db->transaction_complete();
$db->debug = true;
$db->insert_id();
check('insert_id() compare nel log di debug', end($db->queries)['query'] === 'SELECT lastval()');
$db->debug = false;

echo "A2: stringhe dollar-quoted\n";
$db->query('SELECT $$uno?$$ AS a, $q$ due ? $q$ AS b, ? AS c', ['x']);
check('"?" dentro $$...$$ e $tag$...$tag$ ignorati', $db->fetch_assoc() === ['a' => 'uno?', 'b' => ' due ? ', 'c' => 'x']);
$db->query('SELECT 1 AS a$b, ? AS c', ['y']);
check('"$" dentro un identificatore non apre una stringa', $db->fetch_assoc()['c'] === 'y');

echo "A3: float\n";
check('1e-20 non diventa 0', $db->value(1e-20) === '1.0E-20');
check('nessuna cifra inventata', $db->value(123456789.12345679) === '123456789.12345679');
$db->query('SELECT ?::float8 = 1e-20 AS a, ?::float8 = 1.5e300 AS b', [1e-20, 1.5e300]);
check('PostgreSQL legge i float senza perdite', $db->fetch_assoc() === ['a' => 't', 'b' => 't']);

echo "A4: raw() con \"?\" nelle INSERT\n";
$db->query('CREATE TEMPORARY TABLE j (ok boolean)');
check('insert() con operatore JSONB ? dentro raw()', thrownMessage(fn () => $db->insert('j', ['ok' => $db->raw("'{\"k\":1}'::jsonb ? 'k'")])) === null);
check('valore calcolato correttamente', $db->dlookup('ok', 'j') === 't');

echo "A5: insert_bulk() con righe diverse\n";
check('chiave mancante: errore', str_contains((string) thrownMessage(fn () => $db->insert_bulk('t', [['name' => 'a'], ['hits' => 1]])), 'row 1'));
check('chiave in più: errore', thrownMessage(fn () => $db->insert_bulk('t', [['name' => 'a'], ['name' => 'b', 'hits' => 1]])) !== null);

echo "A6: senza connessione\n";
$offline = new MCPDB_Pg();
$offline->halt_on_errors = false;
check('query(): false invece di TypeError', $offline->query('SELECT ?', [1]) === false);
check('last_error spiega il problema', str_contains((string) $offline->last_error, 'Not connected'));
check('insert(): false', $offline->insert('t', ['name' => 'x']) === false);
check('insert_id(): false', $offline->insert_id() === false);
check('value() di una stringa: false', $offline->value('x') === false);
$offline->halt_on_errors = true;
check('con halt_on_errors = true: RuntimeException', str_starts_with((string) thrownMessage(fn () => $offline->query('SELECT 1')), 'RuntimeException'));

echo "C3: dcount() non nasconde gli errori\n";
$db->halt_on_errors = false;
check('dcount() su tabella inesistente: false', $db->dcount('*', 'missing_table') === false);
$db->halt_on_errors = true;
check('dcount() senza righe: 0', $db->dcount('*', 't', 'name = ?', ['nessuno']) === 0);

echo $failures === 0 ? "\nTutti i controlli superati.\n" : "\n$failures controlli falliti.\n";
exit($failures === 0 ? 0 : 1);
