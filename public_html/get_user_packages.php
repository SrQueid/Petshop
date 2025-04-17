<?php
session_start();

// Incluir o arquivo de conexão com o banco de dados
require_once 'config/db_connect.php';

// Verifica se o usuário está logado e é administrador ou o próprio tutor
if (!isset($_SESSION['user_id']) || (!$_SESSION['is_admin'] && $_SESSION['user_id'] != $_GET['user_id'])) {
    http_response_code(403);
    exit(json_encode([]));
}

$user_id = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
if ($user_id === false || $user_id === null) {
    http_response_code(400);
    exit(json_encode([]));
}

try {
    $stmt = $pdo->prepare("SELECT id, pet_name FROM pets WHERE user_id = :user_id ORDER BY pet_name");
    $stmt->execute([':user_id' => $user_id]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($pets);
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode([]));
}