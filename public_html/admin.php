<?php
session_start();

// Verifica se o usuário está logado e é administrador
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
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

// Processar ações de confirmação, cancelamento e marcação como concluído
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $appointment_id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if ($appointment_id === false || $appointment_id === null) {
        $message = '<div class="alert alert-danger">ID de agendamento inválido.</div>';
        writeLog($pdo, "Ação Falhou", "ID de agendamento inválido", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o agendamento existe
            $stmt = $pdo->prepare("SELECT status FROM appointments WHERE id = :id");
            $stmt->execute([':id' => $appointment_id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) {
                $message = '<div class="alert alert-danger">Agendamento não encontrado.</div>';
                writeLog($pdo, "Ação Falhou", "Agendamento não encontrado: ID $appointment_id", $_SESSION['user_id']);
            } else {
                $new_status = '';
                $log_action = '';

                if ($action === 'confirm_appointment' && $appointment['status'] === 'Pendente') {
                    $new_status = 'Confirmado';
                    $log_action = 'Agendamento Confirmado';
                } elseif ($action === 'cancel_appointment' && in_array($appointment['status'], ['Pendente', 'Confirmado'])) {
                    $new_status = 'Cancelado';
                    $log_action = 'Agendamento Cancelado';
                } elseif ($action === 'complete_appointment' && $appointment['status'] === 'Confirmado') {
                    $new_status = 'Concluído';
                    $log_action = 'Agendamento Concluído';
                } else {
                    $message = '<div class="alert alert-danger">Ação inválida ou status do agendamento não permite essa operação.</div>';
                    writeLog($pdo, "Ação Falhou", "Ação inválida ou status inválido: ID $appointment_id, Ação: $action", $_SESSION['user_id']);
                }

                if ($new_status) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = :status WHERE id = :id");
                    $stmt->execute([':status' => $new_status, ':id' => $appointment_id]);

                    $message = '<div class="alert alert-success">Agendamento atualizado com sucesso!</div>';
                    writeLog($pdo, $log_action, "Agendamento ID: $appointment_id", $_SESSION['user_id']);
                }
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao atualizar agendamento: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Atualizar Agendamento", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Obter a data atual ou a data selecionada
$selected_date = $_POST['selected_date'] ?? date('Y-m-d');
$selected_date_formatted = date('d/m/Y', strtotime($selected_date));

// Listar agendamentos pendentes do dia
$pending_appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.pet_name, u.name as user_name 
        FROM appointments a 
        LEFT JOIN pets p ON a.pet_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'Pendente' AND DATE(a.scheduled_at) = :selected_date
        ORDER BY a.scheduled_at ASC
    ");
    $stmt->execute([':selected_date' => $selected_date]);
    $pending_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar agendamentos pendentes: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Agendamentos Pendentes", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar agendamentos confirmados do dia
$confirmed_appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.pet_name, u.name as user_name 
        FROM appointments a 
        LEFT JOIN pets p ON a.pet_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'Confirmado' AND DATE(a.scheduled_at) = :selected_date
        ORDER BY a.scheduled_at ASC
    ");
    $stmt->execute([':selected_date' => $selected_date]);
    $confirmed_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar agendamentos confirmados: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Agendamentos Confirmados", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar agendamentos cancelados do dia
$canceled_appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, p.pet_name, u.name as user_name 
        FROM appointments a 
        LEFT JOIN pets p ON a.pet_id = p.id 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.status = 'Cancelado' AND DATE(a.scheduled_at) = :selected_date
        ORDER BY a.scheduled_at ASC
    ");
    $stmt->execute([':selected_date' => $selected_date]);
    $canceled_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar agendamentos cancelados: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Agendamentos Cancelados", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar tutores para o filtro
$tutors = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users ORDER BY name");
    $stmt->execute();
    $tutors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar tutores: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Tutores", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Consultar agendamentos por data (filtro)
$filtered_appointments = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'filter_appointments') {
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? 'TODOS';
    $tutor_id = $_POST['tutor_id'] ?? 'TODOS';

    try {
        $query = "
            SELECT a.*, p.pet_name, u.name as user_name 
            FROM appointments a 
            LEFT JOIN pets p ON a.pet_id = p.id 
            LEFT JOIN users u ON a.user_id = u.id 
            WHERE 1=1
        ";
        $params = [];

        if ($start_date && $end_date) {
            $query .= " AND DATE(a.scheduled_at) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $start_date;
            $params[':end_date'] = $end_date;
        }

        if ($status !== 'TODOS') {
            $query .= " AND a.status = :status";
            $params[':status'] = $status;
        }

        if ($tutor_id !== 'TODOS') {
            $query .= " AND a.user_id = :tutor_id";
            $params[':tutor_id'] = $tutor_id;
        }

        $query .= " ORDER BY a.scheduled_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $filtered_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = '<div class="alert alert-danger">Erro ao consultar agendamentos: ' . $e->getMessage() . '</div>';
        writeLog($pdo, "Erro ao Consultar Agendamentos", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <!-- Incluir a navbar -->
    <?php include 'navbar.php'; ?>

    <div class="container mt-5">
        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Botões de Navegação -->
        <div class="mb-4">
            <a href="services.php" class="btn btn-primary">Gerenciar Serviços</a>
            <a href="logout.php" class="btn btn-danger">Sair</a>
            <a href="cadastrar_tutor.php" class="btn btn-success">Cadastrar Novo Tutor</a>
            <a href="agendamentos.php" class="btn btn-info">Agendar Serviço</a>
        </div>

        <!-- Agendamentos Pendentes -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Agendamentos Pendentes do Dia <?php echo $selected_date_formatted; ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="mb-3">
                    <div class="row">
                        <div class="col-md-3">
                            <label for="selected_date" class="form-label">Filtrar por Data</label>
                            <input type="date" class="form-control" id="selected_date" name="selected_date" value="<?php echo $selected_date; ?>" required>
                        </div>
                        <div class="col-md-2 align-self-end">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                        </div>
                    </div>
                </form>
                <?php if (empty($pending_appointments)): ?>
                    <div class="alert alert-info">Não há agendamentos pendentes para o intervalo selecionado.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Serviço</th>
                                    <th>Data e Hora</th>
                                    <th>Status</th>
                                    <th>Usuário</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_appointments as $appointment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appointment['pet_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['service']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($appointment['scheduled_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['user_name']); ?></td>
                                        <td>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja confirmar este agendamento?');">
                                                <input type="hidden" name="action" value="confirm_appointment">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm me-1">Confirmar</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja cancelar este agendamento?');">
                                                <input type="hidden" name="action" value="cancel_appointment">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Cancelar</button>
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

        <!-- Agendamentos Confirmados -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Agendamentos Aprovados do Dia <?php echo $selected_date_formatted; ?> - Marcar como Concluído</h5>
            </div>
            <div class="card-body">
                <?php if (empty($confirmed_appointments)): ?>
                    <div class="alert alert-info">Não há agendamentos aprovados.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Serviço</th>
                                    <th>Data e Hora</th>
                                    <th>Status</th>
                                    <th>Usuário</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($confirmed_appointments as $appointment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appointment['pet_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['service']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($appointment['scheduled_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['user_name']); ?></td>
                                        <td>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja marcar este agendamento como concluído?');">
                                                <input type="hidden" name="action" value="complete_appointment">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-primary btn-sm me-1">Marcar como Concluído</button>
                                            </form>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja cancelar este agendamento?');">
                                                <input type="hidden" name="action" value="cancel_appointment">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Cancelar</button>
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

        <!-- Agendamentos Cancelados -->
        <div class="card mb-4">
            <div class="card-header">
                <h5>Agendamentos Rejeitados do Dia <?php echo $selected_date_formatted; ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($canceled_appointments)): ?>
                    <div class="alert alert-info">Não há agendamentos rejeitados.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Serviço</th>
                                    <th>Data e Hora</th>
                                    <th>Status</th>
                                    <th>Usuário</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($canceled_appointments as $appointment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appointment['pet_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['service']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($appointment['scheduled_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['user_name']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Consultar Agendamentos por Data -->
        <div class="card">
            <div class="card-header">
                <h5>Consultar Agendamentos por Data</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="filter_appointments">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Intervalo de Datas</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">&nbsp;</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="TODOS">TODOS</option>
                                <option value="Pendente">Pendente</option>
                                <option value="Confirmado">Confirmado</option>
                                <option value="Cancelado">Cancelado</option>
                                <option value="Concluído">Concluído</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="tutor_id" class="form-label">Tutor</label>
                            <select class="form-select" id="tutor_id" name="tutor_id">
                                <option value="TODOS">TODOS</option>
                                <?php foreach ($tutors as $tutor): ?>
                                    <option value="<?php echo $tutor['id']; ?>"><?php echo htmlspecialchars($tutor['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Filtrar</button>
                    </div>
                </form>

                <?php if (!empty($filtered_appointments)): ?>
                    <div class="table-responsive mt-4">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Pet</th>
                                    <th>Serviço</th>
                                    <th>Data e Hora</th>
                                    <th>Status</th>
                                    <th>Usuário</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filtered_appointments as $appointment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($appointment['pet_name']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['service']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($appointment['scheduled_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['status']); ?></td>
                                        <td><?php echo htmlspecialchars($appointment['user_name']); ?></td>
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
    <script src="js/scripts.js"></script>
</body>
</html>