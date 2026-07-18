<?php
session_start();
require "db.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Who is looking at this profile?
$viewerRole = $_SESSION['role'] ?? 'guest';
$viewerId   = $_SESSION['user_id'] ?? 0;

$tutorId = (int) ($_GET['tutor_id'] ?? 0);

// The tutor is always allowed to see their own full profile
$isOwner = ($viewerId === $tutorId);

// Only the tutor themself or an admin should see salary / location / availability
$canSeePrivateDetails = $isOwner || $viewerRole === 'admin';

$stmt = mysqli_prepare($conn, "
    SELECT u.name, u.email, u.phone, tp.* 
    FROM users u 
    JOIN tutor_profiles tp ON u.id = tp.user_id 
    WHERE u.id = ? AND u.role = 'tutor'
");
mysqli_stmt_bind_param($stmt, "i", $tutorId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$profile = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$profile) {
    die("Tutor profile not found.");
}

$readableAvailability = "Not specified";
if (!empty($profile['availability'])) {
    $slots = explode(",", $profile['availability']);
    $formatted = array_map(function($slot) {
        $parts = explode("-", $slot);
        return isset($parts[1]) ? $parts[0] . " (" . $parts[1] . ")" : $slot;
    }, $slots);
    $readableAvailability = implode(", ", $formatted);
}

// Certificates aren't tracked in the DB — they just live in /uploads with
// filenames like: college_certificate_2_1784273xxx.png, school_certificate_2_....png
// (i.e. "..._certificate_{user_id}_{timestamp}.ext"). Find this tutor's certs
// by matching that pattern against the uploads folder.
$certificates = [];
$uploadsDir   = __DIR__ . "/uploads";

if (is_dir($uploadsDir)) {
    // Matches any file containing "certificate_<tutorId>_" e.g. college_certificate_2_123.png
    $pattern = $uploadsDir . "/*certificate_" . $tutorId . "_*";
    foreach (glob($pattern) as $filePath) {
        $filename = basename($filePath);

        // Turn "college_certificate_2_123.png" into a readable label "College Certificate"
        $label = preg_replace('/_' . $tutorId . '_.*$/', '', $filename);
        $label = ucwords(str_replace('_', ' ', $label));

        $certificates[] = [
            'file_path' => $filename,
            'title'     => $label,
        ];
    }
}

// Reviews — visible to everyone, this is what builds trust for other students/guardians
$avgRating   = null;
$reviewCount = 0;
$reviews     = [];

$ratingStmt = mysqli_prepare($conn, "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM tutor_reviews WHERE tutor_id = ?");
mysqli_stmt_bind_param($ratingStmt, "i", $tutorId);
mysqli_stmt_execute($ratingStmt);
$ratingResult = mysqli_stmt_get_result($ratingStmt);
$ratingRow = mysqli_fetch_assoc($ratingResult);
mysqli_stmt_close($ratingStmt);

if ($ratingRow && $ratingRow['total'] > 0) {
    $avgRating   = round((float) $ratingRow['avg_rating'], 1);
    $reviewCount = (int) $ratingRow['total'];
}

$reviewsStmt = mysqli_prepare($conn, "
    SELECT tr.rating, tr.comment, tr.created_at, u.name AS student_name
    FROM tutor_reviews tr
    JOIN users u ON tr.student_id = u.id
    WHERE tr.tutor_id = ?
    ORDER BY tr.created_at DESC
");
mysqli_stmt_bind_param($reviewsStmt, "i", $tutorId);
mysqli_stmt_execute($reviewsStmt);
$reviewsResult = mysqli_stmt_get_result($reviewsStmt);
$reviews = mysqli_fetch_all($reviewsResult, MYSQLI_ASSOC);
mysqli_stmt_close($reviewsStmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($profile['name']); ?> — Tutor Profile</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --navy:#152238; --ivory:#F6F3EC; --ivory-deep:#EDE8DC;
    --gold:#C9982F; --gold-soft:#E4C87A; --teal:#2F6F6B; --charcoal:#20232A; --line:#D8D2C4;
}
*{ margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body{ background:var(--ivory-deep); color:var(--charcoal); }
.topbar{ background:var(--navy); color:var(--ivory); padding:20px 40px; }
.topbar a{ color:var(--gold-soft); text-decoration:none; font-weight:600; font-size:0.9rem; }

.container{ max-width:750px; margin:30px auto; padding:0 20px 60px; }

.header-card{
    background:#fff; border-radius:16px; padding:30px; display:flex; align-items:center; gap:24px;
    margin-bottom:22px; box-shadow:0 6px 24px rgba(21,34,56,0.08);
}
.pic{ width:100px; height:100px; border-radius:50%; object-fit:cover; border:4px solid var(--gold-soft); }
.pic-placeholder{
    width:100px; height:100px; border-radius:50%; background:var(--ivory-deep);
    display:flex; align-items:center; justify-content:center;
}
.pic-placeholder i{ font-size:2.4rem; color:var(--line); }
.header-card h1{ font-family:'Fraunces', serif; color:var(--navy); font-size:1.5rem; }
.header-card .sub{ color:#6b7280; font-size:0.9rem; margin-top:4px; }

.section-card{
    background:#fff; border-radius:14px; padding:24px; margin-bottom:18px;
    box-shadow:0 6px 20px rgba(21,34,56,0.06);
}
.section-card h3{ font-family:'Fraunces', serif; color:var(--teal); font-size:1.05rem; margin-bottom:12px; }
.row{ margin-bottom:8px; font-size:0.92rem; }
.row b{ display:inline-block; width:180px; color:var(--navy); }

.cert-grid{ display:flex; flex-wrap:wrap; gap:12px; }
.cert-grid img{ width:140px; height:180px; object-fit:cover; border-radius:8px; border:1px solid var(--line); }
.cert-grid a{ text-decoration:none; }

.rating-summary{ display:flex; align-items:center; gap:14px; margin-bottom:6px; }
.rating-summary .avg-number{ font-family:'Fraunces', serif; font-size:2rem; color:var(--navy); }
.rating-summary .stars{ color:var(--gold); font-size:1.1rem; letter-spacing:2px; }
.rating-summary .stars .empty{ color:var(--line); }
.rating-summary .count{ color:#8a8578; font-size:0.85rem; }
.no-reviews{ color:#9CA3AF; font-size:0.9rem; }

.review-item{ padding:14px 0; border-bottom:1px solid #F0EDE3; }
.review-item:last-child{ border-bottom:none; padding-bottom:0; }
.review-item .review-top{ display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.review-item .reviewer{ font-weight:600; color:var(--navy); font-size:0.9rem; }
.review-item .review-stars{ color:var(--gold); font-size:0.85rem; letter-spacing:1px; }
.review-item .review-stars .empty{ color:var(--line); }
.review-item .review-date{ font-size:0.75rem; color:#9CA3AF; }
.review-item .review-comment{ font-size:0.88rem; color:var(--charcoal); line-height:1.5; }
</style>
</head>
<body>

<div class="topbar">
    <a href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="container">

    <div class="header-card">
        <?php if (!empty($profile['profile_pic_path'])): ?>
            <img src="uploads/<?php echo htmlspecialchars($profile['profile_pic_path']); ?>" class="pic" alt="">
        <?php else: ?>
            <div class="pic-placeholder"><i class="fas fa-user"></i></div>
        <?php endif; ?>
        <div>
            <h1><?php echo htmlspecialchars($profile['name']); ?></h1>
            <div class="sub"><?php echo htmlspecialchars($profile['email']); ?> · <?php echo htmlspecialchars($profile['phone']); ?></div>
        </div>
    </div>

    <div class="section-card">
        <h3>Academic Background</h3>
        <div class="row"><b>University:</b> <?php echo htmlspecialchars($profile['university']); ?></div>
        <div class="row"><b>University CGPA:</b> <?php echo htmlspecialchars($profile['university_cgpa']); ?></div>
        <div class="row"><b>College:</b> <?php echo htmlspecialchars($profile['college']); ?></div>
        <div class="row"><b>School:</b> <?php echo htmlspecialchars($profile['school']); ?></div>
        <div class="row"><b>Degree:</b> <?php echo htmlspecialchars($profile['degree']); ?></div>
        <div class="row"><b>Subjects Taught:</b> <?php echo htmlspecialchars($profile['subjects']); ?></div>
    </div>

    <?php /* Certificates: visible to everyone — this is what builds trust for students */ ?>
    <?php if (!empty($certificates)): ?>
        <div class="section-card">
            <h3>Certificates</h3>
            <div class="cert-grid">
                <?php foreach ($certificates as $cert): ?>
                    <a href="uploads/<?php echo htmlspecialchars($cert['file_path']); ?>" target="_blank">
                        <img src="uploads/<?php echo htmlspecialchars($cert['file_path']); ?>" alt="<?php echo htmlspecialchars($cert['title'] ?? 'Certificate'); ?>">
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php /* Reviews: visible to everyone — real feedback from confirmed students builds trust */ ?>
    <div class="section-card">
        <h3>Student Reviews</h3>

        <?php if ($avgRating !== null): ?>
            <div class="rating-summary">
                <div class="avg-number"><?php echo number_format($avgRating, 1); ?></div>
                <div>
                    <div class="stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fa-star <?php echo $i <= round($avgRating) ? 'fas' : 'far empty'; ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="count"><?php echo $reviewCount; ?> review<?php echo $reviewCount === 1 ? '' : 's'; ?></div>
                </div>
            </div>
        <?php else: ?>
            <p class="no-reviews">No reviews yet.</p>
        <?php endif; ?>

        <?php foreach ($reviews as $rev): ?>
            <div class="review-item">
                <div class="review-top">
                    <span class="reviewer"><?php echo htmlspecialchars($rev['student_name']); ?></span>
                    <span class="review-date"><?php echo date("M j, Y", strtotime($rev['created_at'])); ?></span>
                </div>
                <div class="review-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-star <?php echo $i <= $rev['rating'] ? 'fas' : 'far empty'; ?>"></i>
                    <?php endfor; ?>
                </div>
                <?php if (!empty($rev['comment'])): ?>
                    <div class="review-comment"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* Expectations & Availability: private — only the tutor themself or an admin can see these */ ?>
    <?php if ($canSeePrivateDetails): ?>
        <div class="section-card">
            <h3>Expectations</h3>
            <div class="row"><b>Expected Salary:</b> <?php echo htmlspecialchars($profile['expected_salary']); ?></div>
            <div class="row"><b>Preferred Location(s):</b> <?php echo htmlspecialchars($profile['preferred_location']); ?></div>
        </div>

        <div class="section-card">
            <h3>Availability</h3>
            <div class="row"><?php echo htmlspecialchars($readableAvailability); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($profile['additional_info'])): ?>
        <div class="section-card">
            <h3>Additional Information</h3>
            <div class="row"><?php echo nl2br(htmlspecialchars($profile['additional_info'])); ?></div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
