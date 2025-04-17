<?php
session_start();

// Include navbar
include 'navbar.php';

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Verifica se o usuário é administrador
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    $_SESSION['message'] = '<div class="alert alert-danger">Acesso restrito a administradores.</div>';
    header("Location: index.php");
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

// Gerar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

// Listar tutores (usuários que não são administradores)
$tutors = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_admin = 0 ORDER BY name");
    $stmt->execute();
    $tutors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar tutores: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Tutores", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar serviços
$services = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, value FROM services ORDER BY name");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar serviços: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Serviços", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar agendamentos (usando a tabela appointments)
$agendamentos = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.service_id, a.package_id, a.scheduled_at, a.status, a.pet_id,
               u.name AS user_name, s.name AS service_name, sp.name AS package_name, p.name AS pet_name
        FROM appointments a
        JOIN users u ON a.user_id = u.id
        JOIN pets p ON a.pet_id = p.id
        LEFT JOIN services s ON a.service_id = s.id
        LEFT JOIN service_packages sp ON a.package_id = sp.id
        ORDER BY a.scheduled_at DESC
    ");
    $stmt->execute();
    $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar agendamentos: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Agendamentos", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Carrega dados do agendamento para edição, se solicitado
$edit_agendamento = null;
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, u.name AS user_name, p.name AS pet_name, s.name AS service_name
            FROM appointments a
            JOIN users u ON a.user_id = u.id
            JOIN pets p ON a.pet_id = p.id
            LEFT JOIN services s ON a.service_id = s.id
            WHERE a.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $edit_agendamento = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Erro ao carregar agendamento para edição: ' . $e->getMessage() . '</div>';
    }
}

// Processar cadastro de agendamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_agendamento' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);
    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
    $package_id = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
    $scheduled_at = trim($_POST['scheduled_at'] ?? '');

    // Validar os campos
    if (empty($scheduled_at) || $service_id === false || $service_id === null || $user_id === false || $user_id === null || $pet_id === false || $pet_id === null) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios (tutor, pet, serviço e data/hora).</div>';
        writeLog($pdo, "Cadastro de Agendamento Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } elseif (strtotime($scheduled_at) <= time()) {
        $message = '<div class="alert alert-danger">A data deve ser futura.</div>';
        writeLog($pdo, "Cadastro de Agendamento Falhou", "Data não é futura", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o pet pertence ao tutor
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pets WHERE id = :pet_id AND user_id = :user_id");
            $stmt->execute([':pet_id' => $pet_id, ':user_id' => $user_id]);
            $pet_exists = $stmt->fetchColumn();

            if (!$pet_exists) {
                throw new Exception("O pet selecionado não pertence ao tutor informado.");
            }

            // Converter a data/hora para o formato do banco
            $scheduled_at_obj = new DateTime($scheduled_at);
            $formatted_scheduled_at = $scheduled_at_obj->format('Y-m-d H:i:s');

            // Verificar se o agendamento usa um pacote promocional
            if ($package_id !== null) {
                // Verificar se o tutor está associado ao pacote
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM package_tutors WHERE package_id = :package_id AND user_id = :user_id");
                $stmt->execute([':package_id' => $package_id, ':user_id' => $user_id]);
                $is_associated = $stmt->fetchColumn();

                if (!$is_associated) {
                    throw new Exception("O tutor não está associado ao pacote promocional selecionado.");
                }

                // Verificar se há quantidade restante do serviço no pacote para o tutor
                $stmt = $pdo->prepare("
                    SELECT remaining_quantity 
                    FROM service_package_usage 
                    WHERE package_id = :package_id AND user_id = :user_id AND service_id = :service_id
                ");
                $stmt->execute([
                    ':package_id' => $package_id,
                    ':user_id' => $user_id,
                    ':service_id' => $service_id
                ]);
                $remaining_quantity = $stmt->fetchColumn();

                if ($remaining_quantity === false || $remaining_quantity <= 0) {
                    throw new Exception("Não há quantidade restante do serviço selecionado no pacote promocional para este tutor.");
                }

                // Iniciar transação
                $pdo->beginTransaction();

                // Inserir o agendamento
                $stmt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service_id, package_id, scheduled_at, status) VALUES (:user_id, :pet_id, :service_id, :package_id, :scheduled_at, 'PENDING')");
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':pet_id' => $pet_id,
                    ':service_id' => $service_id,
                    ':package_id' => $package_id,
                    ':scheduled_at' => $formatted_scheduled_at
                ]);

                // Decrementar a quantidade restante no pacote para o tutor
                $stmt = $pdo->prepare("
                    UPDATE service_package_usage 
                    SET remaining_quantity = remaining_quantity - 1 
                    WHERE package_id = :package_id AND user_id = :user_id AND service_id = :service_id
                ");
                $stmt->execute([
                    ':package_id' => $package_id,
                    ':user_id' => $user_id,
                    ':service_id' => $service_id
                ]);

                // Commit da transação
                $pdo->commit();

                $message = '<div class="alert alert-success">Agendamento cadastrado com sucesso usando o pacote promocional!</div>';
                writeLog($pdo, "Agendamento Cadastrado", "Tutor ID: $user_id, Pet ID: $pet_id, Serviço ID: $service_id, Pacote ID: $package_id, Data/Hora: $formatted_scheduled_at", $_SESSION['user_id']);
                header("Location: agendamentos.php");
                exit;
            } else {
                // Agendamento sem pacote promocional
                $stmt = $pdo->prepare("INSERT INTO appointments (user_id, pet_id, service_id, package_id, scheduled_at, status) VALUES (:user_id, :pet_id, :service_id, NULL, :scheduled_at, 'PENDING')");
                $stmt->execute([
                    ':user_id' => $user_id,
                    ':pet_id' => $pet_id,
                    ':service_id' => $service_id,
                    ':scheduled_at' => $formatted_scheduled_at
                ]);

                $message = '<div class="alert alert-success">Agendamento cadastrado com sucesso!</div>';
                writeLog($pdo, "Agendamento Cadastrado", "Tutor ID: $user_id, Pet ID: $pet_id, Serviço ID: $service_id, Data/Hora: $formatted_scheduled_at", $_SESSION['user_id']);
                header("Location: agendamentos.php");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = '<div class="alert alert-danger">Erro ao cadastrar agendamento: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar edição de agendamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_agendamento' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);
    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
    $package_id = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT, ['options' => ['default' => null]]);
    $scheduled_at = trim($_POST['scheduled_at'] ?? '');

    if (empty($scheduled_at) || $service_id === false || $service_id === null || $user_id === false || $user_id === null || $pet_id === false || $pet_id === null) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios (tutor, pet, serviço e data/hora).</div>';
        writeLog($pdo, "Edição de Agendamento Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } elseif (strtotime($scheduled_at) <= time()) {
        $message = '<div class="alert alert-danger">A data deve ser futura.</div>';
        writeLog($pdo, "Edição de Agendamento Falhou", "Data não é futura", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o pet pertence ao tutor
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pets WHERE id = :pet_id AND user_id = :user_id");
            $stmt->execute([':pet_id' => $pet_id, ':user_id' => $user_id]);
            $pet_exists = $stmt->fetchColumn();

            if (!$pet_exists) {
                throw new Exception("O pet selecionado não pertence ao tutor informado.");
            }

            // Converter a data/hora para o formato do banco
            $scheduled_at_obj = new DateTime($scheduled_at);
            $formatted_scheduled_at = $scheduled_at_obj->format('Y-m-d H:i:s');

            // Atualizar o agendamento
            $stmt = $pdo->prepare("UPDATE appointments SET user_id = :user_id, pet_id = :pet_id, service_id = :service_id, package_id = :package_id, scheduled_at = :scheduled_at WHERE id = :id");
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $user_id,
                ':pet_id' => $pet_id,
                ':service_id' => $service_id,
                ':package_id' => $package_id,
                ':scheduled_at' => $formatted_scheduled_at
            ]);

            $message = '<div class="alert alert-success">Agendamento atualizado com sucesso!</div>';
            writeLog($pdo, "Agendamento Atualizado", "ID: $id, Tutor ID: $user_id, Pet ID: $pet_id, Serviço ID: $service_id", $_SESSION['user_id']);
            header("Location: agendamentos.php");
            exit;
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">Erro ao atualizar agendamento: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Atualizar Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar rejeição de agendamento (mudar status para REJECTED)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_agendamento' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $agendamento_id = filter_input(INPUT_POST, 'agendamento_id', FILTER_VALIDATE_INT);
    if ($agendamento_id === false || $agendamento_id === null) {
        $message = '<div class="alert alert-danger">ID de agendamento inválido.</div>';
        writeLog($pdo, "Rejeição de Agendamento Falhou", "ID de agendamento inválido", $_SESSION['user_id']);
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'REJECTED' WHERE id = :id");
            $stmt->execute([':id' => $agendamento_id]);
            $message = '<div class="alert alert-success">Agendamento rejeitado com sucesso!</div>';
            writeLog($pdo, "Agendamento Rejeitado", "Agendamento ID: $agendamento_id", $_SESSION['user_id']);
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao rejeitar agendamento: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Rejeitar Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar conclusão de agendamento (mudar status para COMPLETED)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_agendamento' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $agendamento_id = filter_input(INPUT_POST, 'agendamento_id', FILTER_VALIDATE_INT);
    if ($agendamento_id === false || $agendamento_id === null) {
        $message = '<div class="alert alert-danger">ID de agendamento inválido.</div>';
        writeLog($pdo, "Conclusão de Agendamento Falhou", "ID de agendamento inválido", $_SESSION['user_id']);
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'COMPLETED' WHERE id = :id");
            $stmt->execute([':id' => $agendamento_id]);
            $message = '<div class="alert alert-success">Agendamento marcado como concluído com sucesso!</div>';
            writeLog($pdo, "Agendamento Concluído", "Agendamento ID: $agendamento_id", $_SESSION['user_id']);
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao marcar agendamento como concluído: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Concluir Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar exclusão de agendamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_agendamento' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $agendamento_id = filter_input(INPUT_POST, 'agendamento_id', FILTER_VALIDATE_INT);
    if ($agendamento_id === false || $agendamento_id === null) {
        $message = '<div class="alert alert-danger">ID de agendamento inválido.</div>';
        writeLog($pdo, "Exclusão de Agendamento Falhou", "ID de agendamento inválido", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o agendamento usou um pacote promocional
            $stmt = $pdo->prepare("SELECT user_id, service_id, package_id FROM appointments WHERE id = :id");
            $stmt->execute([':id' => $agendamento_id]);
            $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($agendamento && $agendamento['package_id'] !== null) {
                // Iniciar transação
                $pdo->beginTransaction();

                // Excluir o agendamento
                $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = :id");
                $stmt->execute([':id' => $agendamento_id]);

                // Restaurar a quantidade no pacote promocional para o tutor
                $stmt = $pdo->prepare("
                    UPDATE service_package_usage 
                    SET remaining_quantity = remaining_quantity + 1 
                    WHERE package_id = :package_id AND user_id = :user_id AND service_id = :service_id
                ");
                $stmt->execute([
                    ':package_id' => $agendamento['package_id'],
                    ':user_id' => $agendamento['user_id'],
                    ':service_id' => $agendamento['service_id']
                ]);

                // Commit da transação
                $pdo->commit();

                $message = '<div class="alert alert-success">Agendamento excluído com sucesso e quantidade restaurada no pacote!</div>';
                writeLog($pdo, "Agendamento Excluído", "Agendamento ID: $agendamento_id, Pacote ID: {$agendamento['package_id']}", $_SESSION['user_id']);
            } else {
                // Excluir o agendamento sem pacote
                $stmt = $pdo->prepare("DELETE FROM appointments WHERE id = :id");
                $stmt->execute([':id' => $agendamento_id]);

                $message = '<div class="alert alert-success">Agendamento excluído com sucesso!</div>';
                writeLog($pdo, "Agendamento Excluído", "Agendamento ID: $agendamento_id", $_SESSION['user_id']);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao excluir agendamento: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Excluir Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Agendamentos - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">Gerenciar Agendamentos - Petshop</h1>

        <div class="text-end mb-3">
            <a href="services.php" class="btn btn-primary">Gerenciar Serviços</a>
            <a href="index.php" class="btn btn-secondary">Sair</a>
        </div>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Formulário de Cadastro -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Cadastrar Novo Agendamento</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_agendamento">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="user_id" class="form-label">Tutor *</label>
                        <select class="form-control" id="user_id" name="user_id" required onchange="updatePetOptions(this.value); updatePackageOptions(this.value)">
                            <option value="">Selecione um tutor</option>
                            <?php foreach ($tutors as $tutor): ?>
                                <option value="<?php echo htmlspecialchars($tutor['id']); ?>">
                                    <?php echo htmlspecialchars($tutor['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="pet_id" class="form-label">Pet *</label>
                        <select class="form-control" id="pet_id" name="pet_id" required>
                            <option value="">Selecione um pet</option>
                            <!-- Opções preenchidas via JavaScript -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="service_id" class="form-label">Serviço *</label>
                        <select class="form-control" id="service_id" name="service_id" required>
                            <option value="">Selecione um serviço</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo htmlspecialchars($service['id']); ?>">
                                    <?php echo htmlspecialchars($service['name']) . ' (R$ ' . number_format($service['value'], 2, ',', '.') . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="package_id" class="form-label">Pacote Promocional (opcional)</label>
                        <select class="form-control" id="package_id" name="package_id">
                            <option value="">Nenhum pacote</option>
                            <!-- Opções preenchidas via JavaScript -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="scheduled_at" class="form-label">Data e Hora *</label>
                        <input type="datetime-local" class="form-control" id="scheduled_at" name="scheduled_at" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Cadastrar Agendamento</button>
                </form>
            </div>
        </div>

        <!-- Formulário de Edição (exibido se um agendamento estiver sendo editado) -->
        <?php if ($edit_agendamento): ?>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Editar Agendamento</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="edit_agendamento">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_agendamento['id']); ?>">
                        <div class="mb-3">
                            <label for="user_id_edit" class="form-label">Tutor *</label>
                            <select class="form-control" id="user_id_edit" name="user_id" required onchange="updatePetOptions(this.value); updatePackageOptions(this.value)">
                                <option value="">Selecione um tutor</option>
                                <?php foreach ($tutors as $tutor): ?>
                                    <option value="<?php echo htmlspecialchars($tutor['id']); ?>" <?php echo $tutor['id'] == $edit_agendamento['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tutor['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="pet_id_edit" class="form-label">Pet *</label>
                            <select class="form-control" id="pet_id_edit" name="pet_id" required>
                                <option value="">Selecione um pet</option>
                                <!-- Opções preenchidas via JavaScript -->
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="service_id_edit" class="form-label">Serviço *</label>
                            <select class="form-control" id="service_id_edit" name="service_id" required>
                                <option value="">Selecione um serviço</option>
                                <?php foreach ($services as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['id']); ?>" <?php echo $service['id'] == $edit_agendamento['service_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($service['name']) . ' (R$ ' . number_format($service['value'], 2, ',', '.') . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="package_id_edit" class="form-label">Pacote Promocional (opcional)</label>
                            <select class="form-control" id="package_id_edit" name="package_id">
                                <option value="">Nenhum pacote</option>
                                <!-- Opções preenchidas via JavaScript -->
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="scheduled_at_edit" class="form-label">Data e Hora *</label>
                            <input type="datetime-local" class="form-control" id="scheduled_at_edit" name="scheduled_at" value="<?php echo date('Y-m-d\TH:i', strtotime($edit_agendamento['scheduled_at'])); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                        <a href="agendamentos.php" class="btn btn-secondary">Cancelar</a>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tabela de Agendamentos -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Agendamentos</h5>
            </div>
            <div class="card-body">
                <?php if (empty($agendamentos)): ?>
                    <div class="alert alert-info">Nenhum agendamento encontrado.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tutor</th>
                                    <th>Pet</th>
                                    <th>Serviço</th>
                                    <th>Pacote</th>
                                    <th>Data e Hora</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($agendamentos as $agendamento): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($agendamento['id']); ?></td>
                                        <td><?php echo htmlspecialchars($agendamento['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($agendamento['pet_name']); ?></td>
                                        <td><?php echo htmlspecialchars($agendamento['service_name']); ?></td>
                                        <td><?php echo $agendamento['package_name'] ? htmlspecialchars($agendamento['package_name']) : 'N/A'; ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($agendamento['scheduled_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($agendamento['status']); ?></td>
                                        <td>
                                            <a href="?edit=<?php echo $agendamento['id']; ?>" class="btn btn-primary btn-sm">Editar</a>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja rejeitar este agendamento?');">
                                                <input type="hidden" name="action" value="reject_agendamento">
                                                <input type="hidden" name="agendamento_id" value="<?php echo $agendamento['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm">Rejeitar</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja marcar este agendamento como concluído?');">
                                                <input type="hidden" name="action" value="complete_agendamento">
                                                <input type="hidden" name="agendamento_id" value="<?php echo $agendamento['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm">Concluído</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja excluir este agendamento?');">
                                                <input type="hidden" name="action" value="delete_agendamento">
                                                <input type="hidden" name="agendamento_id" value="<?php echo $agendamento['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updatePetOptions(userId) {
            const petSelect = document.getElementById('pet_id');
            const petSelectEdit = document.getElementById('pet_id_edit');
            const targetSelect = petSelectEdit && document.activeElement.id === 'user_id_edit' ? petSelectEdit : petSelect;
            targetSelect.innerHTML = '<option value="">Selecione um pet</option>';

            if (userId) {
                fetch('get_user_pets.php?user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        data.forEach(pet => {
                            const option = document.createElement('option');
                            option.value = pet.id;
                            option.textContent = pet.name;
                            targetSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Erro ao carregar pets:', error);
                    });
            }
        }

        function updatePackageOptions(userId) {
            const packageSelect = document.getElementById('package_id');
            const packageSelectEdit = document.getElementById('package_id_edit');
            const targetSelect = packageSelectEdit && document.activeElement.id === 'user_id_edit' ? packageSelectEdit : packageSelect;
            targetSelect.innerHTML = '<option value="">Nenhum pacote</option>';

            if (userId) {
                fetch('get_user_packages.php?user_id=' + userId)
                    .then(response => response.json())
                    .then(data => {
                        data.forEach(package => {
                            const option = document.createElement('option');
                            option.value = package.id;
                            option.textContent = `Pacote #${package.id} - ${package.name} (R$ ${parseFloat(package.promotional_price).toFixed(2)})`;
                            targetSelect.appendChild(option);
                        });
                    })
                    .catch(error => {
                        console.error('Erro ao carregar pacotes:', error);
                    });
            }
        }

        // Preencher pets e pacotes ao carregar a página de edição
        <?php if ($edit_agendamento): ?>
            document.addEventListener('DOMContentLoaded', function() {
                updatePetOptions(<?php echo $edit_agendamento['user_id']; ?>);
                updatePackageOptions(<?php echo $edit_agendamento['user_id']; ?>);
                document.getElementById('pet_id_edit').value = <?php echo $edit_agendamento['pet_id']; ?>;
                document.getElementById('package_id_edit').value = '<?php echo $edit_agendamento['package_id'] ?: ''; ?>';
            });
        <?php endif; ?>
    </script>
</body>
</html>