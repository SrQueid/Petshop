<?php
session_start();

// Include navbar with error handling
try {
    include 'navbar.php';
} catch (Exception $e) {
    error_log("Failed to include navbar.php: " . $e->getMessage());
    die('<div class="alert alert-danger">Erro ao carregar o menu de navegação: ' . $e->getMessage() . '</div>');
}

// Incluir o arquivo de conexão com o banco de dados
require_once 'db_connect.php';

// Função para registrar logs no banco
function writeLog($pdo, $action, $details, $user_id = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (action, user_id, details) VALUES (:action, :user_id, :details)");
        $stmt->execute([
            ':action' => $action,
            ':user_id' => $user_id,
            ':details' => $details
        ]);
    } catch (PDOException $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}

// Verifica se o usuário está logado
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    header("Location: agendamentos.php");
    exit;
}

// Processa o cadastro
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($name) || empty($phone) || empty($address) || empty($email) || empty($password)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Email inválido.</div>';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $message = '<div class="alert alert-danger">Email já cadastrado.</div>';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, phone, address, email, password, role) VALUES (:name, :phone, :address, :email, :password, 'user')");
                $stmt->execute([
                    ':name' => $name,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':email' => $email,
                    ':password' => $hashedPassword
                ]);
                $message = '<div class="alert alert-success">Cadastro realizado com sucesso! Faça login <a href="login.php">aqui</a>.</div>';
                writeLog($pdo, "Usuário Cadastrado", "Email: $email");
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao cadastrar: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Usuário", "Erro: {$e->getMessage()} - Email: $email");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Referência ao arquivo CSS externo -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container register-container">
        <h1 class="text-center mb-4">Petshop - Cadastro</h1>

        <!-- Formulário de Cadastro -->
        <div class="card shadow mb-4">
            <div class="card-header">
                <h5 class="mb-0">Cadastrar</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="action" value="register">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Telefone *</label>
                        <input type="text" class="form-control" id="phone" name="phone" placeholder="(XX) 9XXXX-XXXX" required>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Endereço *</label>
                        <textarea class="form-control" id="address" name="address" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Senha *</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div id="registerErrorMessage" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Cadastrar</button>
                </form>
            </div>
        </div>

        <!-- Link para Login -->
        <div class="text-center mt-3">
            <p>Já tem uma conta? <a href="login.php">Faça login aqui</a></p>
        </div>

        <!-- Mensagem -->
        <?php if (!empty($message)): ?>
            <?php echo $message; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Referência ao arquivo JavaScript externo -->
    <script src="js/scripts.js"></script>
</body>
</html>