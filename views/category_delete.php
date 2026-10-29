<?php
/**
 * category_delete.php — the delete.
 *
 * The contract (docs/issues/issue-020.md §10):
 *
 *   1. requireLogin() is the FIRST call.
 *   2. The CSRF token is validated BEFORE any input is read.
 *   3. The category is SELECTED first, with the ownership in the
 *      statement. A row that is not there is a 404, never a silent
 *      success — there is no rowCount() on the database wrapper.
 *   4. The transactions are NOT in the DELETE statement. The foreign key
 *      is ON DELETE SET NULL, so the rows survive as uncategorised. A
 *      delete that dropped the transactions would destroy money records.
 *   5. A failed delete answers with HTTP 500, logs the raw driver error
 *      and keeps the dialog open with a "Try again" button. A redirect
 *      cannot carry a status code, so the categories screen is rendered
 *      here instead.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. The guard.
//
// The first line of the screen.
// ---------------------------------------------------------------------------

requireLogin();

// ---------------------------------------------------------------------------
// 2. The CSRF token.
//
// Checked BEFORE any input is read.
// ---------------------------------------------------------------------------

$token = (string) ($_POST['csrf_token'] ?? '');

if (!validateCsrfToken($token)) {
    throw new HttpException(403, 'The form has expired. Please try again.');
}

// ---------------------------------------------------------------------------
// 3. The input.
// ---------------------------------------------------------------------------

$uid  = (int) getCurrentUserId();
$idRaw = post('id', '');
$id    = ($idRaw !== '' && ctype_digit($idRaw)) ? (int) $idRaw : 0;

if ($id <= 0) {
    throw new HttpException(404, 'That category does not exist.');
}

// ---------------------------------------------------------------------------
// 4. The row.
//
// The ownership is in the SELECT. Another account's category is not
// found, and not found means a 404 — the delete must never report
// "deleted" for a row it did not touch.
//
// The transaction count comes with it: the confirmation has to quote the
// SAME number the table shows, and the dialog is rendered from this row.
// ---------------------------------------------------------------------------

$db = Database::getInstance();

$row = $db->fetchOne(
    'SELECT c.id,
            c.name,
            COUNT(t.id) AS tx_count
       FROM categories c
       LEFT JOIN transactions t
              ON t.category_id = c.id
             AND t.user_id = c.user_id
      WHERE c.id = ? AND c.user_id = ?
      GROUP BY c.id, c.name',
    [$id, $uid]
);

if (!is_array($row)) {
    throw new HttpException(404, 'That category does not exist.');
}

$name  = (string) $row['name'];
$count = (int) $row['tx_count'];

// ---------------------------------------------------------------------------
// 5. The delete.
//
// The statement names the category and its owner. The transactions are
// not mentioned: the foreign key sets their category to NULL, so they
// survive as uncategorised.
// ---------------------------------------------------------------------------

try {
    $db->execute(
        'DELETE FROM categories
          WHERE id = :id AND user_id = :uid',
        [
            ':id'  => $id,
            ':uid' => $uid,
        ]
    );
} catch (Throwable $ex) {
    // The context of the failure goes to the log; the visitor gets a
    // sentence.
    logFailure('categories: the delete failed', $ex);

    // The categories screen renders the list, the open dialog and the
    // error, and answers with a 500.
    $deleteFailure = [
        'id'    => $id,
        'name'  => $name,
        'count' => $count,
    ];

    require __DIR__ . '/categories.php';

    exit;
}

// ---------------------------------------------------------------------------
// 6. The success.
//
// The message is a flash, so the next GET renders it — and a refresh does
// not repeat the delete.
// ---------------------------------------------------------------------------

setFlash('category_deleted', 'The category "' . $name . '" was deleted.');

redirect('/categories');
