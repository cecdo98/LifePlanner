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

      // --- ADICIONAR CATEGORIA ---
      if ($_POST['action'] === 'add_category') {
          $name = trim($_POST['category_name'] ?? '');
          if ($name === '') {
              $error = 'O nome da categoria não pode estar vazio.';
          } else {
              $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
              $stmt->bind_param("s", $name);
              $stmt->execute();
              $exists = $stmt->get_result()->fetch_assoc();
              if ($exists) {
                  $error = 'Já existe uma categoria com esse nome.';
              } else {
                  $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                  $stmt->bind_param("s", $name);
                  $stmt->execute();
                  $success = "Categoria \"$name\" adicionada com sucesso.";
              }
          }
      }

      // --- REMOVER CATEGORIA ---
      if ($_POST['action'] === 'delete_category') {
          $cat_id     = intval($_POST['cat_id'] ?? 0);
          $move_to_id = intval($_POST['move_to_id'] ?? 0);

          if ($cat_id === $move_to_id) {
              $error = 'Tens de escolher uma categoria diferente para mover as despesas.';
          } elseif ($cat_id <= 0 || $move_to_id <= 0) {
              $error = 'Categoria inválida.';
          } else {
              $stmt = $conn->prepare("UPDATE transactions SET category_id = ? WHERE category_id = ?");
              $stmt->bind_param("ii", $move_to_id, $cat_id);
              $stmt->execute();
              $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
              $stmt->bind_param("i", $cat_id);
              $stmt->execute();
              $success = 'Categoria removida e despesas movidas com sucesso.';
          }
      }
  }

  // --- BUSCAR TODAS AS CATEGORIAS ---
  $stmt_cats = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
  $stmt_cats->execute();
  $all_categories = [];
  $res_cats = $stmt_cats->get_result();
  while ($c = $res_cats->fetch_assoc()) {
      $all_categories[] = $c;
  }

  // --- BUSCAR DADOS DO UTILIZADOR ---
  $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

  // --- NAVLINKS DINÂMICOS ---
  $navLinks = [["../dashboard/dashboard.php", "Inicio"]];
  foreach ($all_categories as $c) {
      $navLinks[] = ["../options/option.php?cat=" . $c['id'], htmlspecialchars($c['name'])];
  }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definições — LifePlanner</title>
    <link rel="stylesheet" href="./stylesSettings.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
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
    <li><a href="../settings/settings.php" class="active">Definições</a></li>
    <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
  </ul>
</nav>

<div class="page">

  <h1 class="page-title">Definições</h1>
  <p class="page-subtitle">Gere a tua conta e preferências.</p>

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

  <!-- Categorias -->
  <div class="card">
    <div class="card-title">Categorias</div>

    <!-- Adicionar categoria -->
    <p class="section-label">Adicionar nova categoria</p>
    <form method="post" action="" class="inline-form">
      <input type="hidden" name="action" value="add_category">
      <input type="text" name="category_name" placeholder="Nome da categoria" maxlength="60" required>
      <button type="submit" class="btn">Adicionar</button>
    </form>

    <!-- Lista de categorias existentes -->
    <?php if (!empty($all_categories)): ?>
    <p class="section-label" style="margin-top:20px;">Remover categoria</p>
    <p class="section-hint">As despesas da categoria removida serão movidas para a categoria que escolheres.</p>
    <form method="post" action="" class="delete-cat-form">
      <input type="hidden" name="action" value="delete_category">
      <div class="delete-cat-row">
        <select name="cat_id" required>
          <option value="" disabled selected>Categoria a remover</option>
          <?php foreach ($all_categories as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="arrow-label">→ mover para →</span>
        <select name="move_to_id" required>
          <option value="" disabled selected>Categoria destino</option>
          <?php foreach ($all_categories as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-remove"
          onclick="return confirm('Tens a certeza? Esta ação não pode ser desfeita.')">
          Remover
        </button>
      </div>
    </form>

    <!-- Lista atual -->
    <div class="cat-list">
      <?php foreach ($all_categories as $c): ?>
      <span class="cat-badge"><?= htmlspecialchars($c['name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Exportar/Importar Dados -->
  <div class="card">
    <div class="card-title">Exportar/Importar Dados</div>
    <p style="font-size:0.84rem; color:var(--muted); margin-bottom:14px;">
      Exporta os teus dados para um ficheiro JSON ou importa de volta.
    </p>
    <div class="export-import-row">
      <a href="./import.php" class="btn btn-ghost">Gerir dados (Import/Export)</a>
    </div>
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