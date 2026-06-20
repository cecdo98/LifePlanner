<?php
    function require_login($redirect = '../../index.php') {
        if (!isset($_SESSION['user_id'])) {
            header("Location: " . $redirect);
            exit();
        }
    }

    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }

    function verify_csrf_token() {
        $token = $_REQUEST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('Pedido invalido.');
        }
    }

    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function validate_year($value, $default = null) {
        $year = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 2026, 'max_range' => 2070]
        ]);

        return $year !== false ? $year : ($default ?? (int)date('Y'));
    }

    function validate_month($value, $default = null) {
        $month = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 12]
        ]);

        return $month !== false ? $month : ($default ?? (int)date('m'));
    }

    function validate_nif($value, $default = '1') {
        return in_array((string)$value, ['0', '1'], true) ? (string)$value : $default;
    }

    function validate_nif_filter($value, $default = 'all') {
        return in_array((string)$value, ['all', '0', '1'], true) ? (string)$value : $default;
    }

    function flash_message($key) {
        $messages = [
            'salary_saved' => 'Ordenado guardado com sucesso.',
            'budget_saved' => 'Orcamentos guardados com sucesso.',
            'expense_saved' => 'Despesa guardada com sucesso.',
            'expense_deleted' => 'Despesa apagada com sucesso.',
            'category_added' => 'Categoria adicionada com sucesso.',
            'category_renamed' => 'Categoria renomeada com sucesso.',
            'category_deleted' => 'Categoria removida e despesas movidas com sucesso.',
            'goal_saved' => 'Meta de poupanca guardada com sucesso.',
            'goal_updated' => 'Meta de poupanca atualizada com sucesso.',
            'goal_deleted' => 'Meta de poupanca removida.',
            'recurring_saved' => 'Despesa recorrente guardada com sucesso.',
            'recurring_deleted' => 'Despesa recorrente removida.',
            'recurring_applied' => 'Despesas recorrentes aplicadas com sucesso.',
            'tag_saved' => 'Etiqueta criada com sucesso.',
            'tag_deleted' => 'Etiqueta removida.',
            'income_saved' => 'Rendimento adicionado com sucesso.',
            'income_deleted' => 'Rendimento removido.',
        ];

        return $messages[$key] ?? '';
    }
?>
