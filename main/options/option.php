<?php
  session_start();
  include_once "../../config/bd.php";
  include_once "../../config/security.php";
  include_once "../../config/repository.php";

  require_login("../../index.php");

  $category_id = isset($_GET['cat']) ? max(1, intval($_GET['cat'])) : 1;
  $year        = validate_year($_GET['year'] ?? null);
  $user_id     = $_SESSION['user_id'];
  $search      = trim($_GET['q'] ?? '');
  $success     = flash_message($_GET['msg'] ?? '');

  // POST: inserir / editar / apagar
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
      verify_csrf_token();

      if (($_POST['action'] ?? '') === 'delete_transaction') {
          $id_to_delete = intval($_POST['delete_id'] ?? 0);
          if ($id_to_delete > 0) {
              // Apagar tags associadas
              $dt = $conn->prepare("DELETE FROM transaction_tags WHERE transaction_id = ?");
              $dt->bind_param("i", $id_to_delete);
              $dt->execute();
              $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
              $stmt->bind_param("ii", $id_to_delete, $user_id);
              $stmt->execute();
          }
          header("Location: option.php?cat=" . $category_id . "&year=" . $year . "&msg=expense_deleted");
          exit();
      }

      $category_id = isset($_POST['category_id']) ? max(1, intval($_POST['category_id'])) : 1;
      $amount      = filter_var($_POST["amount"] ?? null, FILTER_VALIDATE_FLOAT);
      $date        = $_POST["date"] ?? '';
      $description = trim($_POST["description"] ?? '');
      $detail      = trim($_POST["detail"] ?? '');
      $nif         = (int)validate_nif($_POST["nif"] ?? '0', '0');
      $cat_to_save = $category_id;
      $tagIds      = array_map('intval', (array)($_POST['tags'] ?? []));

      if ($amount === false || $amount < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
          || $description === '' || strlen($description) > 255 || strlen($detail) > 1000
          || !category_exists($conn, $category_id)) {
          header("Location: option.php?cat=" . $category_id . "&year=" . $year);
          exit();
      }

      if (!empty($_POST['edit_id'])) {
          $edit_id = intval($_POST['edit_id']);
          $stmt = $conn->prepare("UPDATE transactions SET category_id=?, amount=?, date=?, description=?, detail=?, nif=? WHERE id=? AND user_id=?");
          $stmt->bind_param("idsssiii", $category_id, $amount, $date, $description, $detail, $nif, $edit_id, $user_id);
          $stmt->execute();
          sync_transaction_tags($conn, $edit_id, $tagIds);
      } else {
          $stmt = $conn->prepare("INSERT INTO transactions (user_id, category_id, amount, date, description, detail, nif) VALUES (?, ?, ?, ?, ?, ?, ?)");
          $stmt->bind_param("iidsssi", $user_id, $cat_to_save, $amount, $date, $description, $detail, $nif);
          $stmt->execute();
          // Obter ID inserido
          $lastId = $conn->prepare("SELECT last_insert_rowid() AS id");
          $lastId->execute();
          $newId = (int)($lastId->get_result()->fetch_assoc()['id'] ?? 0);
          if ($newId > 0) sync_transaction_tags($conn, $newId, $tagIds);
      }
      header("Location: option.php?cat=" . $cat_to_save . "&year=" . $year . "&msg=expense_saved");
      exit();
  }

  // Buscar dados para editar
  $edit_data = null;
  $edit_tags = [];
  if (isset($_GET['edit_id'])) {
      $id_to_fetch = intval($_GET['edit_id']);
      $stmt_edit = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
      $stmt_edit->bind_param("ii", $id_to_fetch, $user_id);
      $stmt_edit->execute();
      $edit_data = $stmt_edit->get_result()->fetch_assoc();
      if ($edit_data) {
          $et = get_transaction_tags($conn, $id_to_fetch);
          $edit_tags = array_column($et, 'id');
      }
  }

  $all_categories = get_categories($conn);
  $all_tags       = get_user_tags($conn, $user_id);

  // Nome da categoria
  $stmt_cat = $conn->prepare("SELECT name FROM categories WHERE id = ?");
  $stmt_cat->bind_param("i", $category_id);
  $stmt_cat->execute();
  $nome_categoria = $stmt_cat->get_result()->fetch_assoc()['name'] ?? "Desconhecida";

  // Totais
  $stmt_total = $conn->prepare("SELECT SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE category_id = ? AND user_id = ?");
  $stmt_total->bind_param("ii", $category_id, $user_id);
  $stmt_total->execute();
  $totals = $stmt_total->get_result()->fetch_assoc();
  $totalCategoria = (float)($totals['total'] ?? 0);
  $numRegistos    = (int)($totals['cnt'] ?? 0);

  // Dados mensais para gráfico
  $stmtMensal = $conn->prepare("
      SELECT MONTH(date) AS mes, SUM(amount) AS total
      FROM transactions
      WHERE category_id = ? AND user_id = ? AND YEAR(date) = ?
      GROUP BY MONTH(date) ORDER BY mes ASC
  ");
  $stmtMensal->bind_param("iii", $category_id, $user_id, $year);
  $stmtMensal->execute();
  $mensalMap = [];
  $resMensal = $stmtMensal->get_result();
  while ($r = $resMensal->fetch_assoc()) {
      $mensalMap[(int)$r['mes']] = (float)$r['total'];
  }

  $mesesAbrev = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
                 7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
  $chartLabels = [];
  $chartTotals = [];
  $chartDeltas = [];
  for ($m = 1; $m <= 12; $m++) {
      $chartLabels[] = $mesesAbrev[$m];
      $chartTotals[] = $mensalMap[$m] ?? 0;
  }
  for ($i = 0; $i < 12; $i++) {
      $prev = $i > 0 ? $chartTotals[$i - 1] : null;
      $curr = $chartTotals[$i];
      if ($prev === null || $prev == 0) { $chartDeltas[] = null; }
      else { $chartDeltas[] = round((($curr - $prev) / $prev) * 100, 1); }
  }
  $jsColors = json_encode(array_map(function($d) {
      return ($d === null || $d <= 0) ? 'rgba(22,163,74,0.75)' : 'rgba(220,38,38,0.75)';
  }, $chartDeltas));
  $jsBorderColors = json_encode(array_map(function($d) {
      return ($d === null || $d <= 0) ? '#16a34a' : '#dc2626';
  }, $chartDeltas));

  $navLinks = build_nav_links($all_categories);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($nome_categoria) ?> — LifePlanner</title>
  <link rel="stylesheet" href="./stylesOption.css">
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <script src="../../assets/global.js"></script>
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links" id="nav-links">
    <?php foreach (array_slice($navLinks, 0, 3) as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
    <?php $catLinks = array_slice($navLinks, 3); if (!empty($catLinks)): ?>
    <li class="nav-dropdown">
      <button class="nav-dropdown-btn has-active" onclick="toggleNavDropdown(this)">Categorias ▾</button>
      <ul class="nav-dropdown-menu">
        <?php foreach ($catLinks as [$href, $label]):
          $catNum = (int)filter_var($href, FILTER_SANITIZE_NUMBER_INT);
          $isActive = strpos($href, 'cat=') !== false && $catNum === $category_id;
        ?>
        <li><a href="<?= e($href) ?>" <?= $isActive ? 'class="active"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </li>
    <?php endif; ?>
  </ul>
  <div class="nav-controls">
    <ul class="nav-right">
      <li><a href="../settings/settings.php">Definições</a></li>
      <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
    </ul>
    <button id="theme-toggle" class="nav-icon-btn" title="Mudar tema"></button>
    <button id="nav-hamburger" class="nav-icon-btn" title="Menu" aria-expanded="false">
      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
    </button>
  </div>
</nav>

<div class="page">
  <h1 class="page-title"><?= e($nome_categoria) ?></h1>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card red">
      <div class="kpi-label">Total Gasto</div>
      <div class="kpi-value"><?= number_format($totalCategoria, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">Registos</div>
      <div class="kpi-value"><?= $numRegistos ?></div>
    </div>
  </div>

  <!-- Formulario -->
  <div class="card">
    <div class="card-title"><?= $edit_data ? 'Editar Despesa' : 'Nova Despesa' ?></div>
    <form action="option.php?cat=<?= $category_id ?>&year=<?= $year ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="category_id" value="<?= $category_id ?>">
      <input type="hidden" name="edit_id" value="<?= $edit_data['id'] ?? '' ?>">

      <div class="form-grid">
        <label>Categoria</label>
        <select name="category_id" required>
          <?php foreach ($all_categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= ($category_id == $cat['id']) ? 'selected' : '' ?>>
            <?= e($cat['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>

        <label>Valor (€)</label>
        <input type="number" name="amount" step="0.01" min="0" value="<?= e($edit_data['amount'] ?? '') ?>" required>

        <label>Data</label>
        <input type="date" name="date" value="<?= e($edit_data['date'] ?? date('Y-m-d')) ?>" required>

        <label>Descrição</label>
        <textarea name="description" required><?= e($edit_data['description'] ?? '') ?></textarea>

        <label>Detalhes</label>
        <textarea name="detail"><?= e($edit_data['detail'] ?? '') ?></textarea>

        <label>NIF</label>
        <div class="radio-group">
          <label><input type="radio" name="nif" value="0" <?= (!$edit_data || (isset($edit_data['nif']) && $edit_data['nif'] == 0)) ? 'checked' : '' ?>> Não</label>
          <label><input type="radio" name="nif" value="1" <?= (isset($edit_data['nif']) && $edit_data['nif'] == 1) ? 'checked' : '' ?>> Sim</label>
        </div>

        <?php if (!empty($all_tags)): ?>
        <label>Etiquetas</label>
        <div class="tags-checkboxes">
          <?php foreach ($all_tags as $tag):
            $checked = in_array((int)$tag['id'], $edit_tags);
          ?>
          <label class="tag-check-label <?= $checked ? 'checked' : '' ?>">
            <input type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>" <?= $checked ? 'checked' : '' ?> onchange="this.closest('label').classList.toggle('checked', this.checked)">
            <?= e($tag['name']) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div></div>
        <div class="form-actions">
          <button type="submit" class="btn"><?= $edit_data ? 'Atualizar' : 'Adicionar' ?></button>
          <?php if ($edit_data): ?>
          <a href="option.php?cat=<?= $category_id ?>&year=<?= $year ?>" class="btn-ghost">Cancelar</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>

  <!-- Gráfico mensal -->
  <div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;">
      <span>Gasto Mensal — <?= $year ?></span>
      <form method="get" action="" style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="hidden" name="cat" value="<?= $category_id ?>">
        <select name="year" onchange="this.form.submit()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:5px;font-family:var(--font);font-size:0.8rem;outline:none;cursor:pointer;">
          <?php for ($y = (int)date('Y'); $y >= 2026; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>
    <div style="position:relative;height:220px;">
      <canvas id="monthlyChart"></canvas>
    </div>
  </div>

  <!-- Tabela -->
  <div class="card">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;">
      <span>Histórico — <?= $year ?></span>
      <form method="get" action="" style="display:flex;gap:8px;align-items:center;margin:0;">
        <input type="hidden" name="cat" value="<?= $category_id ?>">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Pesquisar" class="table-search">
        <select name="year" onchange="this.form.submit()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:5px;font-family:var(--font);font-size:0.8rem;outline:none;cursor:pointer;">
          <?php for ($y = (int)date('Y'); $y >= 2026; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>
    <?php
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $conn->prepare("SELECT id, amount, date, description, detail, nif FROM transactions WHERE category_id = ? AND user_id = ? AND YEAR(date) = ? AND (description LIKE ? OR detail LIKE ? OR date LIKE ? OR CAST(amount AS TEXT) LIKE ?) ORDER BY date DESC");
        $stmt->bind_param("iiissss", $category_id, $user_id, $year, $like, $like, $like, $like);
    } else {
        $stmt = $conn->prepare("SELECT id, amount, date, description, detail, nif FROM transactions WHERE category_id = ? AND user_id = ? AND YEAR(date) = ? ORDER BY date DESC");
        $stmt->bind_param("iii", $category_id, $user_id, $year);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $optRows = $result->fetch_all(MYSQLI_ASSOC);
    $tagsByTxn = get_tags_for_transactions($conn, array_column($optRows, 'id'));
    ?>
    <?php if (empty($optRows)): ?>
      <p class="text-muted">Sem registos para <?= $year ?>.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Valor</th>
          <th>Data</th>
          <th>Descrição</th>
          <th>Detalhes</th>
          <th>NIF</th>
          <th>Etiquetas</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($optRows as $row):
          $rowTags = $tagsByTxn[(int)$row['id']] ?? [];
        ?>
        <tr>
          <td class="amount"><?= number_format($row['amount'], 2, ',', '.') ?> €</td>
          <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
          <td><?= e($row['description']) ?></td>
          <td><span class="detail-text"><?= e($row['detail']) ?></span></td>
          <td>
            <?php if ((int)$row['nif'] === 1): ?>
              <span class="nif-badge nif-sim">Sim</span>
            <?php else: ?>
              <span class="nif-badge nif-nao">Não</span>
            <?php endif; ?>
          </td>
          <td>
            <?php foreach ($rowTags as $rt): ?>
            <span class="tag-pill"><?= e($rt['name']) ?></span>
            <?php endforeach; ?>
          </td>
          <td style="white-space:nowrap">
            <a class="action-link action-edit" href="option.php?cat=<?= $category_id ?>&edit_id=<?= $row['id'] ?>&year=<?= $year ?>">Editar</a>
            <form method="post" action="option.php?cat=<?= $category_id ?>&year=<?= $year ?>" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_transaction">
              <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
              <button type="submit" class="action-link action-delete" onclick="return confirm('Apagar este registo?')">Apagar</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  window.__LP_CHARTS = [];
  const isDark   = () => document.documentElement.getAttribute('data-theme') === 'dark';
  const labels   = <?= json_encode($chartLabels) ?>;
  const totals   = <?= json_encode($chartTotals) ?>;
  const deltas   = <?= json_encode($chartDeltas) ?>;
  const barColors   = <?= $jsColors ?>;
  const borderColors= <?= $jsBorderColors ?>;

  Chart.defaults.color = isDark() ? '#8892a4' : '#6b7280';
  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
  Chart.defaults.font.size = 11;

  window.__LP_CHARTS.push(new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: 'Total Gasto', data: totals, backgroundColor: barColors, borderColor: borderColors, borderWidth: 1, borderRadius: 4 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => {
          const v = ctx.parsed.y;
          const d = deltas[ctx.dataIndex];
          const s = ' ' + v.toLocaleString('pt-PT', {minimumFractionDigits:2}) + ' €';
          if (d === null) return s;
          return [s, ` vs mês anterior: ${d > 0 ? '+' : ''}${d}%`];
        }}}
      },
      scales: {
        x: { grid: { color: isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)' }, ticks: { color: isDark() ? '#8892a4' : '#6b7280' } },
        y: { grid: { color: isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)' }, ticks: { color: isDark() ? '#8892a4' : '#6b7280', callback: v => '€' + v.toLocaleString('pt-PT') }, beginAtZero: true }
      }
    },
    plugins: [{
      id: 'deltaLabels',
      afterDatasetsDraw(chart) {
        const { ctx } = chart;
        const meta = chart.getDatasetMeta(0);
        meta.data.forEach((bar, i) => {
          const d = deltas[i];
          if (d === null) return;
          const color = d > 0 ? '#dc2626' : '#16a34a';
          ctx.save();
          ctx.font = 'bold 10px ' + Chart.defaults.font.family;
          ctx.fillStyle = color;
          ctx.textAlign = 'center';
          ctx.textBaseline = 'bottom';
          ctx.fillText((d > 0 ? '+' : '') + d + '%', bar.x, bar.y - 3);
          ctx.restore();
        });
      }
    }]
  }));
</script>
</body>
</html>
