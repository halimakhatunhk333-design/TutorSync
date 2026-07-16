<?php
/**
 * db.php
 * Database connection for TutorSync.
 */

$host     = "localhost";   // usually 'localhost' on shared/local hosting
$username = "root";        // your MySQL username
$password = "";            // your MySQL password
$database = "TutorSync";   // your database name

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>
