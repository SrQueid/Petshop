<?php
session_start();

// Include navbar with error handling
try {
    include 'navbar.php';
} catch (Exception $e) {
    error_log("Failed to include navbar.php: " . $e->getMessage());
    die('<div class="alert alert-danger">Erro ao carregar o menu de navegação: ' . $e->getMessage() . '</div>');
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

// Verifica se o usuário está logado (se estiver, redireciona para agendamentos)
$isLoggedIn = isset($_SESSION['user_id']);
if ($isLoggedIn) {
    header("Location: agendamentos.php");
    exit;
}

// Processa a solicitação de recuperação de senha
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $email = $_POST['email'] ?? '';

    if (empty($email)) {
        $message = '<div class="alert alert-danger">Por favor, insira seu email.</div>';
    } else {
        try {
            // Verificar se o email existe na tabela users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Gerar um token único
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expira em 1 hora

                // Armazenar o token na tabela password_resets
                $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at) ON DUPLICATE KEY UPDATE token = :token, expires_at = :expires_at");
                $stmt->execute([
                    ':email' => $email,
                    ':token' => $token,
                    ':expires_at' => $expiresAt
                ]);

                // Gerar o link de redefinição
                $resetLink = "http://seusite.com/reset_password.php?token=" . $token;

                // Enviar o email com o link de redefinição (usando PHPMailer)
                require 'vendor/autoload.php'; // Certifique-se de instalar o PHPMailer via Composer
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);

                try {
                    // Configurações do servidor SMTP
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com'; // Substitua pelo seu servidor SMTP
                    $mail->SMTPAuth = true;
                    $mail->Username = 'seuemail@gmail.com'; // Substitua pelo seu email
                    $mail->Password = 'suasenha'; // Substitua pela sua senha ou senha de app
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    // Remetente e destinatário
                    $mail->setFrom('seuemail@gmail.com', 'Petshop PetsLove');
                    $mail->addAddress($email);

                    // Conteúdo do email
                    $mail->isHTML(true);
                    $mail->Subject = 'Redefinição de Senha - Petshop PetsLove';
                    $mail->Body = "Olá,<br><br>Você solicitou a redefinição de sua senha. Clique no link abaixo para redefinir sua senha:<br><a href='$resetLink'>$resetLink</a><br><br>Este link expira em 1 hora.<br><br>Se você não solicitou isso, ignore este email.<br><br>Atenciosamente,<br>Equipe Petshop PetsLove";
                    $mail->AltBody = "Olá,\n\nVocê solicitou a redefinição de sua senha. Acesse o link abaixo para redefinir sua senha:\n$resetLink\n\nEste link expira em 1 hora.\n\nSe você não solicitou isso, ignore este email.\n\nAtenciosamente,\nEquipe Petshop PetsLove";

                    $mail->send();
                    $message = '<div class="alert alert-success">Um link de redefinição de senha foi enviado para o seu email.</div>';
                    writeLog($pdo, "Solicitação de Redefinição de Senha", "Email: $email", null);
                } catch (Exception $e) {
                    $message = '<div class="alert alert-danger">Erro ao enviar o email: ' . $mail->ErrorInfo . '</div>';
                    writeLog($pdo, "Erro ao Enviar Email de Redefinição", "Erro: {$mail->ErrorInfo} - Email: $email", null);
                }
            } else {
                // Mensagem genérica para evitar vazamento de informações
                $message = '<div class="alert alert-success">Se o email estiver registrado, um link de redefinição de senha será enviado.</div>';
                writeLog($pdo, "Tentativa de Redefinição de Senha - Email Não Encontrado", "Email: $email", null);
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">Erro ao processar a solicitação: ' . $e->getMessage() . '</div>';
            writeLog($pdo, "Erro ao Processar Redefinição de Senha", "Erro: {$e->getMessage()} - Email: $email", null);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - Petshop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Referência ao arquivo CSS externo -->
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <div class="container auth-container">
        <h1 class="text-center mb-4">Recuperar Senha</h1>

        <!-- Formulário de Recuperação de Senha -->
        <div class="card shadow">
            <div class="card-header">
                <h5 class="mb-0">Recuperar Senha</h5>
            </div>
            <div class="card-body">
                <p>Insira seu email para receber um link de redefinição de senha.</p>
                <form method="POST" action="" id="forgotPasswordForm">
                    <input type="hidden" name="action" value="forgot_password">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                        <div id="errorMessage" class="text-danger mt-2" style="display: none;"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Enviar Link</button>
                </form>
            </div>
        </div>

        <!-- Link para Voltar ao Login -->
        <div class="text-center mt-3">
            <p><a href="auth.php">Voltar ao Login</a></p>
        </div>

        <!-- Mensagem -->
        <?php if (!empty($message)): ?>
            <?php echo $message; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Referência ao arquivo JavaScript externo -->
    <script src="js/scripts.js"></script>
</body>
</html>