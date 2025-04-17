<?php
session_start();

// Include navbar with error handling
try {
    include 'navbar.php';
} catch (Exception $e) {
    error_log("Failed to include navbar.php: " . $e->getMessage());
    die('<div class="alert alert-danger">Erro ao carregar o menu de navegação: ' . $e->getMessage() . '</div>');
}

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Verifica se o usuário é administrador
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header("Location: agendamentos.php");
    exit;
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

// Processa o cadastro de um novo administrador
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_admin') {
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = 'admin'; // Define o role como 'admin' para novos administradores

    if (empty($name) || empty($phone) || empty($address) || empty($email) || empty($password)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos.</div>';
        writeLog($pdo, "Cadastro de Admin Falhou", "Campos vazios - Email: $email", $_SESSION['user_id']);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Email inválido.</div>';
        writeLog($pdo, "Cadastro de Admin Falhou", "Email inválido - Email: $email", $_SESSION['user_id']);
    } else {
        try {
            // Verifica se o email já está em uso
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetchColumn() > 0) {
                $message = '<div class="alert alert-danger">Email já cadastrado.</div>';
                writeLog($pdo, "Cadastro de Admin Falhou", "Email já em uso - Email: $email", $_SESSION['user_id']);
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, phone, address, email, password, role) VALUES (:name, :phone, :address, :email, :password, :role)");
                $stmt->execute([
                    ':name' => $name,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':email' => $email,
                    ':password' => $hashedPassword,
                    ':role' => $role
                ]);
                $message = '<div class="alert alert-success">Administrador cadastrado com sucesso!</div>';
                writeLog($pdo, "Administrador Cadastrado", "Email: $email", $_SESSION['user_id']);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao cadastrar administrador: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Admin", "Erro: {$e->getMessage()} - Email: $email", $_SESSION['user_id']);
        }
    }
}

// Processa a edição de um usuário existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $user_id = $_POST['user_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';

    if (empty($user_id) || empty($name) || empty($phone) || empty($address) || empty($email)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios.</div>';
        writeLog($pdo, "Edição de Usuário Falhou", "Campos vazios - Email: $email", $_SESSION['user_id']);
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger">Email inválido.</div>';
        writeLog($pdo, "Edição de Usuário Falhou", "Email inválido - Email: $email", $_SESSION['user_id']);
    } else {
        try {
            // Verifica se o email já está em uso por outro usuário
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
            $stmt->execute([':email' => $email, ':id' => $user_id]);
            if ($stmt->fetchColumn() > 0) {
                $message = '<div class="alert alert-danger">Email já está em uso por outro usuário.</div>';
                writeLog($pdo, "Edição de Usuário Falhou", "Email já em uso - Email: $email", $_SESSION['user_id']);
            } else {
                // Atualiza os dados
                $query = "UPDATE users SET name = :name, phone = :phone, address = :address, email = :email, role = :role";
                $params = [
                    ':name' => $name,
                    ':phone' => $phone,
                    ':address' => $address,
                    ':email' => $email,
                    ':role' => $role,
                    ':id' => $user_id
                ];

                // Se a senha foi fornecida, atualiza a senha
                if (!empty($password)) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $query .= ", password = :password";
                    $params[':password'] = $hashedPassword;
                }

                $query .= " WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);

                // Se o usuário editado for o próprio administrador logado, atualiza a sessão
                if ($user_id == $_SESSION['user_id']) {
                    $_SESSION['email'] = $email;
                    $_SESSION['user_role'] = $role;
                    $_SESSION['is_admin'] = ($role === 'admin');
                }

                $message = '<div class="alert alert-success">Usuário atualizado com sucesso!</div>';
                writeLog($pdo, "Usuário Atualizado", "Email: $email, Role: $role", $_SESSION['user_id']);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao atualizar usuário: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Atualizar Usuário", "Erro: {$e->getMessage()} - Email: $email", $_SESSION['user_id']);
        }
    }
}

// Carrega todos os usuários
$users = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message .= '<div class="alert alert-danger">Erro ao carregar usuários: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Carregar Usuários", "Erro: {$e->getMessage()} - Email: {$_SESSION['email']}", $_SESSION['user_id']);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Referência ao arquivo CSS externo -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container manage-users-container">
        <h1 class="text-center mb-4">Gerenciar Usuários</h1>

        <div class="d-flex justify-content-between mb-4">
            <h2>Bem-vindo, <?php echo htmlspecialchars($_SESSION['email']); ?></h2>
            <div>
                <a href="admin.php" class="btn btn-primary">Voltar para Administração</a>
                <a href="auth.php?logout=1" class="btn btn-danger">Sair</a>
            </div>
        </div>

        <!-- Mensagem -->
        <?php if (!empty($message)): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <!-- Formulário de Cadastro de Novo Administrador -->
        <div class="card shadow mb-4">
            <div class="card-header">
                <h5 class="mb-0">Cadastrar Novo Administrador</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="addAdminForm">
                    <input type="hidden" name="action" value="add_admin">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Telefone</label>
                        <input type="text" class="form-control" id="phone" name="phone" required>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Endereço</label>
                        <textarea class="form-control" id="address" name="address" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Senha</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <div id="addAdminErrorMessage" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Cadastrar Administrador</button>
                </form>
            </div>
        </div>

        <!-- Lista de Usuários -->
        <div class="mt-4">
            <h3>Lista de Usuários</h3>
            <?php if (empty($users)): ?>
                <p>Nenhum usuário cadastrado.</p>
            <?php else: ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Telefone</th>
                            <th>Endereço</th>
                            <th>Role</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['phone']); ?></td>
                                <td><?php echo htmlspecialchars($user['address']); ?></td>
                                <td><?php echo htmlspecialchars($user['role']); ?></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $user['id']; ?>">Editar</button>
                                </td>
                            </tr>

                            <!-- Modal para Edição do Usuário -->
                            <div class="modal fade" id="editUserModal<?php echo $user['id']; ?>" tabindex="-1" aria-labelledby="editUserModalLabel<?php echo $user['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editUserModalLabel<?php echo $user['id']; ?>">Editar Usuário: <?php echo htmlspecialchars($user['name']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form method="POST" action="" class="editUserForm">
                                                <input type="hidden" name="action" value="edit_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <div class="mb-3">
                                                    <label for="name_<?php echo $user['id']; ?>" class="form-label">Nome</label>
                                                    <input type="text" class="form-control" id="name_<?php echo $user['id']; ?>" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="phone_<?php echo $user['id']; ?>" class="form-label">Telefone</label>
                                                    <input type="text" class="form-control" id="phone_<?php echo $user['id']; ?>" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="address_<?php echo $user['id']; ?>" class="form-label">Endereço</label>
                                                    <textarea class="form-control" id="address_<?php echo $user['id']; ?>" name="address" required><?php echo htmlspecialchars($user['address']); ?></textarea>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="email_<?php echo $user['id']; ?>" class="form-label">Email</label>
                                                    <input type="email" class="form-control" id="email_<?php echo $user['id']; ?>" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="password_<?php echo $user['id']; ?>" class="form-label">Nova Senha (deixe em branco para não alterar)</label>
                                                    <input type="password" class="form-control" id="password_<?php echo $user['id']; ?>" name="password">
                                                </div>
                                                <div class="mb-3">
                                                    <label for="role_<?php echo $user['id']; ?>" class="form-label">Role</label>
                                                    <select class="form-select" id="role_<?php echo $user['id']; ?>" name="role" required>
                                                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>Usuário</option>
                                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                                    </select>
                                                    <div id="editUserErrorMessage_<?php echo $user['id']; ?>" class="text-danger mt-2" style="display: none;"></div>
                                                </div>
                                                <button type="submit" class="btn btn-primary w-100">Salvar Alterações</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Referência ao arquivo JavaScript externo -->
    <script src="js/scripts.js"></script>
</body>
</html>