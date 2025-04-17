<?php
// Inicia a sessão (necessário para verificar se o usuário está logado)
if (!isset($_SESSION)) {
    session_start();
}

// Verifica se o usuário está logado
$isLoggedIn = isset($_SESSION['user_id']);

// Verifica se o usuário é administrador
$isAdmin = false;
if ($isLoggedIn) {
    try {
        require_once 'db_connect.php';
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $isAdmin = $user && $user['role'] === 'admin';
    } catch (PDOException $e) {
        error_log("Erro ao verificar administrador: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Inclui Bootstrap Icons para o ícone do logotipo -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Referência ao arquivo CSS externo -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <!-- Adiciona um ícone de pet ao lado do texto -->
                <i class="bi bi-paw"></i> <!-- Substitua por <img src="path/to/logo.png" alt="Logo" class="logo"> se tiver um logotipo -->
                Petshop PetsLove
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="agendamentos.php">Agendamentos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="pets.php">Meus Pets</a>
                    </li>
                    <?php if ($isAdmin): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="admin.php">Administração</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="services.php">Gerenciar Serviços</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <?php if ($isLoggedIn): ?>
                        <li class="nav-item">
                            <span class="nav-link text-light">Bem-vindo, <?php echo htmlspecialchars($_SESSION['email']); ?>!</span>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="perfil.php">Perfil</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="index.php?logout=1">Sair</a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="auth.php">Login / Cadastro</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Referência ao arquivo JavaScript externo -->
    <script src="js/scripts.js"></script>
</body>
</html>