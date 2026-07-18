<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = mysqli_prepare($conn, "SELECT u.name, u.email, u.phone, t.* FROM users u JOIN tutor_profiles t ON u.id = t.user_id WHERE u.id = ?");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$profile = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Turn "Mon-Morning,Wed-Evening" into a readable list like "Mon (Morning), Wed (Evening)"
$readableAvailability = "Not specified";
if (!empty($profile['availability'])) {
    $slots = explode(",", $profile['availability']);
    $formatted = array_map(function($slot) {
        $parts = explode("-", $slot);
        return isset($parts[1]) ? $parts[0] . " (" . $parts[1] . ")" : $slot;
    }, $slots);
    $readableAvailability = implode(", ", $formatted);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tutor Profile - <?php echo htmlspecialchars($profile['name']); ?></title>
<style>
body{
    font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    padding:40px;
    color:#20232A;
    max-width:800px;
    margin:0 auto;
}
h1{
    color:#152238;
    border-bottom:3px solid #C9982F;
    padding-bottom:10px;
    font-size:1.6rem;
}
.section{ margin-top:26px; }
.section h3{
    color:#2F6F6B;
    margin-bottom:10px;
    font-size:1.05rem;
    border-bottom:1px solid #D8D2C4;
    padding-bottom:4px;
}
.row{ margin-bottom:7px; font-size:0.95rem; }
.row b{ display:inline-block; width:200px; color:#152238; }
.profile-pic{
    width:110px; height:110px;
    border-radius:50%;
    object-fit:cover;
    float:right;
    border:3px solid #E4C87A;
}
.doc-list{ list-style:none; padding:0; }
.doc-list li{
    padding:8px 0;
    border-bottom:1px dashed #D8D2C4;
    font-size:0.9rem;
}
.doc-list li i{ color:#2F6F6B; margin-right:8px; }
.print-btn{
    padding:10px 20px;
    background:#152238;
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-weight:600;
    margin-bottom:24px;
}
@media print{
    .no-print{ display:none; }
}
</style>
</head>
<body>

<button class="no-print print-btn" onclick="window.print()">Print / Save as PDF</button>

<?php if (!empty($profile['profile_pic_path'])): ?>
    <img src="uploads/<?php echo htmlspecialchars($profile['profile_pic_path']); ?>" class="profile-pic" alt="Profile">
<?php endif; ?>

<h1><?php echo htmlspecialchars($profile['name']); ?> — Tutor Profile</h1>

<div class="section">
    <h3>Contact Information</h3>
    <div class="row"><b>Email:</b> <?php echo htmlspecialchars($profile['email']); ?></div>
    <div class="row"><b>Phone:</b> <?php echo htmlspecialchars($profile['phone']); ?></div>
    <div class="row"><b>Address:</b> <?php echo htmlspecialchars($profile['address']); ?></div>
    <div class="row"><b>Date of Birth:</b> <?php echo htmlspecialchars($profile['date_of_birth']); ?></div>
</div>

<div class="section">
    <h3>Family & Emergency Contact</h3>
    <div class="row"><b>Father's Name:</b> <?php echo htmlspecialchars($profile['father_name']); ?></div>
    <div class="row"><b>Mother's Name:</b> <?php echo htmlspecialchars($profile['mother_name']); ?></div>
    <div class="row"><b>Emergency Contact:</b> <?php echo htmlspecialchars($profile['emergency_contact']); ?></div>
</div>

<div class="section">
    <h3>Academic Information</h3>
    <div class="row"><b>School:</b> <?php echo htmlspecialchars($profile['school']); ?></div>
    <div class="row"><b>School GPA:</b> <?php echo htmlspecialchars($profile['school_gpa']); ?></div>
    <div class="row"><b>College:</b> <?php echo htmlspecialchars($profile['college']); ?></div>
    <div class="row"><b>College GPA:</b> <?php echo htmlspecialchars($profile['college_gpa']); ?></div>
    <div class="row"><b>University:</b> <?php echo htmlspecialchars($profile['university']); ?></div>
    <div class="row"><b>University CGPA:</b> <?php echo htmlspecialchars($profile['university_cgpa']); ?></div>
    <div class="row"><b>Degree / Program:</b> <?php echo htmlspecialchars($profile['degree']); ?></div>
    <div class="row"><b>Subjects Taught:</b> <?php echo htmlspecialchars($profile['subjects']); ?></div>
</div>

<div class="section">
    <h3>Availability</h3>
    <div class="row"><?php echo htmlspecialchars($readableAvailability); ?></div>
</div>

<div class="section">
    <h3>Uploaded Documents</h3>
    <ul class="doc-list">
        <li><i class="fas fa-check"></i> School Certificate: <?php echo !empty($profile['school_certificate_path']) ? 'Uploaded' : 'Not uploaded'; ?></li>
        <li><i class="fas fa-check"></i> College Certificate: <?php echo !empty($profile['college_certificate_path']) ? 'Uploaded' : 'Not uploaded'; ?></li>
        <li><i class="fas fa-check"></i> Current Institute ID: <?php echo !empty($profile['current_id_path']) ? 'Uploaded' : 'Not uploaded'; ?></li>
    </ul>
</div>

<div class="section">
    <h3>Additional Information</h3>
    <div class="row"><?php echo nl2br(htmlspecialchars($profile['additional_info'])); ?></div>
</div>

</body>
</html>
