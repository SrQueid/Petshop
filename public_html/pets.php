<?php
// Habilitar exibição de erros para depuração
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Iniciar a sessão
session_start();

// Verificar se a sessão foi iniciada corretamente
if (session_status() !== PHP_SESSION_ACTIVE) {
    die("Erro: Não foi possível iniciar a sessão.");
}

// Include navbar
if (!file_exists('navbar.php')) {
    die("Erro: O arquivo navbar.php não foi encontrado.");
}
include 'navbar.php';

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Incluir o arquivo de conexão com o banco de dados
if (!file_exists('config/db_connect.php')) {
    die("Erro: O arquivo config/db_connect.php não foi encontrado.");
}
require_once 'config/db_connect.php';

// Verificar se a conexão com o banco de dados foi estabelecida
if (!isset($pdo)) {
    die("Erro: Não foi possível conectar ao banco de dados. Verifique as configurações em config/db_connect.php.");
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

// Gerar token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';

// Processar cadastro de pet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pet' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $pet_name = trim($_POST['pet_name'] ?? '');
    $pet_type = trim($_POST['pet_type'] ?? '');
    $pet_breed = trim($_POST['pet_breed'] ?? '');
    $pet_size = trim($_POST['pet_size'] ?? '');
    $tutor_phone = trim($_POST['tutor_phone'] ?? '');

    if (empty($pet_name) || empty($pet_type) || empty($pet_size)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios para cadastrar o pet.</div>';
        writeLog($pdo, "Cadastro de Pet Falhou", "Campos obrigatórios vazios", $_SESSION['user_id']);
    } else {
        try {
            $photo_path = null;
            if (isset($_FILES['pet_photo']) && $_FILES['pet_photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name = uniqid() . '-' . basename($_FILES['pet_photo']['name']);
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['pet_photo']['tmp_name'], $file_path)) {
                    $photo_path = $file_path;
                } else {
                    $message = '<div class="alert alert-danger">Erro ao fazer upload da foto.</div>';
                    writeLog($pdo, "Cadastro de Pet Falhou", "Erro ao fazer upload da foto", $_SESSION['user_id']);
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO pets (user_id, pet_name, pet_type, pet_breed, pet_size, tutor_phone, photo) 
                VALUES (:user_id, :pet_name, :pet_type, :pet_breed, :pet_size, :tutor_phone, :photo)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':pet_name' => $pet_name,
                ':pet_type' => $pet_type,
                ':pet_breed' => $pet_breed,
                ':pet_size' => $pet_size,
                ':tutor_phone' => $tutor_phone,
                ':photo' => $photo_path
            ]);

            $message = '<div class="alert alert-success">Pet cadastrado com sucesso!</div>';
            writeLog($pdo, "Cadastro de Pet", "Nome: $pet_name", $_SESSION['user_id']);
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao cadastrar pet: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Cadastrar Pet", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
        }
    }
}

// Processar edição de pet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_pet' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);
    $pet_name = trim($_POST['pet_name'] ?? '');
    $pet_type = trim($_POST['pet_type'] ?? '');
    $pet_breed = trim($_POST['pet_breed'] ?? '');
    $pet_size = trim($_POST['pet_size'] ?? '');
    $tutor_phone = trim($_POST['tutor_phone'] ?? '');
    $remove_photo = isset($_POST['remove_photo']) && $_POST['remove_photo'] === 'on';

    if ($pet_id === false || $pet_id === null) {
        $message = '<div class="alert alert-danger">ID de pet inválido.</div>';
    } elseif (empty($pet_name) || empty($pet_type) || empty($pet_size)) {
        $message = '<div class="alert alert-danger">Preencha todos os campos obrigatórios para editar o pet.</div>';
        writeLog($pdo, "Edição de Pet Falhou", "Campos obrigatórios vazios - ID: $pet_id", $_SESSION['user_id']);
    } else {
        try {
            // Verificar se o pet pertence ao usuário logado
            $stmt = $pdo->prepare("SELECT user_id, photo FROM pets WHERE id = :id");
            $stmt->execute([':id' => $pet_id]);
            $pet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pet) {
                $message = '<div class="alert alert-danger">Pet não encontrado.</div>';
            } elseif ($pet['user_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
                $message = '<div class="alert alert-danger">Você não tem permissão para editar este pet.</div>';
            } else {
                $photo_path = $pet['photo'];
                $new_photo_path = $photo_path;

                // Processar upload de nova foto
                if (isset($_FILES['pet_photo']) && $_FILES['pet_photo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = 'uploads/';
                    $file_name = uniqid() . '-' . basename($_FILES['pet_photo']['name']);
                    $file_path = $upload_dir . $file_name;

                    if (move_uploaded_file($_FILES['pet_photo']['tmp_name'], $file_path)) {
                        // Remover a foto antiga, se existir
                        if ($photo_path && file_exists($photo_path)) {
                            unlink($photo_path);
                        }
                        $new_photo_path = $file_path;
                    } else {
                        $message = '<div class="alert alert-danger">Erro ao fazer upload da nova foto.</div>';
                        writeLog($pdo, "Edição de Pet Falhou", "Erro ao fazer upload da foto - ID: $pet_id", $_SESSION['user_id']);
                        $new_photo_path = $photo_path;
                    }
                }

                // Remover a foto, se solicitado
                if ($remove_photo && $photo_path && file_exists($photo_path)) {
                    unlink($photo_path);
                    $new_photo_path = null;
                }

                // Atualizar o pet no banco de dados
                $stmt = $pdo->prepare("
                    UPDATE pets 
                    SET pet_name = :pet_name, pet_type = :pet_type, pet_breed = :pet_breed, 
                        pet_size = :pet_size, tutor_phone = :tutor_phone, photo = :photo 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $pet_id,
                    ':pet_name' => $pet_name,
                    ':pet_type' => $pet_type,
                    ':pet_breed' => $pet_breed,
                    ':pet_size' => $pet_size,
                    ':tutor_phone' => $tutor_phone,
                    ':photo' => $new_photo_path
                ]);

                if ($stmt->rowCount() > 0) {
                    $message = '<div class="alert alert-success">Pet atualizado com sucesso!</div>';
                    writeLog($pdo, "Edição de Pet", "ID: $pet_id, Nome: $pet_name", $_SESSION['user_id']);
                } else {
                    $message = '<div class="alert alert-warning">Nenhuma alteração foi feita no pet.</div>';
                }
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao atualizar pet: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Editar Pet", "Erro: {$e->getMessage()} - ID: $pet_id", $_SESSION['user_id']);
        }
    }
}

// Processar exclusão de pet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_pet' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);

    if ($pet_id === false || $pet_id === null) {
        $message = '<div class="alert alert-danger">ID de pet inválido.</div>';
    } else {
        try {
            // Verificar se o pet pertence ao usuário logado
            $stmt = $pdo->prepare("SELECT user_id, photo FROM pets WHERE id = :id");
            $stmt->execute([':id' => $pet_id]);
            $pet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pet) {
                $message = '<div class="alert alert-danger">Pet não encontrado.</div>';
            } elseif ($pet['user_id'] != $_SESSION['user_id'] && !$_SESSION['is_admin']) {
                $message = '<div class="alert alert-danger">Você não tem permissão para excluir este pet.</div>';
            } else {
                // Remover a foto, se existir
                if ($pet['photo'] && file_exists($pet['photo'])) {
                    unlink($pet['photo']);
                }

                // Excluir o pet do banco de dados
                $stmt = $pdo->prepare("DELETE FROM pets WHERE id = :id");
                $stmt->execute([':id' => $pet_id]);

                $message = '<div class="alert alert-success">Pet excluído com sucesso!</div>';
                writeLog($pdo, "Exclusão de Pet", "ID: $pet_id", $_SESSION['user_id']);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao excluir pet: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Excluir Pet", "Erro: {$e->getMessage()} - ID: $pet_id", $_SESSION['user_id']);
        }
    }
}

// Listar pets do usuário
try {
    if ($_SESSION['is_admin']) {
        $stmt = $pdo->prepare("
            SELECT p.*, u.name as user_name 
            FROM pets p 
            LEFT JOIN users u ON p.user_id = u.id 
            ORDER BY p.id DESC
        ");
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM pets 
            WHERE user_id = :user_id 
            ORDER BY id DESC
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
    }
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = '<div class="alert alert-danger">Erro ao listar pets: ' . $e->getMessage() . '</div>';
    writeLog($pdo, "Erro ao Listar Pets", "Erro: {$e->getMessage()}", $_SESSION['user_id']);
    $pets = [];
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Pets - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">Meus Pets</h1>

        <?php if ($message): ?>
            <div class="mb-4"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="text-end mb-3">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#petModal" onclick="resetPetForm()">Cadastrar Novo Pet</button>
        </div>

        <?php if (empty($pets)): ?>
            <div class="alert alert-info">Nenhum pet cadastrado.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Raça</th>
                            <th>Porte</th>
                            <th>Telefone do Tutor</th>
                            <th>Foto</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pets as $pet): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pet['pet_name']); ?></td>
                                <td><?php echo htmlspecialchars($pet['pet_type']); ?></td>
                                <td><?php echo htmlspecialchars($pet['pet_breed'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pet['pet_size']); ?></td>
                                <td><?php echo htmlspecialchars($pet['tutor_phone'] ?: 'N/A'); ?></td>
                                <td>
                                    <?php if ($pet['photo']): ?>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#photoModal" onclick="showPhoto('<?php echo htmlspecialchars($pet['photo']); ?>')">
                                            <img src="<?php echo htmlspecialchars($pet['photo']); ?>" alt="Foto do Pet" class="pet-photo">
                                        </a>
                                    <?php else: ?>
                                        Sem foto
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-warning me-1" data-bs-toggle="modal" data-bs-target="#petModal" onclick='editPet(<?php echo json_encode($pet); ?>)'>Editar</button>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir este pet?');">
                                        <input type="hidden" name="action" value="delete_pet">
                                        <input type="hidden" name="pet_id" value="<?php echo $pet['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal para cadastro/edição de pet -->
    <div class="modal fade" id="petModal" tabindex="-1" aria-labelledby="petModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="petModalLabel">Cadastrar Novo Pet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" id="petForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" id="formAction" value="add_pet">
                        <input type="hidden" name="pet_id" id="petId">
                        <input type="hidden" name="remove_photo_pet_id" id="removePhotoPetId">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3">
                            <label for="pet_name" class="form-label">Nome do Pet *</label>
                            <input type="text" class="form-control" id="pet_name" name="pet_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="pet_type" class="form-label">Tipo do Pet *</label>
                            <select class="form-select" id="pet_type" name="pet_type" required>
                                <option value="">Selecione o tipo</option>
                                <option value="cachorro">Cachorro</option>
                                <option value="gato">Gato</option>
                                <option value="passaro">Pássaro</option>
                                <option value="outro">Outro</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="pet_breed" class="form-label">Raça do Pet</label>
                            <input type="text" class="form-control" id="pet_breed" name="pet_breed">
                        </div>
                        <div class="mb-3">
                            <label for="pet_size" class="form-label">Porte do Pet *</label>
                            <select class="form-select" id="pet_size" name="pet_size" required>
                                <option value="">Selecione o porte</option>
                                <option value="pequeno">Pequeno</option>
                                <option value="medio">Médio</option>
                                <option value="grande">Grande</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="tutor_phone" class="form-label">Telefone do Tutor</label>
                            <input type="text" class="form-control" id="tutor_phone" name="tutor_phone" placeholder="(XX) 9XXXX-XXXX">
                        </div>
                        <div class="mb-3">
                            <label for="pet_photo" class="form-label">Foto do Pet (opcional)</label>
                            <input type="file" class="form-control" id="pet_photo" name="pet_photo" accept="image/*">
                            <div id="photoPreview" style="display: none;" class="mt-2">
                                <img id="previewImage" src="" alt="Pré-visualização da Foto" class="img-fluid" style="max-height: 200px;">
                            </div>
                            <div id="existingPhoto" style="display: none;" class="mt-2">
                                <p>Foto atual:</p>
                                <img id="existingImage" src="" alt="Foto Atual do Pet" class="img-fluid" style="max-height: 200px;">
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="remove_photo" name="remove_photo">
                                    <label class="form-check-label" for="remove_photo">Remover foto atual</label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary" id="submitButton" form="petForm">Cadastrar Pet</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para visualização de foto -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-labelledby="photoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="photoModalLabel">Foto do Pet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="modalPhoto" src="" alt="Foto do Pet" class="img-fluid">
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
</body>
</html>