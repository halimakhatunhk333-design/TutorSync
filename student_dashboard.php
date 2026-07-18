<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$successMessage = "";

// Turns a MySQL datetime into "3 hours ago" style text
function time_ago($datetime) {
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

// Fetch existing student profile
$stmt = mysqli_prepare($conn, "SELECT * FROM student_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$profile = [
    'guardian_name' => '', 'guardian_contact' => '', 'class_level' => '',
    'institution' => '', 'address' => '', 'profile_pic_path' => ''
];

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $profile = array_merge($profile, $row);
} else {
    $insert = mysqli_prepare($conn, "INSERT INTO student_profiles (user_id) VALUES (?)");
    mysqli_stmt_bind_param($insert, "i", $userId);
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
}
mysqli_stmt_close($stmt);

// Fetch basic user info
$userStmt = mysqli_prepare($conn, "SELECT name, email, phone FROM users WHERE id = ?");
mysqli_stmt_bind_param($userStmt, "i", $userId);
mysqli_stmt_execute($userStmt);
$userResult = mysqli_stmt_get_result($userStmt);
$userInfo = mysqli_fetch_assoc($userResult);
mysqli_stmt_close($userStmt);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'profile_info') {
        $guardianName    = trim($_POST['guardian_name'] ?? '');
        $guardianContact = trim($_POST['guardian_contact'] ?? '');
        $classLevel      = trim($_POST['class_level'] ?? '');
        $institution     = trim($_POST['institution'] ?? '');
        $address         = trim($_POST['address'] ?? '');

        $stmt = mysqli_prepare($conn, "UPDATE student_profiles SET guardian_name=?, guardian_contact=?, class_level=?, institution=?, address=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, "sssssi", $guardianName, $guardianContact, $classLevel, $institution, $address, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $successMessage = "Profile updated.";
    }

    if ($formType === 'profile_pic') {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $fileName = "studentpic_" . $userId . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $fileName);

            $stmt = mysqli_prepare($conn, "UPDATE student_profiles SET profile_pic_path = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt, "si", $fileName, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMessage = "Profile picture updated.";
        }
    }

    if ($formType === 'post_tuition') {
        $subject     = trim($_POST['subject'] ?? '');
        $classLevel  = trim($_POST['tuition_class_level'] ?? '');
        $medium      = trim($_POST['medium'] ?? '');
        $schedule    = trim($_POST['preferred_schedule'] ?? '');
        $budget      = trim($_POST['budget'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $notes       = trim($_POST['additional_notes'] ?? '');

        if ($subject === '') {
            $successMessage = "Subject is required to post a tuition request.";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO tuition_requests (student_id, subject, class_level, medium, preferred_schedule, budget, location, additional_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "isssssss", $userId, $subject, $classLevel, $medium, $schedule, $budget, $location, $notes);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMessage = "Tuition request submitted! It will appear once the admin approves it.";
        }
    }

    if ($formType === 'cancel_tuition') {
        $cancelId = (int) ($_POST['tuition_id'] ?? 0);

        // Only allow cancelling your own request, and only while it's still pending/approved (not matched/closed)
        $stmt = mysqli_prepare($conn, "UPDATE tuition_requests SET status = 'closed' WHERE id = ? AND student_id = ? AND status IN ('pending', 'approved')");
        mysqli_stmt_bind_param($stmt, "ii", $cancelId, $userId);
        mysqli_stmt_execute($stmt);
        $successMessage = mysqli_stmt_affected_rows($stmt) > 0
            ? "Tuition request cancelled."
            : "That request can't be cancelled anymore.";
        mysqli_stmt_close($stmt);
    }

    if ($formType === 'edit_tuition') {
        $editId      = (int) ($_POST['tuition_id'] ?? 0);
        $subject     = trim($_POST['edit_subject'] ?? '');
        $classLevel  = trim($_POST['edit_class_level'] ?? '');
        $medium      = trim($_POST['edit_medium'] ?? '');
        $schedule    = trim($_POST['edit_schedule'] ?? '');
        $budget      = trim($_POST['edit_budget'] ?? '');
        $location    = trim($_POST['edit_location'] ?? '');
        $notes       = trim($_POST['edit_notes'] ?? '');

        if ($subject === '') {
            $successMessage = "Subject is required.";
        } else {
            // Only editable while still pending — once approved/matched, changing it silently could confuse applicants
            $stmt = mysqli_prepare($conn, "UPDATE tuition_requests SET subject=?, class_level=?, medium=?, preferred_schedule=?, budget=?, location=?, additional_notes=? WHERE id = ? AND student_id = ? AND status = 'pending'");
            mysqli_stmt_bind_param($stmt, "sssssssii", $subject, $classLevel, $medium, $schedule, $budget, $location, $notes, $editId, $userId);
            mysqli_stmt_execute($stmt);
            $successMessage = mysqli_stmt_affected_rows($stmt) > 0
                ? "Tuition request updated."
                : "That request can no longer be edited (it may have already been approved).";
            mysqli_stmt_close($stmt);
        }
    }

    // Refresh profile data
    $stmt = mysqli_prepare($conn, "SELECT * FROM student_profiles WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $profile = array_merge($profile, $row);
    mysqli_stmt_close($stmt);
}

// Fetch this student's tuition requests + how many tutors applied to each,
// plus whether this student has already left a review for a matched tuition
$reqStmt = mysqli_prepare($conn, "
    SELECT tr.*, 
    (SELECT COUNT(*) FROM tuition_applications ta WHERE ta.tuition_id = tr.id) AS applicant_count,
    (SELECT COUNT(*) FROM tutor_reviews tvr WHERE tvr.tuition_id = tr.id AND tvr.student_id = tr.student_id) AS already_reviewed
    FROM tuition_requests tr
    WHERE tr.student_id = ?
    ORDER BY tr.created_at DESC
");
mysqli_stmt_bind_param($reqStmt, "i", $userId);
mysqli_stmt_execute($reqStmt);
$reqResult = mysqli_stmt_get_result($reqStmt);
$myRequests = mysqli_fetch_all($reqResult, MYSQLI_ASSOC);
mysqli_stmt_close($reqStmt);

$profileDone = !empty($profile['guardian_name']) && !empty($profile['class_level']) && !empty($profile['institution']);

// Initial notifications (bell dropdown is refreshed live via notifications_get.php, this just avoids a blank flash on load)
$notifUnreadCount = 0;
$notifications = [];
$notifStmt = mysqli_prepare($conn, "SELECT id, type, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
mysqli_stmt_bind_param($notifStmt, "i", $userId);
mysqli_stmt_execute($notifStmt);
$notifResult = mysqli_stmt_get_result($notifStmt);
$notifications = mysqli_fetch_all($notifResult, MYSQLI_ASSOC);
mysqli_stmt_close($notifStmt);
foreach ($notifications as $n) {
    if (!$n['is_read']) $notifUnreadCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
    width:270px;
    background:linear-gradient(180deg, var(--teal) 0%, #1e4a47 100%);
    color:var(--ivory);
    padding:30px 22px;
    position:fixed;
    top:0; bottom:0; left:0;
    overflow-y:auto;
}
.sidebar-brand{ font-family:'Fraunces', serif; font-size:1.3rem; font-weight:600; margin-bottom:6px; }
.sidebar-brand span{ color:var(--gold-soft); }
.sidebar-role{
    font-size:0.7rem; letter-spacing:0.15em; text-transform:uppercase;
    color:rgba(246,243,236,0.55); margin-bottom:30px;
}
.nav-link{
    display:flex; align-items:center; gap:12px; padding:12px 14px;
    border-radius:8px; color:rgba(246,243,236,0.8); text-decoration:none;
    font-size:0.92rem; font-weight:500; margin-bottom:4px;
    transition:background 0.2s ease, color 0.2s ease;
}
.nav-link:hover{ background:rgba(255,255,255,0.08); color:#fff; }
.nav-link i{ width:18px; text-align:center; color:var(--gold-soft); }
.sidebar-logout{ margin-top:30px; padding-top:20px; border-top:1px solid rgba(246,243,236,0.15); }
.sidebar-logout a{ color:#F87171; text-decoration:none; font-size:0.9rem; font-weight:600; display:flex; align-items:center; gap:10px; }

.main{ margin-left:270px; flex:1; padding:36px 44px 80px; }

/* Top bar with notification bell */
.topbar{
    display:flex; justify-content:flex-end; align-items:center;
    margin-bottom:20px; gap:16px;
}
.notif-bell-wrap{ position:relative; }
.notif-bell{
    width:44px; height:44px; border-radius:50%; background:#fff;
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 6px 20px rgba(21,34,56,0.1); cursor:pointer;
    border:1px solid var(--line); position:relative;
}
.notif-bell i{ font-size:1.1rem; color:var(--navy); }
.notif-badge{
    position:absolute; top:-4px; right:-4px;
    background:#DC2626; color:#fff; font-size:0.68rem; font-weight:700;
    min-width:18px; height:18px; border-radius:9px; padding:0 4px;
    display:flex; align-items:center; justify-content:center;
    border:2px solid var(--ivory-deep);
}
.notif-badge.hidden{ display:none; }
.notif-dropdown{
    position:absolute; top:54px; right:0; width:340px; max-height:420px;
    overflow-y:auto; background:#fff; border-radius:12px;
    box-shadow:0 12px 34px rgba(21,34,56,0.18); border:1px solid var(--line);
    display:none; z-index:50;
}
.notif-dropdown.open{ display:block; }
.notif-dropdown-header{
    display:flex; justify-content:space-between; align-items:center;
    padding:14px 16px; border-bottom:1px solid var(--line);
}
.notif-dropdown-header h4{ font-size:0.95rem; color:var(--navy); }
.notif-mark-all{
    font-size:0.78rem; color:var(--teal); background:none; border:none;
    cursor:pointer; font-weight:600;
}
.notif-item{
    display:flex; gap:10px; padding:13px 16px; border-bottom:1px solid #F0EDE3;
    cursor:pointer; transition:background 0.15s ease;
}
.notif-item:hover{ background:var(--ivory); }
.notif-item.unread{ background:#F0F7F6; }
.notif-item i{ color:var(--teal); margin-top:2px; }
.notif-item-text{ font-size:0.85rem; color:var(--charcoal); line-height:1.4; }
.notif-item-time{ font-size:0.72rem; color:#9CA3AF; margin-top:3px; }
.notif-empty{ padding:34px 16px; text-align:center; color:#9CA3AF; font-size:0.85rem; }
.notif-item a{ color:inherit; text-decoration:none; }
.notif-dropdown-footer{
    text-align:center; padding:12px; border-top:1px solid var(--line);
}
.notif-dropdown-footer a{
    font-size:0.82rem; color:var(--teal); text-decoration:none; font-weight:600;
}

.success-msg{
    background:#d1fae5; color:#065f46; padding:12px 18px;
    border-radius:8px; margin-bottom:22px; font-weight:500;
    display:flex; align-items:center; gap:10px;
}

.profile-header{
    background:#fff; border-radius:16px; padding:30px;
    display:flex; align-items:center; gap:24px; margin-bottom:26px;
    box-shadow:0 6px 24px rgba(21,34,56,0.08);
}
.profile-pic-wrap{
    width:96px; height:96px; border-radius:50%; overflow:hidden;
    border:4px solid var(--teal); flex-shrink:0; background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center;
}
.profile-pic-wrap img{ width:100%; height:100%; object-fit:cover; }
.profile-pic-wrap i{ font-size:2.4rem; color:var(--line); }
.profile-header h1{ font-family:'Fraunces', serif; font-size:1.5rem; color:var(--navy); margin-bottom:4px; }
.profile-header .sub{ color:#6b7280; font-size:0.9rem; }
.profile-header .sub i{ margin-right:6px; color:var(--teal); }

.section-card{
    background:#fff; border-radius:16px; padding:28px 30px;
    margin-bottom:24px; box-shadow:0 6px 24px rgba(21,34,56,0.06);
    scroll-margin-top:20px;
}
.section-card h3{ font-family:'Fraunces', serif; font-size:1.15rem; color:var(--navy); margin-bottom:4px; }
.section-card .section-desc{ color:#8a8578; font-size:0.85rem; margin-bottom:20px; }

.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-group{ margin-bottom:16px; }
label{ display:block; margin-bottom:7px; font-weight:600; font-size:0.85rem; color:var(--charcoal); }
input[type=text], input[type=tel], textarea, select{
    width:100%; padding:11px 14px; border:1px solid var(--line);
    border-radius:8px; font-size:14px; background:var(--ivory); color:var(--charcoal);
}
input:focus, textarea:focus{
    outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(47,111,107,0.12);
}
textarea{ min-height:90px; resize:vertical; }
input[type=file]{
    width:100%; padding:10px; border:1.5px dashed var(--line);
    border-radius:8px; background:var(--ivory); font-size:13px;
}
.btn{
    background:var(--teal); color:#fff; border:none; padding:11px 24px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.9rem;
    transition:background 0.2s ease;
}
.btn:hover{ background:#265D59; }

/* My tuition requests list */
.request-card{
    border:1px solid var(--line); border-radius:12px; padding:18px 20px;
    margin-bottom:14px; background:var(--ivory);
}
.request-top{ display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.request-top h4{ color:var(--navy); font-size:1rem; }
.status-badge{
    font-size:0.72rem; font-weight:700; text-transform:uppercase;
    padding:4px 10px; border-radius:20px; letter-spacing:0.03em;
}
.status-pending{ background:#FEF3C7; color:#92400E; }
.status-approved{ background:#D1FAE5; color:#065F46; }
.status-closed{ background:#E5E7EB; color:#374151; }
.status-matched{ background:#DBEAFE; color:#1E40AF; }
.request-meta{ font-size:0.85rem; color:#6b7280; margin-bottom:6px; }
.request-posted{
    font-size:0.78rem; color:#9CA3AF; margin-bottom:8px;
    display:flex; align-items:center; gap:6px;
}
.applicant-link, .review-link{
    display:inline-flex; align-items:center; gap:6px;
    text-decoration:none; font-weight:600; font-size:0.85rem;
    margin-right:16px;
}
.applicant-link{ color:var(--teal); }
.review-link{ color:var(--gold); }
.review-done{ color:#6b7280; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px; }
.edit-link, .cancel-link{
    background:none; border:none; cursor:pointer; font-weight:600; font-size:0.85rem;
    display:inline-flex; align-items:center; gap:6px; margin-right:16px; padding:0;
}
.edit-link{ color:#6b7280; }
.cancel-link{ color:#DC2626; }
.edit-form-panel{
    display:none; margin-top:16px; padding-top:16px; border-top:1px dashed var(--line);
}
.edit-form-panel.open{ display:block; }
.empty-state{ text-align:center; padding:40px 20px; color:#9CA3AF; }
.empty-state i{ font-size:2rem; margin-bottom:10px; display:block; }

@media (max-width:900px){
    .sidebar{ display:none; }
    .main{ margin-left:0; padding:24px; }
    .grid-2{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="shell">

    <div class="sidebar">
        <div class="sidebar-brand">Tutor<span>Sync</span></div>
        <div class="sidebar-role">Student Dashboard</div>

        <a href="#profile-pic" class="nav-link"><i class="fas fa-camera"></i> Profile Picture</a>
        <a href="#profile-info" class="nav-link"><i class="fas fa-id-card"></i> My Profile</a>
        <a href="#post-tuition" class="nav-link"><i class="fas fa-square-plus"></i> Post a Tuition Request</a>
        <a href="#my-requests" class="nav-link"><i class="fas fa-list-check"></i> My Requests</a>

        <div class="sidebar-logout">
            <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <div class="main">

        <div class="topbar">
            <div class="notif-bell-wrap">
                <div class="notif-bell" id="notifBell">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge <?php echo $notifUnreadCount === 0 ? 'hidden' : ''; ?>" id="notifBadge"><?php echo $notifUnreadCount; ?></span>
                </div>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">
                        <h4>Notifications</h4>
                        <button class="notif-mark-all" id="notifMarkAll">Mark all read</button>
                    </div>
                    <div id="notifList">
                        <?php if (count($notifications) === 0): ?>
                            <div class="notif-empty">No notifications yet.</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <div class="notif-item <?php echo $n['is_read'] ? '' : 'unread'; ?>" data-id="<?php echo $n['id']; ?>">
                                    <i class="fas fa-user-graduate"></i>
                                    <div>
                                        <div class="notif-item-text">
                                            <?php if (!empty($n['link'])): ?>
                                                <a href="<?php echo htmlspecialchars($n['link']); ?>"><?php echo htmlspecialchars($n['message']); ?></a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($n['message']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notif-item-time"><?php echo time_ago($n['created_at']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="notifications.php">View all notifications</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="success-msg"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <div class="profile-header">
            <div class="profile-pic-wrap">
                <?php if (!empty($profile['profile_pic_path'])): ?>
                    <img src="uploads/<?php echo htmlspecialchars($profile['profile_pic_path']); ?>" alt="Profile">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div>
                <h1><?php echo htmlspecialchars($userInfo['name']); ?></h1>
                <div class="sub"><i class="fas fa-envelope"></i><?php echo htmlspecialchars($userInfo['email']); ?></div>
                <div class="sub"><i class="fas fa-phone"></i><?php echo htmlspecialchars($userInfo['phone']); ?></div>
            </div>
        </div>

        <!-- Profile Picture -->
        <div class="section-card" id="profile-pic">
            <h3>Profile Picture</h3>
            <p class="section-desc">Add a photo so tutors and admin can recognize you.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="profile_pic">
                <div class="form-group">
                    <input type="file" name="profile_pic" accept="image/*">
                </div>
                <button class="btn" type="submit">Update Picture</button>
            </form>
        </div>

        <!-- Profile Info -->
        <div class="section-card" id="profile-info">
            <h3>My Profile</h3>
            <p class="section-desc">Basic details about you and your guardian.</p>
            <form method="POST">
                <input type="hidden" name="form_type" value="profile_info">
                <div class="grid-2">
                    <div class="form-group">
                        <label>Guardian's Name</label>
                        <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($profile['guardian_name']); ?>" placeholder="Parent or guardian's full name">
                    </div>
                    <div class="form-group">
                        <label>Guardian's Contact</label>
                        <input type="tel" name="guardian_contact" value="<?php echo htmlspecialchars($profile['guardian_contact']); ?>" placeholder="Phone number">
                    </div>
                    <div class="form-group">
                        <label>Class / Grade</label>
                        <input type="text" name="class_level" value="<?php echo htmlspecialchars($profile['class_level']); ?>" placeholder="e.g. Class 9, HSC 1st Year">
                    </div>
                    <div class="form-group">
                        <label>Institution</label>
                        <input type="text" name="institution" value="<?php echo htmlspecialchars($profile['institution']); ?>" placeholder="Your school/college name">
                    </div>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars($profile['address']); ?>" placeholder="Your area/location">
                </div>
                <button class="btn" type="submit">Save Profile</button>
            </form>
        </div>

        <!-- Post a Tuition Request -->
        <div class="section-card" id="post-tuition">
            <h3>Post a Tuition Request</h3>
            <p class="section-desc">Tell us what you need — our admin team will review and match you with a qualified tutor.</p>
            <form method="POST">
                <input type="hidden" name="form_type" value="post_tuition">
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
                    <textarea name="additional_notes" placeholder="Anything specific you're looking for in a tutor..."></textarea>
                </div>
                <button class="btn" type="submit">Submit Request</button>
            </form>
        </div>

        <!-- My Requests -->
        <div class="section-card" id="my-requests">
            <h3>My Tuition Requests</h3>
            <p class="section-desc">Track the status of your submitted requests.</p>

            <?php if (count($myRequests) === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    You haven't posted any tuition requests yet.
                </div>
            <?php else: ?>
                <?php foreach ($myRequests as $req): ?>
                    <div class="request-card">
                        <div class="request-top">
                            <h4><?php echo htmlspecialchars($req['subject']); ?></h4>
                            <span class="status-badge status-<?php echo $req['status']; ?>">
                                <?php echo ucfirst($req['status']); ?>
                            </span>
                        </div>
                        <div class="request-meta">
                            <?php echo htmlspecialchars($req['class_level']); ?>
                            <?php if (!empty($req['medium'])): ?> · <?php echo htmlspecialchars($req['medium']); ?><?php endif; ?>
                            <?php if ($req['preferred_schedule']): ?> · <?php echo htmlspecialchars($req['preferred_schedule']); ?><?php endif; ?>
                            <?php if ($req['budget']): ?> · <i class="fas fa-dollar-sign"></i> <?php echo htmlspecialchars(format_budget($req['budget'])); ?><?php endif; ?>
                        </div>
                        <div class="request-posted">
                            <i class="fas fa-calendar-day"></i>
                            Posted <?php echo date("l, M j, Y", strtotime($req['created_at'])); ?>
                            (<?php echo time_ago($req['created_at']); ?>)
                        </div>

                        <?php if ($req['status'] === 'approved'): ?>
                            <a href="view_applicants.php?tuition_id=<?php echo $req['id']; ?>" class="applicant-link">
                                <i class="fas fa-users"></i> View Applicants (<?php echo $req['applicant_count']; ?>)
                            </a>
                            <button type="button" class="edit-link" onclick="document.getElementById('edit-form-<?php echo $req['id']; ?>').classList.toggle('open')">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this tuition request?');">
                                <input type="hidden" name="form_type" value="cancel_tuition">
                                <input type="hidden" name="tuition_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="cancel-link"><i class="fas fa-xmark"></i> Cancel</button>
                            </form>
                        <?php elseif ($req['status'] === 'pending'): ?>
                            <span style="color:#92400E; font-size:0.85rem;"><i class="fas fa-hourglass-half"></i> Waiting for admin approval</span>
                            <button type="button" class="edit-link" onclick="document.getElementById('edit-form-<?php echo $req['id']; ?>').classList.toggle('open')">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this tuition request?');">
                                <input type="hidden" name="form_type" value="cancel_tuition">
                                <input type="hidden" name="tuition_id" value="<?php echo $req['id']; ?>">
                                <button type="submit" class="cancel-link"><i class="fas fa-xmark"></i> Cancel</button>
                            </form>
                        <?php elseif ($req['status'] === 'matched'): ?>
                            <a href="view_applicants.php?tuition_id=<?php echo $req['id']; ?>" class="applicant-link">
                                <i class="fas fa-users"></i> View Applicants
                            </a>
                            <?php if ((int) $req['already_reviewed'] > 0): ?>
                                <span class="review-done"><i class="fas fa-star"></i> Review submitted</span>
                            <?php else: ?>
                                <a href="submit_review.php?tuition_id=<?php echo $req['id']; ?>" class="review-link">
                                    <i class="fas fa-star"></i> Leave a Review
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#374151; font-size:0.85rem;"><i class="fas fa-lock"></i> This request is closed</span>
                        <?php endif; ?>

                        <?php if (in_array($req['status'], ['pending', 'approved'])): ?>
                            <div class="edit-form-panel" id="edit-form-<?php echo $req['id']; ?>">
                                <form method="POST">
                                    <input type="hidden" name="form_type" value="edit_tuition">
                                    <input type="hidden" name="tuition_id" value="<?php echo $req['id']; ?>">
                                    <div class="grid-2">
                                        <div class="form-group">
                                            <label>Subject</label>
                                            <input type="text" name="edit_subject" value="<?php echo htmlspecialchars($req['subject']); ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Class / Level</label>
                                            <input type="text" name="edit_class_level" value="<?php echo htmlspecialchars($req['class_level']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Medium</label>
                                            <select name="edit_medium">
                                                <option value="">Select medium</option>
                                                <?php foreach (['Bangla Medium', 'English Medium', 'English Version', 'Madrasha Board'] as $opt): ?>
                                                    <option value="<?php echo $opt; ?>" <?php echo $req['medium'] === $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Preferred Schedule</label>
                                            <input type="text" name="edit_schedule" value="<?php echo htmlspecialchars($req['preferred_schedule']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Budget (per month)</label>
                                            <input type="text" name="edit_budget" value="<?php echo htmlspecialchars($req['budget']); ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Location / Area</label>
                                        <input type="text" name="edit_location" value="<?php echo htmlspecialchars($req['location']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Additional Notes</label>
                                        <textarea name="edit_notes"><?php echo htmlspecialchars($req['additional_notes']); ?></textarea>
                                    </div>
                                    <?php if ($req['status'] === 'approved'): ?>
                                        <p style="color:#92400E; font-size:0.8rem; margin-bottom:12px;">
                                            <i class="fas fa-triangle-exclamation"></i> This request is already approved and visible to tutors, so it can't be edited. Cancel it and post a new one if details need to change.
                                        </p>
                                    <?php else: ?>
                                        <button class="btn" type="submit">Save Changes</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
const notifBell = document.getElementById('notifBell');
const notifDropdown = document.getElementById('notifDropdown');
const notifBadge = document.getElementById('notifBadge');
const notifList = document.getElementById('notifList');
const notifMarkAll = document.getElementById('notifMarkAll');

notifBell.addEventListener('click', () => {
    notifDropdown.classList.toggle('open');
});

document.addEventListener('click', (e) => {
    if (!notifDropdown.contains(e.target) && !notifBell.contains(e.target)) {
        notifDropdown.classList.remove('open');
    }
});

function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
    if (diff < 60) return diff <= 1 ? 'just now' : diff + ' seconds ago';
    const mins = Math.floor(diff / 60);
    if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
    const hours = Math.floor(diff / 3600);
    if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
    const days = Math.floor(diff / 86400);
    if (days < 7) return days + (days === 1 ? ' day ago' : ' days ago');
    return new Date(dateStr.replace(' ', 'T')).toLocaleDateString();
}

function renderNotifications(data) {
    notifBadge.textContent = data.unread_count;
    notifBadge.classList.toggle('hidden', data.unread_count === 0);

    if (data.notifications.length === 0) {
        notifList.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
        return;
    }

    notifList.innerHTML = data.notifications.map(n => {
        const safeMsg = n.message.replace(/</g, '&lt;');
        const text = n.link
            ? `<a href="${n.link.replace(/"/g, '&quot;')}">${safeMsg}</a>`
            : safeMsg;
        return `
        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" data-id="${n.id}">
            <i class="fas fa-user-graduate"></i>
            <div>
                <div class="notif-item-text">${text}</div>
                <div class="notif-item-time">${timeAgo(n.created_at)}</div>
            </div>
        </div>`;
    }).join('');

    notifList.querySelectorAll('.notif-item').forEach(item => {
        item.addEventListener('click', () => markRead(item.dataset.id));
    });
}

async function fetchNotifications() {
    try {
        const res = await fetch('notifications_get.php');
        const data = await res.json();
        if (!data.error) renderNotifications(data);
    } catch (err) {
        console.error('Failed to fetch notifications', err);
    }
}

async function markRead(id) {
    try {
        await fetch('notifications_mark_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: id })
        });
        fetchNotifications();
    } catch (err) {
        console.error('Failed to mark notification read', err);
    }
}

notifMarkAll.addEventListener('click', () => markRead('all'));

// Poll every 20 seconds for new notifications (e.g. a tutor just applied)
setInterval(fetchNotifications, 20000);
</script>

</body>
</html>
