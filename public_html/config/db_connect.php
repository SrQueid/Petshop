<?php
// Configuração do PDO
$dsn = 'mysql:host=127.0.0.1:3306;dbname=u130382940_Petlove;charset=utf8';
$username = 'u130382940_petlove';
$password = 'Petlove1!';

try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Registra o erro em um log e exibe uma mensagem amigável
    error_log("Erro ao conectar ao banco de dados: " . $e->getMessage());
    die('<div class="alert alert-danger">Erro ao conectar ao banco de dados: ' . $e->getMessage() . '</div>');
}
?>