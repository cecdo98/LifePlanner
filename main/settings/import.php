<?php
    include_once "../../config/bootstrap.php";
    include_once "../../config/bd.php";
    include_once "../../config/security.php";
    include_once "../../config/repository.php";

    require_login("../../index.php");

    $user_id = $_SESSION['user_id'];
    $success = '';
    $error   = '';
    $stats   = null;

    // --- PROCESSAR UPLOAD ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
        verify_csrf_token();
        $file = $_FILES['backup_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Erro ao fazer upload do ficheiro.';

        } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'json') {
            $error = 'O ficheiro deve ser um .json válido.';

        } else {
            $content = file_get_contents($file['tmp_name']);
            $data    = json_decode($content, true);

            if (!$data || !isset($data['categories'], $data['transactions'], $data['monthly_summary'])
                || !is_array($data['categories']) || !is_array($data['transactions']) || !is_array($data['monthly_summary'])) {
                $error = 'Ficheiro JSON inválido ou estrutura não reconhecida.';

            } else {
                $mode = $_POST['mode'] ?? 'merge'; // 'merge' ou 'replace'
                if (!in_array($mode, ['merge', 'replace'], true)) {
                    $mode = 'merge';
                }

                // ── MODO REPLACE: apagar dados existentes do utilizador ──
                if ($mode === 'replace') {
                    $stmt = $conn->prepare("DELETE FROM monthly_summary WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    $stmt = $conn->prepare("DELETE FROM transactions WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    $stmt = $conn->prepare("DELETE FROM category_budgets WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();

                    // Apagar categorias que não têm transações de outros utilizadores
                    $stmt = $conn->prepare("
                        DELETE FROM categories
                        WHERE id NOT IN (SELECT DISTINCT category_id FROM transactions WHERE category_id IS NOT NULL)
                    ");
                    $stmt->execute();
                }

                // ── IMPORTAR CATEGORIAS ──
                // Mapa: id_antigo -> id_novo (para corrigir foreign keys)
                $catMap = [];
                $catsImported = 0;

                foreach ($data['categories'] as $cat) {
                    if (!isset($cat['id'], $cat['name'])) {
                        continue;
                    }
                    $name = trim($cat['name']);
                    if ($name === '' || strlen($name) > 60) {
                        continue;
                    }

                    // Verificar se já existe categoria com esse nome
                    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                    $stmt->bind_param("s", $name);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();

                    if ($existing) {
                        // Reutilizar a existente
                        $catMap[(int)$cat['id']] = (int)$existing['id'];
                    } else {
                        // Inserir nova
                        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                        $stmt->bind_param("s", $name);
                        $stmt->execute();

                        // Buscar o id inserido
                        $stmt2 = $conn->prepare("SELECT id FROM categories WHERE name = ? ORDER BY id DESC LIMIT 1");
                        $stmt2->bind_param("s", $name);
                        $stmt2->execute();
                        $newId = (int)$stmt2->get_result()->fetch_assoc()['id'];

                        $catMap[(int)$cat['id']] = $newId;
                        $catsImported++;
                    }
                }

                // ── IMPORTAR TRANSACTIONS ──
                $txImported  = 0;
                $txSkipped   = 0;

                foreach ($data['transactions'] as $tx) {
                    if (!isset($tx['amount'], $tx['date'])) {
                        $txSkipped++;
                        continue;
                    }
                    // Mapear category_id para o novo id
                    $old_cat_id = (int)($tx['category_id'] ?? 0);
                    $new_cat_id = $catMap[$old_cat_id] ?? null;

                    $amount      = (float)$tx['amount'];
                    $date        = $tx['date'];
                    $description = $tx['description'] ?? '';
                    $detail      = $tx['detail'] ?? '';
                    $nif         = (int)($tx['nif'] ?? 0);

                    if ($amount < 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        $txSkipped++;
                        continue;
                    }

                    // Em modo merge, evitar duplicados (mesma data + valor + descrição)
                    if ($mode === 'merge') {
                        $stmt = $conn->prepare("
                            SELECT id FROM transactions
                            WHERE user_id = ? AND date = ? AND amount = ? AND description = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param("isds", $user_id, $date, $amount, $description);
                        $stmt->execute();
                        if ($stmt->get_result()->fetch_assoc()) {
                            $txSkipped++;
                            continue;
                        }
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO transactions (user_id, category_id, amount, date, description, detail, nif)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("iidsssi", $user_id, $new_cat_id, $amount, $date, $description, $detail, $nif);
                    $stmt->execute();
                    $txImported++;
                }

                // ── IMPORTAR MONTHLY SUMMARY ──
                $summaryImported = 0;

                foreach ($data['monthly_summary'] as $ms) {
                    if (!isset($ms['year'], $ms['month'], $ms['total_spent'], $ms['salary'], $ms['final_balance'])) {
                        continue;
                    }
                    $year         = (int)$ms['year'];
                    $month        = (int)$ms['month'];
                    $total_spent  = (float)$ms['total_spent'];
                    $salary       = (float)$ms['salary'];
                    $final_balance = (float)$ms['final_balance'];

                    if ($year < LP_MIN_YEAR || $year > LP_MAX_YEAR || $month < 1 || $month > 12) {
                        continue;
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO monthly_summary (user_id, year, month, total_spent, salary, final_balance)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON CONFLICT(user_id, year, month) DO UPDATE SET
                            total_spent   = excluded.total_spent,
                            salary        = excluded.salary,
                            final_balance = excluded.final_balance
                    ");
                    $stmt->bind_param("iiiddd", $user_id, $year, $month, $total_spent, $salary, $final_balance);
                    $stmt->execute();
                    $summaryImported++;
                }

                $budgetsImported = 0;
                foreach (($data['category_budgets'] ?? []) as $budgetRow) {
                    if (!isset($budgetRow['category_id'], $budgetRow['year'], $budgetRow['month'], $budgetRow['budget'])) {
                        continue;
                    }

                    $old_cat_id = (int)$budgetRow['category_id'];
                    $new_cat_id = $catMap[$old_cat_id] ?? null;
                    $budgetYear = (int)$budgetRow['year'];
                    $budgetMonth = (int)$budgetRow['month'];
                    $budget = (float)$budgetRow['budget'];

                    if (!$new_cat_id || $budgetYear < LP_MIN_YEAR || $budgetYear > LP_MAX_YEAR || $budgetMonth < 1 || $budgetMonth > 12 || $budget < 0) {
                        continue;
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO category_budgets (user_id, category_id, year, month, budget)
                        VALUES (?, ?, ?, ?, ?)
                        ON CONFLICT(user_id, category_id, year, month) DO UPDATE SET
                            budget = excluded.budget
                    ");
                    $stmt->bind_param("iiiid", $user_id, $new_cat_id, $budgetYear, $budgetMonth, $budget);
                    $stmt->execute();
                    $budgetsImported++;
                }

                $stats = [
                    'categories' => $catsImported,
                    'transactions' => $txImported,
                    'transactions_skipped' => $txSkipped,
                    'monthly_summary' => $summaryImported,
                    'category_budgets' => $budgetsImported,
                    'mode' => $mode,
                ];
                $success = 'Importação concluída com sucesso.';
            }
        }
    }

    // NavLinks dinâmicos
    $all_categories = get_categories($conn);
    $navLinks = build_nav_links($all_categories);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Dados — LifePlanner</title>
    <link rel="stylesheet" href="../settings/stylesSettings.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
    <style>
        .import-box {
            display: block; 
            width: 100%;  
            box-sizing: border-box;
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 32px 24px;
            text-align: center;
            transition: border-color 0.15s;
            cursor: pointer;
            margin-bottom: 16px;
        }
        .import-box:hover { border-color: var(--accent); }
        .import-box input[type="file"] { display: none; }
        .import-box .upload-icon { font-size: 2rem; margin-bottom: 8px; }
        .import-box .upload-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text);
            display: block;
            margin-bottom: 4px;
        }
        .import-box .upload-hint { font-size: 0.78rem; color: var(--muted); }
        .file-chosen {
            font-size: 0.82rem;
            color: var(--accent);
            font-weight: 500;
            margin-top: 8px;
            display: none;
        }
        .mode-group {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        .mode-card {
            flex: 1;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color 0.12s, background 0.12s;
        }
        .mode-card input[type="radio"] { display: none; }
        .mode-card.selected {
            border-color: var(--accent);
            background: #eff6ff;
        }
        .mode-card .mode-title {
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 3px;
            color: var(--text);
        }
        .mode-card .mode-desc {
            font-size: 0.75rem;
            color: var(--muted);
            line-height: 1.4;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 14px;
        }
        .stat-item {
            background: var(--bg);
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 0.82rem;
        }
        .stat-item strong {
            display: block;
            font-size: 1.2rem;
            color: var(--accent);
        }
        .stat-item.skipped strong { color: var(--muted); }
        .export-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            font-size: 0.84rem;
            font-weight: 600;
            transition: border-color 0.12s;
        }
        .export-link:hover { border-color: var(--accent); color: var(--accent); }
        .export-filter-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .export-filter-form select {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 7px 10px;
            border-radius: 5px;
            font-family: var(--font);
            font-size: 0.84rem;
            outline: none;
        }
        .export-filter-form button { cursor: pointer; }
    </style>
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <?= render_nav($navLinks) ?>
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

    <h1 class="page-title">Dados</h1>
    <p class="page-subtitle">Exporta ou importa os teus dados em formato JSON.</p>

    <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
    <?php if ($stats): ?>
    <div class="stats-grid">
        <div class="stat-item">
            <strong><?= $stats['categories'] ?></strong>
            Categorias importadas
        </div>
        <div class="stat-item">
            <strong><?= $stats['transactions'] ?></strong>
            Transações importadas
        </div>
        <div class="stat-item skipped">
            <strong><?= $stats['transactions_skipped'] ?></strong>
            Transações ignoradas (duplicadas)
        </div>
        <div class="stat-item">
            <strong><?= $stats['monthly_summary'] ?></strong>
            Resumos mensais atualizados
        </div>
        <div class="stat-item">
            <strong><?= $stats['category_budgets'] ?></strong>
            Orcamentos importados
        </div>
    </div>
    <?php endif; ?>
    <?php elseif ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- Exportar -->
    <div class="card">
        <div class="card-title">Exportar</div>
        <p style="font-size:0.84rem; color:var(--muted); margin-bottom:14px;">
            Faz download de todos os teus dados (categorias, transações e resumos mensais) num ficheiro JSON.
        </p>
        <a href="export.php" class="export-link">
            ⬇ Download backup JSON
        </a>
        <form action="export.php" method="get" class="export-filter-form">
            <select name="year">
                <option value="">Todos os anos</option>
                <?php for ($y = LP_MIN_YEAR; $y <= LP_MAX_YEAR; $y++): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="month">
                <option value="">Todos os meses</option>
                <?php
                foreach (month_names(true) as $num => $name):
                ?>
                <option value="<?= $num ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category_id">
                <option value="">Todas as categorias</option>
                <?php foreach ($all_categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="export-link">Download filtrado</button>
        </form>
    </div>

    <!-- Importar -->
    <div class="card">
        <div class="card-title">Importar</div>
        <form method="post" enctype="multipart/form-data" id="importForm">
            <?= csrf_field() ?>

            <!-- Modo -->
            <p class="section-label" style="margin-bottom:10px;">Modo de importação</p>
            <div class="mode-group">
                <label class="mode-card selected" id="card-merge">
                    <input type="radio" name="mode" value="merge" checked onchange="selectMode('merge')">
                    <div class="mode-title">Juntar</div>
                    <div class="mode-desc">Adiciona os dados do ficheiro sem apagar os existentes. Transações duplicadas são ignoradas.</div>
                </label>
                <label class="mode-card" id="card-replace">
                    <input type="radio" name="mode" value="replace" onchange="selectMode('replace')">
                    <div class="mode-title">Substituir</div>
                    <div class="mode-desc">Apaga todos os teus dados atuais e substitui pelos do ficheiro. Esta ação não pode ser desfeita.</div>
                </label>
            </div>

            <!-- Upload -->
            <p class="section-label" style="margin-bottom:10px;">Ficheiro JSON</p>
            <label class="import-box" for="backup_file" id="dropZone">
                <div class="upload-icon">📂</div>
                <span class="upload-label">Clica para escolher o ficheiro</span>
                <span class="upload-hint">Apenas ficheiros .json exportados pelo LifePlanner</span>
                <input type="file" name="backup_file" id="backup_file" accept=".json" required onchange="showFilename(this)">
                <div class="file-chosen" id="fileChosen"></div>
            </label>

            <button type="submit" class="btn"
                onclick="return confirmImport()">
                Importar
            </button>
        </form>
    </div>

</div>

<script>
function selectMode(mode) {
    document.getElementById('card-merge').classList.toggle('selected', mode === 'merge');
    document.getElementById('card-replace').classList.toggle('selected', mode === 'replace');
}

function showFilename(input) {
    const el = document.getElementById('fileChosen');
    if (input.files.length > 0) {
        el.textContent = '✓ ' + input.files[0].name;
        el.style.display = 'block';
    }
}

function confirmImport() {
    const mode = document.querySelector('input[name="mode"]:checked').value;
    if (mode === 'replace') {
        return confirm('Tens a certeza? Modo SUBSTITUIR vai apagar todos os teus dados atuais. Esta ação não pode ser desfeita.');
    }
    return true;
}
</script>

</body>
</html>
