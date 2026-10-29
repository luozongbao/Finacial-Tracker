<?php
/**
 * category_save.php — the create and the rename.
 *
 * ONE handler for both writes: a POST without an id creates a category, a
 * POST with an id renames one.
 *
 * The contract (docs/issues/issue-020.md):
 *
 *   1. requireLogin() is the FIRST call.
 *   2. The CSRF token is validated BEFORE a single field is read.
 *   3. EVERY POST ends with a redirect. The state that has to survive the
 *      redirect travels in the session as a flash message.
 *   4. The uniqueness of the name is enforced by the DATABASE — the
 *      UNIQUE (user_id, name) key. A SELECT first would race: two
 *      simultaneous creates would both pass it and one of them would still
 *      be refused by the index.
 *   5. A rename changes the NAME and nothing else. The type is not in the
 *      SET list, so a posted type cannot flip a category from expense to
 *      income.
 *   6. The ownership is in the SQL. Another account's category is a 404.
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
// Checked BEFORE any input is read: a request that fails the check must
// not be able to make the handler look at its fields at all.
// ---------------------------------------------------------------------------

$token = (string) ($_POST['csrf_token'] ?? '');

if (!validateCsrfToken($token)) {
    throw new HttpException(403, 'The form has expired. Please try again.');
}

// ---------------------------------------------------------------------------
// 3. The input.
//
// post() trims the value and refuses anything that is not a string, so a
// posted array can never reach the SQL as one.
// ---------------------------------------------------------------------------

$uid  = (int) getCurrentUserId();
$name = post('name', '');
$type = post('type', 'expense');

$idRaw = post('id', '');
$id    = ($idRaw !== '' && ctype_digit($idRaw)) ? (int) $idRaw : 0;

// ---------------------------------------------------------------------------
// 4. The rename.
//
// A POST with an id renames the category. The id has to be the signed-in
// user's category: the ownership is in the SELECT, and a row that is not
// found is a 404 — never a rename of somebody else's category.
// ---------------------------------------------------------------------------

if ($id > 0) {
    $db = Database::getInstance();

    $row = $db->fetchOne(
        'SELECT id, name, type
           FROM categories
          WHERE id = ? AND user_id = ?
          LIMIT 1',
        [$id, $uid]
    );

    if (!is_array($row)) {
        throw new HttpException(404, 'That category does not exist.');
    }

    // The type is NOT part of the rename. The stored type is validated
    // instead of the posted one, so a posted type is simply ignored — and
    // an invalid posted type cannot produce an error either.
    $validation = validateCategoryInput($name, (string) $row['type']);
    $newName    = (string) $validation['values']['name'];

    if ($validation['errors'] !== []) {
        setFlash('category_rename_error', [
            'id'      => $id,
            'message' => (string) ($validation['errors']['name'] ?? 'The category could not be renamed.'),
            'value'   => $newName,
        ]);

        redirect('/categories?rename=' . $id);
    }

    try {
        // The SET list holds the NAME and nothing else.
        $db->execute(
            'UPDATE categories
                SET name = :name
              WHERE id = :id AND user_id = :uid',
            [
                ':name' => $newName,
                ':id'   => $id,
                ':uid'  => $uid,
            ]
        );
    } catch (PDOException $ex) {
        if (isDuplicateKeyError($ex)) {
            setFlash('category_rename_error', [
                'id'      => $id,
                'message' => 'That name is already in use.',
                'value'   => $newName,
            ]);

            redirect('/categories?rename=' . $id);
        }

        logFailure('categories: the rename failed', $ex);

        throw new HttpException(500, 'The category could not be renamed. Please try again.');
    }

    setFlash('category_renamed', 'The category "' . $row['name'] . '" was renamed to "' . $newName . '".');

    redirect('/categories');
}

// ---------------------------------------------------------------------------
// 5. The create.
//
// The name is unique per user, and the database is the judge: the
// UNIQUE (user_id, name) key refuses the second "Groceries", and the
// comparison is case-insensitive because the column's collation is.
// ---------------------------------------------------------------------------

$validation = validateCategoryInput($name, $type);
$newName    = (string) $validation['values']['name'];
$newType    = (string) $validation['values']['type'];

if ($validation['errors'] !== []) {
    setFlash('category_create_error', [
        'name'       => $newName,
        'type'       => $newType,
        'name_error' => (string) ($validation['errors']['name'] ?? ''),
        'type_error' => (string) ($validation['errors']['type'] ?? ''),
    ]);

    redirect('/categories');
}

try {
    $db = Database::getInstance();

    $db->execute(
        'INSERT INTO categories (user_id, name, type)
              VALUES (:uid, :name, :type)',
        [
            ':uid'  => $uid,
            ':name' => $newName,
            ':type' => $newType,
        ]
    );

    // execute() returns a bool, never a row count. The id comes from
    // lastInsertId().
    $newId = (int) $db->lastInsertId();
} catch (PDOException $ex) {
    if (isDuplicateKeyError($ex)) {
        setFlash('category_create_error', [
            'name'       => $newName,
            'type'       => $newType,
            'name_error' => 'That name is already in use.',
            'type_error' => '',
        ]);

        redirect('/categories');
    }

    logFailure('categories: the create failed', $ex);

    throw new HttpException(500, 'The category could not be added. Please try again.');
}

// The success is rendered by the NEXT GET, not by this response.
setFlash('category_created', [
    'id'   => $newId,
    'name' => $newName,
    'type' => $newType,
]);

redirect('/categories');
