<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$successMessage = "";

// Turns a MySQL datetime into "3 hours ago" style text
function time_ago($datetime) {
    if (empty($datetime)) return '';
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return $diff <= 1 ? "just now" : $diff . " seconds ago";
    }
    $mins = floor($diff / 60);
    if ($mins < 60) {
        return $mins . ($mins == 1 ? " minute ago" : " minutes ago");
    }
    $hours = floor($diff / 3600);
    if ($hours < 24) {
        return $hours . ($hours == 1 ? " hour ago" : " hours ago");
    }
    $days = floor($diff / 86400);
    if ($days < 7) {
        return $days . ($days == 1 ? " day ago" : " days ago");
    }
    $weeks = floor($days / 7);
    if ($weeks < 5) {
        return $weeks . ($weeks == 1 ? " week ago" : " weeks ago");
    }
    return date("M j, Y", $timestamp);
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

// Notifies tutors whose preferred_location matches the tuition's location.
// Used both when admin approves a student's request AND when admin posts directly on a student's behalf.
function notifyMatchingTutors($conn, $tuitionId, $subject, $tuitionLocation) {
    if (empty($tuitionLocation)) return;

    $allTutorsStmt = mysqli_prepare($conn, "SELECT user_id, preferred_location FROM tutor_profiles WHERE preferred_location IS NOT NULL AND preferred_location != ''");
    mysqli_stmt_execute($allTutorsStmt);
    $allTutorsResult = mysqli_stmt_get_result($allTutorsStmt);

    while ($tutor = mysqli_fetch_assoc($allTutorsResult)) {
        $tutorLocations = array_map('trim', explode(",", $tutor['preferred_location']));
        foreach ($tutorLocations as $loc) {
            if ($loc !== '' && stripos($tuitionLocation, $loc) !== false) {
                $notifMsg = "A new $subject tuition was posted in $tuitionLocation — matches your preferred location ($loc)!";
                $notifLink = "browse_tuitions.php";
                $notifStmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($notifStmt, "iss", $tutor['user_id'], $notifMsg, $notifLink);
                mysqli_stmt_execute($notifStmt);
                mysqli_stmt_close($notifStmt);
                break;
            }
        }
    }
    mysqli_stmt_close($allTutorsStmt);
}

// Handle approve/reject/direct-post actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $formType = $_POST['form_type'] ?? '';

    if (isset($_POST['approve_id'])) {
        $tuitionId = (int) $_POST['approve_id'];

        $fetchStmt = mysqli_prepare($conn, "SELECT subject, location FROM tuition_requests WHERE id = ?");
        mysqli_stmt_bind_param($fetchStmt, "i", $tuitionId);
        mysqli_stmt_execute($fetchStmt);
        $fetchResult = mysqli_stmt_get_result($fetchStmt);
        $tuitionData = mysqli_fetch_assoc($fetchResult);
        mysqli_stmt_close($fetchStmt);

        $subject = $tuitionData['subject'];
        $tuitionLocation = $tuitionData['location'];

        $approveStmt = mysqli_prepare($conn, "UPDATE tuition_requests SET status = 'approved', approved_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($approveStmt, "i", $tuitionId);
        mysqli_stmt_execute($approveStmt);
        mysqli_stmt_close($approveStmt);

        notifyMatchingTutors($conn, $tuitionId, $subject, $tuitionLocation);

        $successMessage = "Tuition request approved and matching tutors notified.";
    }

    if (isset($_POST['reject_id'])) {
        $tuitionId = (int) $_POST['reject_id'];
        $rejectStmt = mysqli_prepare($conn, "UPDATE tuition_requests SET status = 'closed' WHERE id = ?");
        mysqli_stmt_bind_param($rejectStmt, "i", $tuitionId);
        mysqli_stmt_execute($rejectStmt);
        mysqli_stmt_close($rejectStmt);
        $successMessage = "Tuition request rejected.";
    }

    // Admin posts a tuition directly from a phone call — no student account required.
    // This skips the pending step entirely — it's approved and visible to tutors immediately.
    if ($formType === 'admin_post_tuition') {
        $contactName  = trim($_POST['contact_name'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $subject      = trim($_POST['subject'] ?? '');
        $classLevel   = trim($_POST['tuition_class_level'] ?? '');
        $medium       = trim($_POST['medium'] ?? '');
        $schedule     = trim($_POST['preferred_schedule'] ?? '');
        $budget       = trim($_POST['budget'] ?? '');
        $location     = trim($_POST['location'] ?? '');
        $notes        = trim($_POST['additional_notes'] ?? '');

        if ($contactName === '' || $subject === '') {
            $successMessage = "Please enter the student's name and a subject.";
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO tuition_requests (student_id, contact_name, contact_phone, subject, class_level, medium, preferred_schedule, budget, location, additional_notes, status, approved_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
            ");
            mysqli_stmt_bind_param($stmt, "sssssssss", $contactName, $contactPhone, $subject, $classLevel, $medium, $schedule, $budget, $location, $notes);
            mysqli_stmt_execute($stmt);
            $newTuitionId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            notifyMatchingTutors($conn, $newTuitionId, $subject, $location);

            $successMessage = "Tuition posted and matching tutors notified.";
        }
    }
}

// Fetch pending requests
$pendingStmt = mysqli_prepare($conn, "
    SELECT tr.*, 
    COALESCE(u.name, tr.contact_name) AS student_name,
    u.email AS student_email
    FROM tuition_requests tr 
    LEFT JOIN users u ON tr.student_id = u.id 
    WHERE tr.status = 'pending' 
    ORDER BY tr.created_at DESC
");
mysqli_stmt_execute($pendingStmt);
$pendingResult = mysqli_stmt_get_result($pendingStmt);
$pendingRequests = mysqli_fetch_all($pendingResult, MYSQLI_ASSOC);
mysqli_stmt_close($pendingStmt);

// Fetch approved requests
$approvedStmt = mysqli_prepare($conn, "
    SELECT tr.*, 
    COALESCE(u.name, tr.contact_name) AS student_name,
    (SELECT COUNT(*) FROM tuition_applications ta WHERE ta.tuition_id = tr.id) AS applicant_count
    FROM tuition_requests tr 
    LEFT JOIN users u ON tr.student_id = u.id 
    WHERE tr.status = 'approved' 
    ORDER BY tr.approved_at DESC
");
mysqli_stmt_execute($approvedStmt);
$approvedResult = mysqli_stmt_get_result($approvedStmt);
$approvedRequests = mysqli_fetch_all($approvedResult, MYSQLI_ASSOC);
mysqli_stmt_close($approvedStmt);

// For each approved request, fetch its applicants
foreach ($approvedRequests as &$req) {
    $appStmt = mysqli_prepare($conn, "
        SELECT ta.tutor_id, ta.status, u.name, u.email, tp.profile_pic_path, tp.subjects
        FROM tuition_applications ta
        JOIN users u ON ta.tutor_id = u.id
        LEFT JOIN tutor_profiles tp ON tp.user_id = u.id
        WHERE ta.tuition_id = ?
        ORDER BY ta.applied_at ASC
    ");
    mysqli_stmt_bind_param($appStmt, "i", $req['id']);
    mysqli_stmt_execute($appStmt);
    $appResult = mysqli_stmt_get_result($appStmt);
    $req['applicants'] = mysqli_fetch_all($appResult, MYSQLI_ASSOC);
    mysqli_stmt_close($appStmt);
}
unset($req);

// Confirmed matches
$matchedStmt = mysqli_prepare($conn, "
    SELECT tr.subject, tr.location,
           COALESCE(u_student.name, tr.contact_name) AS student_name,
           COALESCE(u_student.phone, tr.contact_phone) AS student_phone,
           u_tutor.name AS tutor_name, u_tutor.phone AS tutor_phone
    FROM tuition_requests tr
    JOIN tuition_applications ta ON ta.tuition_id = tr.id AND ta.status = 'selected'
    LEFT JOIN users u_student ON tr.student_id = u_student.id
    JOIN users u_tutor ON ta.tutor_id = u_tutor.id
    WHERE tr.status = 'matched'
    ORDER BY tr.created_at DESC
");
mysqli_stmt_execute($matchedStmt);
$matchedResult = mysqli_stmt_get_result($matchedStmt);
$matchedTuitions = mysqli_fetch_all($matchedResult, MYSQLI_ASSOC);
mysqli_stmt_close($matchedStmt);

// Quick stats
$totalStudentsStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'student'");
mysqli_stmt_execute($totalStudentsStmt);
$totalStudents = mysqli_fetch_assoc(mysqli_stmt_get_result($totalStudentsStmt))['c'];
mysqli_stmt_close($totalStudentsStmt);

$totalTutorsStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'tutor'");
mysqli_stmt_execute($totalTutorsStmt);
$totalTutors = mysqli_fetch_assoc(mysqli_stmt_get_result($totalTutorsStmt))['c'];
mysqli_stmt_close($totalTutorsStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238; --navy-deep:#0d1626; --ivory:#F6F3EC; --ivory-deep:#EDE8DC;
    --gold:#C9982F; --gold-soft:#E4C87A; --teal:#2F6F6B; --charcoal:#20232A; --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); min-height:100vh; }

.shell{ display:flex; min-height:100vh; }
.sidebar{
    width:250px; background:linear-gradient(180deg, var(--gold) 0%, #a87c22 100%);
    color:var(--navy-deep); padding:30px 22px; position:fixed; top:0; bottom:0; left:0;
    overflow-y:auto;
}
.sidebar-brand{ font-family:'Fraunces', serif; font-size:1.3rem; font-weight:700; margin-bottom:6px; }
.sidebar-role{ font-size:0.7rem; letter-spacing:0.15em; text-transform:uppercase; opacity:0.75; margin-bottom:30px; }
.nav-link{
    display:flex; align-items:center; gap:12px; padding:12px 14px; border-radius:8px;
    color:var(--navy-deep); text-decoration:none; font-size:0.92rem; font-weight:600; margin-bottom:4px;
}
.nav-link:hover{ background:rgba(21,34,56,0.1); }
.sidebar-logout{ margin-top:30px; padding-top:20px; border-top:1px solid rgba(21,34,56,0.2); }
.sidebar-logout a{ color:#7C2D12; text-decoration:none; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:10px; }

.main{ margin-left:250px; flex:1; padding:36px 44px 80px; }

.success-msg{
    background:#d1fae5; color:#065f46; padding:12px 18px; border-radius:8px;
    margin-bottom:22px; font-weight:500;
}

.stats-row{ display:flex; gap:20px; margin-bottom:10px; }
.stat-box{
    flex:1; background:#fff; border-radius:14px; padding:22px;
    box-shadow:0 6px 20px rgba(21,34,56,0.06);
}
.stat-box .num{ font-family:'Fraunces', serif; font-size:2rem; color:var(--navy); }
.stat-box .label{ font-size:0.85rem; color:#8a8578; margin-top:4px; }

.section-title{
    font-family:'Fraunces', serif; font-size:1.2rem; color:var(--navy);
    margin:34px 0 16px; display:flex; align-items:center; gap:10px;
    scroll-margin-top:20px;
}
.section-title i{
    width:34px; height:34px; border-radius:9px; background:rgba(21,34,56,0.06);
    display:inline-flex; align-items:center; justify-content:center;
    font-size:0.95rem; color:var(--teal);
}

.section-desc{ color:#8a8578; font-size:0.85rem; margin:-10px 0 18px; }

.request-card{
    background:#fff; border-radius:14px; padding:22px 24px; margin-bottom:16px;
    box-shadow:0 6px 20px rgba(21,34,56,0.06);
}
.request-top{ display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; }
.request-top h4{ color:var(--navy); font-size:1.1rem; font-family:'Fraunces', serif; }
.student-info{ font-size:0.85rem; color:#6b7280; margin-top:2px; }
.phone-contact-badge{
    display:inline-flex; align-items:center; gap:5px; margin-left:8px;
    background:rgba(201,152,47,0.14); color:#92400E; font-size:0.7rem; font-weight:700;
    padding:2px 9px; border-radius:20px; text-transform:uppercase; letter-spacing:0.03em;
}
.request-posted{ font-size:0.78rem; color:#9CA3AF; margin-top:6px; display:flex; align-items:center; gap:6px; }

.tuition-meta{ display:flex; flex-wrap:wrap; gap:10px; margin:12px 0; }
.tuition-meta span{
    background:var(--ivory); border:1px solid var(--line);
    padding:5px 12px; border-radius:20px; font-size:0.8rem;
}
.tuition-meta span i{ color:var(--teal); margin-right:5px; }
.tuition-notes{ color:#6b7280; font-size:0.88rem; margin-bottom:14px; }

.action-row{ display:flex; gap:10px; }
.btn-approve{
    background:var(--teal); color:#fff; border:none; padding:9px 20px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.85rem;
}
.btn-approve:hover{ background:#265D59; }
.btn-reject{
    background:#fff; color:#DC2626; border:1px solid #DC2626; padding:9px 20px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.85rem;
}
.btn-reject:hover{ background:#FEF2F2; }

.applicant-count-badge{
    background:rgba(47,111,107,0.1); color:var(--teal);
    padding:6px 14px; border-radius:8px; font-size:0.82rem; font-weight:600;
    display:inline-flex; align-items:center; gap:6px;
}

.applicants-list{
    margin-top:16px; padding-top:16px; border-top:1px dashed var(--line);
}
.applicants-list h5{ font-size:0.85rem; color:#8a8578; margin-bottom:10px; text-transform:uppercase; letter-spacing:0.04em; }
.mini-applicant{
    display:flex; align-items:center; gap:10px; padding:8px 0;
}
.mini-pic{ width:36px; height:36px; border-radius:50%; object-fit:cover; background:var(--ivory-deep); }
.mini-pic-placeholder{
    width:36px; height:36px; border-radius:50%; background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center; color:var(--line);
}
.mini-applicant-info{ flex:1; font-size:0.85rem; }
.mini-applicant-info b{ color:var(--navy); }
.mini-applicant-info span{ color:#8a8578; font-size:0.78rem; display:block; }
.mini-view-link{
    color:var(--teal); text-decoration:none; font-weight:600; font-size:0.8rem;
    border:1px solid var(--teal); padding:5px 12px; border-radius:6px;
}
.selected-badge{
    background:var(--teal); color:#fff; font-size:0.72rem; font-weight:700;
    padding:3px 10px; border-radius:20px;
}
.no-applicants{ color:#9CA3AF; font-size:0.85rem; }

.empty-state{ text-align:center; padding:40px 20px; color:#9CA3AF; background:#fff; border-radius:14px; }
.empty-state i{ font-size:2rem; margin-bottom:10px; display:block; }

/* Post-for-student form */
.section-card{
    background:#fff; border-radius:16px; padding:28px 30px;
    margin-bottom:24px; box-shadow:0 6px 24px rgba(21,34,56,0.06);
    scroll-margin-top:20px;
}
.section-card h3{
    font-family:'Fraunces', serif; font-size:1.15rem; color:var(--navy);
    margin-bottom:4px; display:flex; align-items:center; gap:10px;
}
.section-card > .section-desc{ margin-top:0; }
.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group{ margin-bottom:16px; }
label{ display:block; margin-bottom:7px; font-weight:600; font-size:0.85rem; color:var(--charcoal); }
input[type=text], textarea, select{
    width:100%; padding:11px 14px; border:1px solid var(--line);
    border-radius:8px; font-size:14px; background:var(--ivory); color:var(--charcoal);
}
input:focus, textarea:focus, select:focus{
    outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(47,111,107,0.12);
}
textarea{ min-height:80px; resize:vertical; }

@media (max-width:900px){
    .sidebar{ display:none; }
    .main{ margin-left:0; padding:24px; }
    .stats-row{ flex-direction:column; }
    .grid-2{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="shell">

    <div class="sidebar">
        <div class="sidebar-brand">TutorSync</div>
        <div class="sidebar-role">Admin Panel</div>

        <a href="#post-for-student" class="nav-link"><i class="fas fa-phone"></i> Post for a Student</a>
        <a href="#pending" class="nav-link"><i class="fas fa-hourglass-half"></i> Pending Requests</a>
        <a href="#approved" class="nav-link"><i class="fas fa-check-circle"></i> Approved Requests</a>
        <a href="#matched" class="nav-link"><i class="fas fa-handshake"></i> Confirmed Matches</a>

        <div class="sidebar-logout">
            <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <div class="main">

        <?php if ($successMessage): ?>
            <div class="success-msg"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-box">
                <div class="num"><?php echo $totalStudents; ?></div>
                <div class="label">Total Students</div>
            </div>
            <div class="stat-box">
                <div class="num"><?php echo $totalTutors; ?></div>
                <div class="label">Total Tutors</div>
            </div>
            <div class="stat-box">
                <div class="num"><?php echo count($pendingRequests); ?></div>
                <div class="label">Pending Requests</div>
            </div>
        </div>

        <!-- Post a tuition on behalf of a student (e.g. they called in) -->
        <div class="section-card" id="post-for-student">
            <h3><i class="fas fa-phone"></i> Post a Tuition Request for a Student</h3>
            <p class="section-desc">Use this when a student contacts you directly (phone call, in person, etc.) instead of posting it themselves. This goes live immediately for tutors — no separate approval step.</p>
            <form method="POST">
                <input type="hidden" name="form_type" value="admin_post_tuition">

                <div class="grid-2">
                    <div class="form-group">
                        <label>Student's Name</label>
                        <input type="text" name="contact_name" placeholder="Full name" required>
                    </div>
                    <div class="form-group">
                        <label>Student's Phone Number</label>
                        <input type="text" name="contact_phone" placeholder="e.g. 01712345678">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" placeholder="e.g. Physics, English, Math" required>
                    </div>
                    <div class="form-group">
                        <label>Class / Level</label>
                        <input type="text" name="tuition_class_level" placeholder="e.g. Class 10, A-Levels">
                    </div>
                    <div class="form-group">
                        <label>Medium</label>
                        <select name="medium">
                            <option value="">Select medium</option>
                            <option value="Bangla Medium">Bangla Medium</option>
                            <option value="English Medium">English Medium</option>
                            <option value="English Version">English Version</option>
                            <option value="Madrasha Board">Madrasha Board</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Preferred Schedule</label>
                        <input type="text" name="preferred_schedule" placeholder="e.g. Weekdays evening, 3 days/week">
                    </div>
                    <div class="form-group">
                        <label>Budget (per month)</label>
                        <input type="text" name="budget" placeholder="e.g. 5000">
                    </div>
                </div>
                <div class="form-group">
                    <label>Location / Area</label>
                    <input type="text" name="location" placeholder="e.g. Dhanmondi, Dhaka">
                </div>
                <div class="form-group">
                    <label>Additional Notes</label>
                    <textarea name="additional_notes" placeholder="Anything specific the student mentioned..."></textarea>
                </div>

                <button class="btn-approve" type="submit"><i class="fas fa-paper-plane"></i> Post &amp; Notify Tutors</button>
            </form>
        </div>

        <!-- Pending Requests -->
        <div class="section-title" id="pending">
            <i class="fas fa-hourglass-half"></i> Pending Requests
        </div>

        <?php if (count($pendingRequests) === 0): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i>No pending requests right now.</div>
        <?php else: ?>
            <?php foreach ($pendingRequests as $req): ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h4><?php echo htmlspecialchars($req['subject']); ?></h4>
                            <div class="student-info">
                                <?php echo htmlspecialchars($req['student_name']); ?> · <?php echo htmlspecialchars($req['student_email']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="tuition-meta">
                        <?php if ($req['class_level']): ?><span><i class="fas fa-layer-group"></i><?php echo htmlspecialchars($req['class_level']); ?></span><?php endif; ?>
                        <?php if (!empty($req['medium'])): ?><span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($req['medium']); ?></span><?php endif; ?>
                        <?php if ($req['preferred_schedule']): ?><span><i class="fas fa-clock"></i><?php echo htmlspecialchars($req['preferred_schedule']); ?></span><?php endif; ?>
                        <?php if ($req['budget']): ?><span><i class="fas fa-dollar-sign"></i><?php echo htmlspecialchars(format_budget($req['budget'])); ?></span><?php endif; ?>
                        <?php if ($req['location']): ?><span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($req['location']); ?></span><?php endif; ?>
                    </div>
                    <?php if ($req['additional_notes']): ?>
                        <div class="tuition-notes"><?php echo nl2br(htmlspecialchars($req['additional_notes'])); ?></div>
                    <?php endif; ?>
                    <div class="request-posted">
                        <i class="fas fa-clock"></i> Submitted <?php echo time_ago($req['created_at']); ?>
                    </div>

                    <div class="action-row" style="margin-top:14px;">
                        <form method="POST">
                            <input type="hidden" name="approve_id" value="<?php echo $req['id']; ?>">
                            <button class="btn-approve" type="submit"><i class="fas fa-check"></i> Approve</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="reject_id" value="<?php echo $req['id']; ?>">
                            <button class="btn-reject" type="submit"><i class="fas fa-xmark"></i> Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Approved Requests -->
        <div class="section-title" id="approved">
            <i class="fas fa-check-circle"></i> Approved Requests
        </div>

        <?php if (count($approvedRequests) === 0): ?>
            <div class="empty-state"><i class="fas fa-folder-open"></i>No approved requests yet.</div>
        <?php else: ?>
            <?php foreach ($approvedRequests as $req): ?>
                <div class="request-card">
                    <div class="request-top">
                        <div>
                            <h4><?php echo htmlspecialchars($req['subject']); ?></h4>
                            <div class="student-info">
                                <?php echo htmlspecialchars($req['student_name']); ?>
                                <?php if (empty($req['student_id'])): ?>
                                    <span class="phone-contact-badge"><i class="fas fa-phone"></i> Phone contact</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="applicant-count-badge"><i class="fas fa-users"></i> <?php echo $req['applicant_count']; ?> applied</span>
                    </div>
                    <div class="tuition-meta">
                        <?php if ($req['class_level']): ?><span><i class="fas fa-layer-group"></i><?php echo htmlspecialchars($req['class_level']); ?></span><?php endif; ?>
                        <?php if (!empty($req['medium'])): ?><span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($req['medium']); ?></span><?php endif; ?>
                        <?php if ($req['budget']): ?><span><i class="fas fa-dollar-sign"></i><?php echo htmlspecialchars(format_budget($req['budget'])); ?></span><?php endif; ?>
                        <?php if ($req['location']): ?><span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($req['location']); ?></span><?php endif; ?>
                    </div>
                    <div class="request-posted">
                        <i class="fas fa-check-circle"></i> Approved <?php echo time_ago($req['approved_at']); ?>
                    </div>

                    <!-- Applicants for this specific request -->
                    <div class="applicants-list">
                        <h5>Applicants</h5>
                        <?php if (count($req['applicants']) === 0): ?>
                            <div class="no-applicants">No tutors have applied yet.</div>
                        <?php else: ?>
                            <?php foreach ($req['applicants'] as $app): ?>
                                <div class="mini-applicant">
                                    <?php if (!empty($app['profile_pic_path'])): ?>
                                        <img src="uploads/<?php echo htmlspecialchars($app['profile_pic_path']); ?>" class="mini-pic" alt="">
                                    <?php else: ?>
                                        <div class="mini-pic-placeholder"><i class="fas fa-user"></i></div>
                                    <?php endif; ?>
                                    <div class="mini-applicant-info">
                                        <b><?php echo htmlspecialchars($app['name']); ?></b>
                                        <span><?php echo htmlspecialchars($app['subjects'] ?? ''); ?></span>
                                    </div>
                                    <?php if ($app['status'] === 'selected'): ?>
                                        <span class="selected-badge"><i class="fas fa-star"></i> Selected</span>
                                    <?php endif; ?>
                                    <a href="view_tutor_profile.php?tutor_id=<?php echo $app['tutor_id']; ?>" class="mini-view-link">View Profile</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Confirmed Matches -->
        <div class="section-title" id="matched">
            <i class="fas fa-handshake"></i> Confirmed Matches
        </div>

        <?php if (count($matchedTuitions) === 0): ?>
            <div class="empty-state"><i class="fas fa-handshake"></i>No confirmed matches yet.</div>
        <?php else: ?>
            <?php foreach ($matchedTuitions as $match): ?>
                <div class="request-card">
                    <h4><?php echo htmlspecialchars($match['subject']); ?> — <?php echo htmlspecialchars($match['location']); ?></h4>
                    <div class="tuition-meta" style="margin-top:10px;">
                        <span><i class="fas fa-user-graduate"></i>Student: <?php echo htmlspecialchars($match['student_name']); ?> (<?php echo htmlspecialchars($match['student_phone']); ?>)</span>
                        <span><i class="fas fa-chalkboard-teacher"></i>Tutor: <?php echo htmlspecialchars($match['tutor_name']); ?> (<?php echo htmlspecialchars($match['tutor_phone']); ?>)</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
