<?php
/**
 * Database.php — the PDO singleton.
 *
 * The contract (docs/issues/issue-012.md, §4):
 *
 *   1. One connection per request, opened lazily on first use. A request that
 *      never touches the database never opens a socket.
 *   2. ERRMODE_EXCEPTION — a failed query throws. It is never a silent
 *      false: a silent false turns a broken query into an empty page.
 *   3. EMULATE_PREPARES = false — real prepared statements. This is the only
 *      defence against SQL injection that does not depend on the author
 *      remembering to escape a value.
 *   4. ATTR_STRINGIFY_FETCHES = false — a DECIMAL(14,2) comes back as a
 *      STRING, so money is never silently turned into a float on the way in.
 *   5. The session time zone is UTC. The database stores UTC and the PHP
 *      layer converts for display.
 *
 * execute() returns a BOOL, never a row count. The id of an inserted row
 * comes from lastInsertId(), called afterwards:
 *
 *     $db->execute($sql, $params);
 *     $id = (int) $db->lastInsertId();
 *
 * Casting execute()'s return value to an integer is a bug: it is always 1.
 *
 * No value is ever interpolated into SQL. The single exception this codebase
 * allows is a hard-coded table allow-list, and it does not exist yet.
 *
 * The constants come from config.php, which is the only configuration source
 * in the application. A missing constant is a fatal Error in PHP 8 — that is
 * the fail-closed behaviour we want, and it is why this file does not fall
 * back to a default host or an empty password.
 */

declare(strict_types=1);

final class Database
{
    /** The one instance. Null until the first getInstance() call. */
    private static ?Database $instance = null;

    /** The PDO handle. Null until the first query. */
    private ?PDO $pdo = null;

    /**
     * Private: getInstance() is the only way in. A second connection to the
     * same database would double every query's cost and open a second socket
     * for no reason.
     */
    private function __construct()
    {
    }

    /**
     * The singleton. The connection is opened on first use, not here.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The raw PDO handle. Only for the rare case that needs something the
     * wrapper does not offer — a multi-statement load, for instance.
     */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Open the connection.
     *
     * charset=utf8mb4 on the DSN, so the connection and the tables agree on
     * the character set. The tables themselves declare utf8mb4 in
     * database/schema.sql; a mismatched connection charset is how a
     * four-byte emoji turns into a question mark on the way out.
     */
    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // The database stores UTC. Without this the session time zone would be
        // the server's, and a NOW() in SQL would not mean the same thing as a
        // date() in PHP.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /**
     * Prepare and run a statement.
     *
     * One statement per call: EMULATE_PREPARES is off, so the driver sends
     * the statement and the parameters separately and refuses a second one.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Every matching row, as an array of associative rows.
     *
     * An empty array when nothing matches — never false.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * The first matching row, or false when there is none.
     *
     * false and not null, so that `if (!$row)` is the correct test.
     */
    public function fetchOne(string $sql, array $params = []): array|false
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? false : $row;
    }

    /**
     * Run a statement that changes something.
     *
     * Returns a BOOL: true when the statement ran. It is NOT a row count —
     * an UPDATE that matches nothing still returns true, and
     * `(int)$db->execute(...)` is always 1. The id of an inserted row comes
     * from lastInsertId(), called afterwards.
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->getConnection()->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * The id generated by the last INSERT on this connection.
     */
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }
}
