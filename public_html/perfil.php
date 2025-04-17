<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Incluir o arquivo de conexão com o banco de dados
require_once 'config/db_connect.php';

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

// Gerar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

// Carregar os dados do usuário
try {
    $stmt = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $message = '<div class="alert alert-danger">Usuário não encontrado. Por favor, faça login novamente.</div>';
        writeLog($pdo, "Erro ao Carregar Perfil", "Usuário não encontrado: ID {$_SESSION['user_id']}", $_SESSION['user_id']);
        header("Location: logout.php");
        exit;
    }
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao carregar perfil: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Carregar Perfil", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
    $user = [];
}

// Processar a edição do perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($name) || empty($email)) {
        $message = '<div class="alert alert-danger">Nome e email são obrigatórios.</div>';
        writeLog($pdo, "Edição de Perfil Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Email inválido.</div>';
        writeLog($pdo, "Edição de Perfil Falhou", "Email inválido: $email", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o email já está em uso por outro usuário
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $stmt->execute([':email' => $email, ':id' => $_SESSION['user_id']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $message = '<div class="alert alert-danger">Este email já está em uso por outro usuário.</div>';
                writeLog($pdo, "Edição de Perfil Falhou", "Email já em uso: $email", $_SESSION['user_id']);
            } else {
                // Montar a query de atualização
                $query = "UPDATE users SET name = :name, email = :email, phone = :phone, address = :address";
                $params = [
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':id' => $_SESSION['user_id']
                ];

                // Se a senha foi fornecida, atualizar a senha
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $query .= ", password = :password";
                    $params[':password'] = $hashedPassword;
                }

                $query .= " WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);

                // Atualizar a sessão com o novo email
                $_SESSION['user_email'] = $email;

                $message = '<div class="alert alert-success">Perfil atualizado com sucesso!</div>';
                writeLog($pdo, "Perfil Atualizado", "Usuário ID: {$_SESSION['user_id']}", $_SESSION['user_id']);

                // Recarregar os dados do usuário
                $stmt = $pdo->prepare("SELECT name, email, phone, address FROM users WHERE id = :id");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao atualizar perfil: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Atualizar Perfil", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <!-- Incluir a navbar -->
    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <h1 class="text-center mb-4">Meu Perfil</h1>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Telefone</label>
                        <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Endereço</label>
                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Nova Senha (deixe em branco para não alterar)</label>
                        <input type="password" class="form-control" id="password" name="password">
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                        <a href="agendamentos.php" class="btn btn-secondary">Voltar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
</body>
</html>