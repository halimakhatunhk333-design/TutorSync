<?php
/**
 * registration.php
 * Real PHP + MySQL registration for TutorSync.
 * Validates input, hashes the password, and inserts a new student
 * into the `users` table.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require "db.php";

$errorMessage   = "";
$successMessage = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name            = trim($_POST['name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || $password === '' || $confirmPassword === '') {
        $errorMessage = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } elseif (strlen($password) < 6) {
        $errorMessage = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirmPassword) {
        $errorMessage = "Passwords do not match.";
    } else {

        // Check if email already exists
        $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($checkStmt, "s", $email);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);

        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $errorMessage = "An account with this email already exists.";
        } else {

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
           $role = $_POST['role'] ?? 'student';

if ($role !== 'student' && $role !== 'tutor') {
    $role = 'student'; // safety fallback if someone tampers with the form
} // registration form is for students

            $insertStmt = mysqli_prepare($conn, "INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($insertStmt, "sssss", $name, $email, $phone, $hashedPassword, $role);

            if (mysqli_stmt_execute($insertStmt)) {
                $successMessage = "Registration successful! Redirecting to login...";
                header("refresh:2; url=login.php");
            } else {
                $errorMessage = "Something went wrong. Please try again.";
            }

            mysqli_stmt_close($insertStmt);
        }

        mysqli_stmt_close($checkStmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TutorSync - Student Registration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body{
    min-height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;

    background-image:
    linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)),
    url("https://www.shutterstock.com/image-photo/education-learning-concept-mortarboard-graduation-260nw-2556672387.jpg");

    background-size:cover;
    background-position:center;
    background-repeat:no-repeat;
}

.container{
    width:550px;
    padding:30px;
    border-radius:20px;

    background:rgba(255,255,255,0.15);

    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);

    border:1px solid rgba(255,255,255,0.2);

    box-shadow:0 15px 40px rgba(0,0,0,0.25);
}

h2{
    color:white;
    text-align:center;
    margin-bottom:25px;
    font-size:20px;
    font-weight:600;
    letter-spacing:1px;
}

label{
    color:white;
}

.form-group{
    margin-bottom:18px;
}

input{
    width:100%;
    padding:12px 15px;
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.25);
    color:white;
    border-radius:8px;
}
input::placeholder{
    color:#e5e7eb;
}

.btn{
    width:100%;
    padding:13px;
    border:none;
    border-radius:8px;
    background:#10b981;
    color:white;
    font-size:16px;
    font-weight:bold;
    cursor:pointer;
    transition:0.3s;
}

.login-link{
    color:white;
    margin-top:25px;
    text-align:center;
    font-size:15px;
}

.login-link a{
    color:#38bdf8;
    text-decoration:underline;
    font-weight:600;
}

.login-link a:hover{
    color:#7dd3fc;
}
.logo-section{
    text-align:center;
    margin-bottom:15px;
}

.logo-section i{
    font-size:50px;
    color:#10b981;
    margin-bottom:10px;
}

.logo-section h1{
    color:#10b981;
    font-size:32px;
}
.btn:hover{
    background:#059669;
}
input:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 15px rgba(56,189,248,0.5);
}
select{
    width:100%;
    padding:12px 15px;
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.25);
    color:white;
    border-radius:8px;
    font-size:14px;
    cursor:pointer;
    appearance:none;
    -webkit-appearance:none;
    -moz-appearance:none;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'><path d='M7 10l5 5 5-5z'/></svg>");
    background-repeat:no-repeat;
    background-position:right 15px center;
    background-size:16px;
}

select:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 15px rgba(56,189,248,0.5);
}

select option{
    background:#1f2937;
    color:white;
}
.error-box{
    background:rgba(239,68,68,0.2);
    border:1px solid rgba(239,68,68,0.5);
    color:#fecaca;
    padding:10px 14px;
    border-radius:8px;
    margin-bottom:18px;
    font-size:14px;
    text-align:center;
}

.success-box{
    background:rgba(16,185,129,0.2);
    border:1px solid rgba(16,185,129,0.5);
    color:#a7f3d0;
    padding:10px 14px;
    border-radius:8px;
    margin-bottom:18px;
    font-size:14px;
    text-align:center;
}
    </style>
</head>
<body>

<div class="container">
<a href="WelcomePage.php" style="display:inline-block; margin-bottom:15px; color:white; text-decoration:none; font-size:14px;">
    <i class="fas fa-arrow-left"></i> Back to Home
</a>


    <div class="logo-section">
        <i class="fas fa-user-graduate"></i>
        <h1>TutorSync</h1>
    </div>

    <h2>Create Your Student Account</h2>

    <?php if ($errorMessage !== ""): ?>
        <div class="error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ""): ?>
        <div class="success-box"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <form id="registrationForm" method="POST" action="registration.php">

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" placeholder="Enter your full name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="Enter your email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="Enter your phone number" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
        </div>
<div class="form-group">
    <label>I am a:</label>
    <select name="role" required>
        <option value="student" <?php echo (($_POST['role'] ?? '') === 'student') ? 'selected' : ''; ?>>Student (looking for a tutor)</option>
        <option value="tutor" <?php echo (($_POST['role'] ?? '') === 'tutor') ? 'selected' : ''; ?>>Tutor (offering to teach)</option>
    </select>
</div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Create a password" required>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirmPassword" placeholder="Confirm password" required>
        </div>

        <button class="btn" type="submit">
            Register
        </button>

        <div class="login-link">
            Already have an account?
            <a href="login.php">Login</a>
        </div>

    </form>

</div>

</body>
</html>
