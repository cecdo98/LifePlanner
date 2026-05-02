<?php
  session_start();
  include_once "./config/bd.php";

  $usernameErr = $passwordErr = $loginErr = "";
  $username = "";

  if ($_SERVER["REQUEST_METHOD"] === "POST") {

      if (empty($_POST["username"])) {
          $usernameErr = "Username obrigatorio.";
      } else {
          $username = trim($_POST["username"]);
          if (!preg_match("/^[a-zA-Z0-9_\-' ]*$/", $username)) {
              $usernameErr = "Apenas letras, numeros e espacos.";
          }
      }

      if (empty($_POST["password"])) {
          $passwordErr = "Password obrigatoria.";
      }

      if (!$usernameErr && !$passwordErr) {
          $loginErr = login($conn, $username, $_POST["password"]);
      }
  }

  function login($conn, $username, $password) {
      $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
      $stmt->bind_param("s", $username);
      $stmt->execute();
      $result = $stmt->get_result();

      if ($result->num_rows > 0) {
          $user = $result->fetch_assoc();
          if (password_verify($password, $user['password_hash'])) {
              $_SESSION['user_id']  = $user['id'];
              $_SESSION['username'] = $user['username'];
              header('Location: ./main/dashboard/dashboard.php');
              exit();
          }
          return "Password incorreta.";
      }
      return "Utilizador nao encontrado.";
  }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LifePlanner — Login</title>
  <link rel="stylesheet" href="./stylesLogin.css">
  <link rel="icon" type="image/x-icon" href="./assets/favicon.ico">
</head>
<body>

<div class="login-wrap">
  <div class="brand">
    <div class="brand-name">LifePlanner</div>
    <div class="brand-sub">Gestao financeira pessoal</div>
  </div>

  <div class="card">
    <div class="card-title">Iniciar sessao</div>

    <form method="post" action="<?= htmlspecialchars($_SERVER["PHP_SELF"]) ?>" autocomplete="off">
      <div class="form-stack">

        <?php if ($loginErr): ?>
        <div class="alert-error"><?= htmlspecialchars($loginErr) ?></div>
        <?php endif; ?>

        <div class="form-field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username"
                 value="<?= htmlspecialchars($username) ?>"
                 class="<?= $usernameErr ? 'has-error' : '' ?>"
                 autocomplete="username">
          <?php if ($usernameErr): ?>
          <span class="field-error"><?= htmlspecialchars($usernameErr) ?></span>
          <?php endif; ?>
        </div>

        <div class="form-field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password"
                 class="<?= $passwordErr ? 'has-error' : '' ?>"
                 autocomplete="current-password">
          <?php if ($passwordErr): ?>
          <span class="field-error"><?= htmlspecialchars($passwordErr) ?></span>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn">Entrar</button>

      </div>
    </form>
  </div>
</div>

</body>
</html>