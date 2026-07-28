<?php
/** @var array $branding */
$__title = 'About';
require __DIR__ . '/../partials/header.php';
$__productName = (string) config('product_name');
?>
<div class="panel">
    <h1>About</h1>
    <p>
        This is <strong><?= htmlspecialchars($__productName) ?></strong>, an online assessment
        platform for schools - set tests, mark them on screen, moderate them, and let students see
        their own work and (when you choose to) their marks and grade. It's deployed here for
        <strong><?= htmlspecialchars($branding['school_name']) ?></strong>, with their own logo and
        colours applied (see Admin &gt; Branding, if you have access).
    </p>

    <h2>What it does</h2>
    <ul>
        <li><strong>Papers.</strong> Build a digital paper question-by-question (multiple choice, short answer, extended text, each with its own mark scheme), or attach a PDF exam paper and mark scheme for students to write directly onto.</li>
        <li><strong>Assigning tests.</strong> Assign a paper to a class, with a due date, and optionally push it straight to the class's Microsoft Teams assignment.</li>
        <li><strong>Marking.</strong> Mark on screen with pen, highlighter, text comments and stamps directly over a student's typed answers or handwritten script - annotate exactly where you'd write on paper.</li>
        <li><strong>Moderation.</strong> Send a submission for a second opinion, blind or open, with variances outside an agreed tolerance automatically flagged for review.</li>
        <li><strong>Self-marking.</strong> Once you release it, a student can compare their own answer against the mark scheme and record how they think they did, before you moderate it.</li>
        <li><strong>Results and reporting.</strong> Per-paper results, department-wide results across a subject, and institution-wide reporting for leadership.</li>
    </ul>

    <p>See the <a href="/assessment/instructions">Instructions</a> page for a walkthrough of each of these by role.</p>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
