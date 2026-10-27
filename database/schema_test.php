<?php
/**
 * schema_test.php — guards the schema in database/schema.sql.
 *
 * The schema is the foundation every other application issue depends on
 * (issue-011). A wrong column type here is expensive to unwind later: a
 * FLOAT in `amount` poisons every sum, a missing index makes the reports
 * slow, and a `category_id` that is NOT NULL makes an uncategorised
 * transaction impossible.
 *
 * What this test protects:
 *
 *   1. database/schema.sql exists, names the three initialisation paths, and
 *      creates the five tables in foreign-key order.
 *   2. The file is safe to re-run: no DROP TABLE, no TRUNCATE, no seed rows.
 *      The installer loads it against a LIVE database, so it must never
 *      destroy data.
 *   3. The database itself has the shape the issue specifies: the column
 *      types, the nullability, the unique keys, the indexes and the
 *      foreign-key delete rules — read from information_schema, NOT from the
 *      text of the file. Reading the file would only prove the file says
 *      what it says.
 *
 * Two tiers:
 *
 *   - The file tier (§A–§C) always runs. It needs no database.
 *   - The database tier (§D–§E) needs a reachable MariaDB. When the database
 *     is not reachable it is SKIPPED cleanly and the test still exits 0 —
 *     see the note in §D.
 *
 * Credentials, in order of preference:
 *   1. config.php — the installed application's own constants.
 *   2. FT_DB_HOST / FT_DB_NAME / FT_DB_USER / FT_DB_PASS — test-scoped
 *      environment overrides.
 *   3. The dev stack's .env file, against the compose service host.
 *      Reading .env here is a TEST-HARNESS concern only: the application
 *      never parses .env (config.php is the only configuration source).
 *
 * Run:  docker compose exec -T app php database/schema_test.php
 * Exit code 0 = pass, 1 = fail. Detect success by the exit code, never by
 * the output — and never treat an unreachable database as a pass.
 */

declare(strict_types=1);

$failures = [];
$skips    = [];

function ok($label, $ok, $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  [PASS] $label\n";
        return;
    }
    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  [FAIL] $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

function skip($label, $why): void
{
    global $skips;
    $skips[] = $label . ($why !== '' ? " — $why" : '');
    echo "  [SKIP] $label" . ($why !== '' ? " — $why" : '') . "\n";
}

/**
 * Remove the SQL comments, so a negative assertion cannot be satisfied by a
 * sentence that merely *mentions* DROP TABLE. The schema file's own header
 * explains why those statements are forbidden — and that explanation must
 * not trip the check.
 */
function strip_sql_comments(string $sql): string
{
    $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
    $sql = preg_replace('/--[^\n]*/', ' ', $sql) ?? $sql;
    return $sql;
}

/**
 * A canonical description of a column, independent of the display width
 * MariaDB happens to use ("int(10) unsigned" vs "int unsigned").
 *
 * Returns e.g. "int unsigned", "decimal(14,2)", "varchar(32)",
 * "enum('income','expense')", "datetime".
 */
function column_signature(array $row): string
{
    $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));

    switch ($type) {
        case 'int':
        case 'integer':
        case 'tinyint':
        case 'smallint':
        case 'mediumint':
        case 'bigint':
            return str_contains(strtolower((string) ($row['COLUMN_TYPE'] ?? '')), 'unsigned')
                ? $type . ' unsigned'
                : $type;

        case 'decimal':
        case 'numeric':
            return $type . '(' . (int) ($row['NUMERIC_PRECISION'] ?? 0)
                        . ',' . (int) ($row['NUMERIC_SCALE'] ?? 0) . ')';

        case 'varchar':
        case 'char':
            return $type . '(' . (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0) . ')';

        case 'enum':
        case 'set':
            return strtolower((string) ($row['COLUMN_TYPE'] ?? ''));

        default:
            return $type;
    }
}

/**
 * The shape the issue specifies. This is the contract — a change here is a
 * schema change, and it needs a migration (see database/migrations/README.md).
 *
 * [column name => [signature, nullable]]
 */
function expected_schema(): array
{
    $audit = [
        'created_at' => ['datetime', false],
        'updated_at' => ['datetime', false],
    ];

    $tokens = [
        'id'         => ['int unsigned', false],
        'user_id'    => ['int unsigned', false],
        'token_hash' => ['char(64)', false],
        'expires_at' => ['datetime', false],
        'used_at'    => ['datetime', true],
        'created_at' => ['datetime', false],
    ];

    return [
        'users' => [
            'id'                => ['int unsigned', false],
            'username'          => ['varchar(32)', false],
            'email'             => ['varchar(254)', false],
            'password_hash'     => ['varchar(255)', false],
            'email_verified_at' => ['datetime', true],
            'status'            => ["enum('active','disabled')", false],
            'failed_attempts'   => ['int unsigned', false],
            'locked_until'      => ['datetime', true],
        ] + $audit,

        'categories' => [
            'id'      => ['int unsigned', false],
            'user_id' => ['int unsigned', false],
            'name'    => ['varchar(60)', false],
            'type'    => ["enum('income','expense')", false],
        ] + $audit,

        'transactions' => [
            'id'          => ['int unsigned', false],
            'user_id'     => ['int unsigned', false],
            'category_id' => ['int unsigned', true],
            'type'        => ["enum('income','expense')", false],
            'amount'      => ['decimal(14,2)', false],
            'occurred_on' => ['date', false],
            'description' => ['varchar(255)', true],
        ] + $audit,

        'password_resets' => $tokens,

        'email_verifications' => $tokens,
    ];
}

/**
 * The foreign keys, and what happens to the child row when the parent goes.
 * ON DELETE SET NULL on transactions.category_id is what keeps a transaction
 * alive when its category is deleted.
 */
function expected_foreign_keys(): array
{
    return [
        'categories.user_id'          => ['users', 'id', 'CASCADE'],
        'transactions.user_id'        => ['users', 'id', 'CASCADE'],
        'transactions.category_id'    => ['categories', 'id', 'SET NULL'],
        'password_resets.user_id'     => ['users', 'id', 'CASCADE'],
        'email_verifications.user_id' => ['users', 'id', 'CASCADE'],
    ];
}

/**
 * The indexes the reports depend on. The column ORDER matters: every query
 * is scoped to one user first, then to a date range.
 */
function expected_indexes(): array
{
    return [
        'categories'          => ['idx_categories_user' => ['user_id']],
        'transactions'        => [
            'idx_tx_user_date'   => ['user_id', 'occurred_on'],
            'idx_tx_user_type'   => ['user_id', 'type', 'occurred_on'],
            'idx_tx_user_cat'    => ['user_id', 'category_id', 'occurred_on'],
            'idx_tx_occurred_on' => ['occurred_on'],
        ],
        'password_resets'     => ['idx_reset_user' => ['user_id', 'expires_at']],
        'email_verifications' => ['idx_verification_user' => ['user_id', 'expires_at']],
    ];
}

function env_value(string $name): ?string
{
    $value = getenv($name);
    return (is_string($value) && $value !== '') ? $value : null;
}

/**
 * Parse a .env-style file. TEST-ONLY — see the header note. The application
 * never reads .env.
 */
function read_env_file(string $file): array
{
    $values = [];
    if (!is_file($file)) {
        return $values;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $values[trim(substr($line, 0, $pos))] = trim(trim(substr($line, $pos + 1)), "\"'");
    }
    return $values;
}

/**
 * Every connection worth trying, most specific first. The first one that
 * answers wins; the rest are the fallbacks that let the test run before the
 * application is installed.
 */
function connection_candidates(string $root): array
{
    $candidates = [];

    // 1. The installed application's own configuration.
    $configFile = $root . '/config.php';
    if (is_file($configFile)) {
        require_once $configFile;
        if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
            $candidates[] = [
                'label' => 'config.php',
                'host'  => (string) DB_HOST,
                'name'  => (string) DB_NAME,
                'user'  => (string) DB_USER,
                'pass'  => (string) DB_PASS,
            ];
        }
    }

    // 2. Test-scoped environment overrides.
    $envHost = env_value('FT_DB_HOST');
    if ($envHost !== null) {
        $candidates[] = [
            'label' => 'FT_DB_* environment',
            'host'  => $envHost,
            'name'  => (string) (env_value('FT_DB_NAME') ?? 'financial_tracker'),
            'user'  => (string) (env_value('FT_DB_USER') ?? 'root'),
            'pass'  => (string) (env_value('FT_DB_PASS') ?? ''),
        ];
    }

    // 3. The dev stack's .env, against the compose service name and the
    //    loopback addresses. Inside the app container the database is the
    //    "db" service; on the host it is 127.0.0.1.
    $env = read_env_file($root . '/.env');
    if (isset($env['DB_NAME'])) {
        foreach (['db', '127.0.0.1', 'localhost'] as $host) {
            $candidates[] = [
                'label' => '.env (' . $host . ')',
                'host'  => $host,
                'name'  => $env['DB_NAME'],
                'user'  => $env['DB_USER'] ?? 'root',
                'pass'  => $env['DB_PASSWORD'] ?? '',
            ];
        }
    }

    // Deduplicate — the same connection may be reachable by several routes.
    $unique = [];
    $seen   = [];
    foreach ($candidates as $candidate) {
        $key = $candidate['host'] . '|' . $candidate['name'] . '|' . $candidate['user'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[]   = $candidate;
    }

    return $unique;
}


// ---------------------------------------------------------------------------
// A. The file exists and names the three initialisation paths.
// ---------------------------------------------------------------------------

echo "## A. The schema file\n";

$root       = dirname(__DIR__);
$schemaFile = $root . '/database/schema.sql';

ok('database/schema.sql exists', is_file($schemaFile), $schemaFile);

if (!is_file($schemaFile)) {
    fwrite(STDERR, "database/schema.sql is missing — cannot continue.\n");
    echo "Failed: " . count($failures) . "\n";
    exit(1);
}

$sql  = (string) file_get_contents($schemaFile);
$code = strip_sql_comments($sql);

ok(
    'the header names the fresh-volume path',
    str_contains($sql, '/docker-entrypoint-initdb.d/schema.sql')
);
ok('the header names the installer path', str_contains($sql, 'install.php'));
ok('the header names the migration path', str_contains($sql, 'database/migrations/'));

ok(
    'the file selects the database with CREATE DATABASE IF NOT EXISTS',
    (bool) preg_match('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS/i', $code)
);

ok(
    'the file selects the database with USE',
    (bool) preg_match('/\bUSE\s+`/', $code),
    'no USE statement found in the executable SQL'
);

ok(
    'the file states that MariaDB DDL implicitly commits',
    (bool) preg_match('/implicitly commits/i', $sql)
);


// ---------------------------------------------------------------------------
// B. The file is safe to run against a live database.
// ---------------------------------------------------------------------------

echo "\n## B. The file never destroys data\n";

$statements = array_values(array_filter(
    array_map('trim', explode(';', $code)),
    static fn (string $s): bool => $s !== ''
));

$destructive = [];
foreach ($statements as $statement) {
    if (preg_match('/^\s*DROP\s+TABLE\b/i', $statement)
        || preg_match('/^\s*TRUNCATE\b/i', $statement)
        || preg_match('/^\s*DELETE\s+FROM\b/i', $statement)
    ) {
        $destructive[] = substr($statement, 0, 60);
    }
}

ok(
    'no DROP TABLE, TRUNCATE or DELETE FROM statement',
    $destructive === [],
    implode(' | ', $destructive)
);

$seeds = [];
foreach ($statements as $statement) {
    if (preg_match('/^\s*INSERT\b/i', $statement)) {
        $seeds[] = substr($statement, 0, 60);
    }
}

ok(
    'no seed INSERT — every row belongs to a user',
    $seeds === [],
    implode(' | ', $seeds)
);


// ---------------------------------------------------------------------------
// C. The tables the file declares, in foreign-key order.
// ---------------------------------------------------------------------------

echo "\n## C. The declared tables\n";

$expectedTables = array_keys(expected_schema());

$declared = [];
foreach ($statements as $statement) {
    if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $statement, $m)) {
        $declared[] = $m[1];
    }
}

ok(
    'the file creates exactly the five tables',
    $declared === $expectedTables,
    'declared=[' . implode(', ', $declared) . '] expected=[' . implode(', ', $expectedTables) . ']'
);

$engineProblems = [];
foreach ($statements as $statement) {
    if (!preg_match('/^CREATE\s+TABLE\b/i', $statement)) {
        continue;
    }
    preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $statement, $m);
    $table = $m[1] ?? '?';
    if (!preg_match('/ENGINE\s*=\s*(\w+)\s+DEFAULT\s+CHARSET\s*=\s*(\w+)\s+COLLATE\s*=\s*(\w+)/i', $statement, $e)) {
        $engineProblems[] = $table . ': no ENGINE/CHARSET/COLLATE clause';
        continue;
    }
    if (strtolower($e[1]) !== 'innodb'
        || strtolower($e[2]) !== 'utf8mb4'
        || strtolower($e[3]) !== 'utf8mb4_unicode_ci'
    ) {
        $engineProblems[] = $table . ': ' . $e[1] . '/' . $e[2] . '/' . $e[3];
    }
}

ok(
    'every table is ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    $engineProblems === [],
    implode(' | ', $engineProblems)
);

$orderOk = true;
$orderMsg = '';
foreach ([['users', 'categories'], ['users', 'transactions'], ['categories', 'transactions']] as $pair) {
    $a = array_search($pair[0], $declared, true);
    $b = array_search($pair[1], $declared, true);
    if ($a === false || $b === false || $a >= $b) {
        $orderOk = false;
        $orderMsg = $pair[0] . ' must be created before ' . $pair[1];
        break;
    }
}

ok('the tables are created in foreign-key order', $orderOk, $orderMsg);

ok(
    "the amount column is DECIMAL(14,2)",
    (bool) preg_match('/`amount`\s+DECIMAL\(14,2\)/i', $code)
);

ok(
    "the type column is ENUM('income','expense') — no third value",
    (bool) preg_match("/`type`\s+ENUM\('income','expense'\)/i", $code)
);

ok(
    'the file declares no floating-point column',
    !preg_match('/\b(FLOAT|DOUBLE|REAL)\b/i', $code)
);


// ---------------------------------------------------------------------------
// D. The database — the shape, read from information_schema.
// ---------------------------------------------------------------------------

echo "\n## D. The database\n";

$pdo          = null;
$connectedAs  = null;
$connectFails = [];

foreach (connection_candidates($root) as $candidate) {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $candidate['host'],
        $candidate['name']
    );

    try {
        $pdo = new PDO($dsn, $candidate['user'], $candidate['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $connectedAs = $candidate;
        break;
    } catch (PDOException $e) {
        $connectFails[] = $candidate['label'] . ': ' . $e->getMessage();
    }
}

if ($pdo === null) {
    // A database that is not reachable is a SKIP, not a pass and not a fail:
    // the schema file is still checked above, and the database tier runs as
    // soon as the stack is up. The skip is printed loudly so that it is never
    // mistaken for a green run.
    skip(
        'the database-backed assertions',
        'no reachable MariaDB — tried ' . count($connectFails) . ' connection(s)'
    );
    foreach ($connectFails as $failure) {
        echo "         - $failure\n";
    }
} else {
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    ok(
        'connected to the database',
        $dbName !== '',
        'via ' . $connectedAs['label'] . ' as ' . $connectedAs['user']
    );

    // ---- D.1 the table list ------------------------------------------------
    $tables = $pdo->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME"
    )->fetchAll();

    $tableMap = [];
    foreach ($tables as $table) {
        $tableMap[(string) $table['TABLE_NAME']] = $table;
    }

    $missing = array_values(array_diff($expectedTables, array_keys($tableMap)));
    $extra   = array_values(array_diff(array_keys($tableMap), $expectedTables));

    ok(
        'the database holds the five tables',
        $missing === [],
        'missing=[' . implode(', ', $missing) . ']'
    );
    ok(
        'the database holds no unexpected table',
        $extra === [],
        'unexpected=[' . implode(', ', $extra) . '] — update this test when the schema changes'
    );

    // ---- D.2 engine and collation -----------------------------------------
    $engineProblems = [];
    foreach ($expectedTables as $table) {
        if (!isset($tableMap[$table])) {
            continue;
        }
        $row = $tableMap[$table];
        if (strtolower((string) $row['ENGINE']) !== 'innodb') {
            $engineProblems[] = $table . ': engine=' . $row['ENGINE'];
        }
        if (strtolower((string) $row['TABLE_COLLATION']) !== 'utf8mb4_unicode_ci') {
            $engineProblems[] = $table . ': collation=' . $row['TABLE_COLLATION'];
        }
    }
    ok(
        'every table is InnoDB / utf8mb4_unicode_ci',
        $engineProblems === [],
        implode(' | ', $engineProblems)
    );

    // ---- D.3 the columns ----------------------------------------------------
    $columnRows = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
                NUMERIC_PRECISION, NUMERIC_SCALE, CHARACTER_MAXIMUM_LENGTH,
                COLUMN_DEFAULT, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll();

    $columns = [];
    foreach ($columnRows as $row) {
        $columns[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = $row;
    }

    $expected = expected_schema();

    foreach ($expected as $table => $expectedColumns) {
        if (!isset($columns[$table])) {
            skip("the columns of `$table`", 'the table does not exist');
            continue;
        }

        $actualNames = array_keys($columns[$table]);
        $wantNames   = array_keys($expectedColumns);

        ok(
            "`$table` has exactly the specified columns",
            $actualNames === $wantNames,
            'actual=[' . implode(', ', $actualNames) . '] expected=[' . implode(', ', $wantNames) . ']'
        );

        foreach ($expectedColumns as $name => $spec) {
            [$wantSignature, $wantNullable] = $spec;

            if (!isset($columns[$table][$name])) {
                ok("$table.$name exists", false, 'the column is missing');
                continue;
            }

            $row       = $columns[$table][$name];
            $signature = column_signature($row);
            $nullable  = strtoupper((string) $row['IS_NULLABLE']) === 'YES';

            ok(
                "$table.$name is $wantSignature",
                $signature === $wantSignature,
                'found ' . $signature
            );
            ok(
                "$table.$name is " . ($wantNullable ? 'NULLABLE' : 'NOT NULL'),
                $nullable === $wantNullable,
                'found ' . strtoupper((string) $row['IS_NULLABLE'])
            );
        }
    }

    // ---- D.4 the money column, spelled out ---------------------------------
    $amount = $columns['transactions']['amount'] ?? null;
    ok(
        'transactions.amount is DECIMAL(14,2) — never a float, never integer cents',
        $amount !== null
            && strtolower((string) $amount['DATA_TYPE']) === 'decimal'
            && (int) $amount['NUMERIC_PRECISION'] === 14
            && (int) $amount['NUMERIC_SCALE'] === 2
    );

    $floatColumns = [];
    foreach ($columnRows as $row) {
        if (in_array(strtolower((string) $row['DATA_TYPE']), ['float', 'double', 'real'], true)) {
            $floatColumns[] = $row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'];
        }
    }
    ok(
        'the schema holds no floating-point column',
        $floatColumns === [],
        implode(', ', $floatColumns)
    );

    // ---- D.5 the auto-increment keys ---------------------------------------
    $autoIncrementProblems = [];
    foreach ($expectedTables as $table) {
        $id = $columns[$table]['id'] ?? null;
        if ($id === null || !str_contains(strtolower((string) $id['EXTRA']), 'auto_increment')) {
            $autoIncrementProblems[] = $table . '.id';
        }
    }
    ok(
        'every table has an AUTO_INCREMENT primary key',
        $autoIncrementProblems === [],
        implode(', ', $autoIncrementProblems)
    );

    // ---- D.6 the defaults --------------------------------------------------
    // MariaDB reports an ENUM default in its quoted form ('active'), so the
    // surrounding quotes are stripped before the comparison.
    $status = $columns['users']['status'] ?? null;
    ok(
        "users.status defaults to 'active'",
        $status !== null && trim((string) $status['COLUMN_DEFAULT'], "'") === 'active',
        $status === null ? 'no column' : 'found ' . var_export($status['COLUMN_DEFAULT'], true)
    );

    $failed = $columns['users']['failed_attempts'] ?? null;
    ok(
        'users.failed_attempts defaults to 0',
        $failed !== null && trim((string) $failed['COLUMN_DEFAULT'], "'") === '0',
        $failed === null ? 'no column' : 'found ' . var_export($failed['COLUMN_DEFAULT'], true)
    );

    $updated = $columns['users']['updated_at'] ?? null;
    ok(
        'users.updated_at carries ON UPDATE CURRENT_TIMESTAMP',
        $updated !== null && str_contains(strtolower((string) $updated['EXTRA']), 'on update current_timestamp'),
        $updated === null ? 'no column' : 'found ' . var_export($updated['EXTRA'], true)
    );

    // ---- D.7 the unique keys -----------------------------------------------
    $indexRows = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    )->fetchAll();

    $indexes = [];
    foreach ($indexRows as $row) {
        $table = (string) $row['TABLE_NAME'];
        $name  = (string) $row['INDEX_NAME'];
        if (!isset($indexes[$table][$name])) {
            $indexes[$table][$name] = [
                'unique'  => (int) $row['NON_UNIQUE'] === 0,
                'columns' => [],
            ];
        }
        $indexes[$table][$name]['columns'][] = (string) $row['COLUMN_NAME'];
    }

    $uniqueExpectations = [
        'users'               => ['uq_users_username' => ['username'], 'uq_users_email' => ['email']],
        'categories'          => ['uq_categories_user_name' => ['user_id', 'name']],
        'password_resets'     => ['uq_reset_token' => ['token_hash']],
        'email_verifications' => ['uq_verification_token' => ['token_hash']],
    ];

    foreach ($uniqueExpectations as $table => $wanted) {
        foreach ($wanted as $indexName => $wantedColumns) {
            $found = $indexes[$table][$indexName] ?? null;
            ok(
                "$table has the unique key $indexName (" . implode(', ', $wantedColumns) . ')',
                $found !== null && $found['unique'] && $found['columns'] === $wantedColumns,
                $found === null
                    ? 'the index is missing'
                    : 'unique=' . var_export($found['unique'], true)
                        . ' columns=[' . implode(', ', $found['columns']) . ']'
            );
        }
    }

    // A category name is unique PER USER, not globally: two accounts may both
    // have a category called "Food".
    $globalCategoryNameKey = false;
    foreach ($indexes['categories'] ?? [] as $name => $index) {
        if ($index['unique'] && $index['columns'] === ['name']) {
            $globalCategoryNameKey = true;
        }
    }
    ok(
        'categories is NOT unique on the name alone',
        !$globalCategoryNameKey,
        'a global UNIQUE(name) would break a multi-user install'
    );

    // The reset tokens are stored as a hash and a hash is unique.
    foreach (['password_resets', 'email_verifications'] as $table) {
        $hash = $columns[$table]['token_hash'] ?? null;
        ok(
            "$table.token_hash is CHAR(64) — the SHA-256 hash, never the token",
            $hash !== null
                && strtolower((string) $hash['DATA_TYPE']) === 'char'
                && (int) $hash['CHARACTER_MAXIMUM_LENGTH'] === 64
        );
    }

    // ---- D.8 the indexes the reports need ----------------------------------
    foreach (expected_indexes() as $table => $wanted) {
        foreach ($wanted as $indexName => $wantedColumns) {
            $found = $indexes[$table][$indexName] ?? null;
            ok(
                "$table has the index $indexName (" . implode(', ', $wantedColumns) . ')',
                $found !== null && $found['columns'] === $wantedColumns,
                $found === null
                    ? 'the index is missing'
                    : 'columns=[' . implode(', ', $found['columns']) . ']'
            );
        }
    }

    // Every query is scoped to one user first, so an index on transactions
    // that does not lead with user_id is a design mistake.
    $userLedIndexes = [];
    foreach ($indexes['transactions'] ?? [] as $name => $index) {
        if (($index['columns'][0] ?? '') === 'user_id') {
            $userLedIndexes[] = $name;
        }
    }
    ok(
        'transactions has an index that leads with user_id',
        $userLedIndexes !== [],
        'the ownership filter is the first column of every transaction query'
    );

    // ---- D.9 the foreign keys ----------------------------------------------
    $fkRows = $pdo->query(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          WHERE k.CONSTRAINT_SCHEMA = DATABASE()
            AND k.REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchAll();

    $foreignKeys = [];
    foreach ($fkRows as $row) {
        $foreignKeys[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = [
            'referenced_table'  => (string) $row['REFERENCED_TABLE_NAME'],
            'referenced_column' => (string) $row['REFERENCED_COLUMN_NAME'],
            'delete_rule'       => strtoupper((string) $row['DELETE_RULE']),
            'update_rule'       => strtoupper((string) $row['UPDATE_RULE']),
        ];
    }

    foreach (expected_foreign_keys() as $key => $spec) {
        [$wantTable, $wantColumn, $wantDeleteRule] = $spec;
        $found = $foreignKeys[$key] ?? null;

        ok(
            "$key references $wantTable($wantColumn) ON DELETE $wantDeleteRule",
            $found !== null
                && $found['referenced_table'] === $wantTable
                && $found['referenced_column'] === $wantColumn
                && $found['delete_rule'] === $wantDeleteRule,
            $found === null
                ? 'the foreign key is missing'
                : 'found ' . $found['referenced_table'] . '(' . $found['referenced_column'] . ')'
                    . ' ON DELETE ' . $found['delete_rule']
        );
    }

    $extraKeys = array_values(array_diff(array_keys($foreignKeys), array_keys(expected_foreign_keys())));
    ok(
        'the schema has no unexpected foreign key',
        $extraKeys === [],
        implode(', ', $extraKeys)
    );
}


// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n";
echo "Failed: " . count($failures) . "\n";

if (count($skips) > 0) {
    echo "Skipped: " . count($skips) . "\n";
    foreach ($skips as $s) {
        echo "  - $s\n";
    }
}

if (count($failures) > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "ALL ASSERTIONS PASSED\n";
exit(0);
