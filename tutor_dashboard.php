<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$successMessage = "";

// Fetch existing profile (if any)
$stmt = mysqli_prepare($conn, "SELECT * FROM tutor_profiles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$profile = [
    'address' => '', 'date_of_birth' => '', 'father_name' => '', 'mother_name' => '',
    'emergency_contact' => '', 'university' => '', 'university_cgpa' => '', 'college' => '',
    'college_gpa' => '', 'school' => '', 'school_gpa' => '', 'degree' => '', 'subjects' => '',
    'expected_salary' => '', 'preferred_location' => '',
    'availability' => '', 'additional_info' => '', 'school_certificate_path' => '',
    'college_certificate_path' => '', 'current_id_path' => '', 'profile_pic_path' => ''
];

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $profile = array_merge($profile, $row);
} else {
    $insert = mysqli_prepare($conn, "INSERT INTO tutor_profiles (user_id) VALUES (?)");
    mysqli_stmt_bind_param($insert, "i", $userId);
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
}
mysqli_stmt_close($stmt);

// Fetch user's basic info
$userStmt = mysqli_prepare($conn, "SELECT name, email, phone FROM users WHERE id = ?");
mysqli_stmt_bind_param($userStmt, "i", $userId);
mysqli_stmt_execute($userStmt);
$userResult = mysqli_stmt_get_result($userStmt);
$userInfo = mysqli_fetch_assoc($userResult);
mysqli_stmt_close($userStmt);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'personal_info') {
        $address     = trim($_POST['address'] ?? '');
        $dob         = trim($_POST['date_of_birth'] ?? '');
        $fatherName  = trim($_POST['father_name'] ?? '');
        $motherName  = trim($_POST['mother_name'] ?? '');
        $emergency   = trim($_POST['emergency_contact'] ?? '');

        $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET address=?, date_of_birth=?, father_name=?, mother_name=?, emergency_contact=? WHERE user_id=?");
        mysqli_stmt_bind_param($stmt, "sssssi", $address, $dob, $fatherName, $motherName, $emergency, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $successMessage = "Personal info updated.";
    }

if ($formType === 'academic_info') {
    $university     = trim($_POST['university'] ?? '');
    $universityCgpa = trim($_POST['university_cgpa'] ?? '');
    $college        = trim($_POST['college'] ?? '');
    $collegeGpa     = trim($_POST['college_gpa'] ?? '');
    $school         = trim($_POST['school'] ?? '');
    $schoolGpa      = trim($_POST['school_gpa'] ?? '');
    $degree         = trim($_POST['degree'] ?? '');
    $subjects       = trim($_POST['subjects'] ?? '');
    $expectedSalary = trim($_POST['expected_salary'] ?? '');
    $prefLocation   = trim($_POST['preferred_location'] ?? '');

    $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET university=?, university_cgpa=?, college=?, college_gpa=?, school=?, school_gpa=?, degree=?, subjects=?, expected_salary=?, preferred_location=? WHERE user_id=?");
    mysqli_stmt_bind_param($stmt, "ssssssssssi", $university, $universityCgpa, $college, $collegeGpa, $school, $schoolGpa, $degree, $subjects, $expectedSalary, $prefLocation, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $successMessage = "Academic info updated.";
}

    if ($formType === 'availability') {
        // Checkbox values come in as an array, e.g. ["Mon-Morning", "Wed-Evening"]
        $slots = $_POST['availability_slots'] ?? [];
        $availabilityString = implode(",", $slots);

        $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET availability = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "si", $availabilityString, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $successMessage = "Availability updated.";
    }

    if ($formType === 'additional_info') {
        $additionalInfo = trim($_POST['additional_info'] ?? '');

        $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET additional_info = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "si", $additionalInfo, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $successMessage = "Additional info updated.";
    }

    if ($formType === 'proof_docs') {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        $docFields = [
            'school_certificate' => 'school_certificate_path',
            'college_certificate' => 'college_certificate_path',
            'current_id' => 'current_id_path'
        ];

        foreach ($docFields as $inputName => $columnName) {
            if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] === 0) {
                $ext = pathinfo($_FILES[$inputName]['name'], PATHINFO_EXTENSION);
                $fileName = $inputName . "_" . $userId . "_" . time() . "." . $ext;
                move_uploaded_file($_FILES[$inputName]['tmp_name'], $uploadDir . $fileName);

                $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET $columnName = ? WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt, "si", $fileName, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        }

        $successMessage = "Documents uploaded.";
    }

    if ($formType === 'profile_pic') {
        $uploadDir = "uploads/";
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $fileName = "profilepic_" . $userId . "_" . time() . "." . $ext;
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $fileName);

            $stmt = mysqli_prepare($conn, "UPDATE tutor_profiles SET profile_pic_path = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt, "si", $fileName, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $successMessage = "Profile picture updated.";
        }
    }

    // Refresh profile data after any update
    $stmt = mysqli_prepare($conn, "SELECT * FROM tutor_profiles WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $profile = array_merge($profile, $row);
    mysqli_stmt_close($stmt);
}

// Calculate profile completion percentage
$fieldsToCheck = [
    'address', 'date_of_birth', 'father_name', 'mother_name', 'emergency_contact',
    'university', 'school', 'degree', 'subjects', 'availability',
    'school_certificate_path', 'college_certificate_path', 'current_id_path', 'profile_pic_path'
];

$filledCount = 0;
foreach ($fieldsToCheck as $field) {
    if (!empty($profile[$field])) { $filledCount++; }
}
$completionPercent = round(($filledCount / count($fieldsToCheck)) * 100);

$personalDone  = !empty($profile['father_name']) && !empty($profile['mother_name']) && !empty($profile['emergency_contact']);
$academicDone  = !empty($profile['university']) || !empty($profile['school']);
$proofDone     = !empty($profile['school_certificate_path']) && !empty($profile['college_certificate_path']) && !empty($profile['current_id_path']);
$availDone     = !empty($profile['availability']);
$picDone       = !empty($profile['profile_pic_path']);

// Parse existing availability into an array for checkbox pre-selection
$selectedSlots = !empty($profile['availability']) ? explode(",", $profile['availability']) : [];
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$timeSlots = ['Morning (6AM-12PM)', 'Afternoon (12PM-5PM)', 'Evening (5PM-9PM)', 'Night (9PM-12AM)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tutor Dashboard — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238;
    --navy-deep:#0d1626;
    --ivory:#F6F3EC;
    --ivory-deep:#EDE8DC;
    --gold:#C9982F;
    --gold-soft:#E4C87A;
    --teal:#2F6F6B;
    --charcoal:#20232A;
    --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); min-height:100vh; }

/* ---------- Layout shell ---------- */
.shell{ display:flex; min-height:100vh; }

/* ---------- Sidebar ---------- */
.sidebar{
    width:270px;
    background:linear-gradient(180deg, var(--navy) 0%, var(--navy-deep) 100%);
    color:var(--ivory);
    padding:30px 22px;
    position:fixed;
    top:0; bottom:0; left:0;
    overflow-y:auto;
}
.sidebar-brand{
    font-family:'Fraunces', serif;
    font-size:1.3rem;
    font-weight:600;
    margin-bottom:6px;
}
.sidebar-brand span{ color:var(--gold-soft); }
.sidebar-role{
    font-size:0.7rem;
    letter-spacing:0.15em;
    text-transform:uppercase;
    color:rgba(246,243,236,0.5);
    margin-bottom:30px;
}

.nav-link{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius:8px;
    color:rgba(246,243,236,0.75);
    text-decoration:none;
    font-size:0.92rem;
    font-weight:500;
    margin-bottom:4px;
    transition:background 0.2s ease, color 0.2s ease;
}
.nav-link:hover{ background:rgba(255,255,255,0.06); color:#fff; }
.nav-link i{ width:18px; text-align:center; color:var(--gold-soft); }
.nav-link .check{
    margin-left:auto;
    font-size:0.7rem;
    color:#6EE7B7;
}

.sidebar-logout{
    margin-top:30px;
    padding-top:20px;
    border-top:1px solid rgba(246,243,236,0.15);
}
.sidebar-logout a{
    color:#F87171;
    text-decoration:none;
    font-size:0.9rem;
    font-weight:600;
    display:flex;
    align-items:center;
    gap:10px;
}

/* ---------- Main content ---------- */
.main{ margin-left:270px; flex:1; padding:36px 44px 80px; }

.success-msg{
    background:#d1fae5;
    color:#065f46;
    padding:12px 18px;
    border-radius:8px;
    margin-bottom:22px;
    font-weight:500;
    display:flex;
    align-items:center;
    gap:10px;
}

/* ---------- Profile header card ---------- */
.profile-header{
    background:#fff;
    border-radius:16px;
    padding:30px;
    display:flex;
    align-items:center;
    gap:24px;
    margin-bottom:26px;
    box-shadow:0 6px 24px rgba(21,34,56,0.08);
}
.profile-pic-wrap{
    width:96px; height:96px;
    border-radius:50%;
    overflow:hidden;
    border:4px solid var(--gold-soft);
    flex-shrink:0;
    background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center;
}
.profile-pic-wrap img{ width:100%; height:100%; object-fit:cover; }
.profile-pic-wrap i{ font-size:2.4rem; color:var(--line); }

.profile-header h1{
    font-family:'Fraunces', serif;
    font-size:1.5rem;
    color:var(--navy);
    margin-bottom:4px;
}
.profile-header .sub{ color:#6b7280; font-size:0.9rem; }
.profile-header .sub i{ margin-right:6px; color:var(--teal); }

/* ---------- Completion card ---------- */
.progress-card{
    background:#fff;
    border-radius:16px;
    padding:26px 30px;
    margin-bottom:26px;
    box-shadow:0 6px 24px rgba(21,34,56,0.08);
}
.progress-card .top-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:14px;
}
.progress-card h3{ font-size:1.05rem; color:var(--navy); }
.progress-percent{ font-family:'Fraunces', serif; font-size:1.6rem; color:var(--gold); font-weight:600; }

.progress-bar-bg{
    background:#e9e5da;
    border-radius:20px;
    height:14px;
    overflow:hidden;
}
.progress-bar-fill{
    background:linear-gradient(90deg, var(--gold), var(--teal));
    height:100%;
    transition:width 0.5s ease;
}

.apply-banner{
    background:rgba(47,111,107,0.1);
    border:1px solid var(--teal);
    color:var(--teal);
    padding:12px 18px;
    border-radius:10px;
    margin-top:16px;
    font-weight:600;
    font-size:0.9rem;
    display:flex;
    align-items:center;
    gap:10px;
}
.hint-text{ margin-top:14px; color:#8a8578; font-size:0.85rem; }

/* ---------- Section cards ---------- */
.section-card{
    background:#fff;
    border-radius:16px;
    padding:28px 30px;
    margin-bottom:24px;
    box-shadow:0 6px 24px rgba(21,34,56,0.06);
    scroll-margin-top:20px;
}
.section-card h3{
    font-family:'Fraunces', serif;
    font-size:1.15rem;
    color:var(--navy);
    margin-bottom:4px;
}
.section-card .section-desc{
    color:#8a8578;
    font-size:0.85rem;
    margin-bottom:20px;
}

.grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }

.form-group{ margin-bottom:16px; }
label{ display:block; margin-bottom:7px; font-weight:600; font-size:0.85rem; color:var(--charcoal); }
input[type=text], input[type=date], input[type=tel], input[type=email], textarea{
    width:100%;
    padding:11px 14px;
    border:1px solid var(--line);
    border-radius:8px;
    font-size:14px;
    background:var(--ivory);
    color:var(--charcoal);
}
input:focus, textarea:focus{
    outline:none;
    border-color:var(--teal);
    box-shadow:0 0 0 3px rgba(47,111,107,0.12);
}
textarea{ min-height:90px; resize:vertical; }

input[type=file]{
    width:100%;
    padding:10px;
    border:1.5px dashed var(--line);
    border-radius:8px;
    background:var(--ivory);
    font-size:13px;
}

.btn{
    background:var(--navy);
    color:var(--ivory);
    border:none;
    padding:11px 24px;
    border-radius:8px;
    cursor:pointer;
    font-weight:600;
    font-size:0.9rem;
    transition:background 0.2s ease;
}
.btn:hover{ background:var(--navy-deep); }

.file-note{
    font-size:0.8rem;
    color:var(--teal);
    margin-top:6px;
    display:flex;
    align-items:center;
    gap:6px;
}

.pdf-section{
    background:linear-gradient(135deg, var(--navy), var(--navy-deep));
    color:var(--ivory);
    border-radius:16px;
    padding:34px;
    text-align:center;
}
.pdf-section h3{ font-family:'Fraunces', serif; font-size:1.3rem; margin-bottom:8px; color:#fff; }
.pdf-section p{ color:rgba(246,243,236,0.7); font-size:0.9rem; margin-bottom:20px; }
.pdf-btn{
    display:inline-flex;
    align-items:center;
    gap:10px;
    background:var(--gold);
    color:var(--navy-deep);
    padding:13px 28px;
    border-radius:8px;
    text-decoration:none;
    font-weight:700;
    font-size:0.95rem;
    transition:background 0.2s ease;
}
.pdf-btn:hover{ background:var(--gold-soft); }
.avail-table-wrap{ overflow-x:auto; }
.avail-table{
    width:100%;
    border-collapse:collapse;
    font-size:0.85rem;
}
.avail-table th{
    background:var(--navy);
    color:var(--ivory);
    padding:10px;
    font-size:0.75rem;
    text-transform:uppercase;
    letter-spacing:0.04em;
}
.avail-table td{
    text-align:center;
    padding:10px;
    border-bottom:1px solid var(--ivory-deep);
}
.day-label{
    font-weight:700;
    color:var(--navy);
    text-align:left !important;
}
.slot-check{
    display:inline-block;
    cursor:pointer;
}
.slot-check input{ display:none; }
.slot-check span{
    display:block;
    width:22px; height:22px;
    border:2px solid var(--line);
    border-radius:6px;
    background:var(--ivory);
    transition:all 0.15s ease;
    position:relative;
}
.slot-check input:checked + span{
    background:var(--teal);
    border-color:var(--teal);
}
.slot-check input:checked + span::after{
    content:"✓";
    color:#fff;
    font-size:14px;
    font-weight:bold;
    position:absolute;
    top:50%; left:50%;
    transform:translate(-50%,-50%);
}
@media (max-width:900px){
    .sidebar{ display:none; }
    .main{ margin-left:0; padding:24px; }
    .grid-2{ grid-template-columns:1fr; }
}
</style>
</head>
<body>

<div class="shell">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">Tutor<span>Sync</span></div>
        <div class="sidebar-role">Tutor Dashboard</div>

<?php
$notifStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS unread FROM notifications WHERE user_id = ? AND is_read = 0");
mysqli_stmt_bind_param($notifStmt, "i", $userId);
mysqli_stmt_execute($notifStmt);
$notifResult = mysqli_stmt_get_result($notifStmt);
$unreadCount = mysqli_fetch_assoc($notifResult)['unread'];
mysqli_stmt_close($notifStmt);
?>
<a href="notifications.php" class="nav-link" style="margin-bottom:16px; background:rgba(255,255,255,0.06);">
    <i class="fas fa-bell"></i> Notifications
    <?php if ($unreadCount > 0): ?>
        <span style="margin-left:auto; background:#EF4444; color:#fff; font-size:0.7rem; font-weight:700; padding:2px 8px; border-radius:10px;"><?php echo $unreadCount; ?></span>
    <?php endif; ?>
</a>
<a href="browse_tuitions.php" class="nav-link">
    <i class="fas fa-magnifying-glass"></i> Browse Tuitions
</a>
        <a href="#profile-pic" class="nav-link">
            <i class="fas fa-camera"></i> Profile Picture
            <?php if ($picDone): ?><span class="check"><i class="fas fa-check-circle"></i></span><?php endif; ?>
        </a>
        <a href="#personal" class="nav-link">
            <i class="fas fa-id-card"></i> Personal Info
            <?php if ($personalDone): ?><span class="check"><i class="fas fa-check-circle"></i></span><?php endif; ?>
        </a>
        <a href="#academic" class="nav-link">
            <i class="fas fa-graduation-cap"></i> Academic Info
            <?php if ($academicDone): ?><span class="check"><i class="fas fa-check-circle"></i></span><?php endif; ?>
        </a>
        <a href="#proof" class="nav-link">
            <i class="fas fa-file-shield"></i> Proof Documents
            <?php if ($proofDone): ?><span class="check"><i class="fas fa-check-circle"></i></span><?php endif; ?>
        </a>
        <a href="#availability" class="nav-link">
            <i class="fas fa-calendar-check"></i> Availability
            <?php if ($availDone): ?><span class="check"><i class="fas fa-check-circle"></i></span><?php endif; ?>
        </a>
        <a href="#additional" class="nav-link">
            <i class="fas fa-circle-plus"></i> Additional Info
        </a>

        <div class="sidebar-logout">
            <a href="logout.php"><i class="fas fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- Main content -->
    <div class="main">

        <?php if ($successMessage): ?>
            <div class="success-msg"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <!-- Profile header -->
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

        <!-- Completion -->
        <div class="progress-card">
            <div class="top-row">
                <h3>Profile Completion</h3>
                <div class="progress-percent"><?php echo $completionPercent; ?>%</div>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar-fill" style="width:<?php echo $completionPercent; ?>%;"></div>
            </div>

            <?php if ($completionPercent >= 80): ?>
                <div class="apply-banner">
                    <i class="fas fa-circle-check"></i>
                    Your profile is <?php echo $completionPercent; ?>% complete — you can now apply for tuitions!
                </div>
            <?php else: ?>
                <p class="hint-text">Complete at least 80% of your profile to unlock tuition applications.</p>
            <?php endif; ?>
        </div>

        <!-- Profile Picture -->
        <div class="section-card" id="profile-pic">
            <h3>Profile Picture</h3>
            <p class="section-desc">A clear photo helps students recognize and trust you.</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="form_type" value="profile_pic">
                <div class="form-group">
                    <input type="file" name="profile_pic" accept="image/*">
                </div>
                <button class="btn" type="submit">Update Picture</button>
            </form>
        </div>

        <!-- Personal Info -->
<div class="section-card" id="personal">
    <h3>Personal Information</h3>
    <p class="section-desc">Your basic contact and identity details.</p>
    <form method="POST">
        <input type="hidden" name="form_type" value="personal_info">
        <div class="grid-2">
            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" value="<?php echo htmlspecialchars($profile['address']); ?>" placeholder="Your current address">
            </div>
            <div class="form-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($profile['date_of_birth']); ?>">
            </div>
            <div class="form-group">
                <label>Father's Name</label>
                <input type="text" name="father_name" value="<?php echo htmlspecialchars($profile['father_name']); ?>" placeholder="Father's full name">
            </div>
            <div class="form-group">
                <label>Mother's Name</label>
                <input type="text" name="mother_name" value="<?php echo htmlspecialchars($profile['mother_name']); ?>" placeholder="Mother's full name">
            </div>
            <div class="form-group">
                <label>Emergency Contact Number</label>
                <input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($profile['emergency_contact']); ?>" placeholder="A number we can call in emergencies">
            </div>
        </div>
        <button class="btn" type="submit">Save Personal Info</button>
    </form>
</div>
<div class="section-card" id="academic">
    <h3>Academic Information</h3>
    <p class="section-desc">Your educational background — helps students find qualified tutors.</p>
    <form method="POST">
        <input type="hidden" name="form_type" value="academic_info">
        <div class="grid-2">
            <div class="form-group">
                <label>School</label>
                <input type="text" name="school" value="<?php echo htmlspecialchars($profile['school']); ?>" placeholder="e.g. Your school name">
            </div>
            <div class="form-group">
                <label>School GPA</label>
                <input type="text" name="school_gpa" value="<?php echo htmlspecialchars($profile['school_gpa']); ?>" placeholder="e.g. 5.00">
            </div>
            <div class="form-group">
                <label>College</label>
                <input type="text" name="college" value="<?php echo htmlspecialchars($profile['college']); ?>" placeholder="e.g. Notre Dame College">
            </div>
            <div class="form-group">
                <label>College GPA</label>
                <input type="text" name="college_gpa" value="<?php echo htmlspecialchars($profile['college_gpa']); ?>" placeholder="e.g. 5.00">
            </div>
            <div class="form-group">
                <label>University</label>
                <input type="text" name="university" value="<?php echo htmlspecialchars($profile['university']); ?>" placeholder="e.g. University of Dhaka">
            </div>
            <div class="form-group">
                <label>University CGPA</label>
                <input type="text" name="university_cgpa" value="<?php echo htmlspecialchars($profile['university_cgpa']); ?>" placeholder="e.g. 3.80">
            </div>
            <div class="form-group">
                <label>Degree / Program</label>
                <input type="text" name="degree" value="<?php echo htmlspecialchars($profile['degree']); ?>" placeholder="e.g. BSc in Computer Science">
            </div>
        </div>
<div class="form-group">
            <label>Subjects You Can Teach</label>
            <input type="text" name="subjects" value="<?php echo htmlspecialchars($profile['subjects']); ?>" placeholder="e.g. Math, Physics, English">
        </div>
        <div class="grid-2">
            <div class="form-group">
                <label>Expected Salary (per month)</label>
                <input type="text" name="expected_salary" value="<?php echo htmlspecialchars($profile['expected_salary']); ?>" placeholder="e.g. 6000 BDT">
            </div>
          <div class="form-group">
    <label>Preferred Locations</label>
    <input type="text" name="preferred_location" value="<?php echo htmlspecialchars($profile['preferred_location']); ?>" placeholder="e.g. Dhanmondi, Mirpur, Uttara (separate with commas)">
    <div class="file-note" style="color:#8a8578;"><i class="fas fa-circle-info"></i> Add multiple areas separated by commas — you'll get notified for tuitions in any of them.</div>
</div>
        </div>
        <button class="btn" type="submit">Save Academic Info</button>
    </form>
</div>

        <!-- Proof Documents -->
   <div class="section-card" id="proof">
    <h3>Proof Documents</h3>
    <p class="section-desc">Upload documents to verify your academic background and identity.</p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="form_type" value="proof_docs">
        <div class="grid-2">
            <div class="form-group">
                <label>School Certificate</label>
                <input type="file" name="school_certificate" accept="image/*,.pdf">
                <?php if (!empty($profile['school_certificate_path'])): ?>
                    <div class="file-note"><i class="fas fa-check"></i> Uploaded</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>College Certificate</label>
                <input type="file" name="college_certificate" accept="image/*,.pdf">
                <?php if (!empty($profile['college_certificate_path'])): ?>
                    <div class="file-note"><i class="fas fa-check"></i> Uploaded</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Current Institute ID Card</label>
                <input type="file" name="current_id" accept="image/*,.pdf">
                <?php if (!empty($profile['current_id_path'])): ?>
                    <div class="file-note"><i class="fas fa-check"></i> Uploaded</div>
                <?php endif; ?>
            </div>
        </div>
        <button class="btn" type="submit">Upload Documents</button>
    </form>
</div>

        <!-- Availability -->
<div class="section-card" id="availability">
    <h3>Availability</h3>
    <p class="section-desc">Click every day and time slot when you're free to teach.</p>
    <form method="POST">
        <input type="hidden" name="form_type" value="availability">
        <div class="avail-table-wrap">
            <table class="avail-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <?php foreach ($timeSlots as $slot): ?>
                            <th><?php echo htmlspecialchars($slot); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($days as $day): ?>
                        <tr>
                            <td class="day-label"><?php echo $day; ?></td>
                            <?php foreach ($timeSlots as $slot):
                                $slotKey = $day . "-" . explode(" ", $slot)[0];
                                $isChecked = in_array($slotKey, $selectedSlots) ? 'checked' : '';
                            ?>
                                <td>
                                    <label class="slot-check">
                                        <input type="checkbox" name="availability_slots[]" value="<?php echo $slotKey; ?>" <?php echo $isChecked; ?>>
                                        <span></span>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button class="btn" type="submit" style="margin-top:16px;">Save Availability</button>
    </form>
</div>

        <!-- Additional Info -->
        <div class="section-card" id="additional">
            <h3>Additional Information</h3>
            <p class="section-desc">Teaching experience, certifications, achievements — anything else worth sharing.</p>
            <form method="POST">
                <input type="hidden" name="form_type" value="additional_info">
                <div class="form-group">
                    <textarea name="additional_info" placeholder="Tell students more about yourself..."><?php echo htmlspecialchars($profile['additional_info']); ?></textarea>
                </div>
                <button class="btn" type="submit">Save Additional Info</button>
            </form>
        </div>

        <!-- Download PDF -->
        <?php if ($completionPercent == 100): ?>
            <div class="pdf-section">
                <h3><i class="fas fa-file-pdf"></i> Your Profile is Complete!</h3>
                <p>Download a formatted copy of your full tutor profile.</p>
                <a href="download_profile.php" class="pdf-btn" target="_blank">
                    <i class="fas fa-download"></i> Download Profile as PDF
                </a>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
