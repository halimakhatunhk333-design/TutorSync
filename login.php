<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * login.php
 * Real PHP + MySQL login for TutorSync.
 * Checks submitted email/password against the `users` table,
 * verifies the hashed password, and starts a session on success.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require "db.php";

$errorMessage = "";

// If already logged in, skip straight to the right dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'tutor') {
        header("Location: tutor_dashboard.php");
    } else {
        header("Location: student_dashboard.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $errorMessage = "Please fill in all fields.";
    } else {

        // Use a prepared statement to avoid SQL injection
        $stmt = mysqli_prepare($conn, "SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");

        if (!$stmt) {
            die("Prepare failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);

        // Bind results manually instead of using get_result (works without mysqlnd)
        mysqli_stmt_bind_result($stmt, $id, $name, $dbEmail, $dbPassword, $role);

        $user = null;
        if (mysqli_stmt_fetch($stmt)) {
            $user = [
                'id'       => $id,
                'name'     => $name,
                'email'    => $dbEmail,
                'password' => $dbPassword,
                'role'     => $role
            ];
        }

        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user['password'])) {

            // Correct credentials — start the session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role']; // 'student' or 'tutor'

            if ($user['role'] === 'tutor') {
                header("Location: tutor_dashboard.php");
            } else {
                header("Location: student_dashboard.php");
            }
            exit;

        } else {
            $errorMessage = "Invalid email or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TutorSync - Login</title>

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
    url("https://burst.shopifycdn.com/photos/person-holds-a-book-over-a-stack-and-turns-the-page.jpg?width=1000&format=pjpg&exif=0&iptc=0");

    background-size:cover;
    background-position:center;
    background-repeat:no-repeat;
}

.container{
    width:500px;
    padding:30px;
    border-radius:20px;

    background:rgba(255,255,255,0.15);

    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);

    border:1px solid rgba(255,255,255,0.2);

    box-shadow:0 15px 40px rgba(0,0,0,0.25);
}

.logo-section{
    text-align:center;
    margin-bottom:15px;
}

.logo-section i{
    font-size:50px;
    color:#10b981;
}

.logo-section h1{
    color:#10b981;
    font-size:34px;
    letter-spacing:2px;
}

h2{
    text-align:center;
    color:white;
    margin-bottom:25px;
    font-size:24px;
    letter-spacing:1px;
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

.form-group{
    margin-bottom:18px;
}

label{
    display:block;
    margin-bottom:6px;
    color:white;
    font-weight:600;
}

input{
    width:100%;
    padding:12px 15px;
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.25);
    border-radius:8px;
    color:white;
    font-size:14px;
}

input::placeholder{
    color:#e5e7eb;
}

input:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 15px rgba(56,189,248,0.5);
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

.btn:hover{
    background:#059669;
    box-shadow:0 0 20px rgba(56,189,248,0.7);
}

.register-link{
    text-align:center;
    margin-top:25px;
    color:white;
}

.register-link a{
    color:#38bdf8;
    text-decoration:underline;
    font-weight:600;
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
   

    <h2>Student Login Portal</h2>

    <?php if ($errorMessage !== ""): ?>
        <div class="error-box"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form id="loginForm" method="POST" action="login.php">

        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" id="email" placeholder="Enter your email" autocomplete="off" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" id="password" placeholder="Enter your password" autocomplete="off" required>
        </div>

        <button class="btn" type="submit">
            Login
        </button>

        <div class="register-link">
            Don't have an account?
            <a href="registration.php">Register</a>
        </div>

    </form>

</div>

</body>
</html>
