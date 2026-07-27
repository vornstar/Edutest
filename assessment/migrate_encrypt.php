<?php
declare(strict_types=1);

/**
 * ONE-OFF migration script - step 2 of 3 for encrypting existing plaintext
 * student data at rest (answers, self-mark reflections, marker comments,
 * in-PDF annotations).
 *
 * 1. Run config/migrate_encrypt_step1_add_columns.sql first (adds the new
 *    *_cipher columns; the old plaintext columns are left in place).
 * 2. Set ASSESSMENT_MIGRATION_SECRET in .env.php to any long random string.
 * 3. Visit this file directly, e.g.
 *    https://www.qmhsportal.co.uk/assessment/migrate_encrypt.php?secret=YOUR_SECRET
 *    Safe to run more than once - it only touches rows that don't already
 *    have their cipher column filled in.
 * 4. Check the counts printed look right, and spot-check the app still
 *    shows correct answers/comments/annotations for a real submission.
 * 5. Run config/migrate_encrypt_step3_drop_plaintext.sql to remove the old
 *    plaintext columns.
 * 6. DELETE THIS FILE from the server - leaving a working migration
 *    endpoint on a live site is an unnecessary risk once it's done its job.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/models/Database.php';
require_once __DIR__ . '/models/Crypto.php';

header('Content-Type: text/plain');

$expectedSecret = (string) config('migration_secret', '');
$providedSecret = (string) ($_GET['secret'] ?? '');
if ($expectedSecret === '' || $providedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    echo "Forbidden.\nSet ASSESSMENT_MIGRATION_SECRET in .env.php, then visit this URL with ?secret=that-value\n";
    exit;
}

/** Encrypts every row's plaintext column into its cipher column, skipping rows already migrated. */
function migrateColumn(PDO $pdo, string $table, string $idCol, string $plainCol, string $cipherCol): int
{
    $count = 0;
    $select = $pdo->query("SELECT {$idCol}, {$plainCol} FROM {$table} WHERE {$plainCol} IS NOT NULL AND {$cipherCol} IS NULL");
    $update = $pdo->prepare("UPDATE {$table} SET {$cipherCol} = :cipher WHERE {$idCol} = :id");
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $update->execute([
            'cipher' => Crypto::encrypt($row[$plainCol]),
            'id' => $row[$idCol],
        ]);
        $count++;
    }
    return $count;
}

/** users needs both email_cipher AND the deterministic email_hash (for login lookup/uniqueness) computed together, plus display_name_cipher - different shape from migrateColumn(). */
function migrateUsers(PDO $pdo): int
{
    $count = 0;
    $select = $pdo->query('SELECT id, email, display_name FROM users WHERE email_cipher IS NULL');
    $update = $pdo->prepare('UPDATE users SET email_cipher = :email_cipher, email_hash = :email_hash, display_name_cipher = :display_name_cipher WHERE id = :id');
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $email = strtolower(trim((string) $row['email']));
        $update->execute([
            'email_cipher' => Crypto::encrypt($email),
            'email_hash' => Crypto::searchHash($email),
            'display_name_cipher' => Crypto::encrypt((string) $row['display_name']),
            'id' => $row['id'],
        ]);
        $count++;
    }
    return $count;
}

$pdo = Database::connection();

echo "Encrypting existing plaintext data...\n\n";
echo "answers.answer_text            -> answer_cipher:     " . migrateColumn($pdo, 'answers', 'id', 'answer_text', 'answer_cipher') . " row(s)\n";
echo "self_marks.reflection_comment  -> reflection_cipher:  " . migrateColumn($pdo, 'self_marks', 'id', 'reflection_comment', 'reflection_cipher') . " row(s)\n";
echo "marks.comment                  -> comment_cipher:     " . migrateColumn($pdo, 'marks', 'id', 'comment', 'comment_cipher') . " row(s)\n";
echo "annotations.data_json          -> data_cipher:        " . migrateColumn($pdo, 'annotations', 'id', 'data_json', 'data_cipher') . " row(s)\n";
echo "users.email/display_name       -> email_cipher/email_hash/display_name_cipher: " . migrateUsers($pdo) . " row(s)\n";

echo "\nDone. Next steps:\n";
echo "1. Spot-check the app still shows correct answers/comments/annotations for a real submission.\n";
echo "2. Run config/migrate_encrypt_step3_drop_plaintext.sql.\n";
echo "3. Delete this file (migrate_encrypt.php) from the server.\n";
