<?php
session_start();
include_once "../../config/bd.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit();
}

$user_id  = $_SESSION['user_id'];
$success  = '';
$error    = '';

// --- ALTERAR PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current, $row['password_hash'])) {
            $error = 'A password atual esta incorreta.';
        } elseif (strlen($new) < 6) {
            $error = 'A nova password deve ter pelo menos 6 caracteres.';
        } elseif ($new !== $confirm) {
            $error = 'As passwords nao coincidem.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $user_id);
            $stmt->execute();
            $success = 'Password alterada com sucesso.';
        }
    }
}

// --- BUSCAR DADOS DO UTILIZADOR ---
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$navLinks = [
    ["../dashboard/dashboard.php", "Inicio"],
    ["../options/option.php?cat=1", "Carro"],
    ["../options/option.php?cat=2", "Ginasio"],
    ["../options/option.php?cat=3", "Entretenimento"],
    ["../options/option.php?cat=4", "Saude"],
    ["../options/option.php?cat=5", "Educacao"],
    ["../options/option.php?cat=6", "Outros"],
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definicoes — LifePlanner</title>
    <link rel="stylesheet" href="./stylesSettings.css">
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= $href ?>"><?= $label ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php" class="active">Definicoes</a></li>
    <li><a href="../../config/logout.php">Sair</a></li>
  </ul>
</nav>

<div class="page">

  <h1 class="page-title">Definicoes</h1>
  <p class="page-subtitle">Gere a tua conta e preferencias.</p>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php elseif ($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Conta -->
  <div class="card">
    <div class="card-title">Conta</div>
    <div class="user-row">
      <div class="user-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="user-label">Utilizador</div>
      </div>
    </div>
  </div>

  <!-- Alterar Password -->
  <div class="card">
    <div class="card-title">Alterar Password</div>
    <form method="post" action="">
      <input type="hidden" name="action" value="change_password">
      <div class="form-stack">
        <div class="form-field">
          <label for="current_password">Password atual</label>
          <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-field">
          <label for="new_password">Nova password</label>
          <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
        </div>
        <div class="form-field">
          <label for="confirm_password">Confirmar nova password</label>
          <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn">Guardar password</button>
      </div>
    </form>
  </div>

  <!-- Zona de perigo -->
  <div class="card">
    <div class="card-title">Zona de Perigo</div>
    <p style="font-size:0.84rem; color:var(--muted); margin-bottom:14px;">
      Terminar sessao remove o teu acesso imediatamente.
    </p>
    <a href="../../config/logout.php" class="btn btn-danger">Terminar sessao</a>
  </div>

</div><!-- /page -->
</body>
</html>