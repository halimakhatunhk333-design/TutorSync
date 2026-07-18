<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit;
}

$tutorId = $_SESSION['user_id'];
$errorMessage = "";

// Where should we send the tutor after they agree? (so we can redirect back
// to wherever they were trying to go, e.g. applying to a specific tuition)
$returnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? 'tutor_dashboard.php';
// Only allow internal redirects, never an external URL
if (preg_match('#^https?://#i', $returnTo) || str_starts_with($returnTo, '//')) {
    $returnTo = 'tutor_dashboard.php';
}

// view=1  -> just display the agreement, don't redirect even if already agreed
//            (used by the "View Agreement" link and the per-application modal)
// embed=1 -> strip the page down to just the terms box, no header/form/chrome
//            (used when this page is loaded inside an iframe)
$viewOnly = isset($_GET['view']) && $_GET['view'] == '1';
$embed    = isset($_GET['embed']) && $_GET['embed'] == '1';

// Already agreed? Skip straight through — unless we're just viewing the terms.
$checkStmt = mysqli_prepare($conn, "SELECT id FROM tutor_agreements WHERE tutor_id = ?");
mysqli_stmt_bind_param($checkStmt, "i", $tutorId);
mysqli_stmt_execute($checkStmt);
$checkResult = mysqli_stmt_get_result($checkStmt);
$alreadyAgreed = mysqli_fetch_assoc($checkResult) !== null;
mysqli_stmt_close($checkStmt);

if ($alreadyAgreed && !$viewOnly) {
    header("Location: " . $returnTo);
    exit;
}

// The accept-and-continue form only exists for first-time agreement, so only
// process a POST when the tutor hasn't already agreed.
if (!$alreadyAgreed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['agree'])) {
        $errorMessage = "You must check the box confirming you've read and agree to the terms before continuing.";
    } else {
        $insertStmt = mysqli_prepare($conn, "INSERT INTO tutor_agreements (tutor_id) VALUES (?)");
        mysqli_stmt_bind_param($insertStmt, "i", $tutorId);
        mysqli_stmt_execute($insertStmt);
        mysqli_stmt_close($insertStmt);

        header("Location: " . $returnTo);
        exit;
    }
}

// --- Embedded view (inside the modal iframe): just the terms, nothing else ---
if ($embed) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Service Agreement</title>
<style>
:root{ --navy:#152238; --ivory:#F6F3EC; --charcoal:#20232A; --line:#D8D2C4; }
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', system-ui, sans-serif; }
body{ background:var(--ivory); color:var(--charcoal); padding:18px 20px; font-size:0.88rem; line-height:1.7; }
h3{ font-size:0.95rem; color:var(--navy); margin:16px 0 6px; }
h3:first-child{ margin-top:0; }
ul{ margin:6px 0 6px 20px; }
b{ color:var(--navy); }
</style>
</head>
<body>
    <h3>1. Service Fee</h3>
    <p>By accepting a confirmed tuition through TutorSync, the tutor agrees to pay a total service fee equal to <b>50% of one month's agreed salary</b> for that tuition, split into two installments:</p>
    <ul>
        <li><b>25%</b> — due within <b>7 days</b> of being confirmed by the student/guardian for the tuition.</li>
        <li><b>25%</b> — due after the tutor receives their <b>first salary payment</b> from the student/guardian.</li>
    </ul>

    <h3>2. Late or Missed Payment</h3>
    <p>Failure to pay the first installment within 7 days of confirmation may result in the tuition match being cancelled and the tutor's account being suspended pending payment.</p>

    <h3>3. Conduct</h3>
    <p>Tutors are expected to communicate honestly with students/guardians and represent their qualifications and certificates accurately. Falsified certificates or credentials may result in permanent removal from the platform.</p>

    <h3>4. Cancellations</h3>
    <p>If a tutor withdraws from a confirmed tuition without reasonable cause after payment has been made, the service fee is non-refundable.</p>

    <h3>5. Agreement Scope</h3>
    <p>This agreement applies to all tuitions confirmed through TutorSync going forward and remains in effect for as long as the tutor's account is active.</p>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Service Agreement — TutorSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238; --ivory:#F6F3EC; --ivory-deep:#EDE8DC;
    --gold:#C9982F; --gold-soft:#E4C87A; --teal:#2F6F6B; --charcoal:#20232A; --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:30px; }

.card{
    background:#fff; border-radius:16px; padding:36px; max-width:620px; width:100%;
    box-shadow:0 6px 24px rgba(21,34,56,0.1);
}
.card h1{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.4rem; margin-bottom:6px; }
.card .sub{ color:#8a8578; font-size:0.88rem; margin-bottom:22px; }

.terms-box{
    background:var(--ivory); border:1px solid var(--line); border-radius:10px;
    padding:20px 22px; max-height:320px; overflow-y:auto; margin-bottom:22px;
    font-size:0.88rem; line-height:1.7; color:var(--charcoal);
}
.terms-box h3{ font-size:0.95rem; color:var(--navy); margin:16px 0 6px; }
.terms-box h3:first-child{ margin-top:0; }
.terms-box ul{ margin:6px 0 6px 20px; }
.terms-box b{ color:var(--navy); }

.error-msg{
    background:#fee2e2; color:#991b1b; padding:12px 16px;
    border-radius:8px; margin-bottom:18px; font-weight:600; font-size:0.88rem;
}

.agree-row{
    display:flex; align-items:flex-start; gap:10px; margin-bottom:22px;
    background:var(--ivory); padding:14px 16px; border-radius:8px;
    border:1px solid var(--line);
}
.agree-row input{ margin-top:3px; accent-color:var(--teal); cursor:pointer; }
.agree-row label{ font-size:0.88rem; line-height:1.5; cursor:pointer; }

.btn{
    width:100%; background:var(--teal); color:#fff; border:none; padding:13px;
    border-radius:8px; cursor:pointer; font-weight:700; font-size:0.95rem;
}
.btn:hover{ background:#265D59; }

.back-link{ display:inline-block; margin-top:16px; color:var(--teal); font-size:0.85rem; text-decoration:none; font-weight:600; }
</style>
</head>
<body>

<div class="card">
    <h1><i class="fas fa-file-contract"></i> Tutor Service Agreement</h1>
    <div class="sub">
        <?php echo $alreadyAgreed
            ? "You've already accepted this agreement. Shown here for your reference."
            : "Please read this carefully before continuing — you'll only need to do this once."; ?>
    </div>

    <?php if ($errorMessage): ?>
        <div class="error-msg"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <div class="terms-box">
        <h3>1. Service Fee</h3>
        <p>By accepting a confirmed tuition through TutorSync, the tutor agrees to pay a total service fee equal to <b>50% of one month's agreed salary</b> for that tuition, split into two installments:</p>
        <ul>
            <li><b>25%</b> — due within <b>7 days</b> of being confirmed by the student/guardian for the tuition.</li>
            <li><b>25%</b> — due after the tutor receives their <b>first salary payment</b> from the student/guardian.</li>
        </ul>

        <h3>2. Late or Missed Payment</h3>
        <p>Failure to pay the first installment within 7 days of confirmation may result in the tuition match being cancelled and the tutor's account being suspended pending payment.</p>

        <h3>3. Conduct</h3>
        <p>Tutors are expected to communicate honestly with students/guardians and represent their qualifications and certificates accurately. Falsified certificates or credentials may result in permanent removal from the platform.</p>

        <h3>4. Cancellations</h3>
        <p>If a tutor withdraws from a confirmed tuition without reasonable cause after payment has been made, the service fee is non-refundable.</p>

        <h3>5. Agreement Scope</h3>
        <p>This agreement applies to all tuitions confirmed through TutorSync going forward and remains in effect for as long as the tutor's account is active.</p>
    </div>

    <?php if ($alreadyAgreed): ?>
        <a class="back-link" href="<?php echo htmlspecialchars($returnTo); ?>"><i class="fas fa-arrow-left"></i> Back</a>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">
            <div class="agree-row">
                <input type="checkbox" name="agree" id="agree" value="1">
                <label for="agree">I have read and agree to the TutorSync Service Agreement, including the 25% payment due within 7 days of confirmation and the remaining 25% due after my first salary.</label>
            </div>
            <button class="btn" type="submit"><i class="fas fa-check"></i> Agree & Continue</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
