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
    header("Location: index.php");
    exit;
}

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

// Listar pacotes promocionais e seus itens
$packages = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, promotional_price FROM service_packages ORDER BY name");
    $stmt->execute();
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packages as $key => $package) {
        // Buscar os tutores associados ao pacote
        $stmt = $pdo->prepare("
            SELECT u.id, u.name 
            FROM package_tutors pt 
            JOIN users u ON pt.user_id = u.id 
            WHERE pt.package_id = :package_id 
            ORDER BY u.name
        ");
        $stmt->execute([':package_id' => $package['id']]);
        $tutors_for_package = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $packages[$key]['tutors'] = $tutors_for_package;

        // Buscar os serviços associados e suas quantidades iniciais e restantes por tutor
        $stmt = $pdo->prepare("
            SELECT spi.quantity AS initial_quantity, spu.user_id, spu.remaining_quantity, s.id AS service_id, s.name AS service_name, s.value 
            FROM service_package_items spi 
            JOIN services s ON spi.service_id = s.id 
            LEFT JOIN service_package_usage spu ON spu.package_id = spi.package_id AND spu.service_id = spi.service_id 
            WHERE spi.package_id = :package_id
        ");
        $stmt->execute([':package_id' => $package['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organizar os itens por serviço e tutor
        $organized_items = [];
        foreach ($items as $item) {
            $service_id = $item['service_id'];
            if (!isset($organized_items[$service_id])) {
                $organized_items[$service_id] = [
                    'name' => $item['service_name'],
                    'value' => $item['value'],
                    'initial_quantity' => $item['initial_quantity'],
                    'tutors' => []
                ];
            }
            if ($item['user_id']) {
                $organized_items[$service_id]['tutors'][$item['user_id']] = [
                    'remaining_quantity' => $item['remaining_quantity']
                ];
            }
        }
        $packages[$key]['items'] = $organized_items;
    }
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar pacotes: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Pacotes", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Processar exclusão de pacote promocional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_package' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $package_id = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);
    if ($package_id === false || $package_id === null) {
        $message = '<div class="alert alert-danger">ID de pacote inválido.</div>';
        writeLog($pdo, "Exclusão de Pacote Falhou", "ID de pacote inválido", $_SESSION['user_id']);
    } else {
        try {
            // Iniciar transação
            $pdo->beginTransaction();

            // Excluir os registros de uso do pacote
            $stmt = $pdo->prepare("DELETE FROM service_package_usage WHERE package_id = :id");
            $stmt->execute([':id' => $package_id]);

            // Excluir os itens associados ao pacote
            $stmt = $pdo->prepare("DELETE FROM service_package_items WHERE package_id = :id");
            $stmt->execute([':id' => $package_id]);

            // Excluir as associações de tutores
            $stmt = $pdo->prepare("DELETE FROM package_tutors WHERE package_id = :id");
            $stmt->execute([':id' => $package_id]);

            // Excluir o pacote
            $stmt = $pdo->prepare("DELETE FROM service_packages WHERE id = :id");
            $stmt->execute([':id' => $package_id]);

            // Commit da transação
            $pdo->commit();

            $message = '<div class="alert alert-success">Pacote promocional excluído com sucesso!</div>';
            writeLog($pdo, "Pacote Excluído", "Pacote ID: $package_id", $_SESSION['user_id']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">Erro ao excluir pacote: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Excluir Pacote", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar cadastro de serviço
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_service' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $name = trim($_POST['name'] ?? '');
    $value = filter_input(INPUT_POST, 'value', FILTER_VALIDATE_FLOAT);

    if (empty($name) || $value === false || $value <= 0) {
        $message = '<div class="alert alert-danger">Preencha todos os campos com valores válidos.</div>';
        writeLog($pdo, "Cadastro de Serviço Falhou", "Campos inválidos", $_SESSION['user_id']);
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO services (name, value) VALUES (:name, :value)");
            $stmt->execute([
                ':name' => $name,
                ':value' => $value
            ]);
            $message = '<div class="alert alert-success">Serviço cadastrado com sucesso!</div>';
            writeLog($pdo, "Serviço Cadastrado", "Serviço: $name, Valor: $value", $_SESSION['user_id']);
            header("Location: services.php");
            exit;
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao cadastrar serviço: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Serviço", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar cadastro de pacote promocional (via modal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_package' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $name = trim($_POST['name'] ?? '');
    $promotional_price = filter_input(INPUT_POST, 'promotional_price', FILTER_VALIDATE_FLOAT);
    $selected_tutors = isset($_POST['tutors']) && is_array($_POST['tutors']) ? array_keys(array_filter($_POST['tutors'])) : [];
    $selected_services = isset($_POST['services']) && is_array($_POST['services']) ? $_POST['services'] : [];
    $quantities = isset($_POST['quantities']) && is_array($_POST['quantities']) ? $_POST['quantities'] : [];
    $quantities_hidden = isset($_POST['quantities_hidden']) && is_array($_POST['quantities_hidden']) ? $_POST['quantities_hidden'] : [];

    // Validar os campos
    if (empty($name) || $promotional_price === false || $promotional_price <= 0) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios (nome e preço promocional).</div>';
        writeLog($pdo, "Cadastro de Pacote Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } elseif (empty($selected_tutors)) {
        $message = '<div class="alert alert-danger">Selecione pelo menos um tutor para associar ao pacote promocional.</div>';
        writeLog($pdo, "Cadastro de Pacote Falhou", "Nenhum tutor selecionado", $_SESSION['user_id']);
    } elseif (empty($selected_services)) {
        $message = '<div class="alert alert-danger">Selecione pelo menos um serviço para criar o pacote promocional.</div>';
        writeLog($pdo, "Cadastro de Pacote Falhou", "Nenhum serviço selecionado", $_SESSION['user_id']);
    } else {
        try {
            // Verificar quantidades antes de iniciar a transação
            $invalid_quantities = [];
            foreach ($selected_services as $service_id => $selected) {
                if ($selected) {
                    $quantity = isset($quantities[$service_id]) ? filter_var($quantities[$service_id], FILTER_VALIDATE_INT) : null;
                    if ($quantity === false || $quantity === null) {
                        $quantity = isset($quantities_hidden[$service_id]) ? filter_var($quantities_hidden[$service_id], FILTER_VALIDATE_INT) : 0;
                    }
                    if ($quantity === false || $quantity <= 0) {
                        $stmt = $pdo->prepare("SELECT name FROM services WHERE id = :id");
                        $stmt->execute([':id' => $service_id]);
                        $service_name = $stmt->fetchColumn() ?: "ID $service_id";
                        $invalid_quantities[] = $service_name;
                    }
                }
            }

            if (!empty($invalid_quantities)) {
                $message = '<div class="alert alert-danger">Quantidade inválida para os serviços: ' . implode(', ', $invalid_quantities) . '. A quantidade deve ser um número inteiro maior que 0.</div>';
                writeLog($pdo, "Cadastro de Pacote Falhou", "Quantidades inválidas: " . implode(', ', $invalid_quantities), $_SESSION['user_id']);
            } else {
                // Iniciar transação
                $pdo->beginTransaction();

                // Inserir o pacote promocional (sem user_id)
                $stmt = $pdo->prepare("INSERT INTO service_packages (name, promotional_price) VALUES (:name, :promotional_price)");
                $stmt->execute([
                    ':name' => $name,
                    ':promotional_price' => $promotional_price
                ]);
                $package_id = $pdo->lastInsertId();

                // Inserir os tutores associados na tabela package_tutors
                $stmt_tutors = $pdo->prepare("INSERT INTO package_tutors (package_id, user_id) VALUES (:package_id, :user_id)");
                foreach ($selected_tutors as $tutor_id) {
                    $stmt_tutors->execute([
                        ':package_id' => $package_id,
                        ':user_id' => $tutor_id
                    ]);
                }

                // Inserir os serviços associados na tabela service_package_items
                $stmt_items = $pdo->prepare("INSERT INTO service_package_items (package_id, service_id, quantity) VALUES (:package_id, :service_id, :quantity)");
                $stmt_usage = $pdo->prepare("INSERT INTO service_package_usage (package_id, user_id, service_id, initial_quantity, remaining_quantity) VALUES (:package_id, :user_id, :service_id, :initial_quantity, :remaining_quantity)");
                
                foreach ($selected_services as $service_id => $selected) {
                    if ($selected) {
                        $quantity = isset($quantities[$service_id]) ? filter_var($quantities[$service_id], FILTER_VALIDATE_INT) : null;
                        if ($quantity === false || $quantity === null) {
                            $quantity = isset($quantities_hidden[$service_id]) ? filter_var($quantities_hidden[$service_id], FILTER_VALIDATE_INT) : 1;
                        }
                        // Inserir em service_package_items
                        $stmt_items->execute([
                            ':package_id' => $package_id,
                            ':service_id' => $service_id,
                            ':quantity' => $quantity
                        ]);
                        // Inserir em service_package_usage para cada tutor selecionado
                        foreach ($selected_tutors as $tutor_id) {
                            $stmt_usage->execute([
                                ':package_id' => $package_id,
                                ':user_id' => $tutor_id,
                                ':service_id' => $service_id,
                                ':initial_quantity' => $quantity,
                                ':remaining_quantity' => $quantity
                            ]);
                        }
                    }
                }

                // Commit da transação
                $pdo->commit();

                $message = '<div class="alert alert-success">Pacote promocional cadastrado com sucesso!</div>';
                writeLog($pdo, "Pacote Cadastrado", "Pacote: $name, Preço Promocional: $promotional_price, Tutores: " . implode(', ', $selected_tutors), $_SESSION['user_id']);
                header("Location: services.php");
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">Erro ao cadastrar pacote: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Pacote", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Serviços - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">Gerenciar Serviços - Petshop</h1>

        <div class="text-end mb-3">
            <a href="agendamentos.php" class="btn btn-primary">Gerenciar Agendamentos</a>
            <a href="index.php" class="btn btn-secondary">Sair</a>
        </div>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Cadastrar Novo Serviço</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_service">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome do Serviço *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="value" class="form-label">Valor do Serviço (R$)*</label>
                        <input type="number" step="0.01" class="form-control" id="value" name="value" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Cadastrar Serviço</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Serviços Cadastrados</h5>
            </div>
            <div class="card-body">
                <div class="text-end mb-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#packageModal">Novo Pacote Promocional</button>
                </div>
                <?php if (empty($services)): ?>
                    <div class="alert alert-info">Nenhum serviço cadastrado.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nome</th>
                                    <th>Valor (R$)</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($services as $service): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($service['id']); ?></td>
                                        <td><?php echo htmlspecialchars($service['name']); ?></td>
                                        <td><?php echo number_format($service['value'], 2, ',', '.'); ?></td>
                                        <td>
                                            <a href="edit_service.php?id=<?php echo $service['id']; ?>" class="btn btn-warning btn-sm me-1">Editar</a>
                                            <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja excluir este serviço?');">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
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


<!--Pacotes Promocionais Cadastrados -->
<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Pacotes Promocionais Cadastrados</h5>
    </div>
    <div class="card-body">
        <?php if (empty($packages)): ?>
            <div class="alert alert-info">Nenhum pacote promocional cadastrado.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Tutores</th>
                            <th>Preço Promocional (R$)</th>
                            <th>Serviços Incluídos</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $package): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($package['id']); ?></td>
                                <td><?php echo htmlspecialchars($package['name']); ?></td>
                                <td>
                                    <?php if (!empty($package['tutors'])): ?>
                                        <ul>
                                            <?php foreach ($package['tutors'] as $tutor): ?>
                                                <li><?php echo htmlspecialchars($tutor['name']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        Nenhum tutor associado.
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($package['promotional_price'], 2, ',', '.'); ?></td>
                                <td>
                                    <?php if (!empty($package['items'])): ?>
                                        <ul>
                                            <?php foreach ($package['items'] as $service_id => $item): ?>
                                                <li>
                                                    <?php echo htmlspecialchars($item['name']) . ' (R$ ' . number_format($item['value'], 2, ',', '.') . ')'; ?>
                                                    <ul>
                                                        <?php foreach ($package['tutors'] as $tutor): ?>
                                                            <?php
                                                            $remaining = isset($item['tutors'][$tutor['id']]) ? $item['tutors'][$tutor['id']]['remaining_quantity'] : $item['initial_quantity'];
                                                            ?>
                                                            <li>
                                                                <?php echo htmlspecialchars($tutor['name']) . ': Inicial: ' . $item['initial_quantity'] . ', Restante: ' . $remaining; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        Nenhum serviço associado.
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit_package.php?id=<?php echo $package['id']; ?>" class="btn btn-warning btn-sm me-1">Editar</a>
                                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja excluir este pacote promocional?');">
                                        <input type="hidden" name="action" value="delete_package">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
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

    <!-- Modal para novo pacote promocional -->
    <div class="modal fade" id="packageModal" tabindex="-1" aria-labelledby="packageModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="packageModalLabel">Cadastrar Novo Pacote Promocional</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" id="packageForm">
                        <input type="hidden" name="action" value="add_package">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3">
                            <label for="package_name" class="form-label">Nome do Pacote *</label>
                            <input type="text" class="form-control" id="package_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Tutores * (selecione pelo menos um)</label>
                            <?php foreach ($tutors as $tutor): ?>
                                <div class="form-check">
                                    <input class="form-check-input tutor-checkbox" type="checkbox" name="tutors[<?php echo $tutor['id']; ?>]" id="tutor_<?php echo $tutor['id']; ?>" value="1">
                                    <label class="form-check-label" for="tutor_<?php echo $tutor['id']; ?>">
                                        <?php echo htmlspecialchars($tutor['name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label for="promotional_price" class="form-label">Preço Promocional (R$)*</label>
                            <input type="number" step="0.01" class="form-control" id="promotional_price" name="promotional_price" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Serviços Incluídos (selecione pelo menos um)*</label>
                            <?php foreach ($services as $service): ?>
                                <div class="form-check">
                                    <input class="form-check-input service-checkbox" type="checkbox" name="services[<?php echo $service['id']; ?>]" id="service_<?php echo $service['id']; ?>" value="1">
                                    <label class="form-check-label" for="service_<?php echo $service['id']; ?>">
                                        <?php echo htmlspecialchars($service['name']) . ' (R$ ' . number_format($service['value'], 2, ',', '.') . ')'; ?>
                                    </label>
                                    <input type="number" class="form-control mt-1 quantity-input" name="quantities[<?php echo $service['id']; ?>]" placeholder="Quantidade" min="1" style="display: none;" id="quantity_<?php echo $service['id']; ?>">
                                    <input type="hidden" name="quantities_hidden[<?php echo $service['id']; ?>]" value="0" id="quantity_hidden_<?php echo $service['id']; ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary" form="packageForm">Cadastrar Pacote Promocional</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Mostrar/esconder o campo de quantidade ao selecionar/desmarcar um serviço
    document.querySelectorAll('.service-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            const serviceId = this.id.split('_')[1];
            const quantityInput = document.getElementById('quantity_' + serviceId);
            const quantityHidden = document.getElementById('quantity_hidden_' + serviceId);

            if (this.checked) {
                quantityInput.style.display = 'block';
                quantityInput.required = true;
                quantityInput.value = quantityInput.value || '1';
                quantityHidden.value = quantityInput.value;
            } else {
                quantityInput.style.display = 'none';
                quantityInput.required = false;
                quantityInput.value = '';
                quantityHidden.value = '0';
            }
        });
    });

    // Sincronizar o campo visível com o campo oculto ao alterar a quantidade
    document.querySelectorAll('.quantity-input').forEach(function(input) {
        input.addEventListener('input', function() {
            const serviceId = this.id.split('_')[1];
            const quantityHidden = document.getElementById('quantity_hidden_' + serviceId);
            quantityHidden.value = this.value || '0';
        });
    });

    // Validar se pelo menos um tutor e um serviço foram selecionados e se as quantidades são válidas
    document.getElementById('packageForm').addEventListener('submit', function(event) {
        const selectedTutors = document.querySelectorAll('input[name^="tutors"]:checked');
        const selectedServices = document.querySelectorAll('input[name^="services"]:checked');

        if (selectedTutors.length === 0) {
            event.preventDefault();
            alert('Selecione pelo menos um tutor para associar ao pacote promocional.');
            return;
        }

        if (selectedServices.length === 0) {
            event.preventDefault();
            alert('Selecione pelo menos um serviço para criar o pacote promocional.');
            return;
        }

        let hasInvalidQuantity = false;
        selectedServices.forEach(function(checkbox) {
            const serviceId = checkbox.id.split('_')[1];
            const quantityInput = document.getElementById('quantity_' + serviceId);
            const quantity = parseInt(quantityInput.value);
            if (!quantity || quantity <= 0) {
                hasInvalidQuantity = true;
                quantityInput.classList.add('is-invalid');
            } else {
                quantityInput.classList.remove('is-invalid');
            }
        });

        if (hasInvalidQuantity) {
            event.preventDefault();
            alert('Por favor, informe uma quantidade válida (maior que 0) para todos os serviços selecionados.');
        }
    });
</script>
</body>
</html>