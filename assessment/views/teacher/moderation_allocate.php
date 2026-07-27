<?php
/** @var array $submission */
require_once __DIR__ . '/../../models/User.php';
$__title = 'Allocate moderation';
require __DIR__ . '/../partials/header.php';
$markers = User::all();
?>
<div class="panel">
    <h1>Allocate for moderation</h1>
    <form method="post" action="/assessment/teacher/moderation/<?= (int) $submission['id'] ?>/allocate">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthController::csrfToken()) ?>">
        <label>Second marker
            <select name="secondary_marker_id" required>
                <?php foreach ($markers as $m): if (!in_array($m['role'], [User::ROLE_TEACHER, User::ROLE_SUBJECT_LEADER], true)) continue; ?>
                    <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset>
            <legend>Mode</legend>
            <label><input type="radio" name="mode" value="open" checked> Open moderation (second marker sees primary marks)</label>
            <label><input type="radio" name="mode" value="blind"> Blind moderation (second marker marks independently)</label>
        </fieldset>
        <label>Tolerance (marks) <input type="number" step="0.5" name="tolerance" value="2"></label>
        <button type="submit" class="btn btn-primary">Allocate</button>
    </form>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
