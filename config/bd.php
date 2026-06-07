<?php
    if (!defined('MYSQLI_ASSOC')) {
        define('MYSQLI_ASSOC', 1);
    }

    include_once __DIR__ . "/migrations.php";

    class MockMysqliStmt {
        private $pdoStmt;
        private $params = [];
        private $result = [];

        public function __construct($pdoStmt) {
            $this->pdoStmt = $pdoStmt;
        }

        public function bind_param($types, &...$vars) {
            $this->params = &$vars;
        }

        public function execute() {
            foreach ($this->params as $k => $v) {
                $type = PDO::PARAM_STR;
                if (is_int($v)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($v)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($v)) {
                    $type = PDO::PARAM_NULL;
                }
                $this->pdoStmt->bindValue($k + 1, $v, $type);
            }

            $res = $this->pdoStmt->execute();
            if ($res && preg_match('/^\s*(SELECT|PRAGMA)/i', $this->pdoStmt->queryString)) {
                $this->result = $this->pdoStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $res;
        }

        public function get_result() {
            return new MockMysqliResult($this->result ?: []);
        }

        public function close() {
            $this->pdoStmt = null;
        }
    }

    class MockMysqliResult {
        private $rows;
        private $index = 0;
        public $num_rows;

        public function __construct($rows) {
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc() {
            if ($this->index < $this->num_rows) {
                return $this->rows[$this->index++];
            }
            return null;
        }

        public function fetch_all($mode = null) {
            return $this->rows;
        }
    }

    class MockMysqli {
        private $pdo;
        public $connect_error = null;
        public $error = null;

        public function __construct($dbFile) {
            try {
                $this->pdo = new PDO("sqlite:" . $dbFile);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
                $this->pdo->sqliteCreateFunction('YEAR', function($date) {
                    return (int)date('Y', strtotime($date));
                }, 1);
                $this->pdo->sqliteCreateFunction('MONTH', function($date) {
                    return (int)date('m', strtotime($date));
                }, 1);
            } catch (PDOException $e) {
                $this->connect_error = $e->getMessage();
            }
        }

        public function prepare($sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                return new MockMysqliStmt($stmt);
            } catch (Exception $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }
    }

    $localConfigFile = __DIR__ . "/local.php";
    $localConfig = file_exists($localConfigFile) ? include $localConfigFile : [];

    $candidatePaths = [];
    if (!empty($localConfig['db_path'])) {
        $candidatePaths[] = $localConfig['db_path'];
    }
    $candidatePaths[] = "C:\Users\carlo\OneDrive\Ambiente de Trabalho\Engenharia de Informática\pessoal/financas.sqlite";
    $candidatePaths[] = "C:\Users\Carlos\OneDrive\Ambiente de Trabalho\Engenharia de Informática\pessoal/financas.sqlite";
    $candidatePaths[] = __DIR__ . "/financas.sqlite";

    $dbFinal = null;
    foreach ($candidatePaths as $path) {
        if (file_exists($path)) {
            $dbFinal = $path;
            break;
        }
    }

    if (!$dbFinal) {
        die("Nenhum ficheiro de base de dados encontrado. Cria config/local.php a partir de config/local.example.php.");
    }

    $conn = new MockMysqli($dbFinal);
    if ($conn->connect_error) {
        die("Falha na ligacao: " . $conn->connect_error . " (Ficheiro: $dbFinal)");
    }

    run_migrations($conn);
?>
