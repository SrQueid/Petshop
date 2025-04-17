<?php
session_start();

// Cabeçalhos para evitar cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Include navbar with error handling
try {
    include 'navbar.php';
} catch (Exception $e) {
    error_log("Failed to include navbar.php: " . $e->getMessage());
    die('<div class="alert alert-danger">Erro ao carregar o menu de navegação: ' . $e->getMessage() . '</div>');
}

// Incluir o arquivo de conexão com o banco de dados
require_once 'db_connect.php';

// Função para verificar login
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

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

// Carrega os animais do usuário logado
$pets = [];
if (isLoggedIn()) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM pets WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao carregar animais: " . $e->getMessage());
    }
}

// Processa logout
if (isset($_GET['logout'])) {
    if (isLoggedIn()) {
        writeLog($pdo, "Logout", "Email: {$_SESSION['email']}", $_SESSION['user_id']);
    }
    session_destroy();
    header("Location: auth.php");
    exit;
}

// Processa ações de agendamento
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isLoggedIn()) {
        header("Location: auth.php");
        exit;
    }

    if ($_POST['action'] === 'create') {
        $pet_id = $_POST['pet_id'] ?? '';
        $service = $_POST['service'] ?? '';
        $taxi_dog = isset($_POST['taxi_dog']) ? 1 : 0;
        $scheduled_at = $_POST['scheduled_at'] ?? '';

        if (empty($pet_id) || empty($service) || empty($scheduled_at)) {
            $message = '<div class="alert alert-danger">Por favor, preencha todos os campos obrigatórios.</div>';
            writeLog($pdo, "Erro ao Criar Agendamento", "Campos obrigatórios não preenchidos - Email: {$_SESSION['email']}", $_SESSION['user_id']);
        } elseif (strtotime($scheduled_at) <= time()) {
            $message = '<div class="alert alert-danger">A data deve ser futura.</div>';
            writeLog($pdo, "Erro ao Criar Agendamento", "Data não é futura - Email: {$_SESSION['email']}", $_SESSION['user_id']);
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service, taxi_dog, scheduled_at) VALUES (:user_id, :pet_id, :service, :taxi_dog, :scheduled_at)");
                $stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':pet_id' => $pet_id,
                    ':service' => $service,
                    ':taxi_dog' => $taxi_dog,
                    ':scheduled_at' => $scheduled_at
                ]);
                $message = '<div class="alert alert-success">Agendamento realizado com sucesso!</div>';
                writeLog($pdo, "Agendamento Criado", "Pet ID: $pet_id, Serviço: $service - Email: {$_SESSION['email']}", $_SESSION['user_id']);
            } catch (PDOException $e) {
                $message = '<div class="alert alert-danger">Erro ao salvar: ' . $e->getMessage() . '</div>';
                writeLog($pdo, "Erro ao Criar Agendamento", "Erro: {$e->getMessage()} - Email: {$_SESSION['email']}", $_SESSION['user_id']);
            }
        }
    } elseif ($_POST['action'] === 'update') {
        $id = $_POST['id'] ?? 0;
        $pet_id = $_POST['pet_id'] ?? '';
        $service = $_POST['service'] ?? '';
        $taxi_dog = isset($_POST['taxi_dog']) ? 1 : 0;
        $scheduled_at = $_POST['scheduled_at'] ?? '';

        if (empty($pet_id) || empty($service) || empty($scheduled_at)) {
            $message = '<div class="alert alert-danger">Por favor, preencha todos os campos obrigatórios.</div>';
            writeLog($pdo, "Erro ao Atualizar Agendamento", "Campos obrigatórios não preenchidos - Email: {$_SESSION['email']}", $_SESSION['user_id']);
        } elseif (strtotime($scheduled_at) <= time()) {
            $message = '<div class="alert alert-danger">A data deve ser futura.</div>';
            writeLog($pdo, "Erro ao Atualizar Agendamento", "Data não é futura - Email: {$_SESSION['email']}", $_SESSION['user_id']);
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE appointments SET pet_id = :pet_id, service = :service, taxi_dog = :taxi_dog, scheduled_at = :scheduled_at WHERE id = :id AND user_id = :user_id");
                $stmt->execute([
                    ':id' => $id,
                    ':pet_id' => $pet_id,
                    ':service' => $service,
                    ':taxi_dog' => $taxi_dog,
                    ':scheduled_at' => $scheduled_at,
                    ':user_id' => $_SESSION['user_id']
                ]);
                $message = '<div class="alert alert-success">Agendamento atualizado com sucesso!</div>';
                writeLog($pdo, "Agendamento Atualizado", "ID: $id, Pet ID: $pet_id - Email: {$_SESSION['email']}", $_SESSION['user_id']);
            } catch (PDOException $e) {
                $message = '<div class="alert alert-danger">Erro ao atualizar: ' . $e->getMessage() . '</div>';
                writeLog($pdo, "Erro ao Atualizar Agendamento", "Erro: {$e->getMessage()} - Email: {$_SESSION['email']}", $_SESSION['user_id']);
            }
        }
    }
}

// Processa exclusão
if (isset($_GET['delete'])) {
    if (!isLoggedIn()) {
        header("Location: auth.php");
        exit;
    }

    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $id, ':user_id' => $_SESSION['user_id']]);
        $message = '<div class="alert alert-success">Agendamento excluído com sucesso!</div>';
        writeLog($pdo, "Agendamento Excluído", "ID: $id - Email: {$_SESSION['email']}", $_SESSION['user_id']);
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Erro ao excluir: ' . $e->getMessage() . '</div>';
        writeLog($pdo, "Erro ao Excluir Agendamento", "Erro: {$e->getMessage()} - Email: {$_SESSION['email']}", $_SESSION['user_id']);
    }
}

// Carrega agendamentos do usuário logado com informações do animal
$appointments = [];
if (isLoggedIn()) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, p.pet_name, p.pet_type 
            FROM appointments a 
            JOIN pets p ON a.pet_id = p.id 
            WHERE a.user_id = :user_id 
            ORDER BY a.scheduled_at ASC
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        writeLog($pdo, "Agendamentos Carregados", "Email: {$_SESSION['email']}", $_SESSION['user_id']);
    } catch (PDOException $e) {
        $message .= '<div class="alert alert-danger">Erro ao carregar agendamentos: ' . $e->getMessage() . '</div>';
        writeLog($pdo, "Erro ao Carregar Agendamentos", "Erro: {$e->getMessage()} - Email: {$_SESSION['email']}", $_SESSION['user_id']);
    }
}

// Carrega dados do agendamento para edição, se solicitado
$edit_appointment = null;
if (isset($_GET['edit']) && isLoggedIn()) {
    $id = $_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT a.*, p.pet_name FROM appointments a JOIN pets p ON a.pet_id = p.id WHERE a.id = :id AND a.user_id = :user_id");
        $stmt->execute([':id' => $id, ':user_id' => $_SESSION['user_id']]);
        $edit_appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Erro ao carregar agendamento para edição: ' . $e->getMessage() . '</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Petshop PetsLove - Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container">
        <!-- Mensagem -->
        <?php if (isset($_SESSION['message'])): ?>
            <?php echo $_SESSION['message']; ?>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        <?php if (!empty($message)): ?>
            <?php echo $message; ?>
        <?php endif; ?>

        <!-- Conteúdo Principal -->
        <div class="header-section">
            <h1>Bem-vindo ao Petshop PetsLove!</h1>
        </div>

        <!-- Quem Somos -->
        <h2 class="section-title">Quem Somos</h2>
        <p>O <strong>PetsLove</strong> é mais do que um petshop: é um lugar onde o amor pelos animais ganha vida! Nossa missão é oferecer serviços e produtos de qualidade para garantir o bem-estar e a felicidade do seu pet.</p>

        <!-- Nossos Serviços -->
        <h2 class="section-title">Nossos Serviços</h2>
        <ul>
            <li><strong>Banho & Tosa:</strong> Profissionais experientes para deixar seu pet sempre limpo e estiloso.</li>
            <li><strong>Veterinário:</strong> Atendimento especializado para cuidar da saúde do seu melhor amigo.</li>
            <li><strong>Taxi Dog:</strong> Transporte seguro e confortável para levar e trazer seu pet.</li>
            <li><strong>Loja de Produtos:</strong> Alimentos, brinquedos e acessórios para todas as necessidades.</li>
        </ul>

        <!-- Por que escolher a PetsLove? -->
        <h2 class="section-title">Por que escolher a PetsLove?</h2>
        <ul>
            <li>Atendimento personalizado e carinho com cada pet.</li>
            <li>Produtos e serviços de alta qualidade.</li>
            <li>Ambiente seguro e aconchegante para o seu bichinho.</li>
            <li>Equipe apaixonada por animais!</li>
        </ul>

        <!-- Entre em Contato -->
        <h2 class="section-title">Entre em Contato</h2>
        <div class="contact-info">
            <p>📍 <strong>Endereço:</strong> Rua dos Pets, 123 - Cidade Feliz</p>
            <p>📞 <strong>Telefone:</strong> (11) 98765-4321</p>
            <p>🌐 <strong>Site:</strong> <a href="http://www.petslove.com.br" target="_blank">www.petslove.com.br</a></p>
            <p>📩 <strong>E-mail:</strong> <a href="mailto:contato@petslove.com.br">contato@petslove.com.br</a></p>
        </div>

        <!-- Seção de Agendamentos -->
        <?php if (isLoggedIn()): ?>
            <!-- Formulário de Cadastro -->
            <h2 class="section-title">Novo Agendamento</h2>
            <form method="POST" action="" class="mt-4">
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                    <label for="pet_id_create" class="form-label">Pet *</label>
                    <select class="form-control" id="pet_id_create" name="pet_id" required>
                        <option value="">Selecione um pet</option>
                        <?php foreach ($pets as $pet): ?>
                            <option value="<?php echo htmlspecialchars($pet['id']); ?>">
                                <?php echo htmlspecialchars($pet['pet_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="service_create" class="form-label">Serviço *</label>
                    <select class="form-control" id="service_create" name="service" required>
                        <option value="">Selecione um serviço</option>
                        <option value="Banho & Tosa">Banho & Tosa</option>
                        <option value="Veterinário">Veterinário</option>
                        <option value="Taxi Dog">Taxi Dog</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="taxi_dog_create" class="form-label">Incluir Taxi Dog?</label>
                    <input type="checkbox" id="taxi_dog_create" name="taxi_dog" value="1">
                </div>
                <div class="mb-3">
                    <label for="scheduled_at_create" class="form-label">Data e Hora *</label>
                    <input type="datetime-local" class="form-control" id="scheduled_at_create" name="scheduled_at" required>
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-agendamento">Fazer um Agendamento</button>
            </form>

            <!-- Formulário de Edição (exibido se um agendamento estiver sendo editado) -->
            <?php if ($edit_appointment): ?>
                <h2 class="section-title mt-5">Editar Agendamento</h2>
                <form method="POST" action="" class="mt-4">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_appointment['id']); ?>">
                    <div class="mb-3">
                        <label for="pet_id_edit" class="form-label">Pet *</label>
                        <select class="form-control" id="pet_id_edit" name="pet_id" required>
                            <option value="">Selecione um pet</option>
                            <?php foreach ($pets as $pet): ?>
                                <option value="<?php echo htmlspecialchars($pet['id']); ?>" <?php echo $pet['id'] == $edit_appointment['pet_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pet['pet_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="service_edit" class="form-label">Serviço *</label>
                        <select class="form-control" id="service_edit" name="service" required>
                            <option value="">Selecione um serviço</option>
                            <option value="Banho & Tosa" <?php echo $edit_appointment['service'] == 'Banho & Tosa' ? 'selected' : ''; ?>>Banho & Tosa</option>
                            <option value="Veterinário" <?php echo $edit_appointment['service'] == 'Veterinário' ? 'selected' : ''; ?>>Veterinário</option>
                            <option value="Taxi Dog" <?php echo $edit_appointment['service'] == 'Taxi Dog' ? 'selected' : ''; ?>>Taxi Dog</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="taxi_dog_edit" class="form-label">Incluir Taxi Dog?</label>
                        <input type="checkbox" id="taxi_dog_edit" name="taxi_dog" value="1" <?php echo $edit_appointment['taxi_dog'] ? 'checked' : ''; ?>>
                    </div>
                    <div class="mb-3">
                        <label for="scheduled_at_edit" class="form-label">Data e Hora *</label>
                        <input type="datetime-local" class="form-control" id="scheduled_at_edit" name="scheduled_at" value="<?php echo date('Y-m-d\TH:i', strtotime($edit_appointment['scheduled_at'])); ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">Salvar Alterações</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">Cancelar</a>
                </form>
            <?php endif; ?>

            <!-- Tabela de Agendamentos -->
            <?php if (!empty($appointments)): ?>
                <h2 class="section-title mt-5">Meus Agendamentos</h2>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Pet</th>
                                <th>Serviço</th>
                                <th>Taxi Dog</th>
                                <th>Data e Hora</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appointment['pet_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appointment['service']); ?></td>
                                    <td><?php echo $appointment['taxi_dog'] ? 'Sim' : 'Não'; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($appointment['scheduled_at'])); ?></td>
                                    <td>
                                        <a href="?edit=<?php echo $appointment['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                        <a href="?delete=<?php echo $appointment['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja cancelar este agendamento?');">Cancelar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="mt-4 text-center">Você ainda não tem agendamentos.</p>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center">
                <a href="auth.php" class="btn btn-primary btn-lg btn-agendamento">Faça login para agendar</a>
            </div>
        <?php endif; ?>

        <p class="mt-4 text-center">Venha nos visitar e proporcione o melhor para o seu pet! 🐶🐱💙</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
</body>
</html>