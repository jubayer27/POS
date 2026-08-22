<?php
// setup_admin.php
require_once '../config/db.php';

$username = 'admin';
$password = 'admin123'; // Change this to your preferred secure password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (:username, :password, 'admin')");
    $stmt->execute([
        'username' => $username,
        'password' => $hashed_password
    ]);
    echo "Success! Admin user created. You can log in using Username: <b>$username</b> and Password: <b>$password</b><br><br>";
    echo "<strong style='color:red;'>Important: Delete this file (setup_admin.php) immediately for security.</strong>";
} catch (PDOException $e) {
    echo "Error: It looks like the user might already exist, or there is a database issue -> " . $e->getMessage();
}
?>