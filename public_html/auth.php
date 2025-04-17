<?php
// Start session at the top
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

// Processa o login
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos.</div>';
    } else {
        try {
            // Verificar na tabela users
            $stmt = $pdo->prepare("SELECT id, email, password, role FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['is_admin'] = ($user['role'] === 'admin'); // Define is_admin com base no role
                writeLog($pdo, "Login", "Email: $email, Role: {$user['role']}", $user['id']);
                header("Location: agendamentos.php");
                exit;
            }

            $message = '<div class="alert alert-danger">Email ou senha incorretos.</div>';
            writeLog($pdo, "Tentativa de Login Falhou", "Email: $email");
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao fazer login: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Fazer Login", "Erro: {$e->getMessage()} - Email: $email");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Referência ao arquivo CSS externo -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container auth-container">
        <h1 class="text-center mb-4">Petshop - Login</h1>

        <!-- Formulário de Login -->
        <div class="card shadow">
            <div class="card-header">
                <h5 class="mb-0">Login</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="text" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Entrar</button>
                </form>
                <!-- Link para recuperação de senha -->
                <div class="text-center mt-3">
                    <p><a href="forgot_password.php">Esqueceu sua senha?</a></p>
                </div>
            </div>
        </div>

        <!-- Link para Cadastro -->
        <div class="text-center mt-3">
            <p>Não tem uma conta? <a href="register.php">Cadastre-se aqui</a></p>
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