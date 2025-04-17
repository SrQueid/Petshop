<?php
session_start();

// Incluir o arquivo de conexão com o banco de dados
require_once 'config/db_connect.php';

// Verificar se o usuário é administrador
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

// Obter o tutor_id da requisição
$tutor_id = filter_input(INPUT_POST, 'tutor_id', FILTER_VALIDATE_INT);

if ($tutor_id === false || $tutor_id === null) {
    http_response_code(400);
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, pet_name FROM pets WHERE user_id = :user_id ORDER BY pet_name");
    $stmt->execute([':user_id' => $tutor_id]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($pets);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([]);
    error_log("Erro ao buscar pets: " . $e->getMessage());
}
?>