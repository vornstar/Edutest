<?php
/** @var array $papers */
require_once __DIR__ . '/../../models/Database.php';
require_once __DIR__ . '/../../models/Crypto.php';
$__title = 'Marking queue';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Marking queue</h1>
    <table class="data-table">
        <thead><tr><th>Paper</th><th>Student</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
        <?php
        // Build the marking queue directly: every submission belonging to an
        // assignment of one of this teacher's papers that is awaiting marking.
        $paperIds = array_column($papers, 'id');
        $rows = [];
        foreach ($papers as $paper) {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'SELECT s.*, u.display_name_cipher AS student_name_cipher, p.title AS paper_title, a.class_id FROM submissions s
                 INNER JOIN test_assignments a ON a.id = s.assignment_id
                 INNER JOIN users u ON u.id = s.student_id
                 INNER JOIN papers p ON p.id = a.paper_id
                 WHERE p.id = :paper_id AND s.status IN ("submitted", "pending_moderation")
                 ORDER BY s.submitted_at'
            );
            $stmt->execute(['paper_id' => $paper['id']]);
            foreach ($stmt->fetchAll() as $row) {
                $row['student_name'] = Crypto::decrypt($row['student_name_cipher']);
                unset($row['student_name_cipher']);
                $rows[] = $row;
            }
        }
        ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['paper_title']) ?>
                    <?php if ($row['class_id'] === null): ?>
                        <span class="autosave-status" title="Your own self-test, not a real student">(TEST)</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($row['student_name']) ?></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= htmlspecialchars($row['submitted_at'] ?? '—') ?></td>
                <td><a class="btn" href="/assessment/teacher/marking/<?= (int) $row['id'] ?>">Mark</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5">Nothing awaiting marking.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
