<?php

/**
 *  MCPDB_Pg - a lightweight PostgreSQL wrapper
 *
 *  Uses the native "pgsql" extension (pg_* functions). Not a PDO wrapper.
 *  API: connect, query (with "?" placeholders), fetch_*, insert, insert_bulk, insert_update, update,
 *  delete, select, dlookup, dcount, dmax, dmin, dsum, escape, raw, transactions, query log.
 *
 *  PostgreSQL specifics:
 *   - identifiers are quoted with double quotes
 *   - insert_id() relies on lastval(), so it works only for serial/identity columns
 *   - insert_update() needs the conflict columns (ON CONFLICT)
 *   - "insert ignore" is ON CONFLICT DO NOTHING
 *   - no result caching, no debug console (only a simple query log)
 *
 *  Requirements: PHP 7.4+ with the pgsql extension.
 *
 *  @copyright (C) 2026 Giuseppe Solla
 *  @license   https://www.gnu.org/licenses/gpl-3.0.txt GNU GENERAL PUBLIC LICENSE v3
 *  SPDX-License-Identifier: GPL-3.0-or-later
 *
 *  This program is free software: you can redistribute it and/or modify it under the terms of the
 *  GNU General Public License as published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 *  even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 *  General Public License for more details. You should have received a copy of it along with this
 *  program (file LICENSE); if not, see <https://www.gnu.org/licenses/>.
 */
class MCPDB_Pg
{
    /** if TRUE, errors throw a RuntimeException; if FALSE, query() returns FALSE and sets $last_error */
    public $halt_on_errors = true;

    /** if TRUE, every executed query is stored in $queries (see get_queries()) */
    public $debug = false;

    /** @var string|null */
    public $last_error = null;

    /** @var array */
    public $queries = [];

    /** @var resource|\PgSql\Connection|null */
    private $connection = null;

    /** @var resource|\PgSql\Result|false|null */
    private $last_result = null;

    /** @var string|null */
    private $last_query = null;

    // ------------------------------------------------------------------ connection

    /**
     *  @param string $host
     *  @param string $user
     *  @param string $password
     *  @param string $database
     *  @param int    $port
     *  @param string $charset   client encoding
     *  @return $this
     */
    public function connect($host, $user, $password, $database, $port = 5432, $charset = 'UTF8')
    {
        $dsn = sprintf(
            "host='%s' port='%d' dbname='%s' user='%s' password='%s' options='--client_encoding=%s'",
            addcslashes($host, "'\\"), (int)$port, addcslashes($database, "'\\"),
            addcslashes($user, "'\\"), addcslashes($password, "'\\"), addcslashes($charset, "'\\ ")
        );

        $this->connection = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if (!$this->connection) {
            $this->connection = null;
            return $this->error('Could not connect to the PostgreSQL server');
        }

        return $this;
    }

    public function close()
    {
        if ($this->connection) {
            pg_close($this->connection);
        }
        $this->connection = null;
        $this->last_result = null;
    }

    public function get_link()
    {
        return $this->connection;
    }

    // ------------------------------------------------------------------ querying

    /**
     *  Runs a query. Every "?" (outside of quoted strings/identifiers/comments) is replaced with the
     *  escaped corresponding value from $replacements. Arrays expand to a comma separated list (for IN).
     *
     *  @return resource|\PgSql\Result|false
     */
    public function query($sql, $replacements = [])
    {
        if (!$this->connected_or_fail()) {
            return false;
        }

        $sql = $this->bind($sql, $this->as_list($replacements));

        return $sql === false ? false : $this->execute($sql);
    }

    /** Executes already-bound SQL; callers have already checked the connection */
    private function execute($sql)
    {
        $result = $this->run($sql);
        $this->last_query = $sql;

        if ($result === false) {
            $this->last_result = false;
            return $this->error(pg_last_error($this->connection), $sql);
        }

        $this->last_error = null;

        return $this->last_result = $result;
    }

    /** Sends SQL to the server, timing and logging it; leaves last_result, last_query and last_error alone */
    private function run($sql)
    {
        $start = microtime(true);
        $result = @pg_query($this->connection, $sql);
        $elapsed = microtime(true) - $start;

        if ($this->debug) {
            $this->queries[] = [
                'query' => $sql,
                'time'  => $elapsed,
                'rows'  => $result ? $this->rows_of($result) : null,
                'error' => $result ? null : pg_last_error($this->connection),
            ];
        }

        return $result;
    }

    /** Shorthand: SELECT with optional WHERE/ORDER. Returns the result resource. */
    public function select($columns, $table, $where = '', $replacements = [], $order = '', $limit = null)
    {
        $sql = 'SELECT ' . ($columns === '' || $columns === null ? '*' : $columns) . ' FROM ' . $this->identifier($table);

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        return $this->query($sql, $replacements);
    }

    // ------------------------------------------------------------------ fetching
    // all fetch methods work on the last result unless a $resource is given

    public function fetch_assoc($resource = '')
    {
        $r = $this->res($resource);
        return $r ? (pg_fetch_assoc($r) ?: false) : false;
    }

    public function fetch_obj($resource = '')
    {
        $r = $this->res($resource);
        return $r ? (pg_fetch_object($r) ?: false) : false;
    }

    public function fetch_row($resource = '')
    {
        $r = $this->res($resource);
        return $r ? (pg_fetch_row($r) ?: false) : false;
    }

    /**
     *  Fetches all rows as associative arrays. If $index is given, that column's values become the keys.
     */
    public function fetch_assoc_all($index = '', $resource = '')
    {
        $r = $this->res($resource);
        if (!$r) {
            return [];
        }

        $rows = pg_fetch_all($r) ?: [];

        if ($index !== '') {
            $keyed = [];
            foreach ($rows as $row) {
                $keyed[$row[$index]] = $row;
            }
            return $keyed;
        }

        return $rows;
    }

    public function fetch_obj_all($index = '', $resource = '')
    {
        $r = $this->res($resource);
        if (!$r) {
            return [];
        }

        $rows = [];
        while ($o = pg_fetch_object($r)) {
            if ($index !== '') {
                $rows[$o->$index] = $o;
            } else {
                $rows[] = $o;
            }
        }
        return $rows;
    }

    /** Returns one column (the first by default) of all rows as a flat array. */
    public function fetch_column($column = 0, $resource = '')
    {
        $r = $this->res($resource);
        return $r ? (pg_fetch_all_columns($r, $column) ?: []) : [];
    }

    /** Moves the internal row pointer */
    public function seek($row, $resource = '')
    {
        $r = $this->res($resource);
        return $r ? pg_result_seek($r, $row) : false;
    }

    public function free_result($resource = '')
    {
        $r = $this->res($resource);
        return $r ? pg_free_result($r) : false;
    }

    /** Number of rows returned by a SELECT */
    public function returned_rows($resource = '')
    {
        $r = $this->res($resource);
        return $r ? pg_num_rows($r) : 0;
    }

    /** Number of rows affected by the last INSERT/UPDATE/DELETE */
    public function affected_rows($resource = '')
    {
        $r = $this->res($resource);
        return $r ? pg_affected_rows($r) : 0;
    }

    // ------------------------------------------------------------------ writing
    // the writers build already-escaped SQL and call execute() directly: running it through bind()
    // again would mistake a "?" inside a raw() expression (e.g. the JSONB operator) for a placeholder

    /**
     *  @param string $table
     *  @param array  $columns  column => value (wrap SQL expressions in $this->raw(), e.g. raw('NOW()'))
     *  @param bool   $ignore   TRUE => ON CONFLICT DO NOTHING
     *  @param string $returning optional RETURNING clause, e.g. 'id'; makes the result fetchable
     */
    public function insert($table, $columns, $ignore = false, $returning = '')
    {
        if (!$this->connected_or_fail()) {
            return false;
        }
        if (empty($columns)) {
            return $this->error('insert(): no columns given');
        }

        return $this->execute(
            $this->insert_sql($table, array_keys($columns), [$columns])
            . ($ignore ? ' ON CONFLICT DO NOTHING' : '')
            . ($returning !== '' ? ' RETURNING ' . $returning : '')
        );
    }

    /**
     *  Inserts many rows with a single query. $rows is an array of column => value arrays
     *  that must all have the same keys (in any order).
     */
    public function insert_bulk($table, $rows, $ignore = false)
    {
        if (!$this->connected_or_fail()) {
            return false;
        }
        if (empty($rows)) {
            return $this->error('insert_bulk(): no rows given');
        }

        $keys = array_keys(reset($rows));
        $expected = array_flip($keys);

        foreach ($rows as $n => $row) {
            if (count($row) !== count($keys) || array_diff_key($row, $expected)) {
                return $this->error('insert_bulk(): row ' . $n . ' does not have the same columns as the first row');
            }
        }

        return $this->execute(
            $this->insert_sql($table, $keys, $rows)
            . ($ignore ? ' ON CONFLICT DO NOTHING' : '')
        );
    }

    /**
     *  UPSERT. PostgreSQL needs to know what constitutes a conflict:
     *
     *  $db->insert_update('users', ['email' => $e, 'name' => $n], ['email']);
     *
     *  @param array $conflict_columns columns of the unique index / primary key
     *  @param array $update           columns to update on conflict (default: all non-conflict columns)
     */
    public function insert_update($table, $columns, $conflict_columns, $update = [])
    {
        if (!$this->connected_or_fail()) {
            return false;
        }

        if (empty($update)) {
            $update = array_diff_key($columns, array_flip($conflict_columns));
        }

        return $this->execute(
            $this->insert_sql($table, array_keys($columns), [$columns])
            . ' ON CONFLICT (' . implode(', ', array_map([$this, 'identifier'], $conflict_columns)) . ')'
            . ($update ? ' DO UPDATE SET ' . $this->set_clause($update) : ' DO NOTHING')
        );
    }

    public function update($table, $columns, $where = '', $replacements = [])
    {
        if (!$this->connected_or_fail()) {
            return false;
        }
        if (empty($columns)) {
            return $this->error('update(): no columns given');
        }

        $where = $this->where_clause($where, $replacements);
        if ($where === false) {
            return false;
        }

        return $this->execute('UPDATE ' . $this->identifier($table) . ' SET ' . $this->set_clause($columns) . $where);
    }

    public function delete($table, $where = '', $replacements = [])
    {
        if (!$this->connected_or_fail()) {
            return false;
        }

        $where = $this->where_clause($where, $replacements);
        if ($where === false) {
            return false;
        }

        return $this->execute('DELETE FROM ' . $this->identifier($table) . $where);
    }

    /**
     *  The id generated by the last INSERT on a serial/identity column in this session (or FALSE).
     *  Never throws and leaves the last result untouched, so affected_rows() still refers to the INSERT.
     */
    public function insert_id()
    {
        if (!$this->connected_or_fail()) {
            return false;
        }

        // inside a transaction a failing lastval() would abort it: the savepoint keeps it usable
        $in_transaction = pg_transaction_status($this->connection) === PGSQL_TRANSACTION_INTRANS;

        if ($in_transaction) {
            $this->run('SAVEPOINT mcpdb_insert_id');
        }

        $r = $this->run('SELECT lastval()');

        if ($in_transaction) {
            $this->run(($r ? 'RELEASE SAVEPOINT' : 'ROLLBACK TO SAVEPOINT') . ' mcpdb_insert_id');
        }

        $row = $r ? pg_fetch_row($r) : false;
        return $row ? (int)$row[0] : false;
    }

    // ------------------------------------------------------------------ "d" functions

    /** Returns a single value from the first matching row (or FALSE) */
    public function dlookup($column, $table, $where = '', $replacements = [])
    {
        $r = $this->select($column, $table, $where, $replacements, '', 1);
        if (!$r) {
            return false;
        }
        $row = pg_fetch_row($r);
        return $row ? $row[0] : false;
    }

    /** COUNT as an integer, or FALSE on error (COUNT always returns a row, so FALSE cannot mean "none") */
    public function dcount($column, $table, $where = '', $replacements = [])
    {
        $count = $this->dlookup('COUNT(' . $column . ')', $table, $where, $replacements);
        return $count === false ? false : (int)$count;
    }

    public function dmax($column, $table, $where = '', $replacements = [])
    {
        return $this->dlookup('MAX(' . $column . ')', $table, $where, $replacements);
    }

    public function dmin($column, $table, $where = '', $replacements = [])
    {
        return $this->dlookup('MIN(' . $column . ')', $table, $where, $replacements);
    }

    public function dsum($column, $table, $where = '', $replacements = [])
    {
        return $this->dlookup('SUM(' . $column . ')', $table, $where, $replacements);
    }

    // ------------------------------------------------------------------ transactions

    public function transaction_start()
    {
        return $this->query('BEGIN');
    }

    /** Commits (default) or rolls back */
    public function transaction_complete($commit = true)
    {
        return $this->query($commit ? 'COMMIT' : 'ROLLBACK');
    }

    // ------------------------------------------------------------------ escaping / helpers

    /**
     *  Marks a piece of SQL to be used as is wherever a value is expected (insert, update, placeholders):
     *
     *  $db->update('pages', ['hits' => $db->raw('hits + 1'), 'seen_at' => $db->raw('NOW()')], 'id = ?', [$id]);
     *
     *  Never pass user input to raw(): it is not escaped.
     */
    public function raw($sql)
    {
        return new MCPDB_Pg_Raw($sql);
    }

    /** Escapes a string (without quotes) */
    public function escape($string)
    {
        if (!$this->connected_or_fail()) {
            return false;
        }
        return pg_escape_string($this->connection, (string)$string);
    }

    /** Turns a PHP value into a safe SQL literal */
    public function value($v)
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_int($v)) {
            return (string)$v;
        }
        if (is_float($v)) {
            // var_export gives the shortest exact form (e.g. 1.0E-20), which PostgreSQL accepts
            return is_finite($v) ? var_export($v, true) : 'NULL';
        }
        if (is_array($v)) {
            return implode(', ', array_map([$this, 'value'], $v));
        }
        if ($v instanceof MCPDB_Pg_Raw) {
            return (string)$v;
        }
        if (!$this->connected_or_fail()) {
            return false;
        }
        if ($v instanceof DateTimeInterface) {
            return pg_escape_literal($this->connection, $v->format('Y-m-d H:i:s.u'));
        }
        return pg_escape_literal($this->connection, (string)$v);
    }

    /** Quotes an identifier; "schema.table.column" and "*" are handled */
    public function identifier($name)
    {
        if (!$this->connected_or_fail()) {
            return false;
        }

        $parts = explode('.', $name);
        foreach ($parts as &$p) {
            $p = ($p === '*') ? '*' : pg_escape_identifier($this->connection, $p);
        }
        return implode('.', $parts);
    }

    public function get_queries()
    {
        return $this->queries;
    }

    public function get_last_query()
    {
        return $this->last_query;
    }

    // ------------------------------------------------------------------ internals

    /**
     *  Replaces "?" with escaped values, ignoring those inside '...', "...", $$...$$ / $tag$...$tag$
     *  and comments. On a count mismatch returns FALSE (or throws) and clears the last result, so that
     *  fetch_* cannot silently read the rows of a previous query.
     */
    private function bind($sql, array $values)
    {
        if (!$values && strpos($sql, '?') === false) {
            return $sql;
        }

        $out = '';
        $i = 0;
        $n = strlen($sql);
        $vi = 0;

        while ($i < $n) {
            // copy in one go everything up to the next character that may start a token of interest
            $plain = strcspn($sql, "'\"-/?\$", $i);
            if ($plain > 0) {
                $out .= substr($sql, $i, $plain);
                $i += $plain;
                if ($i >= $n) {
                    break;
                }
            }

            $c = $sql[$i];

            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $n) {
                    if ($sql[$j] === $c) {
                        if ($j + 1 < $n && $sql[$j + 1] === $c) { $j += 2; continue; }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
            } elseif ($c === '-' && substr($sql, $i, 2) === '--') {
                $j = strpos($sql, "\n", $i);
                $j = ($j === false) ? $n : $j;
                $out .= substr($sql, $i, $j - $i);
                $i = $j;
            } elseif ($c === '/' && substr($sql, $i, 2) === '/*') {
                $j = strpos($sql, '*/', $i + 2);
                $j = ($j === false) ? $n : $j + 2;
                $out .= substr($sql, $i, $j - $i);
                $i = $j;
            } elseif ($c === '$' && ($tag = $this->dollar_tag($sql, $i)) !== null) {
                $j = strpos($sql, $tag, $i + strlen($tag));
                $j = ($j === false) ? $n : $j + strlen($tag);
                $out .= substr($sql, $i, $j - $i);
                $i = $j;
            } elseif ($c === '?') {
                if (!array_key_exists($vi, $values)) {
                    $this->last_result = false;
                    return $this->error('Number of "?" placeholders does not match the number of replacements', $sql);
                }
                $out .= $this->value($values[$vi++]);
                $i++;
            } else {
                $out .= $c;
                $i++;
            }
        }

        if ($vi !== count($values)) {
            $this->last_result = false;
            return $this->error('Number of replacements does not match the number of "?" placeholders', $sql);
        }

        return $out;
    }

    /**
     *  Returns the dollar-quote delimiter ($$ or $tag$) starting at $i, or NULL.
     *  A "$" inside an identifier (PostgreSQL allows col$name) or a positional $1 is not a delimiter.
     */
    private function dollar_tag($sql, $i)
    {
        if ($i > 0 && preg_match('/[A-Za-z0-9_\x80-\xff]/', $sql[$i - 1])) {
            return null;
        }
        return preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $m, 0, $i) ? $m[0] : null;
    }

    /** Builds INSERT INTO table (keys) VALUES (...), (...) with every value escaped */
    private function insert_sql($table, array $keys, array $rows)
    {
        $values = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $k) {
                $line[] = $this->value($row[$k]);
            }
            $values[] = '(' . implode(', ', $line) . ')';
        }

        return 'INSERT INTO ' . $this->identifier($table)
            . ' (' . implode(', ', array_map([$this, 'identifier'], $keys)) . ')'
            . ' VALUES ' . implode(', ', $values);
    }

    /** Builds "col" = value, ... with every value escaped */
    private function set_clause(array $columns)
    {
        $set = [];
        foreach ($columns as $k => $v) {
            $set[] = $this->identifier($k) . ' = ' . $this->value($v);
        }
        return implode(', ', $set);
    }

    /** Returns ' WHERE ...' with placeholders bound, '' when there is no condition, FALSE on a bind error */
    private function where_clause($where, $replacements)
    {
        if ($where === '') {
            return '';
        }
        $where = $this->bind($where, $this->as_list($replacements));
        return $where === false ? false : ' WHERE ' . $where;
    }

    private function as_list($replacements)
    {
        if (!is_array($replacements)) {
            return ($replacements === '' || $replacements === null) ? [] : [$replacements];
        }
        return array_values($replacements);
    }

    private function res($resource)
    {
        return $resource !== '' && $resource !== null ? $resource : $this->last_result;
    }

    private function rows_of($result)
    {
        return pg_num_rows($result) ?: pg_affected_rows($result);
    }

    /** TRUE if connected; otherwise reports the error (throws, or returns FALSE with halt_on_errors off) */
    private function connected_or_fail()
    {
        if ($this->connection) {
            return true;
        }
        $this->last_result = false;
        return $this->error('Not connected to a database. Call connect() first');
    }

    private function error($message, $sql = null)
    {
        $this->last_error = $message;

        if ($this->halt_on_errors) {
            throw new RuntimeException($message . ($sql !== null ? "\nQuery: " . $sql : ''));
        }

        return false;
    }
}

/** A piece of SQL that MCPDB_Pg::value() emits verbatim. Create it with $db->raw(). */
class MCPDB_Pg_Raw
{
    /** @var string */
    private $sql;

    public function __construct($sql)
    {
        $this->sql = (string)$sql;
    }

    public function __toString()
    {
        return $this->sql;
    }
}
