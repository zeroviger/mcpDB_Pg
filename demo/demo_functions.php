<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Giuseppe Solla
 */
declare(strict_types=1);

/**
 * Restituisce il corpo di una closure così come è scritto nel sorgente, senza l'indentazione comune.
 *
 * Presuppone che la firma stia su una riga e la graffa di chiusura su un'altra,
 * che è il formato usato in demo_cases.php.
 *
 * @param Closure $closure Closure di cui estrarre il sorgente
 * @return string Codice del corpo della closure
 */
function getClosureBody(Closure $closure): string
{
    // tutte le closure stanno nello stesso file: lo si legge una volta sola
    static $fileCache = [];

    $reflection = new ReflectionFunction($closure);
    $fileName = (string) $reflection->getFileName();
    $fileCache[$fileName] ??= file($fileName, FILE_IGNORE_NEW_LINES);
    $lines = $fileCache[$fileName];

    if ($lines === false) {
        return '';
    }

    $startLine = $reflection->getStartLine();
    $body = array_slice($lines, $startLine, $reflection->getEndLine() - $startLine - 1);

    $indent = PHP_INT_MAX;
    foreach ($body as $line) {
        if (trim($line) !== '') {
            $indent = min($indent, strlen($line) - strlen(ltrim($line, ' ')));
        }
    }
    $indent = $indent === PHP_INT_MAX ? 0 : $indent;

    return implode("\n", array_map(fn (string $line): string => substr($line, $indent), $body));
}

/**
 * Esegue una demo raccogliendo risultato, eventuale errore e query inviate al database.
 *
 * @param array    $demo Voce di demo_cases.php
 * @param MCPDB_Pg $db   Istanza con debug attivo, così da leggere il log delle query
 * @return array{result: array|null, error: string|null, queries: array, time: float}
 */
function runDemo(array $demo, MCPDB_Pg $db): array
{
    $firstQuery = count($db->queries);
    $start = microtime(true);
    $result = null;
    $error = null;

    try {
        $result = ($demo['run'])($db);
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    return [
        'result'  => $result,
        'error'   => $error,
        'queries' => array_slice($db->queries, $firstQuery),
        'time'    => microtime(true) - $start,
    ];
}

/**
 * Converte una durata in secondi nei millisecondi formattati all'italiana.
 *
 * @param float $seconds  Durata in secondi
 * @param int   $decimals Cifre decimali
 * @return string Per esempio "12,4"
 */
function formatMs(float $seconds, int $decimals = 1): string
{
    return number_format($seconds * 1000, $decimals, ',', '.');
}

/**
 * Escapa un testo descrittivo e trasforma i tratti tra `backtick` in <code>.
 *
 * @param string $text Testo della descrizione
 * @return string HTML sicuro
 */
function formatText(string $text): string
{
    return (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', htmlspecialchars($text));
}

/**
 * Rappresenta un valore scalare mettendone in evidenza il tipo PHP.
 *
 * @param mixed $value Valore da mostrare
 * @return string HTML sicuro
 */
function formatScalar(mixed $value): string
{
    return match (true) {
        $value === null                     => '<span class="val-null">null</span>',
        is_bool($value)                     => '<span class="val-bool">' . ($value ? 'true' : 'false') . '</span>',
        is_int($value), is_float($value)    => '<span class="val-num">' . $value . '</span>',
        is_string($value)                   => '<span class="val-str">"' . htmlspecialchars($value) . '"</span>',
        default                             => htmlspecialchars(print_r($value, true)),
    };
}

/**
 * Rappresenta un valore qualsiasi: righe di risultato come tabella, array associativi
 * come coppie chiave/valore, liste di scalari in linea.
 *
 * @param mixed $value Valore da mostrare
 * @return string HTML sicuro
 */
function renderValue(mixed $value): string
{
    if (is_object($value)) {
        return '<div class="obj-label">' . htmlspecialchars(get_class($value)) . '</div>'
            . renderValue(get_object_vars($value));
    }

    if (!is_array($value)) {
        return formatScalar($value);
    }

    if ($value === []) {
        return '<span class="val-empty">[] array vuoto</span>';
    }

    $allRows = array_reduce($value, fn (bool $carry, mixed $v): bool => $carry && (is_array($v) || is_object($v)), true);
    if ($allRows) {
        return renderRows($value, !array_is_list($value));
    }

    if (array_is_list($value)) {
        return '<span class="val-list">[' . implode(', ', array_map('renderValue', $value)) . ']</span>';
    }

    return renderPairs($value);
}

/**
 * Tabella chiave/valore.
 *
 * @param array $pairs Array associativo
 * @return string HTML sicuro
 */
function renderPairs(array $pairs): string
{
    $html = '<table class="pairs"><tbody>';
    foreach ($pairs as $key => $value) {
        $html .= '<tr><th>' . htmlspecialchars((string) $key) . '</th><td>' . renderValue($value) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Tabella di righe (array o oggetti con le stesse chiavi).
 *
 * @param array $rows    Righe da mostrare
 * @param bool  $showKey true per aggiungere una colonna con la chiave dell'array (es. fetch_assoc_all('sku'))
 * @return string HTML sicuro
 */
function renderRows(array $rows, bool $showKey): string
{
    $columns = array_keys((array) reset($rows));

    $html = '<div class="rows-wrap"><table class="rows"><thead><tr>';
    if ($showKey) {
        $html .= '<th class="row-key">chiave</th>';
    }
    foreach ($columns as $column) {
        $html .= '<th>' . htmlspecialchars((string) $column) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $key => $row) {
        $row = (array) $row;
        $html .= '<tr>';
        if ($showKey) {
            $html .= '<td class="row-key">' . formatScalar($key) . '</td>';
        }
        foreach ($columns as $column) {
            $html .= '<td>' . formatScalar($row[$column] ?? null) . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}
