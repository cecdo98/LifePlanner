<?php
  session_start();
  include_once "../../config/bd.php";
  include_once "../../config/security.php";
  include_once "../../config/repository.php";

  require_login("../../index.php");

  $user_id = $_SESSION['user_id'];
  $success = '';
  $error   = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
      verify_csrf_token();
      $action = $_POST['action'];

      // --- Alterar password ---
      if ($action === 'change_password') {
          $current = $_POST['current_password'] ?? '';
          $new     = $_POST['new_password'] ?? '';
          $confirm = $_POST['confirm_password'] ?? '';
          $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
          $stmt->bind_param("i", $user_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          if (!$row || !password_verify($current, $row['password_hash'])) {
              $error = 'A password atual está incorreta.';
          } elseif (strlen($new) < 6) {
              $error = 'A nova password deve ter pelo menos 6 caracteres.';
          } elseif ($new !== $confirm) {
              $error = 'As passwords não coincidem.';
          } else {
              $hash = password_hash($new, PASSWORD_DEFAULT);
              $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
              $stmt->bind_param("si", $hash, $user_id);
              $stmt->execute();
              $success = 'Password alterada com sucesso.';
          }
      }

      // --- Adicionar categoria ---
      if ($action === 'add_category') {
          $name = trim($_POST['category_name'] ?? '');
          if ($name === '') { $error = 'O nome da categoria não pode estar vazio.'; }
          elseif (strlen($name) > 60) { $error = 'Máximo 60 caracteres.'; }
          else {
              $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
              $stmt->bind_param("s", $name);
              $stmt->execute();
              if ($stmt->get_result()->fetch_assoc()) {
                  $error = 'Já existe uma categoria com esse nome.';
              } else {
                  $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                  $stmt->bind_param("s", $name);
                  $stmt->execute();
                  $success = flash_message('category_added');
              }
          }
      }

      // --- Renomear categoria ---
      if ($action === 'rename_category') {
          $cat_id = intval($_POST['cat_id'] ?? 0);
          $name   = trim($_POST['category_name'] ?? '');
          if ($cat_id <= 0 || !category_exists($conn, $cat_id)) { $error = 'Categoria inválida.'; }
          elseif ($name === '') { $error = 'O nome não pode estar vazio.'; }
          elseif (strlen($name) > 60) { $error = 'Máximo 60 caracteres.'; }
          else {
              $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id <> ?");
              $stmt->bind_param("si", $name, $cat_id);
              $stmt->execute();
              if ($stmt->get_result()->fetch_assoc()) { $error = 'Já existe uma categoria com esse nome.'; }
              else {
                  $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                  $stmt->bind_param("si", $name, $cat_id);
                  $stmt->execute();
                  $success = flash_message('category_renamed');
              }
          }
      }

      // --- Remover categoria ---
      if ($action === 'delete_category') {
          $cat_id     = intval($_POST['cat_id'] ?? 0);
          $move_to_id = intval($_POST['move_to_id'] ?? 0);
          if ($cat_id === $move_to_id) { $error = 'Escolhe uma categoria diferente.'; }
          elseif ($cat_id <= 0 || $move_to_id <= 0 || !category_exists($conn, $cat_id) || !category_exists($conn, $move_to_id)) { $error = 'Categoria inválida.'; }
          else {
              $stmt = $conn->prepare("UPDATE transactions SET category_id = ? WHERE category_id = ? AND user_id = ?");
              $stmt->bind_param("iii", $move_to_id, $cat_id, $user_id);
              $stmt->execute();
              $stmt = $conn->prepare("SELECT id FROM transactions WHERE category_id = ? LIMIT 1");
              $stmt->bind_param("i", $cat_id);
              $stmt->execute();
              if ($stmt->get_result()->fetch_assoc()) {
                  $error = 'Despesas movidas, mas a categoria ainda é usada por outro utilizador.';
              } else {
                  $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                  $stmt->bind_param("i", $cat_id);
                  $stmt->execute();
                  $success = flash_message('category_deleted');
              }
          }
      }

      // --- Guardar orçamentos ---
      if ($action === 'save_budgets') {
          $budgetYear  = validate_year($_POST['year'] ?? null);
          $budgetMonth = validate_month($_POST['month'] ?? null);
          foreach (($_POST['budgets'] ?? []) as $catId => $val) {
              $catId  = (int)$catId;
              $budget = filter_var($val, FILTER_VALIDATE_FLOAT);
              if ($catId <= 0 || $budget === false || $budget < 0 || !category_exists($conn, $catId)) continue;
              save_monthly_budget($conn, $user_id, $catId, $budgetYear, $budgetMonth, $budget);
          }
          $success = flash_message('budget_saved');
      }

      // --- Metas de poupança: adicionar ---
      if ($action === 'add_goal') {
          $name    = trim($_POST['goal_name'] ?? '');
          $target  = filter_var($_POST['goal_target'] ?? 0, FILTER_VALIDATE_FLOAT);
          $current = filter_var($_POST['goal_current'] ?? 0, FILTER_VALIDATE_FLOAT);
          $deadline = trim($_POST['goal_deadline'] ?? '');
          $desc    = trim($_POST['goal_desc'] ?? '');
          if ($name === '') { $error = 'O nome da meta não pode estar vazio.'; }
          elseif ($target === false || $target <= 0) { $error = 'O valor objetivo deve ser positivo.'; }
          else {
              if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) $deadline = null;
              if ($current === false || $current < 0) $current = 0;
              save_savings_goal($conn, $user_id, $name, $target, $current, $deadline ?: null, $desc);
              $success = flash_message('goal_saved');
          }
      }

      // --- Metas de poupança: atualizar valor atual ---
      if ($action === 'update_goal') {
          $gid     = intval($_POST['goal_id'] ?? 0);
          $current = filter_var($_POST['goal_current'] ?? 0, FILTER_VALIDATE_FLOAT);
          if ($gid > 0 && $current !== false && $current >= 0) {
              $stmt = $conn->prepare("UPDATE savings_goals SET current_amount = ? WHERE id = ? AND user_id = ?");
              $stmt->bind_param("dii", $current, $gid, $user_id);
              $stmt->execute();
              $success = flash_message('goal_updated');
          }
      }

      // --- Metas de poupança: apagar ---
      if ($action === 'delete_goal') {
          $gid = intval($_POST['goal_id'] ?? 0);
          if ($gid > 0) {
              delete_savings_goal($conn, $user_id, $gid);
              $success = flash_message('goal_deleted');
          }
      }

      // --- Despesas recorrentes: adicionar ---
      if ($action === 'add_recurring') {
          $desc    = trim($_POST['rec_desc'] ?? '');
          $amount  = filter_var($_POST['rec_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
          $cat_id  = intval($_POST['rec_category'] ?? 0);
          $day     = max(1, min(28, intval($_POST['rec_day'] ?? 1)));
          $nif     = (int)validate_nif($_POST['rec_nif'] ?? '0', '0');
          $detail  = trim($_POST['rec_detail'] ?? '');
          if ($desc === '') { $error = 'A descrição não pode estar vazia.'; }
          elseif ($amount === false || $amount <= 0) { $error = 'O valor deve ser positivo.'; }
          elseif ($cat_id > 0 && !category_exists($conn, $cat_id)) { $error = 'Categoria inválida.'; }
          else {
              $catParam = $cat_id > 0 ? $cat_id : null;
              $stmt = $conn->prepare("INSERT INTO recurring_expenses (user_id, category_id, amount, description, detail, nif, day_of_month) VALUES (?,?,?,?,?,?,?)");
              $stmt->bind_param("iidssii", $user_id, $catParam, $amount, $desc, $detail, $nif, $day);
              $stmt->execute();
              $success = flash_message('recurring_saved');
          }
      }

      // --- Despesas recorrentes: apagar ---
      if ($action === 'delete_recurring') {
          $rid = intval($_POST['recurring_id'] ?? 0);
          if ($rid > 0) {
              $stmt = $conn->prepare("DELETE FROM recurring_expenses WHERE id = ? AND user_id = ?");
              $stmt->bind_param("ii", $rid, $user_id);
              $stmt->execute();
              $stmt2 = $conn->prepare("DELETE FROM recurring_applied WHERE recurring_id = ? AND user_id = ?");
              $stmt2->bind_param("ii", $rid, $user_id);
              $stmt2->execute();
              $success = flash_message('recurring_deleted');
          }
      }

      // --- Despesas recorrentes: toggle activo ---
      if ($action === 'toggle_recurring') {
          $rid    = intval($_POST['recurring_id'] ?? 0);
          $active = intval($_POST['active'] ?? 0);
          if ($rid > 0) {
              $stmt = $conn->prepare("UPDATE recurring_expenses SET active = ? WHERE id = ? AND user_id = ?");
              $stmt->bind_param("iii", $active, $rid, $user_id);
              $stmt->execute();
              $success = 'Estado actualizado.';
          }
      }

      // --- Etiquetas: adicionar ---
      if ($action === 'add_tag') {
          $name = trim($_POST['tag_name'] ?? '');
          if ($name === '' || strlen($name) > 40) { $error = 'Nome de etiqueta inválido.'; }
          else {
              $stmt = $conn->prepare("INSERT OR IGNORE INTO tags (user_id, name) VALUES (?,?)");
              $stmt->bind_param("is", $user_id, $name);
              $stmt->execute();
              $success = flash_message('tag_saved');
          }
      }

      // --- Etiquetas: apagar ---
      if ($action === 'delete_tag') {
          $tid = intval($_POST['tag_id'] ?? 0);
          if ($tid > 0) {
              $stmt = $conn->prepare("DELETE FROM transaction_tags WHERE tag_id = ?");
              $stmt->bind_param("i", $tid);
              $stmt->execute();
              $stmt2 = $conn->prepare("DELETE FROM tags WHERE id = ? AND user_id = ?");
              $stmt2->bind_param("ii", $tid, $user_id);
              $stmt2->execute();
              $success = flash_message('tag_deleted');
          }
      }
  }

  $all_categories = get_categories($conn);
  $budget_year    = validate_year($_GET['budget_year'] ?? date('Y'));
  $budget_month   = validate_month($_GET['budget_month'] ?? date('m'));
  $budget_rows    = get_monthly_budget_rows($conn, $user_id, $budget_year, $budget_month);

  $category_counts = [];
  $stmt_counts = $conn->prepare("SELECT category_id, COUNT(*) AS total FROM transactions WHERE user_id = ? GROUP BY category_id");
  $stmt_counts->bind_param("i", $user_id);
  $stmt_counts->execute();
  $res_counts = $stmt_counts->get_result();
  while ($row = $res_counts->fetch_assoc()) {
      $category_counts[(int)$row['category_id']] = (int)$row['total'];
  }

  $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

  $savings_goals    = get_savings_goals($conn, $user_id);
  $recurring_list   = get_recurring_expenses($conn, $user_id);
  $all_tags         = get_user_tags($conn, $user_id);
  $navLinks = build_nav_links($all_categories);
  $months = [1=>'Janeiro',2=>'Fevereiro',3=>'Marco',4=>'Abril',5=>'Maio',6=>'Junho',
             7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definições — LifePlanner</title>
    <link rel="stylesheet" href="./stylesSettings.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
    <script src="../../assets/global.js"></script>
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links" id="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="nav-controls">
    <ul class="nav-right">
      <li><a href="../settings/settings.php" class="active">Definições</a></li>
      <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
    </ul>
    <button id="theme-toggle" class="nav-icon-btn" title="Mudar tema"></button>
    <button id="nav-hamburger" class="nav-icon-btn" title="Menu" aria-expanded="false">
      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
    </button>
  </div>
</nav>

<div class="page">
  <h1 class="page-title">Definições</h1>
  <p class="page-subtitle">Gere a tua conta e preferências.</p>

  <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <!-- Conta -->
  <div class="card">
    <div class="card-title">Conta</div>
    <div class="user-row">
      <div class="user-avatar"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></div>
      <div>
        <div class="user-name"><?= e($user['username']) ?></div>
        <div class="user-label">Utilizador</div>
      </div>
    </div>
  </div>

  <!-- Password -->
  <div class="card">
    <div class="card-title">Alterar Password</div>
    <form method="post" action="">
      <input type="hidden" name="action" value="change_password">
      <?= csrf_field() ?>
      <div class="form-stack">
        <div class="form-field"><label>Password atual</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="form-field"><label>Nova password</label><input type="password" name="new_password" required autocomplete="new-password"></div>
        <div class="form-field"><label>Confirmar</label><input type="password" name="confirm_password" required autocomplete="new-password"></div>
        <button type="submit" class="btn">Guardar password</button>
      </div>
    </form>
  </div>

  <!-- Categorias -->
  <div class="card">
    <div class="card-title">Categorias</div>

    <p class="section-label">Adicionar categoria</p>
    <form method="post" action="" class="inline-form" autocomplete="off">
      <input type="hidden" name="action" value="add_category">
      <?= csrf_field() ?>
      <input type="text" name="category_name" placeholder="Nome da categoria" maxlength="60" required>
      <button type="submit" class="btn">Adicionar</button>
    </form>

    <?php if (!empty($all_categories)): ?>
    <p class="section-label" style="margin-top:18px;">Renomear categoria</p>
    <form method="post" action="" class="delete-cat-row" autocomplete="off">
      <input type="hidden" name="action" value="rename_category">
      <?= csrf_field() ?>
      <select name="cat_id" required>
        <option value="" disabled selected>Categoria</option>
        <?php foreach ($all_categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="category_name" placeholder="Novo nome" maxlength="60" required>
      <button type="submit" class="btn">Renomear</button>
    </form>

    <p class="section-label" style="margin-top:18px;">Remover categoria</p>
    <p class="section-hint">As despesas serão movidas para a categoria que escolheres.</p>
    <form method="post" action="" class="delete-cat-row">
      <input type="hidden" name="action" value="delete_category">
      <?= csrf_field() ?>
      <select name="cat_id" required>
        <option value="" disabled selected>Categoria a remover</option>
        <?php foreach ($all_categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= $category_counts[(int)$c['id']] ?? 0 ?>)</option><?php endforeach; ?>
      </select>
      <span class="arrow-label">→ mover para →</span>
      <select name="move_to_id" required>
        <option value="" disabled selected>Categoria destino</option>
        <?php foreach ($all_categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-remove" onclick="return confirm('Tens a certeza?')">Remover</button>
    </form>

    <div class="cat-list">
      <?php foreach ($all_categories as $c): ?><span class="cat-badge"><?= e($c['name']) ?></span><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Orçamentos -->
  <div class="card">
    <div class="card-title">Orçamentos Mensais</div>
    <form method="get" action="" class="delete-cat-row" style="margin-bottom:16px;">
      <select name="budget_year">
        <?php for ($y=2026;$y<=2070;$y++): ?><option value="<?=$y?>" <?=$y===$budget_year?'selected':''?>><?=$y?></option><?php endfor; ?>
      </select>
      <select name="budget_month">
        <?php foreach ($months as $n => $nm): ?><option value="<?=$n?>" <?=$n===$budget_month?'selected':''?>><?=e($nm)?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn">Ver</button>
    </form>
    <form method="post" action="">
      <input type="hidden" name="action" value="save_budgets">
      <input type="hidden" name="year" value="<?= $budget_year ?>">
      <input type="hidden" name="month" value="<?= $budget_month ?>">
      <?= csrf_field() ?>
      <div class="budget-grid">
        <?php foreach ($budget_rows as $row): ?>
        <label><?= e($row['name']) ?></label>
        <input type="number" step="0.01" min="0" name="budgets[<?= (int)$row['id'] ?>]" value="<?= e($row['budget']) ?>">
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn">Guardar orçamentos</button>
    </form>
  </div>

  <!-- Metas de Poupança -->
  <div class="card" id="goals">
    <div class="card-title">Metas de Poupança</div>

    <?php if (!empty($savings_goals)): ?>
    <div class="item-list" style="margin-bottom:20px;">
      <?php foreach ($savings_goals as $g):
        $gpct = $g['target_amount'] > 0 ? min(100, round(($g['current_amount'] / $g['target_amount']) * 100)) : 0;
        $gcolor = $gpct >= 100 ? 'var(--green)' : ($gpct >= 60 ? 'var(--accent)' : 'var(--orange)');
      ?>
      <div class="item-row" style="flex-direction:column;align-items:stretch;gap:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div class="item-info">
            <div class="item-name"><?= e($g['name']) ?></div>
            <?php if ($g['description']): ?><div class="item-meta"><?= e($g['description']) ?></div><?php endif; ?>
            <?php if ($g['deadline']): ?><div class="item-meta">Prazo: <?= date('d/m/Y', strtotime($g['deadline'])) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0;margin-left:12px;">
            <div style="font-weight:700;color:<?= $gcolor ?>"><?= $gpct ?>%</div>
            <div class="item-meta"><?= number_format((float)$g['current_amount'],2,',','.') ?> / <?= number_format((float)$g['target_amount'],2,',','.') ?> €</div>
          </div>
        </div>
        <div class="goal-bar"><div class="goal-fill" style="width:<?= $gpct ?>%;background:<?= $gcolor ?>"></div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <form method="post" action="" style="display:flex;gap:6px;align-items:center;">
            <input type="hidden" name="action" value="update_goal">
            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
            <?= csrf_field() ?>
            <input type="number" step="0.01" min="0" name="goal_current" value="<?= e($g['current_amount']) ?>" style="width:120px;padding:5px 8px;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:5px;font-size:0.82rem;outline:none;">
            <button type="submit" class="btn btn-sm">Atualizar</button>
          </form>
          <form method="post" action="">
            <input type="hidden" name="action" value="delete_goal">
            <input type="hidden" name="goal_id" value="<?= (int)$g['id'] ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Apagar esta meta?')" style="margin-top:0;">Apagar</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="section-label">Nova meta</p>
    <form method="post" action="">
      <input type="hidden" name="action" value="add_goal">
      <?= csrf_field() ?>
      <div class="form-stack">
        <div class="form-field"><label>Nome</label><input type="text" name="goal_name" required maxlength="80" placeholder="Ex: Férias de verão"></div>
        <div class="form-field"><label>Objetivo (€)</label><input type="number" name="goal_target" step="0.01" min="0.01" required></div>
        <div class="form-field"><label>Valor atual (€)</label><input type="number" name="goal_current" step="0.01" min="0" value="0"></div>
        <div class="form-field"><label>Prazo</label><input type="date" name="goal_deadline"></div>
        <div class="form-field"><label>Notas</label><input type="text" name="goal_desc" maxlength="200"></div>
        <button type="submit" class="btn">Adicionar meta</button>
      </div>
    </form>
  </div>

  <!-- Despesas Recorrentes -->
  <div class="card">
    <div class="card-title">Despesas Recorrentes</div>
    <p class="section-hint">Despesas fixas mensais. Aplica-as no dashboard para as inserir automaticamente.</p>

    <?php if (!empty($recurring_list)): ?>
    <div class="item-list" style="margin-bottom:20px;">
      <?php foreach ($recurring_list as $r): ?>
      <div class="item-row">
        <div class="item-info">
          <div class="item-name">
            <?= e($r['description']) ?>
            <?php if (!$r['active']): ?><span style="font-size:0.72rem;color:var(--muted);margin-left:6px;">(inativo)</span><?php endif; ?>
          </div>
          <div class="item-meta">Dia <?= (int)$r['day_of_month'] ?> · <?= e($r['category_name'] ?? 'Sem categoria') ?><?= $r['nif'] ? ' · NIF' : '' ?></div>
        </div>
        <div class="item-amount"><?= number_format((float)$r['amount'], 2, ',', '.') ?> €</div>
        <div class="item-actions">
          <form method="post" action="" style="display:inline;">
            <input type="hidden" name="action" value="toggle_recurring">
            <input type="hidden" name="recurring_id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="active" value="<?= $r['active'] ? 0 : 1 ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-ghost" style="margin-top:0;"><?= $r['active'] ? 'Pausar' : 'Ativar' ?></button>
          </form>
          <form method="post" action="" style="display:inline;">
            <input type="hidden" name="action" value="delete_recurring">
            <input type="hidden" name="recurring_id" value="<?= (int)$r['id'] ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Apagar despesa recorrente?')" style="margin-top:0;">Apagar</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="section-label">Nova despesa recorrente</p>
    <form method="post" action="">
      <input type="hidden" name="action" value="add_recurring">
      <?= csrf_field() ?>
      <div class="form-stack">
        <div class="form-field"><label>Descrição</label><input type="text" name="rec_desc" required maxlength="255" placeholder="Ex: Renda"></div>
        <div class="form-field"><label>Valor (€)</label><input type="number" name="rec_amount" step="0.01" min="0.01" required></div>
        <div class="form-field">
          <label>Categoria</label>
          <select name="rec_category">
            <option value="">Sem categoria</option>
            <?php foreach ($all_categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-field"><label>Dia do mês</label><input type="number" name="rec_day" min="1" max="28" value="1"></div>
        <div class="form-field">
          <label>NIF</label>
          <div style="display:flex;gap:12px;padding-top:4px;">
            <label style="font-size:0.85rem;font-weight:400;color:var(--text);cursor:pointer;"><input type="radio" name="rec_nif" value="0" checked style="accent-color:var(--accent);"> Não</label>
            <label style="font-size:0.85rem;font-weight:400;color:var(--text);cursor:pointer;"><input type="radio" name="rec_nif" value="1" style="accent-color:var(--accent);"> Sim</label>
          </div>
        </div>
        <button type="submit" class="btn">Adicionar recorrente</button>
      </div>
    </form>
  </div>

  <!-- Etiquetas -->
  <div class="card">
    <div class="card-title">Etiquetas</div>

    <?php if (!empty($all_tags)): ?>
    <div class="cat-list" style="margin-bottom:16px;">
      <?php foreach ($all_tags as $tag): ?>
      <span style="display:inline-flex;align-items:center;gap:5px;" class="cat-badge">
        <?= e($tag['name']) ?>
        <form method="post" action="" style="display:inline;margin:0;">
          <input type="hidden" name="action" value="delete_tag">
          <input type="hidden" name="tag_id" value="<?= (int)$tag['id'] ?>">
          <?= csrf_field() ?>
          <button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;font-size:0.75rem;padding:0;line-height:1;" title="Apagar" onclick="return confirm('Apagar etiqueta?')">×</button>
        </form>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="" class="inline-form">
      <input type="hidden" name="action" value="add_tag">
      <?= csrf_field() ?>
      <input type="text" name="tag_name" placeholder="Nova etiqueta" maxlength="40" required>
      <button type="submit" class="btn">Adicionar</button>
    </form>
  </div>

  <!-- Import/Export -->
  <div class="card">
    <div class="card-title">Exportar / Importar Dados</div>
    <p style="font-size:0.84rem;color:var(--muted);margin-bottom:14px;">Exporta os teus dados para JSON ou importa de volta.</p>
    <div class="export-import-row">
      <a href="./import.php" class="btn btn-ghost">Gerir dados (Import/Export)</a>
    </div>
  </div>

  <!-- Zona de perigo -->
  <div class="card">
    <div class="card-title">Zona de Perigo</div>
    <p style="font-size:0.84rem;color:var(--muted);margin-bottom:14px;">Terminar sessão remove o acesso imediatamente.</p>
    <a href="../../config/logout.php" class="btn btn-danger">Terminar sessão</a>
  </div>
</div>
</body>
</html>
