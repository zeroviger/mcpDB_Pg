<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Giuseppe Solla
 */
declare(strict_types=1);

// Parametri di connessione. I default sono quelli del database locale di
// DDEV (non sono credenziali reali); altrove si impostano con le variabili d'ambiente standard PG*.
define('DB_HOST', getenv('PGHOST') ?: 'db');
define('DB_USER', getenv('PGUSER') ?: 'db');
define('DB_PASS', getenv('PGPASSWORD') ?: 'db');
define('DB_NAME', getenv('PGDATABASE') ?: 'db');
define('DB_PORT', (int) (getenv('PGPORT') ?: 5432));
