<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Mark all as read when viewed
$updateStmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?");
mysqli_stmt_bind_param($updateStmt, "i", $userId);
mysqli_stmt_execute($updateStmt);
mysqli_stmt_close($updateStmt);

$stmt = mysqli_prepare($conn, "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$notifications = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$backLink = $_SESSION['role'] === 'tutor' ? 'tutor_dashboard.php' : 'student_dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — TutorSync</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', sans-serif; }
body{ background:#EDE8DC; color:#20232A; }
.topbar{ background:#152238; color:#F6F3EC; padding:20px 40px; display:flex; justify-content:space-between; align-items:center; }
.topbar a{ color:#E4C87A; text-decoration:none; font-weight:600; }
.container{ max-width:700px; margin:30px auto; padding:0 20px 60px; }
.notif-item{
    background:#fff; border-radius:10px; padding:16px 20px; margin-bottom:12px;
    box-shadow:0 4px 14px rgba(21,34,56,0.06); display:flex; align-items:flex-start; gap:14px;
}
.notif-item i{ color:#2F6F6B; font-size:1.2rem; margin-top:3px; }
.notif-text{ flex:1; }
.notif-text a{ color:#152238; text-decoration:none; font-weight:600; }
.notif-time{ font-size:0.78rem; color:#9CA3AF; margin-top:4px; }
.empty-state{ text-align:center; padding:60px 20px; color:#9CA3AF; }
.empty-state i{ font-size:2.5rem; margin-bottom:14px; display:block; }
</style>
</head>
<body>

<div class="topbar">
    <h2>Notifications</h2>
    <a href="<?php echo $backLink; ?>"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<div class="container">
    <?php if (count($notifications) === 0): ?>
        <div class="empty-state">
            <i class="fas fa-bell-slash"></i>
            No notifications yet.
        </div>
    <?php else: ?>
        <?php foreach ($notifications as $notif): ?>
            <div class="notif-item">
                <i class="fas fa-circle-info"></i>
                <div class="notif-text">
                    <?php if ($notif['link']): ?>
                        <a href="<?php echo htmlspecialchars($notif['link']); ?>"><?php echo htmlspecialchars($notif['message']); ?></a>
                    <?php else: ?>
                        <?php echo htmlspecialchars($notif['message']); ?>
                    <?php endif; ?>
                    <div class="notif-time"><?php echo date("M j, Y g:i A", strtotime($notif['created_at'])); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
