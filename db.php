<?php
// Database connection (XAMPP defaults: user root, empty password)
$pdo = new PDO('mysql:host=localhost;dbname=parking_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
