<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Giuseppe Solla
 */
declare(strict_types=1);

require_once __DIR__ . '/../MCPDB_Pg.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/demo_functions.php';

$db = new MCPDB_Pg();
$db->debug = true;

$demos = require __DIR__ . '/demo_cases.php';
$runs = [];
$stopped = false;

foreach ($demos as $index => $demo) {
    if ($stopped) {
        $runs[$index] = null;
        continue;
    }
    $runs[$index] = runDemo($demo, $db);
    $stopped = $runs[$index]['error'] !== null && !empty($demo['critical']);
}

$okCount = count(array_filter($runs, fn (?array $run): bool => $run !== null && $run['error'] === null));
$queryCount = count($db->queries);
$queryTime = array_sum(array_column($db->queries, 'time'));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCPDB_Pg Demo</title>
    <link rel="stylesheet" href="assets/css/demo-style.css">
</head>
<body>

<header class="page-head">
    <div class="page-head-inner">
        <p class="eyebrow">PostgreSQL · estensione pgsql · nessuna dipendenza</p>
        <h1>MCPDB_Pg</h1>
        <p class="lead">
            Ogni sezione esegue codice vero sul database PostgreSQL di DDEV. Sotto trovi il codice PHP,
            la query SQL che la libreria ha generato e il risultato.
        </p>
        <ul class="stats">
            <li><strong><?= $okCount ?>/<?= count($demos) ?></strong> demo riuscite</li>
            <li><strong><?= $queryCount ?></strong> query eseguite</li>
            <li><strong><?= formatMs($queryTime) ?> ms</strong> tempo totale</li>
        </ul>
    </div>
</header>

<div class="layout">

    <nav class="toc" aria-label="Indice delle demo">
        <ol>
            <?php foreach ($demos as $index => $demo): ?>
                <?php $run = $runs[$index]; ?>
                <li class="<?= $run === null ? 'is-skipped' : ($run['error'] === null ? 'is-ok' : 'is-error') ?>">
                    <a href="#<?= htmlspecialchars($demo['id']) ?>"><?= htmlspecialchars($demo['title']) ?></a>
                </li>
            <?php endforeach; ?>
            <li><a href="#query-log">Log delle query</a></li>
        </ol>
    </nav>

    <main class="demos">

        <?php foreach ($demos as $index => $demo): ?>
            <?php $run = $runs[$index]; ?>
            <section class="demo-card" id="<?= htmlspecialchars($demo['id']) ?>">
                <header class="demo-head">
                    <span class="demo-num"><?= sprintf('%02d', $index + 1) ?></span>
                    <h2><?= htmlspecialchars($demo['title']) ?></h2>
                    <?php if ($run === null): ?>
                        <span class="badge badge-skipped">non eseguita</span>
                    <?php elseif ($run['error'] === null): ?>
                        <span class="badge badge-ok"><?= formatMs($run['time']) ?> ms</span>
                    <?php else: ?>
                        <span class="badge badge-error">errore</span>
                    <?php endif; ?>
                </header>

                <p class="demo-text"><?= formatText($demo['text']) ?></p>

                <div class="demo-grid">
                    <div class="panel panel-code">
                        <div class="panel-title">Codice PHP</div>
                        <pre><code><?= htmlspecialchars(getClosureBody($demo['run'])) ?></code></pre>
                    </div>

                    <div class="panel-stack">
                        <div class="panel panel-sql">
                            <div class="panel-title">SQL inviato a PostgreSQL</div>
                            <?php if ($run === null || $run['queries'] === []): ?>
                                <p class="panel-empty">Nessuna query: tutto avviene in PHP.</p>
                            <?php else: ?>
                                <?php foreach ($run['queries'] as $query): ?>
                                    <div class="sql-item<?= $query['error'] !== null ? ' has-error' : '' ?>">
                                        <pre><code><?= htmlspecialchars(trim($query['query'])) ?></code></pre>
                                        <div class="sql-meta">
                                            <?= formatMs($query['time'], 2) ?> ms
                                            <?php if ($query['error'] !== null): ?>
                                                · <span class="sql-error"><?= htmlspecialchars(trim($query['error'])) ?></span>
                                            <?php else: ?>
                                                · <?= (int) $query['rows'] ?> righe
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="panel panel-result">
                            <div class="panel-title">Risultato</div>
                            <?php if ($run === null): ?>
                                <p class="panel-empty">Non eseguita: una demo precedente è fallita.</p>
                            <?php elseif ($run['error'] !== null): ?>
                                <pre class="result-error"><?= htmlspecialchars($run['error']) ?></pre>
                            <?php else: ?>
                                <?= renderPairs($run['result']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="demo-card" id="query-log">
            <header class="demo-head">
                <span class="demo-num">∑</span>
                <h2>Log delle query</h2>
            </header>
            <p class="demo-text">
                <?= formatText('Con `$db->debug = true` ogni query finisce in `get_queries()`, insieme al tempo, alle righe e all\'eventuale errore. Questa pagina usa il log per mostrare l\'SQL di ogni demo.') ?>
            </p>
            <div class="rows-wrap">
                <table class="rows query-log">
                    <thead>
                        <tr><th>#</th><th>ms</th><th>righe</th><th>query</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($db->get_queries() as $n => $query): ?>
                            <tr class="<?= $query['error'] !== null ? 'has-error' : '' ?>">
                                <td><?= $n + 1 ?></td>
                                <td><?= formatMs($query['time'], 2) ?></td>
                                <td><?= $query['error'] !== null ? 'errore' : (int) $query['rows'] ?></td>
                                <td><code><?= htmlspecialchars((string) preg_replace('/\s+/', ' ', trim($query['query']))) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

</body>
</html>
