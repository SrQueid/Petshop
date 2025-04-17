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

// Obter o ID do pacote a ser editado
$package_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($package_id === false || $package_id === null) {
    header("Location: services.php");
    exit;
}

// Carregar os dados do pacote
$package = null;
$package_items = [];
$package_tutors = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, promotional_price FROM service_packages WHERE id = :id");
    $stmt->execute([':id' => $package_id]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$package) {
        header("Location: services.php");
        exit;
    }

    // Carregar os itens do pacote
    $stmt = $pdo->prepare("SELECT service_id, quantity FROM service_package_items WHERE package_id = :package_id");
    $stmt->execute([':package_id' => $package_id]);
    $package_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Carregar os tutores associados ao pacote
    $stmt = $pdo->prepare("SELECT user_id FROM package_tutors WHERE package_id = :package_id");
    $stmt->execute([':package_id' => $package_id]);
    $package_tutors = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao carregar pacote: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Carregar Pacote", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
}

// Listar serviços disponíveis
$services = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, value FROM services ORDER BY name");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar serviços: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Serviços", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
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

// Processar edição de pacote promocional
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_package' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $name = trim($_POST['name'] ?? '');
    $promotional_price = filter_input(INPUT_POST, 'promotional_price', FILTER_VALIDATE_FLOAT);
    $selected_tutors = isset($_POST['tutors']) && is_array($_POST['tutors']) ? array_keys(array_filter($_POST['tutors'])) : [];
    $selected_services = isset($_POST['services']) && is_array($_POST['services']) ? $_POST['services'] : [];
    $quantities = isset($_POST['quantities']) && is_array($_POST['quantities']) ? $_POST['quantities'] : [];
    $quantities_hidden = isset($_POST['quantities_hidden']) && is_array($_POST['quantities_hidden']) ? $_POST['quantities_hidden'] : [];

    // Validar os campos
    if (empty($name) || $promotional_price === false || $promotional_price <= 0) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios (nome e preço promocional).</div>';
        writeLog($pdo, "Edição de Pacote Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } elseif (empty($selected_tutors)) {
        $message = '<div class="alert alert-danger">Selecione pelo menos um tutor para associar ao pacote promocional.</div>';
        writeLog($pdo, "Edição de Pacote Falhou", "Nenhum tutor selecionado", $_SESSION['user_id']);
    } elseif (empty($selected_services)) {
        $message = '<div class="alert alert-danger">Selecione pelo menos um serviço para o pacote promocional.</div>';
        writeLog($pdo, "Edição de Pacote Falhou", "Nenhum serviço selecionado", $_SESSION['user_id']);
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
                writeLog($pdo, "Edição de Pacote Falhou", "Quantidades inválidas: " . implode(', ', $invalid_quantities), $_SESSION['user_id']);
            } else {
                // Iniciar transação
                $pdo->beginTransaction();

                // Atualizar o pacote promocional
                $stmt = $pdo->prepare("UPDATE service_packages SET name = :name, promotional_price = :promotional_price WHERE id = :id");
                $stmt->execute([
                    ':name' => $name,
                    ':promotional_price' => $promotional_price,
                    ':id' => $package_id
                ]);

                // Excluir os itens atuais do pacote
                $stmt = $pdo->prepare("DELETE FROM service_package_items WHERE package_id = :package_id");
                $stmt->execute([':package_id' => $package_id]);

                // Excluir os registros atuais de uso
                $stmt = $pdo->prepare("DELETE FROM service_package_usage WHERE package_id = :package_id");
                $stmt->execute([':package_id' => $package_id]);

                // Excluir as associações atuais de tutores
                $stmt = $pdo->prepare("DELETE FROM package_tutors WHERE package_id = :package_id");
                $stmt->execute([':package_id' => $package_id]);

                // Inserir os novos tutores associados
                $stmt_tutors = $pdo->prepare("INSERT INTO package_tutors (package_id, user_id) VALUES (:package_id, :user_id)");
                foreach ($selected_tutors as $tutor_id) {
                    $stmt_tutors->execute([
                        ':package_id' => $package_id,
                        ':user_id' => $tutor_id
                    ]);
                }

                // Inserir os novos serviços associados
                $stmt_items = $pdo->prepare("INSERT INTO service_package_items (package_id, service_id, quantity) VALUES (:package_id, :service_id, :quantity)");
                $stmt_usage = $pdo->prepare("INSERT INTO service_package_usage (package_id, user_id, service_id, initial_quantity, remaining_quantity) VALUES (:package_id, :user_id, :service_id, :initial_quantity, :remaining_quantity)");
                foreach ($selected_services as $service_id => $selected) {
                    if ($selected) {
                        $quantity = isset($quantities[$service_id]) ? filter_var($quantities[$service_id], FILTER_VALIDATE_INT) : null;
                        if ($quantity === false || $quantity === null) {
                            $quantity = isset($quantities_hidden[$service_id]) ? filter_var($quantities_hidden[$service_id], FILTER_VALIDATE_INT) : 1;
                        }
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

                $message = '<div class="alert alert-success">Pacote promocional atualizado com sucesso!</div>';
                writeLog($pdo, "Pacote Atualizado", "Pacote ID: $package_id, Nome: $name, Preço Promocional: $promotional_price, Tutores: " . implode(', ', $selected_tutors), $_SESSION['user_id']);
                header("Location: services.php");
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '<div class="alert alert-danger">Erro ao atualizar pacote: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Atualizar Pacote", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Pacote Promocional - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">Editar Pacote Promocional - Petshop</h1>

        <div class="text-end mb-3">
            <a href="services.php" class="btn btn-secondary">Voltar</a>
        </div>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Editar Pacote Promocional</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="edit_package">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nome do Pacote *</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($package['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tutores * (selecione pelo menos um)</label>
                        <?php foreach ($tutors as $tutor): ?>
                            <div class="form-check">
                                <input class="form-check-input tutor-checkbox" type="checkbox" name="tutors[<?php echo $tutor['id']; ?>]" id="tutor_<?php echo $tutor['id']; ?>" value="1" <?php echo in_array($tutor['id'], $package_tutors) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="tutor_<?php echo $tutor['id']; ?>">
                                    <?php echo htmlspecialchars($tutor['name']); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label for="promotional_price" class="form-label">Preço Promocional (R$)*</label>
                        <input type="number" step="0.01" class="form-control" id="promotional_price" name="promotional_price" value="<?php echo htmlspecialchars($package['promotional_price']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Serviços Incluídos (selecione pelo menos um)*</label>
                        <?php foreach ($services as $service): ?>
                            <?php
                            $is_selected = false;
                            $quantity = 1;
                            foreach ($package_items as $item) {
                                if ($item['service_id'] == $service['id']) {
                                    $is_selected = true;
                                    $quantity = $item['quantity'];
                                    break;
                                }
                            }
                            ?>
                            <div class="form-check">
                                <input class="form-check-input service-checkbox" type="checkbox" name="services[<?php echo $service['id']; ?>]" id="service_<?php echo $service['id']; ?>" value="1" <?php echo $is_selected ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="service_<?php echo $service['id']; ?>">
                                    <?php echo htmlspecialchars($service['name']) . ' (R$ ' . number_format($service['value'], 2, ',', '.') . ')'; ?>
                                </label>
                                <input type="number" class="form-control mt-1 quantity-input" name="quantities[<?php echo $service['id']; ?>]" placeholder="Quantidade" min="1" value="<?php echo $is_selected ? $quantity : ''; ?>" style="display: <?php echo $is_selected ? 'block' : 'none'; ?>;" id="quantity_<?php echo $service['id']; ?>">
                                <input type="hidden" name="quantities_hidden[<?php echo $service['id']; ?>]" value="0" id="quantity_hidden_<?php echo $service['id']; ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                </form>
            </div>
        </div>
    </div>

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
        document.querySelector('form').addEventListener('submit', function(event) {
            const selectedTutors = document.querySelectorAll('input[name^="tutors"]:checked');
            const selectedServices = document.querySelectorAll('input[name^="services"]:checked');

            if (selectedTutors.length === 0) {
                event.preventDefault();
                alert('Selecione pelo menos um tutor para associar ao pacote promocional.');
                return;
            }

            if (selectedServices.length === 0) {
                event.preventDefault();
                alert('Selecione pelo menos um serviço para o pacote promocional.');
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