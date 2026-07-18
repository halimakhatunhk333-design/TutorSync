<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit;
}

$tutorId = $_SESSION['user_id'];
$successMessage = "";

// Force the tutor to read & accept the service agreement before they can browse or apply
$agreementStmt = mysqli_prepare($conn, "SELECT id FROM tutor_agreements WHERE tutor_id = ?");
mysqli_stmt_bind_param($agreementStmt, "i", $tutorId);
mysqli_stmt_execute($agreementStmt);
$agreementResult = mysqli_stmt_get_result($agreementStmt);
$hasAgreed = mysqli_fetch_assoc($agreementResult) !== null;
mysqli_stmt_close($agreementStmt);

if (!$hasAgreed) {
    header("Location: agree_terms.php?return_to=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Convert a MySQL datetime string into a human-friendly "time ago" label
function timeAgo($datetime) {
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    if ($timestamp === false) return '';

    $diff = time() - $timestamp;
    if ($diff < 0) $diff = 0;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks == 1 ? '' : 's') . ' ago';
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months . ' month' . ($months == 1 ? '' : 's') . ' ago';
    } else {
        $years = floor($diff / 31536000);
        return $years . ' year' . ($years == 1 ? '' : 's') . ' ago';
    }
}

// Displays a budget consistently as "1000 BDT" regardless of how it was typed in
function format_budget($budget) {
    $budget = trim($budget ?? '');
    if ($budget === '') return '';
    if (stripos($budget, 'bdt') === false) {
        $budget .= ' BDT';
    }
    return $budget;
}

// Shortens the medium field to a compact code for the badge
function medium_short($medium) {
    $map = [
        'Bangla Medium'   => 'BM',
        'English Medium'  => 'EM',
        'English Version' => 'EV',
        'Madrasha Board'  => 'MB',
    ];
    return $map[$medium] ?? $medium;
}

// Calculate this tutor's profile completion percentage
$profStmt = mysqli_prepare($conn, "SELECT * FROM tutor_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($profStmt, "i", $tutorId);
mysqli_stmt_execute($profStmt);
$profResult = mysqli_stmt_get_result($profStmt);
$tutorProfile = mysqli_fetch_assoc($profResult) ?: [];
mysqli_stmt_close($profStmt);

$fieldsToCheck = [
    'address', 'date_of_birth', 'father_name', 'mother_name', 'emergency_contact',
    'university', 'school', 'degree', 'subjects', 'availability',
    'school_certificate_path', 'college_certificate_path', 'current_id_path', 'profile_pic_path'
];
$filledCount = 0;
foreach ($fieldsToCheck as $field) {
    if (!empty($tutorProfile[$field])) { $filledCount++; }
}
$tutorCompletionPercent = round(($filledCount / count($fieldsToCheck)) * 100);
$canApply = $tutorCompletionPercent >= 80;

// Handle apply action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_tuition_id'])) {
    $tuitionId = (int) $_POST['apply_tuition_id'];

    // Require fresh confirmation of the agreement on every application, even though
    // the tutor accepted the general terms once already (see tutor_agreements above).
    $agreedThisTime = isset($_POST['agree_confirm']) && $_POST['agree_confirm'] === '1';

    if (!$agreedThisTime) {
        $successMessage = "Please review and agree to the service agreement before applying.";
    } elseif (!$canApply) {
        $successMessage = "Please complete at least 80% of your profile before applying. You're currently at $tutorCompletionPercent%.";
    } else {
        $checkStmt = mysqli_prepare($conn, "SELECT id FROM tuition_applications WHERE tuition_id = ? AND tutor_id = ?");
        mysqli_stmt_bind_param($checkStmt, "ii", $tuitionId, $tutorId);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);

        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $successMessage = "You've already applied to this tuition.";
        } else {
            $insertStmt = mysqli_prepare($conn, "INSERT INTO tuition_applications (tuition_id, tutor_id) VALUES (?, ?)");
            mysqli_stmt_bind_param($insertStmt, "ii", $tuitionId, $tutorId);
            mysqli_stmt_execute($insertStmt);
            mysqli_stmt_close($insertStmt);

            // Notify the student who owns this tuition request (skip phone-in requests with no linked account)
            $ownerStmt = mysqli_prepare($conn, "SELECT student_id, subject FROM tuition_requests WHERE id = ?");
            mysqli_stmt_bind_param($ownerStmt, "i", $tuitionId);
            mysqli_stmt_execute($ownerStmt);
            $owner = mysqli_fetch_assoc(mysqli_stmt_get_result($ownerStmt));
            mysqli_stmt_close($ownerStmt);

            if (!empty($owner['student_id'])) {
                $notifMessage = "A tutor applied to your \"" . $owner['subject'] . "\" tuition request.";
                $notifLink = "view_applicants.php?tuition_id=" . $tuitionId;

                $notifStmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($notifStmt, "iss", $owner['student_id'], $notifMessage, $notifLink);
                mysqli_stmt_execute($notifStmt);
                mysqli_stmt_close($notifStmt);
            }

            $successMessage = "Application submitted successfully!";
        }
        mysqli_stmt_close($checkStmt);
    }
}

// --- Search / filter (subject, location, max budget) ---
// Values come from the GET form below so filters survive page reloads and can be shared/bookmarked.
$filterSubject  = trim($_GET['subject'] ?? '');
$filterLocation = trim($_GET['location'] ?? '');
$filterBudget   = trim($_GET['max_budget'] ?? '');

$conditions = ["tr.status = 'approved'"];
$paramTypes = "i"; // starts with the tutorId used in the already_applied subquery
$params     = [$tutorId];

if ($filterSubject !== '') {
    $conditions[] = "tr.subject LIKE ?";
    $paramTypes  .= "s";
    $params[]     = "%" . $filterSubject . "%";
}
if ($filterLocation !== '') {
    $conditions[] = "tr.location LIKE ?";
    $paramTypes  .= "s";
    $params[]     = "%" . $filterLocation . "%";
}
if ($filterBudget !== '' && is_numeric($filterBudget)) {
    // budget is stored as free text (e.g. "5000" or "5000 BDT"), so pull out the numeric part to compare
    $conditions[] = "CAST(REGEXP_REPLACE(tr.budget, '[^0-9]', '') AS UNSIGNED) <= ?";
    $paramTypes  .= "i";
    $params[]     = (int) $filterBudget;
}

$whereClause = implode(" AND ", $conditions);

$sql = "
    SELECT tr.*, 
    (SELECT COUNT(*) FROM tuition_applications ta WHERE ta.tuition_id = tr.id AND ta.tutor_id = ?) AS already_applied
    FROM tuition_requests tr
    WHERE $whereClause
    ORDER BY tr.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $paramTypes, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$tuitions = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Browse Tuitions — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238; --navy-deep:#0d1626; --ivory:#F6F3EC; --ivory-deep:#EDE8DC;
    --gold:#C9982F; --gold-soft:#E4C87A; --teal:#2F6F6B; --charcoal:#20232A; --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); }

.topbar{
    background:var(--navy); color:var(--ivory);
    padding:20px 40px; display:flex; justify-content:space-between; align-items:center;
}
.topbar h2{ font-family:'Fraunces', serif; font-size:1.3rem; }
.topbar-links{ display:flex; align-items:center; gap:22px; }
.topbar a{ color:var(--gold-soft); text-decoration:none; font-weight:600; font-size:0.9rem; }
.topbar a i{ margin-right:5px; }

.container{ max-width:850px; margin:30px auto; padding:0 20px 60px; }

.success-msg{
    background:#d1fae5; color:#065f46; padding:12px 18px;
    border-radius:8px; margin-bottom:20px; font-weight:500;
}

.filter-bar{
    background:#fff; border-radius:14px; padding:18px 20px; margin-bottom:22px;
    box-shadow:0 6px 20px rgba(21,34,56,0.06);
    display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;
}
.filter-field{ flex:1; min-width:150px; }
.filter-field label{ display:block; font-size:0.78rem; font-weight:600; color:var(--charcoal); margin-bottom:5px; }
.filter-field input{
    width:100%; padding:9px 12px; border:1px solid var(--line);
    border-radius:8px; font-size:0.88rem; background:var(--ivory); color:var(--charcoal);
}
.filter-field input:focus{ outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(47,111,107,0.12); }
.filter-actions{ display:flex; gap:8px; }
.filter-btn{
    background:var(--teal); color:#fff; border:none; padding:9px 18px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.85rem; white-space:nowrap;
}
.filter-btn:hover{ background:#265D59; }
.filter-clear{
    background:#fff; color:var(--charcoal); border:1px solid var(--line); padding:9px 14px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.85rem; text-decoration:none;
    display:inline-flex; align-items:center;
}

.tuition-card{
    background:#fff; border-radius:14px; padding:24px;
    margin-bottom:18px; box-shadow:0 6px 20px rgba(21,34,56,0.07);
}
.tuition-top{ display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin-bottom:10px; }
.tuition-card h3{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.15rem; }
.tuition-posted{
    font-size:0.78rem; color:#9CA3AF; white-space:nowrap;
    display:flex; align-items:center; gap:6px; margin-top:3px;
}
.tuition-meta{ display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
.tuition-meta span{
    background:var(--ivory); border:1px solid var(--line);
    padding:5px 12px; border-radius:20px; font-size:0.8rem; color:var(--charcoal);
}
.tuition-meta span i{ color:var(--teal); margin-right:5px; }
.medium-badge{
    background:rgba(47,111,107,0.12); border:1px solid var(--teal); color:var(--teal);
    padding:5px 11px; border-radius:20px; font-size:0.78rem; font-weight:700;
    letter-spacing:0.02em;
}
.tuition-notes{ color:#6b7280; font-size:0.9rem; margin-bottom:16px; line-height:1.5; }

.apply-btn{
    background:var(--teal); color:#fff; border:none;
    padding:10px 22px; border-radius:8px; cursor:pointer; font-weight:600; font-size:0.88rem;
}
.apply-btn:hover{ background:#265D59; }
.apply-btn:disabled{ background:#9CA3AF; cursor:not-allowed; }

.applied-tag{
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(47,111,107,0.1); color:var(--teal);
    padding:8px 16px; border-radius:8px; font-weight:600; font-size:0.85rem;
}

.empty-state{
    text-align:center; padding:60px 20px; color:#9CA3AF;
}
.empty-state i{ font-size:2.5rem; margin-bottom:14px; display:block; }

/* --- Agreement modal --- */
.modal-overlay{
    display:none; position:fixed; inset:0; background:rgba(13,22,38,0.55);
    align-items:center; justify-content:center; z-index:1000; padding:20px;
}
.modal-overlay.active{ display:flex; }
.modal-box{
    background:#fff; border-radius:14px; width:100%; max-width:620px;
    max-height:85vh; display:flex; flex-direction:column; overflow:hidden;
    box-shadow:0 20px 60px rgba(13,22,38,0.35);
}
.modal-header{
    padding:18px 22px; border-bottom:1px solid var(--line);
    display:flex; justify-content:space-between; align-items:center;
}
.modal-header h3{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.1rem; }
.modal-close{
    background:none; border:none; font-size:1.4rem; cursor:pointer; color:#9CA3AF; line-height:1;
}
.modal-body{ flex:1; overflow:hidden; background:var(--ivory); }
.modal-body iframe{ width:100%; height:100%; min-height:340px; border:none; }
.modal-footer{
    padding:16px 22px; border-top:1px solid var(--line);
    display:flex; flex-direction:column; gap:12px;
}
.modal-footer label{ font-size:0.85rem; display:flex; align-items:flex-start; gap:8px; }
.modal-actions{ display:flex; justify-content:flex-end; gap:10px; }
</style>
</head>
<body>

<div class="topbar">
    <h2>Browse Tuitions</h2>
    <div class="topbar-links">
        <a href="#" onclick="openAgreementModal(null); return false;"><i class="fas fa-file-contract"></i>View Agreement</a>
        <a href="tutor_dashboard.php"><i class="fas fa-arrow-left"></i>Back to Dashboard</a>
    </div>
</div>

<div class="container">
   <?php if (!$canApply): ?>
        <div class="success-msg" style="background:#FEF3C7; color:#92400E;">
            <i class="fas fa-triangle-exclamation"></i>
            Your profile is <?php echo $tutorCompletionPercent; ?>% complete. Complete at least 80% in your dashboard to unlock applying for tuitions.
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="success-msg"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <div class="filter-field">
            <label>Subject</label>
            <input type="text" name="subject" placeholder="e.g. Physics" value="<?php echo htmlspecialchars($filterSubject); ?>">
        </div>
        <div class="filter-field">
            <label>Location</label>
            <input type="text" name="location" placeholder="e.g. Dhanmondi" value="<?php echo htmlspecialchars($filterLocation); ?>">
        </div>
        <div class="filter-field">
            <label>Max Budget (BDT)</label>
            <input type="number" name="max_budget" placeholder="e.g. 8000" value="<?php echo htmlspecialchars($filterBudget); ?>">
        </div>
        <div class="filter-actions">
            <button class="filter-btn" type="submit"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($filterSubject !== '' || $filterLocation !== '' || $filterBudget !== ''): ?>
                <a href="browse_tuitions.php" class="filter-clear">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (count($tuitions) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <?php echo (count($conditions) > 1) ? "No tuitions match your filters." : "No tuitions available right now. Check back soon!"; ?>
        </div>
    <?php else: ?>
        <?php foreach ($tuitions as $tuition): ?>
            <div class="tuition-card">
                <div class="tuition-top">
                    <h3><?php echo htmlspecialchars($tuition['subject']); ?></h3>
                    <div class="tuition-posted">
                        <i class="fas fa-clock"></i> Posted <?php echo timeAgo($tuition['created_at']); ?>
                    </div>
                </div>
                <div class="tuition-meta">
                    <?php if ($tuition['class_level']): ?>
                        <span><i class="fas fa-layer-group"></i><?php echo htmlspecialchars($tuition['class_level']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($tuition['medium'])): ?>
                        <span class="medium-badge"><?php echo htmlspecialchars(medium_short($tuition['medium'])); ?></span>
                    <?php endif; ?>
                    <?php if ($tuition['preferred_schedule']): ?>
                        <span><i class="fas fa-clock"></i><?php echo htmlspecialchars($tuition['preferred_schedule']); ?></span>
                    <?php endif; ?>
                    <?php if ($tuition['budget']): ?>
                        <span><i class="fas fa-dollar-sign"></i><?php echo htmlspecialchars(format_budget($tuition['budget'])); ?></span>
                    <?php endif; ?>
                    <?php if ($tuition['location']): ?>
                        <span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($tuition['location']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($tuition['additional_notes']): ?>
                    <div class="tuition-notes"><?php echo nl2br(htmlspecialchars($tuition['additional_notes'])); ?></div>
                <?php endif; ?>

                <?php if ($tuition['already_applied'] > 0): ?>
                    <span class="applied-tag"><i class="fas fa-check-circle"></i> Already Applied</span>
                <?php elseif (!$canApply): ?>
                    <button class="apply-btn" disabled><i class="fas fa-lock"></i> Complete Profile to Apply</button>
                <?php else: ?>
                    <button class="apply-btn" type="button" onclick="openAgreementModal(<?php echo (int) $tuition['id']; ?>)">
                        Apply for this Tuition
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<!-- Agreement modal: reused for "View Agreement" (pendingTuitionId = null) and per-tuition apply flow -->
<div class="modal-overlay" id="agreementModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Service Agreement</h3>
            <button class="modal-close" type="button" onclick="closeAgreementModal()">&times;</button>
        </div>
        <div class="modal-body">
            <iframe id="agreementFrame" src="about:blank" title="Service Agreement"></iframe>
        </div>
        <div class="modal-footer" id="applyModalFooter">
            <label>
                <input type="checkbox" id="agreeCheckbox" onchange="toggleAgreeBtn()">
                I have read and agree to the terms above
            </label>
            <div class="modal-actions">
                <button class="filter-clear" type="button" onclick="closeAgreementModal()">Cancel</button>
                <button class="apply-btn" id="confirmApplyBtn" disabled onclick="confirmApply()">Agree &amp; Apply</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingTuitionId = null;

function openAgreementModal(tuitionId) {
    pendingTuitionId = tuitionId; // null when just viewing, an id when applying
    document.getElementById('agreeCheckbox').checked = false;
    document.getElementById('confirmApplyBtn').disabled = true;

    // View-only mode (from "View Agreement" link) hides the apply action entirely
    document.getElementById('applyModalFooter').style.display = tuitionId === null ? 'none' : 'flex';

    document.getElementById('agreementFrame').src = 'agree_terms.php?view=1&embed=1';
    document.getElementById('agreementModal').classList.add('active');
}

function closeAgreementModal() {
    document.getElementById('agreementModal').classList.remove('active');
    document.getElementById('agreementFrame').src = 'about:blank';
    pendingTuitionId = null;
}

function toggleAgreeBtn() {
    document.getElementById('confirmApplyBtn').disabled = !document.getElementById('agreeCheckbox').checked;
}

function confirmApply() {
    if (!pendingTuitionId) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML =
        '<input type="hidden" name="apply_tuition_id" value="' + pendingTuitionId + '">' +
        '<input type="hidden" name="agree_confirm" value="1">';
    document.body.appendChild(form);
    form.submit();
}

document.getElementById('agreementModal').addEventListener('click', function(e) {
    if (e.target === this) closeAgreementModal();
});
</script>

</body>
</html>
