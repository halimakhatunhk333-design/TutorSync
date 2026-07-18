<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = $_SESSION['user_id'];
$tuitionId = (int) ($_GET['tuition_id'] ?? 0);
$successMessage = "";

// Verify this tuition belongs to this student
$checkStmt = mysqli_prepare($conn, "SELECT * FROM tuition_requests WHERE id = ? AND student_id = ?");
mysqli_stmt_bind_param($checkStmt, "ii", $tuitionId, $studentId);
mysqli_stmt_execute($checkStmt);
$checkResult = mysqli_stmt_get_result($checkStmt);
$tuition = mysqli_fetch_assoc($checkResult);
mysqli_stmt_close($checkStmt);

if (!$tuition) {
    die("Tuition request not found or you don't have access to it.");
}

// Handle confirm action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_tutor_id'])) {
    $confirmedTutorId = (int) $_POST['confirm_tutor_id'];

    // Mark selected tutor as 'selected', others as 'rejected'
    $selectStmt = mysqli_prepare($conn, "UPDATE tuition_applications SET status = 'selected' WHERE tuition_id = ? AND tutor_id = ?");
    mysqli_stmt_bind_param($selectStmt, "ii", $tuitionId, $confirmedTutorId);
    mysqli_stmt_execute($selectStmt);
    mysqli_stmt_close($selectStmt);

    $rejectStmt = mysqli_prepare($conn, "UPDATE tuition_applications SET status = 'rejected' WHERE tuition_id = ? AND tutor_id != ?");
    mysqli_stmt_bind_param($rejectStmt, "ii", $tuitionId, $confirmedTutorId);
    mysqli_stmt_execute($rejectStmt);
    mysqli_stmt_close($rejectStmt);

    // Mark tuition as matched
    $matchStmt = mysqli_prepare($conn, "UPDATE tuition_requests SET status = 'matched' WHERE id = ?");
    mysqli_stmt_bind_param($matchStmt, "i", $tuitionId);
    mysqli_stmt_execute($matchStmt);
    mysqli_stmt_close($matchStmt);

    // Get tutor + student names for the notification message
    $tutorNameStmt = mysqli_prepare($conn, "SELECT name FROM users WHERE id = ?");
    mysqli_stmt_bind_param($tutorNameStmt, "i", $confirmedTutorId);
    mysqli_stmt_execute($tutorNameStmt);
    $tutorName = mysqli_fetch_assoc(mysqli_stmt_get_result($tutorNameStmt))['name'];
    mysqli_stmt_close($tutorNameStmt);

    $studentName = $_SESSION['name'];
    $subject = $tuition['subject'];

    // Notify ALL admins
    $adminStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE role = 'admin'");
    mysqli_stmt_execute($adminStmt);
    $adminResult = mysqli_stmt_get_result($adminStmt);

    $notifMsg = "$studentName confirmed $tutorName for the $subject tuition. Please contact the tutor to proceed.";
    $notifLink = "admin_dashboard.php";

    while ($admin = mysqli_fetch_assoc($adminResult)) {
        $notifStmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($notifStmt, "iss", $admin['id'], $notifMsg, $notifLink);
        mysqli_stmt_execute($notifStmt);
        mysqli_stmt_close($notifStmt);
    }
    mysqli_stmt_close($adminStmt);

    // Notify the confirmed tutor too
    $tutorNotifMsg = "Congratulations! $studentName selected you for the $subject tuition. Our admin team will contact you shortly.";
    $tutorNotifStmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($tutorNotifStmt, "iss", $confirmedTutorId, $tutorNotifMsg, $notifLink);
    mysqli_stmt_execute($tutorNotifStmt);
    mysqli_stmt_close($tutorNotifStmt);

    $successMessage = "You've confirmed $tutorName for this tuition! Admin has been notified and will reach out to arrange things.";

    // Refresh tuition status
    $tuition['status'] = 'matched';
}

// Fetch all applicants for this tuition
// NOTE: expected_salary / preferred_location are intentionally still selected here
// (not displayed) in case a future admin-only view needs them — but they are
// never echoed to the student below.
$applicantsStmt = mysqli_prepare($conn, "
    SELECT ta.*, u.name, u.email, u.phone, tp.university, tp.college, tp.school, tp.subjects, 
           tp.expected_salary, tp.preferred_location, tp.profile_pic_path
    FROM tuition_applications ta
    JOIN users u ON ta.tutor_id = u.id
    LEFT JOIN tutor_profiles tp ON tp.user_id = u.id
    WHERE ta.tuition_id = ?
    ORDER BY ta.applied_at ASC
");
mysqli_stmt_bind_param($applicantsStmt, "i", $tuitionId);
mysqli_stmt_execute($applicantsStmt);
$applicantsResult = mysqli_stmt_get_result($applicantsStmt);
$applicants = mysqli_fetch_all($applicantsResult, MYSQLI_ASSOC);
mysqli_stmt_close($applicantsStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Applicants — TutorSync</title>
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
    background:var(--teal); color:var(--ivory); padding:20px 40px;
    display:flex; justify-content:space-between; align-items:center;
}
.topbar h2{ font-family:'Fraunces', serif; font-size:1.3rem; }
.topbar a{ color:var(--gold-soft); text-decoration:none; font-weight:600; font-size:0.9rem; }

.container{ max-width:850px; margin:30px auto; padding:0 20px 60px; }

.success-msg{
    background:#d1fae5; color:#065f46; padding:14px 18px; border-radius:8px;
    margin-bottom:20px; font-weight:600;
}

.tuition-summary{
    background:#fff; border-radius:14px; padding:22px 24px; margin-bottom:24px;
    box-shadow:0 6px 20px rgba(21,34,56,0.07);
}
.tuition-summary h3{ font-family:'Fraunces', serif; color:var(--navy); margin-bottom:6px; }
.tuition-summary .meta{ color:#6b7280; font-size:0.88rem; }

.status-matched{
    display:inline-flex; align-items:center; gap:8px;
    background:#D1FAE5; color:#065F46; padding:8px 16px;
    border-radius:8px; font-weight:600; font-size:0.88rem; margin-top:12px;
}

.applicant-card{
    background:#fff; border-radius:14px; padding:22px 24px; margin-bottom:16px;
    box-shadow:0 6px 20px rgba(21,34,56,0.06); display:flex; gap:18px;
}
.applicant-pic{
    width:70px; height:70px; border-radius:50%; object-fit:cover;
    border:3px solid var(--gold-soft); flex-shrink:0; background:var(--ivory-deep);
}
.applicant-pic-placeholder{
    width:70px; height:70px; border-radius:50%; background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.applicant-pic-placeholder i{ font-size:1.8rem; color:var(--line); }

.applicant-info{ flex:1; }
.applicant-info h4{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.1rem; margin-bottom:4px; }
.applicant-meta{ display:flex; flex-wrap:wrap; gap:8px; margin:10px 0; }
.applicant-meta span{
    background:var(--ivory); border:1px solid var(--line);
    padding:4px 10px; border-radius:20px; font-size:0.78rem;
}
.applicant-meta span i{ color:var(--teal); margin-right:4px; }

.applicant-actions{ display:flex; gap:10px; margin-top:12px; }
.btn-view{
    background:#fff; color:var(--navy); border:1px solid var(--navy);
    padding:8px 18px; border-radius:8px; text-decoration:none; font-weight:600; font-size:0.85rem;
}
.btn-confirm{
    background:var(--gold); color:var(--navy-deep); border:none;
    padding:8px 18px; border-radius:8px; cursor:pointer; font-weight:700; font-size:0.85rem;
}
.btn-confirm:hover{ background:var(--gold-soft); }

.selected-tag{
    background:var(--teal); color:#fff; padding:6px 14px;
    border-radius:8px; font-weight:600; font-size:0.82rem; display:inline-flex; align-items:center; gap:6px;
}

.empty-state{ text-align:center; padding:50px 20px; color:#9CA3AF; background:#fff; border-radius:14px; }
.empty-state i{ font-size:2.2rem; margin-bottom:12px; display:block; }
</style>
</head>
<body>

<div class="topbar">
    <h2>Applicants</h2>
    <a href="student_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<div class="container">

    <?php if ($successMessage): ?>
        <div class="success-msg"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <div class="tuition-summary">
        <h3><?php echo htmlspecialchars($tuition['subject']); ?></h3>
        <div class="meta">
            <?php echo htmlspecialchars($tuition['class_level']); ?> ·
            <?php echo htmlspecialchars($tuition['preferred_schedule']); ?> ·
            <?php echo htmlspecialchars($tuition['budget']); ?>
        </div>
        <?php if ($tuition['status'] === 'matched'): ?>
            <div class="status-matched"><i class="fas fa-handshake"></i> Tutor Confirmed — Admin will contact them soon</div>
        <?php endif; ?>
    </div>

    <?php if (count($applicants) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            No tutors have applied yet. Check back soon!
        </div>
    <?php else: ?>
        <?php foreach ($applicants as $app): ?>
            <div class="applicant-card">
                <?php if (!empty($app['profile_pic_path'])): ?>
                    <img src="uploads/<?php echo htmlspecialchars($app['profile_pic_path']); ?>" class="applicant-pic" alt="">
                <?php else: ?>
                    <div class="applicant-pic-placeholder"><i class="fas fa-user"></i></div>
                <?php endif; ?>

                <div class="applicant-info">
                    <h4><?php echo htmlspecialchars($app['name']); ?></h4>
                    <div class="applicant-meta">
                        <?php if ($app['university']): ?><span><i class="fas fa-graduation-cap"></i><?php echo htmlspecialchars($app['university']); ?></span><?php endif; ?>
                        <?php if ($app['subjects']): ?><span><i class="fas fa-book"></i><?php echo htmlspecialchars($app['subjects']); ?></span><?php endif; ?>
                        <!-- Expected salary and preferred location intentionally omitted here — -->
                        <!-- students shouldn't see a tutor's private expectations on this card. -->
                        <!-- Full profile view (view_tutor_profile.php) already gates these behind -->
                        <!-- ownership/admin, so they still aren't exposed there either. -->
                    </div>

                    <div class="applicant-actions">
                        <a href="view_tutor_profile.php?tutor_id=<?php echo $app['tutor_id']; ?>" class="btn-view">
                            <i class="fas fa-eye"></i> View Full Profile
                        </a>

                        <?php if ($app['status'] === 'selected'): ?>
                            <span class="selected-tag"><i class="fas fa-star"></i> Confirmed</span>
                        <?php elseif ($tuition['status'] !== 'matched'): ?>
                            <form method="POST" onsubmit="return confirm('Confirm this tutor for the tuition?');">
                                <input type="hidden" name="confirm_tutor_id" value="<?php echo $app['tutor_id']; ?>">
                                <button class="btn-confirm" type="submit"><i class="fas fa-check"></i> Confirm This Tutor</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>
