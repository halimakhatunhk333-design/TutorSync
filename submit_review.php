<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$studentId = $_SESSION['user_id'];
$tuitionId = (int) ($_GET['tuition_id'] ?? $_POST['tuition_id'] ?? 0);
$errorMessage = "";
$successMessage = "";

// 1. Confirm this tuition request belongs to this student AND is matched
$tuitionStmt = mysqli_prepare($conn, "SELECT * FROM tuition_requests WHERE id = ? AND student_id = ? AND status = 'matched'");
mysqli_stmt_bind_param($tuitionStmt, "ii", $tuitionId, $studentId);
mysqli_stmt_execute($tuitionStmt);
$tuitionResult = mysqli_stmt_get_result($tuitionStmt);
$tuition = mysqli_fetch_assoc($tuitionResult);
mysqli_stmt_close($tuitionStmt);

if (!$tuition) {
    die("This tuition request wasn't found, isn't yours, or hasn't been matched with a tutor yet.");
}

// 2. Find the confirmed tutor for this tuition (status = 'selected')
$tutorStmt = mysqli_prepare($conn, "
    SELECT u.id, u.name, tp.profile_pic_path
    FROM tuition_applications ta
    JOIN users u ON ta.tutor_id = u.id
    LEFT JOIN tutor_profiles tp ON tp.user_id = u.id
    WHERE ta.tuition_id = ? AND ta.status = 'selected'
    LIMIT 1
");
mysqli_stmt_bind_param($tutorStmt, "i", $tuitionId);
mysqli_stmt_execute($tutorStmt);
$tutorResult = mysqli_stmt_get_result($tutorStmt);
$tutor = mysqli_fetch_assoc($tutorResult);
mysqli_stmt_close($tutorStmt);

if (!$tutor) {
    die("No confirmed tutor found for this tuition request.");
}

// 3. Has this student already reviewed this specific match?
$existingStmt = mysqli_prepare($conn, "SELECT * FROM tutor_reviews WHERE tuition_id = ? AND student_id = ?");
mysqli_stmt_bind_param($existingStmt, "ii", $tuitionId, $studentId);
mysqli_stmt_execute($existingStmt);
$existingResult = mysqli_stmt_get_result($existingStmt);
$existingReview = mysqli_fetch_assoc($existingResult);
mysqli_stmt_close($existingStmt);

// 4. Handle submission (only allowed if no existing review yet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$existingReview) {
    $rating  = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $errorMessage = "Please select a star rating between 1 and 5.";
    } else {
        $insertStmt = mysqli_prepare($conn, "INSERT INTO tutor_reviews (tuition_id, tutor_id, student_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($insertStmt, "iiiis", $tuitionId, $tutor['id'], $studentId, $rating, $comment);

        if (mysqli_stmt_execute($insertStmt)) {
            $successMessage = "Thanks! Your review has been posted.";
            $existingReview = [
                'rating'  => $rating,
                'comment' => $comment,
            ];
        } else {
            $errorMessage = "Something went wrong submitting your review. Please try again.";
        }
        mysqli_stmt_close($insertStmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review <?php echo htmlspecialchars($tutor['name']); ?> — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238; --ivory:#F6F3EC; --ivory-deep:#EDE8DC;
    --gold:#C9982F; --gold-soft:#E4C87A; --teal:#2F6F6B; --charcoal:#20232A; --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); min-height:100vh; }

.topbar{ background:var(--teal); color:var(--ivory); padding:20px 40px; }
.topbar a{ color:var(--gold-soft); text-decoration:none; font-weight:600; font-size:0.9rem; }

.container{ max-width:560px; margin:40px auto; padding:0 20px 60px; }

.card{
    background:#fff; border-radius:16px; padding:30px;
    box-shadow:0 6px 24px rgba(21,34,56,0.08);
}

.tutor-row{ display:flex; align-items:center; gap:16px; margin-bottom:24px; }
.tutor-pic{ width:64px; height:64px; border-radius:50%; object-fit:cover; border:3px solid var(--gold-soft); }
.tutor-pic-placeholder{
    width:64px; height:64px; border-radius:50%; background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center;
}
.tutor-pic-placeholder i{ font-size:1.6rem; color:var(--line); }
.tutor-row h2{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.2rem; }
.tutor-row .sub{ color:#8a8578; font-size:0.85rem; margin-top:2px; }

.success-msg{
    background:#d1fae5; color:#065f46; padding:12px 16px;
    border-radius:8px; margin-bottom:20px; font-weight:600;
}
.error-msg{
    background:#fee2e2; color:#991b1b; padding:12px 16px;
    border-radius:8px; margin-bottom:20px; font-weight:600;
}

/* Star rating input — CSS-only, reversed order so hover highlights correctly */
.star-input{
    display:flex; flex-direction:row-reverse; justify-content:flex-end;
    gap:4px; margin-bottom:22px;
}
.star-input input{ display:none; }
.star-input label{
    font-size:2.2rem; color:var(--line); cursor:pointer; transition:color 0.15s ease;
}
.star-input input:checked ~ label,
.star-input label:hover,
.star-input label:hover ~ label{
    color:var(--gold);
}

.star-display{ color:var(--gold); font-size:1.3rem; letter-spacing:2px; margin-bottom:10px; }
.star-display .empty{ color:var(--line); }

label.field-label{ display:block; margin-bottom:8px; font-weight:600; font-size:0.85rem; }
textarea{
    width:100%; padding:12px 14px; border:1px solid var(--line);
    border-radius:8px; font-size:14px; background:var(--ivory);
    min-height:100px; resize:vertical; margin-bottom:20px;
}
textarea:focus{ outline:none; border-color:var(--teal); box-shadow:0 0 0 3px rgba(47,111,107,0.12); }

.btn{
    background:var(--teal); color:#fff; border:none; padding:12px 26px;
    border-radius:8px; cursor:pointer; font-weight:600; font-size:0.92rem;
}
.btn:hover{ background:#265D59; }

.already-reviewed{
    background:var(--ivory); border:1px solid var(--line); border-radius:10px;
    padding:18px 20px;
}
.already-reviewed p{ margin-top:8px; color:var(--charcoal); font-size:0.92rem; line-height:1.5; }
</style>
</head>
<body>

<div class="topbar">
    <a href="student_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<div class="container">
    <div class="card">

        <div class="tutor-row">
            <?php if (!empty($tutor['profile_pic_path'])): ?>
                <img src="uploads/<?php echo htmlspecialchars($tutor['profile_pic_path']); ?>" class="tutor-pic" alt="">
            <?php else: ?>
                <div class="tutor-pic-placeholder"><i class="fas fa-user"></i></div>
            <?php endif; ?>
            <div>
                <h2><?php echo htmlspecialchars($tutor['name']); ?></h2>
                <div class="sub">for <?php echo htmlspecialchars($tuition['subject']); ?></div>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="success-msg"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="error-msg"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <?php if ($existingReview): ?>
            <div class="already-reviewed">
                <div class="star-display">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-star <?php echo $i <= $existingReview['rating'] ? 'fas' : 'far empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <?php if (!empty($existingReview['comment'])): ?>
                    <p><?php echo nl2br(htmlspecialchars($existingReview['comment'])); ?></p>
                <?php else: ?>
                    <p style="color:#9CA3AF;">You didn't leave a written comment.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <form method="POST">
                <input type="hidden" name="tuition_id" value="<?php echo $tuitionId; ?>">

                <label class="field-label">Your Rating</label>
                <div class="star-input">
                    <input type="radio" name="rating" id="star5" value="5"><label for="star5"><i class="fas fa-star"></i></label>
                    <input type="radio" name="rating" id="star4" value="4"><label for="star4"><i class="fas fa-star"></i></label>
                    <input type="radio" name="rating" id="star3" value="3"><label for="star3"><i class="fas fa-star"></i></label>
                    <input type="radio" name="rating" id="star2" value="2"><label for="star2"><i class="fas fa-star"></i></label>
                    <input type="radio" name="rating" id="star1" value="1"><label for="star1"><i class="fas fa-star"></i></label>
                </div>

                <label class="field-label">Your Review (optional)</label>
                <textarea name="comment" placeholder="How was your experience with this tutor? This helps other students and guardians decide."></textarea>

                <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> Submit Review</button>
            </form>

        <?php endif; ?>

    </div>
</div>

</body>
</html>
