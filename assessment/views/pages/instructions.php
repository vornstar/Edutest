<?php
/** @var array $user */
$__title = 'Instructions';
require __DIR__ . '/../partials/header.php';
?>
<div class="panel">
    <h1>Instructions</h1>
    <p class="autosave-status">A walkthrough by role. Only the sections relevant to your own role are worth reading closely, but everyone can see all of them here.</p>

    <h2>Students</h2>
    <ul>
        <li><strong>My tests</strong> lists everything currently assigned to you. Open one to start it - your work autosaves as you go, so you can safely close the tab and come back.</li>
        <li>For a PDF-type paper, type or write directly onto the exam paper using the Pen, Text and Highlighter tools. For a digital paper, type your answer under each question.</li>
        <li>Once you submit, your teacher can still see your work but you can't change it - if you started over on a page mid-test, only your most recent attempt counts, though your teacher can still see earlier ones.</li>
        <li><strong>Self-marking</strong>: if your teacher turns this on for your test, you'll get a link to compare your own answers against the official mark scheme and record what you think you scored, before your teacher moderates it.</li>
        <li>If your teacher releases your grade, you'll see it on your submission page, along with the grade boundaries it was worked out from.</li>
    </ul>

    <h2>Teachers and Subject Leaders</h2>
    <ul>
        <li><strong>Papers</strong> is where you build or upload a test. A digital paper is built question by question, each with its own mark scheme and marks; a PDF paper is a single exam paper (and mark scheme) file students write directly onto, with one overall score.</li>
        <li><strong>Bulk upload</strong> lets you drop several exam paper/mark scheme PDFs into a shared holding area at once, then attach each one to the paper it belongs to whenever you're ready - useful when digitising a batch of past papers.</li>
        <li>Group related papers together (e.g. by topic or course) from a paper's own page, or manage groups directly under Papers &gt; Groups.</li>
        <li>Try a paper yourself first with <strong>Start test</strong> on its page - the exact same flow a student gets, so you can check it before anyone else sees it.</li>
        <li><strong>Assign to class</strong> sets a due date and, if the class is linked to Teams, can push the assignment there too - grades sync back to Teams automatically once you mark or moderate a submission.</li>
        <li><strong>Classes &gt; Open test windows</strong> lists everything you've assigned. Close a window early to stop further work being accepted, or cancel a test outright to pull it from students' lists entirely (nothing is deleted - a cancelled test can be restored, or found under Deleted tests).</li>
        <li><strong>Marking</strong> shows everything awaiting a mark. Mark on screen with Pen, Highlighter, Text and Stamps (including your own custom stamps, which only you can see) directly over the student's own answers.</li>
        <li><strong>Moderation</strong> is for a second opinion on a submission - open or blind (primary scores hidden until you submit your own). Variances outside the agreed tolerance are automatically flagged for the Subject Leader.</li>
        <li><strong>Results</strong> under a paper's own page shows every submission against it; <strong>Subject results</strong> shows every result across your subject, including colleagues' classes.</li>
        <li>Subject Leaders additionally see <strong>Flagged variances</strong> (moderation disagreements needing a decision) and manage/delete authority over every paper in their subject, not just their own.</li>
    </ul>

    <h2>Data role</h2>
    <ul>
        <li><strong>Reports</strong> gives an institution-wide read-only view: submissions by status, average scores by paper, and moderation variance - never anything that lets you change data, only see it.</li>
    </ul>

    <h2>Admins</h2>
    <ul>
        <li><strong>Users</strong>: set a colleague's role and, for a Teacher or Subject Leader, which subject they belong to - this drives what papers they can see/manage. You can pre-provision someone by email before they've ever signed in.</li>
        <li><strong>Subjects</strong>: the canonical list every subject dropdown offers across the platform.</li>
        <li><strong>Branding</strong>: your school's own name, logo and brand colours, shown in the header for everyone.</li>
        <li><strong>Audit log</strong>: a record of role changes and other administrative actions.</li>
        <li><strong>OneDrive setup</strong>: checks the shared OneDrive folder link (used to store exam paper PDFs, scanned scripts, and mark schemes) is configured and working.</li>
    </ul>
</div>
<?php require __DIR__ . '/../partials/footer.php'; ?>
