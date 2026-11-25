<?php
session_start();
require 'db.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $q = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $q->execute([trim($_POST['username'] ?? '')]);
    $u = $q->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);   // prevents session fixation
        $_SESSION['user'] = $u['username'];
        $_SESSION['role'] = $u['role'];
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Staff Login</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:380px;margin-top:12vh">
  <div class="card p-4">
    <h4 class="mb-3">ADMIN or STAFF Login Here!</h4>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="d-grid gap-2">
      <input class="form-control" name="username" placeholder="Username" required>
      <input class="form-control" type="password" name="password" placeholder="Password" required>
      <button class="btn btn-primary">Log in</button>
      <a href="index.php" class="btn btn-link">Back to availability</a>
    </form>
  </div>
</div>
</body>
</html>
