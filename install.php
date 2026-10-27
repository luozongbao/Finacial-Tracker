<?php
/**
 * install.php — the web installer (issue-015).
 *
 * The contract this file implements, in the order it happens:
 *
 *   1. The wizard runs BEFORE config.php exists. It never loads config.php
 *      and never reads a DB_* constant — the collected values live in the
 *      session until the last step writes the file.
 *   2. startSession() is the first call. output_buffering is 0 in this
 *      stack, so a byte emitted before it breaks header() and the redirect
 *      after a step silently fails.
 *   3. The step state lives in $_SESSION['install']. A hidden form field can
 *      be edited; the session cannot.
 *   4. Every step is a POST that redirects when it succeeds — the
 *      Post/Redirect/Get pattern. The redirect is what keeps the state on
 *      the server and the browser's refresh button safe.
 *   5. The CSRF token is validated BEFORE any input is read, and every form
 *      carries the hidden input.
 *   6. The raw driver error never reaches the page. It is classified into a
 *      plain-language sentence; the raw text goes to the error log.
 *   7. config.php is written with var_export(), atomically (a temp file and
 *      a rename), and parse-checked before the wizard declares success.
 *   8. The wizard is ONE file. Splitting it would leave fragments behind
 *      that have to be deleted as well.
 *
 * The page has no application navigation and no footer link farm: the
 * visitor is not signed in and the installer is not part of the product.
 * It renders its own document instead of includes/header.php, whose copy
 * and whose app shell belong to the signed-in application.
 *
 * After a successful install this file must be deleted. The success page
 * says so, in the loudest words on the page.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// ---------------------------------------------------------------------------
// 1. The session.
//
// The FIRST call. output_buffering is 0 in this stack, so a byte emitted
// before it breaks header() and the redirect after a step silently fails.
// The session also holds the step state and the CSRF token, so it has to
// exist before either of them is used.
// ---------------------------------------------------------------------------
startSession();

// PHPMailer lives in vendor/, which is installed at vendor time and may
// legitimately be absent on a machine that only wants the database. The
// autoloader is loaded when it is there; step 2 reports its absence in
// plain words, and step 2 can be skipped.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * The path of this wizard. It is always the web root — the .htaccess
 * rewrite does not intercept a real file, and the front controller's
 * install gate sends the visitor here.
 */
const INSTALL_PATH = '/install.php';

$view    = '';
$errors  = [];
$done    = false;
$state   = installState();
$db      = array_merge(installDbDefaults(), $state['db']);
$mail    = array_merge(installMailDefaults(), $state['mail']);
$step    = $state['step'];

// ---------------------------------------------------------------------------
// 2. The install gate.
//
// A live installer is a re-configuration hole: anyone who can reach
// install.php can rewrite the database credentials, the mail identity and
// the registration flag. The wizard refuses to run once config.php exists.
// ---------------------------------------------------------------------------
if (is_file(__DIR__ . '/config.php')) {
    http_response_code(403);
    $view = 'installed';
    $errors[] = 'Financial Tracker is already installed. Delete install.php from the project directory — '
        . 'a live installer can reconfigure the application for anyone who can reach it.';
} else {
    // -----------------------------------------------------------------------
    // 3. The request.
    //
    // A POST carries the step it belongs to, but the posted step is never
    // trusted: the session holds the truth. A visitor who edits the hidden
    // field to reach step 3 gets the step they have actually reached.
    // -----------------------------------------------------------------------
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        // The CSRF token is validated BEFORE any input is read.
        $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;

        if (!validateCsrfToken($token)) {
            $errors[] = 'Your session could not be verified. Reload the page and submit the form again.';
        } elseif ((int) post('step', '0') !== $step) {
            $errors[] = 'This step is out of date. Please complete the current step.';
        } else {
            $action = post('action');

            // -------------------------------------------------------------------
            // 4. Step 1 — the database.
            // -------------------------------------------------------------------
            if ($action === 'test_db') {
                $submitted = installCollectDb($db);
                $invalid  = installValidateDb($submitted);

                if ($invalid !== []) {
                    $errors = $invalid;
                    $db     = array_merge($db, $submitted);
                } else {
                    $result = installTestDatabase($submitted);

                    if ($result['ok'] === false) {
                        // The step does not advance. The raw driver message is
                        // in the error log, not on the page.
                        $errors[] = $result['message'];
                        $db       = array_merge($db, $submitted);
                    } else {
                        $state['db']         = $submitted;
                        $state['db_created'] = $result['created'];
                        $state['step']       = 2;
                        installSaveState($state);
                        redirect(INSTALL_PATH);
                    }
                }
            }

            // -------------------------------------------------------------------
            // 5. Step 2 — the mail server.
            //
            // All or nothing: a half-filled mail configuration is not a
            // configuration. Either the test passes and the values are kept,
            // or the step is skipped and the mail is simply not configured.
            // -------------------------------------------------------------------
            elseif ($action === 'test_mail' || $action === 'skip_mail') {
                $submitted = installCollectMail($mail);

                if ($action === 'skip_mail') {
                    // Skipping is a legitimate choice, not a failure. The mail
                    // is left unconfigured and can be added later.
                    $state['mail'] = [
                        'smtp_host'      => '',
                        'smtp_port'      => '587',
                        'smtp_user'      => '',
                        'smtp_pass'      => '',
                        'mail_from'      => installMailDefaults()['mail_from'],
                        'mail_from_name' => installMailDefaults()['mail_from_name'],
                    ];
                    $state['mail_configured'] = false;
                    $state['step']            = 3;
                    installSaveState($state);
                    redirect(INSTALL_PATH);
                }

                $invalid = installValidateMail($submitted);

                if ($invalid !== []) {
                    $errors = $invalid;
                    $mail   = array_merge($mail, $submitted);
                } else {
                    $result = installTestMail($submitted);

                    if ($result['ok'] === false) {
                        $errors[] = $result['message'];
                        $mail     = array_merge($mail, $submitted);
                    } else {
                        $state['mail']             = $submitted;
                        $state['mail_configured']  = true;
                        $state['step']             = 3;
                        installSaveState($state);
                        redirect(INSTALL_PATH);
                    }
                }
            }

            // -------------------------------------------------------------------
            // 6. Step 3 — the install.
            //
            // The order matters: the schema first (a config.php without tables
            // starts an application that dies on its first query), then the
            // configuration file, then the session goes away.
            // -------------------------------------------------------------------
            elseif ($action === 'install') {
                // The switch writes a real boolean. The hidden 0 is submitted
                // when the box is unticked, and the checkbox's 1 wins when it
                // is ticked.
                $state['allow_registration'] = post('allow_registration', '0') === '1';

                // The values come back through the defaulting helpers: a
                // hand-crafted session must not be able to make the wizard
                // read a key that is not there.
                $dbValues   = installDbValues($state['db']);
                $mailValues = array_merge(installMailDefaults(), $state['mail']);

                $schema = installLoadSchema(__DIR__ . '/database/schema.sql');

                if ($schema['ok'] === false) {
                    $errors[] = $schema['message'];
                } else {
                    $connection = installConnect($dbValues, true);

                    if ($connection['ok'] === false) {
                        $errors[] = $connection['message'];
                        $errors[] = 'The database could not be reached again. Check that it is still running '
                            . 'and that the details are right, then run the installer again.';
                    } else {
                        $pdo = $connection['pdo'];
                        $loaded = installApplySchema($pdo, $schema['sql']);

                        if ($loaded['ok'] === false) {
                            $errors[] = $loaded['message'];
                        } else {
                            $written = installWriteConfig([
                                'db_host'            => $dbValues['db_host'],
                                'db_name'            => $dbValues['db_name'],
                                'db_user'            => $dbValues['db_user'],
                                'db_pass'            => $dbValues['db_pass'],
                                'smtp_host'          => (string) $mailValues['smtp_host'],
                                'smtp_port'          => (int) $mailValues['smtp_port'],
                                'smtp_user'          => (string) $mailValues['smtp_user'],
                                'smtp_pass'          => (string) $mailValues['smtp_pass'],
                                'mail_from'          => (string) $mailValues['mail_from'],
                                'mail_from_name'     => (string) $mailValues['mail_from_name'],
                                'allow_registration' => $state['allow_registration'],
                            ]);

                            if ($written['ok'] === false) {
                                // Nothing was installed. The tables are there,
                                // but there is no configuration file, so the
                                // application still redirects to the wizard.
                                $errors[] = $written['message'];
                                $errors[] = 'The database tables were created, but nothing was configured. '
                                    . 'Fix the directory permissions and run the installer again — your entries are still here.';
                            } else {
                                $dbCreated = $state['db_created'];

                                // The session goes away: the next visitor must
                                // not inherit the step state, and the values
                                // now live in config.php.
                                installDestroySession();

                                $done   = true;
                                $view   = 'done';
                                $state  = ['db_created' => $dbCreated];
                                $errors = [];
                            }
                        }
                    }
                }
            } else {
                $errors[] = 'That is not an action the installer knows.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 7. The helpers.
// ---------------------------------------------------------------------------

/**
 * The install state, with the defaults filled in.
 *
 * The state is server-side. A hidden form field can be edited; the session
 * cannot.
 *
 * @return array<string, mixed>
 */
function installState(): array
{
    $state = $_SESSION['install'] ?? [];

    if (!is_array($state)) {
        $state = [];
    }

    $step = (int) ($state['step'] ?? 1);

    return [
        'step'              => ($step >= 1 && $step <= 3) ? $step : 1,
        'db'                => is_array($state['db'] ?? null) ? $state['db'] : [],
        'mail'              => is_array($state['mail'] ?? null) ? $state['mail'] : [],
        'allow_registration' => (bool) ($state['allow_registration'] ?? true),
        'db_created'        => (bool) ($state['db_created'] ?? false),
        'mail_configured'   => (bool) ($state['mail_configured'] ?? false),
    ];
}

/**
 * Store the state back into the session.
 *
 * @param array<string, mixed> $state
 */
function installSaveState(array $state): void
{
    $_SESSION['install'] = $state;
}

/**
 * The default values of the database fields.
 *
 * @return array<string, string>
 */
function installDbDefaults(): array
{
    return [
        'db_host' => 'localhost',
        'db_name' => 'financial_tracker',
        'db_user' => '',
        'db_pass' => '',
    ];
}

/**
 * The default values of the mail fields.
 *
 * @return array<string, string>
 */
function installMailDefaults(): array
{
    return [
        'smtp_host'      => '',
        'smtp_port'      => '587',
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'mail_from'      => 'no-reply@example.com',
        'mail_from_name' => 'Financial Tracker',
    ];
}

/**
 * The database values of the state, with the defaults filled in.
 *
 * @param  array<string, mixed> $stored
 * @return array<string, string>
 */
function installDbValues(array $stored): array
{
    $values = array_merge(installDbDefaults(), $stored);

    return [
        'db_host' => (string) $values['db_host'],
        'db_name' => (string) $values['db_name'],
        'db_user' => (string) $values['db_user'],
        'db_pass' => (string) $values['db_pass'],
    ];
}

/**
 * The submitted database fields.
 *
 * The password is read raw, not trimmed: a space is a valid character in a
 * password, and silently trimming it would lock the operator out.
 *
 * @param  array<string, string> $current
 * @return array<string, string>
 */
function installCollectDb(array $current): array
{
    return [
        'db_host' => post('db_host', $current['db_host']),
        'db_name' => post('db_name', $current['db_name']),
        'db_user' => post('db_user', $current['db_user']),
        'db_pass' => installRawPost('db_pass', $current['db_pass']),
    ];
}

/**
 * The submitted mail fields.
 *
 * @param  array<string, string> $current
 * @return array<string, string>
 */
function installCollectMail(array $current): array
{
    return [
        'smtp_host'      => post('smtp_host', $current['smtp_host']),
        'smtp_port'      => post('smtp_port', $current['smtp_port']),
        'smtp_user'      => post('smtp_user', $current['smtp_user']),
        'smtp_pass'      => installRawPost('smtp_pass', $current['smtp_pass']),
        'mail_from'      => post('mail_from', $current['mail_from']),
        'mail_from_name' => post('mail_from_name', $current['mail_from_name']),
    ];
}

/**
 * A POST value that is not trimmed.
 *
 * The password fields are the reason: trim() would change a password that
 * legitimately begins or ends with a space.
 */
function installRawPost(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

/**
 * The validation messages of the database fields.
 *
 * @param  array<string, string> $values
 * @return string[]
 */
function installValidateDb(array $values): array
{
    $errors = [];

    if ($values['db_host'] === '') {
        $errors[] = 'Enter the database host.';
    } elseif (preg_match('/[\s;]/', $values['db_host']) === 1) {
        // A semicolon would end the DSN and start another option, and a
        // space is never part of a host name.
        $errors[] = 'The database host may not contain spaces or a semicolon.';
    }

    if ($values['db_name'] === '') {
        $errors[] = 'Enter the database name.';
    } elseif (preg_match('/^[A-Za-z0-9_]+$/', $values['db_name']) !== 1) {
        // The name is the one value that is interpolated into SQL, so it is
        // restricted to the characters an identifier may hold.
        $errors[] = 'The database name may only contain letters, digits and underscores.';
    }

    if ($values['db_user'] === '') {
        $errors[] = 'Enter the database user.';
    }

    return $errors;
}

/**
 * The validation messages of the mail fields.
 *
 * @param  array<string, string> $values
 * @return string[]
 */
function installValidateMail(array $values): array
{
    $errors = [];

    if ($values['smtp_host'] === '') {
        $errors[] = 'Enter the SMTP host, or skip this step and add mail later.';
    }

    $port = filter_var($values['smtp_port'], FILTER_VALIDATE_INT);

    if ($port === false || $port < 1 || $port > 65535) {
        $errors[] = 'The SMTP port must be a number between 1 and 65535.';
    }

    if (!isValidEmail($values['mail_from'])) {
        $errors[] = 'Enter a valid From: address.';
    }

    return $errors;
}

/**
 * Open a connection to the database server.
 *
 * The wizard uses its OWN handle. Database::getInstance() reads the
 * constants of config.php, and config.php does not exist yet — that is the
 * whole point of the wizard.
 *
 * The host was validated before it got here: no whitespace, no semicolon.
 *
 * A failed connection is classified, not lumped together: a wrong password
 * (1045) and a server that is not running (2002) need different sentences.
 *
 * @param  array<string, string> $values
 * @param  bool                 $withDatabase Select the database in the DSN.
 * @return array{ok: bool, pdo: PDO|null, message: string}
 */
function installConnect(array $values, bool $withDatabase = false): array
{
    $dsn = 'mysql:host=' . $values['db_host'];

    if ($withDatabase === true) {
        $dsn .= ';dbname=' . $values['db_name'];
    }

    $dsn .= ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, $values['db_user'], $values['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);

        return ['ok' => true, 'pdo' => $pdo, 'message' => ''];
    } catch (PDOException $ex) {
        error_log('[install] connection: ' . $ex->getMessage());

        return ['ok' => false, 'pdo' => null, 'message' => installDatabaseErrorMessage($ex)];
    }
}

/**
 * Test the database details.
 *
 * The test is three things in one: the server answers, the user may write,
 * and the database exists (it is created when it is missing and the user is
 * allowed to).
 *
 * @param  array<string, string> $values
 * @return array{ok: bool, message: string, created: bool}
 */
function installTestDatabase(array $values): array
{
    $connection = installConnect($values);

    if ($connection['ok'] === false) {
        return [
            'ok'      => false,
            'message' => $connection['message'],
            'created' => false,
        ];
    }

    $pdo  = $connection['pdo'];
    $name = $values['db_name'];

    try {
        $exists = $pdo->query(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($name)
        )->fetchColumn();

        if ($exists === false) {
            // The name was validated against /^[A-Za-z0-9_]+$/ before it got
            // here, so the backticks around it are safe.
            $pdo->exec(
                'CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $created = true;
        } else {
            $created = false;
        }

        // The write test: a table is created and dropped again. A database
        // that cannot be written to is not a database this application can
        // use, and the operator has to know now.
        $pdo->exec('USE `' . $name . '`');
        $pdo->exec('CREATE TABLE IF NOT EXISTS `ft_install_probe` (`id` INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('DROP TABLE `ft_install_probe`');
    } catch (PDOException $ex) {
        error_log('[install] database: ' . $ex->getMessage());

        // The probe table may be left behind when the DROP was the statement
        // that failed.
        try {
            $pdo->exec('DROP TABLE IF EXISTS `ft_install_probe`');
        } catch (PDOException $ignored) {
            error_log('[install] probe cleanup: ' . $ignored->getMessage());
        }

        return [
            'ok'      => false,
            'message' => installDatabaseErrorMessage($ex),
            'created' => false,
        ];
    }

    return [
        'ok'      => true,
        'message' => '',
        'created' => $created,
    ];
}

/**
 * The plain-language sentence for a database failure.
 *
 * The driver message itself carries the host, the user and the SQLSTATE.
 * It is not written for a visitor to read: it goes to the error log, and
 * the page says what a person can do about it.
 *
 * The wording is the mockup's: a short title — the failure — and a hint
 * under it. installSplitMessage() separates the two at the first stop.
 */
function installDatabaseErrorMessage(PDOException $ex): string
{
    $code = (int) ($ex->errorInfo[1] ?? 0);

    return match ($code) {
        1045 => "The database user was denied access. Check the user's permissions, and that it may use this database.",
        1044 => 'The database user was denied access. That user may not create the database — create it first, '
            . 'or use a user that may.',
        1049 => 'That database does not exist on the server. Create it, or check the name.',
        2002, 2003, 2005 => 'The database server refused the connection. Check that the database server is running '
            . 'and that the host and port are correct.',
        default => 'The database connection failed. Check the host, the name, the user and the password, then try again.',
    };
}

/**
 * Split a message into the alert title and the hint under it.
 *
 * The mockup's alert has a short title — the failure — and a hint under
 * it: what to do about it. A message that is a single sentence has no
 * hint, and the alert shows the sentence alone.
 *
 * @return array{title: string, hint: string}
 */
function installSplitMessage(string $message): array
{
    $pos = strpos($message, '. ');

    if ($pos === false) {
        return ['title' => $message, 'hint' => ''];
    }

    return [
        'title' => substr($message, 0, $pos + 1),
        'hint'  => substr($message, $pos + 2),
    ];
}

/**
 * Test the mail details by sending a real message.
 *
 * A connection that opens is not a mail server that works. The only honest
 * test is a message that arrives, so the test message goes to the From:
 * address — the one address the operator has just proved they control.
 *
 * @param  array<string, string> $values
 * @return array{ok: bool, message: string}
 */
function installTestMail(array $values): array
{
    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        return [
            'ok'      => false,
            'message' => 'The mail library is not installed. Run composer install --no-dev --optimize-autoloader, '
                . 'or skip this step and add mail later.',
        ];
    }

    $port = (int) $values['smtp_port'];

    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $values['smtp_host'];
        $mailer->Port       = $port;
        $mailer->SMTPAuth   = $values['smtp_user'] !== '';
        $mailer->Username   = $values['smtp_user'];
        $mailer->Password   = $values['smtp_pass'];
        $mailer->CharSet    = 'UTF-8';
        $mailer->Timeout    = 30;
        $mailer->SMTPSecure = $port === 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $mailer->setFrom($values['mail_from'], $values['mail_from_name']);
        $mailer->addAddress($values['mail_from']);
        $mailer->Subject = 'Financial Tracker — mail test';
        $mailer->isHTML(true);
        $mailer->Body = '<p>This is a test message from the Financial Tracker installer. '
            . 'If you are reading it, the mail settings are correct.</p>';

        $sent = $mailer->send();
    } catch (Throwable $ex) {
        error_log('[install] mail: ' . $ex->getMessage());

        return [
            'ok'      => false,
            'message' => 'The mail server could not be reached. Check the host and the port, or skip this step '
                . 'and add mail later.',
        ];
    }

    if ($sent !== true) {
        error_log('[install] mail: the message was refused');

        return [
            'ok'      => false,
            'message' => 'The mail server refused the message. Check the user name and the password, or skip this '
                . 'step and add mail later.',
        ];
    }

    return [
        'ok'      => true,
        'message' => 'A test message was sent to ' . $values['mail_from'] . '.',
    ];
}

/**
 * Read the schema file.
 *
 * The file is the single source of truth for a fresh install, and it is
 * applied as it is written — the wizard hands the whole string to its own
 * PDO handle, and PDO's multi-statement mode runs it as written.
 *
 * @return array{ok: bool, message: string, sql: string}
 */
function installLoadSchema(string $path): array
{
    if (!is_file($path)) {
        return [
            'ok'      => false,
            'message' => 'The database schema file is missing from the project directory, so the tables could not '
                . 'be created. Nothing was installed.',
            'sql'     => '',
        ];
    }

    $sql = file_get_contents($path);

    if ($sql === false || trim($sql) === '') {
        return [
            'ok'      => false,
            'message' => 'The database schema file could not be read, so the tables could not be created. '
                . 'Nothing was installed.',
            'sql'     => '',
        ];
    }

    return ['ok' => true, 'message' => '', 'sql' => $sql];
}

/**
 * Apply the schema.
 *
 * @return array{ok: bool, message: string}
 */
function installApplySchema(PDO $pdo, string $sql): array
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $ex) {
        error_log('[install] schema: ' . $ex->getMessage());

        return [
            'ok'      => false,
            'message' => 'The database tables could not be created. Check the error log, then run the installer again.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Write config.php.
 *
 * The file is written with var_export() and nothing else — addslashes() is
 * not a PHP escaper, and the file is parsed by PHP, not by SQL. The string
 * is built in a temporary file and moved into place with rename(), so a
 * failed write can never leave a half-written config.php behind. The
 * temporary file is parse-checked before the move: a config.php that does
 * not parse is worse than no config.php, because the install gate would let
 * the application start and every request would fatal.
 *
 * @param  array<string, mixed> $values
 * @return array{ok: bool, message: string}
 */
function installWriteConfig(array $values): array
{
    $path = __DIR__ . '/config.php';
    $temp = $path . '.tmp';

    $lines = [
        '<?php',
        '/**',
        ' * config.php — the configuration of this installation.',
        ' *',
        ' * Written by install.php at install time. Do not commit this file: it',
        ' * holds the database password and the mail credentials.',
        ' *',
        ' * The tracked twin of this file is config.template.php. Both declare the',
        ' * SAME constant set — only the values differ.',
        ' */',
        '',
        'declare(strict_types=1);',
        '',
        '// ---- Database ---------------------------------------------------------',
        'const DB_HOST = ' . var_export((string) $values['db_host'], true) . ';',
        'const DB_NAME = ' . var_export((string) $values['db_name'], true) . ';',
        'const DB_USER = ' . var_export((string) $values['db_user'], true) . ';',
        'const DB_PASS = ' . var_export((string) $values['db_pass'], true) . ';',
        '',
        '// ---- SMTP (used by the account-verification and password-reset mailer) ----',
        'const SMTP_HOST = ' . var_export((string) $values['smtp_host'], true) . ';',
        'const SMTP_PORT = ' . (int) $values['smtp_port'] . ';',
        'const SMTP_USER = ' . var_export((string) $values['smtp_user'], true) . ';',
        'const SMTP_PASS = ' . var_export((string) $values['smtp_pass'], true) . ';',
        '',
        '// Sender identity. Written by install.php — see docs/issues/issue-010.md.',
        'const MAIL_FROM = ' . var_export((string) $values['mail_from'], true) . ';',
        'const MAIL_FROM_NAME = ' . var_export((string) $values['mail_from_name'], true) . ';',
        '',
        '// ---- Application ------------------------------------------------------',
        '',
        '// Public registration. true = the register page is open; false = it is closed',
        '// on BOTH the front end (403, no form) and the back end (the POST handler',
        '// rejects the request).',
        'const ALLOW_REGISTRATION = ' . var_export((bool) $values['allow_registration'], true) . ';',
        '',
        '// Week-start rule for period reports, as a weekday number: 1 = Monday ... 7 = Sunday.',
        'const WEEK_START = 1;',
        '',
        '// Transaction dates are stored as DATE in UTC.',
        "const TIMEZONE = 'UTC';",
        '',
    ];

    $contents = implode("\n", $lines);

    $written = @file_put_contents($temp, $contents, LOCK_EX);

    if ($written === false || $written !== strlen($contents)) {
        @unlink($temp);

        return [
            'ok'      => false,
            'message' => 'The configuration file could not be written. Check that the project directory is writable.',
        ];
    }

    // The parse check runs on the temporary file, which holds exactly the
    // bytes that are about to be moved into place.
    if (!installParses($temp)) {
        @unlink($temp);

        return [
            'ok'      => false,
            'message' => 'The configuration file could not be written. Check that the project directory is writable.',
        ];
    }

    if (!@rename($temp, $path)) {
        @unlink($temp);

        return [
            'ok'      => false,
            'message' => 'The configuration file could not be written. Check that the project directory is writable.',
        ];
    }

    // The file holds a password. It does not need to be readable by anyone
    // but the web server that runs the application.
    @chmod($path, 0600);

    return ['ok' => true, 'message' => ''];
}

/**
 * Does this file parse?
 *
 * php -l is the parser's own verdict. When the binary cannot be run, the
 * parser itself answers: a syntax error in an included file is a ParseError,
 * and it is catchable.
 */
function installParses(string $path): bool
{
    if (function_exists('shell_exec')) {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');

        if (is_string($output) && $output !== '') {
            return str_contains($output, 'No syntax errors');
        }
    }

    try {
        include $path;

        return true;
    } catch (ParseError $ex) {
        error_log('[install] config parse: ' . $ex->getMessage());

        return false;
    }
}

/**
 * End the session.
 *
 * The step state, the CSRF token and the collected secrets all go. The next
 * visitor must not inherit any of it.
 */
function installDestroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 42000, '/');
    }

    session_destroy();
}

// ---------------------------------------------------------------------------
// 8. The page.
//
// The wizard renders its own document: no app bar, no navigation, no footer
// link farm. The visitor is not signed in, and the installer is not part
// of the product.
// ---------------------------------------------------------------------------

$themeCookie = isset($_COOKIE['theme']) && is_string($_COOKIE['theme']) ? $_COOKIE['theme'] : '';
$theme       = ($themeCookie === 'dark' || $themeCookie === 'light') ? $themeCookie : '';

$stepLabels = [
    1 => 'Database',
    2 => 'Mail (optional)',
    3 => 'Access and summary',
];

$mask = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";
$dash = "\u{2014}";

?>
<!DOCTYPE html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install Financial Tracker</title>
<link rel="stylesheet" href="<?= e(asset_url('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/components.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('css/pages/install.css')) ?>">
</head>
<body>

<main class="auth install">

<?php if ($view === 'installed'): ?>

    <header class="auth__brand">
        <h1 class="auth__brand-name">Install Financial Tracker</h1>
        <p class="auth__brand-note">Three short steps. You will need the database and mail details from whoever runs your server.</p>
    </header>

    <div class="card">
        <h2 class="card__title">Already installed</h2>

        <div role="alert">
            <div class="alert alert--danger">
                <div class="alert__body">
<?php $alert = installSplitMessage($errors[0]); ?>
                    <p class="alert__title"><?= e($alert['title']) ?></p>
<?php if ($alert['hint'] !== ''): ?>
                    <p><?= e($alert['hint']) ?></p>
<?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($view === 'done'): ?>

    <header class="auth__brand">
        <h1 class="auth__brand-name">Install Financial Tracker</h1>
        <p class="auth__brand-note">The installation is finished. One last thing to do.</p>
    </header>

    <div class="card">
        <h2 class="card__title">Install complete</h2>

        <div role="status" aria-live="polite">
            <div class="alert alert--success">
                <div class="alert__body">
                    <p class="alert__title">Financial Tracker is installed.</p>
                    <p><?= $state['db_created'] === true
                        ? 'The database was created and the configuration file was written.'
                        : 'The database was ready and the configuration file was written.' ?></p>
                </div>
            </div>
        </div>

        <div class="alert alert--danger">
            <div class="alert__body">
                <p class="alert__title">Delete install.php now.</p>
                <p>
                    The installer can reconfigure your database and mail settings.
                    Remove the file from the project directory before you use the
                    application.
                </p>
                <p>The installer did not remove itself. Run this from a shell:</p>
                <code class="install__command">rm -f <?= e(__DIR__ . '/install.php') ?></code>
            </div>
        </div>

        <a class="btn btn--primary btn--block" href="/dashboard">Go to the dashboard</a>
    </div>

<?php else: ?>

    <header class="auth__brand">
        <h1 class="auth__brand-name">Install Financial Tracker</h1>
        <p class="auth__brand-note">Three short steps. You will need the database and mail details from whoever runs your server.</p>
    </header>

    <ol class="stepper">
<?php foreach ($stepLabels as $number => $label): ?>
        <li class="stepper__item"<?= $number === $step ? ' aria-current="step"' : '' ?>>
            <span class="stepper__num"><?= e($number) ?></span>
            <span><?= e($label) ?></span>
        </li>
<?php endforeach; ?>
    </ol>

<?php if ($errors !== []): ?>
    <div role="alert">
        <div class="alert alert--danger">
            <div class="alert__body">
<?php $alert = installSplitMessage($errors[0]); ?>
                <p class="alert__title"><?= e($alert['title']) ?></p>
<?php if ($alert['hint'] !== ''): ?>
                <p><?= e($alert['hint']) ?></p>
<?php endif; ?>
<?php foreach (array_slice($errors, 1) as $extra): ?>
                <p><?= e($extra) ?></p>
<?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($step === 1): ?>

    <div class="card">
        <h2 class="card__title">Database connection</h2>

        <form method="post" action="<?= e(INSTALL_PATH) ?>" aria-label="Database connection">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="step" value="1">

            <div class="field">
                <label class="field__label" for="iw-db-host">Database host</label>
                <input class="input" id="iw-db-host" name="db_host" type="text"
                       value="<?= e($db['db_host']) ?>" spellcheck="false" autocapitalize="off" required>
                <span class="field__hint">Hostname or IP address. Use <code>db</code> in the Docker stack.</span>
            </div>

            <div class="field">
                <label class="field__label" for="iw-db-name">Database name</label>
                <input class="input" id="iw-db-name" name="db_name" type="text"
                       value="<?= e($db['db_name']) ?>" spellcheck="false" autocapitalize="off" required>
                <span class="field__hint">Letters, digits and underscores. The wizard creates the database when it is missing.</span>
            </div>

            <div class="field">
                <label class="field__label" for="iw-db-user">Database user</label>
                <input class="input" id="iw-db-user" name="db_user" type="text"
                       value="<?= e($db['db_user']) ?>" spellcheck="false" autocapitalize="off" required>
            </div>

            <div class="field">
                <label class="field__label" for="iw-db-pass">Database password</label>
                <input class="input" id="iw-db-pass" name="db_pass" type="password"
                       autocomplete="new-password" value="">
            </div>

            <button type="submit" class="btn btn--primary btn--block" name="action" value="test_db">Test and continue</button>
        </form>
    </div>

<?php elseif ($step === 2): ?>

    <div class="card">
        <h2 class="card__title">Mail server</h2>
        <p class="field__hint">
            Mail is only needed for account verification and password reset
            links. You can skip this step and add it later.
        </p>

        <form method="post" action="<?= e(INSTALL_PATH) ?>" aria-label="Mail server">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="step" value="2">

            <div class="field">
                <label class="field__label" for="iw-smtp-host">SMTP host</label>
                <input class="input" id="iw-smtp-host" name="smtp_host" type="text"
                       value="<?= e($mail['smtp_host']) ?>" spellcheck="false" autocapitalize="off">
            </div>

            <div class="field">
                <label class="field__label" for="iw-smtp-port">SMTP port</label>
                <input class="input" id="iw-smtp-port" name="smtp_port" type="number"
                       min="1" max="65535" step="1" value="<?= e($mail['smtp_port']) ?>">
            </div>

            <div class="field">
                <label class="field__label" for="iw-smtp-user">SMTP user</label>
                <input class="input" id="iw-smtp-user" name="smtp_user" type="text"
                       value="<?= e($mail['smtp_user']) ?>" spellcheck="false" autocapitalize="off">
            </div>

            <div class="field">
                <label class="field__label" for="iw-smtp-pass">SMTP password</label>
                <input class="input" id="iw-smtp-pass" name="smtp_pass" type="password"
                       autocomplete="new-password" value="">
            </div>

            <div class="field">
                <label class="field__label" for="iw-mail-from">From: address</label>
                <input class="input" id="iw-mail-from" name="mail_from" type="email"
                       value="<?= e($mail['mail_from']) ?>" spellcheck="false" autocapitalize="off">
                <span class="field__hint">The address the message is sent from. Required once an SMTP host is set.</span>
            </div>

            <div class="field">
                <label class="field__label" for="iw-mail-from-name">From: name</label>
                <input class="input" id="iw-mail-from-name" name="mail_from_name" type="text"
                       value="<?= e($mail['mail_from_name']) ?>">
                <span class="field__hint">Shown to the recipient next to the address.</span>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn--primary" name="action" value="test_mail">Test and continue</button>
                <button type="submit" class="btn btn--secondary" name="action" value="skip_mail">Skip mail setup and continue</button>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="card">
        <h2 class="card__title">Access</h2>

        <form method="post" action="<?= e(INSTALL_PATH) ?>" aria-label="Access">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="step" value="3">

            <div class="field">
                <span class="field__label" id="iw-reg-label">Allow registration</span>
                <input type="hidden" name="allow_registration" value="0">
                <label class="switch" for="iw-reg">
                    <input type="checkbox" id="iw-reg" name="allow_registration" value="1"
                           <?= $state['allow_registration'] === true ? 'checked' : '' ?>>
                    <span class="switch__track" aria-hidden="true"></span>
                    <span>Allow registration</span>
                </label>
                <span class="field__hint" id="iw-reg-hint">
                    When this is off, the register page returns 403 and no new
                    accounts can be created. Existing accounts keep working.
                </span>
            </div>

            <button type="submit" class="btn btn--primary btn--block" name="action" value="install">Install</button>
        </form>
    </div>

    <div class="card">
        <h2 class="card__title">Summary</h2>
        <p class="field__hint">
            These are the values that will be written to the configuration
            file.
        </p>

        <dl class="readonly-list">
            <div class="readonly-list__row">
                <dt>Database host</dt>
                <dd><?= e($db['db_host']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>Database name</dt>
                <dd><?= e($db['db_name']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>Database user</dt>
                <dd><?= e($db['db_user']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>Database password</dt>
                <dd class="masked"><?= e($mask) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>SMTP host</dt>
                <dd><?= $mail['smtp_host'] !== '' ? e($mail['smtp_host']) : e($dash) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>SMTP port</dt>
                <dd><?= e($mail['smtp_port']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>SMTP user</dt>
                <dd><?= $mail['smtp_user'] !== '' ? e($mail['smtp_user']) : e($dash) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>SMTP password</dt>
                <dd class="masked"><?= e($mask) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>From: address</dt>
                <dd><?= e($mail['mail_from']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>From: name</dt>
                <dd><?= e($mail['mail_from_name']) ?></dd>
            </div>
            <div class="readonly-list__row">
                <dt>Registration allowed</dt>
                <dd><?= $state['allow_registration'] === true ? 'Yes' : 'No' ?></dd>
            </div>
        </dl>
    </div>

<?php endif; ?>

<?php // Closes the step chain above. ?>
<?php endif; ?>

</main>
</body>
</html>
