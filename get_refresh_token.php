<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client;
use Google\Service\Drive;

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado pelo terminal (CLI).\n");
}

$dotenv = Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
} catch (Exception $e) {
    die("Erro ao carregar o arquivo .env. Certifique-se de que ele existe e está configurado com GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET.\n");
}

$clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;

if (!$clientId || !$clientSecret) {
    die("Erro: GOOGLE_CLIENT_ID ou GOOGLE_CLIENT_SECRET não estão definidos no .env\n");
}

$client = new Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
// Para scripts CLI, usamos urn:ietf:wg:oauth:2.0:oob ou http://localhost para o redirect
$client->setRedirectUri('http://localhost');
$client->addScope(Drive::DRIVE_FILE);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

$authUrl = $client->createAuthUrl();

echo "1. Abra o seguinte link no seu navegador:\n";
echo $authUrl . "\n\n";
echo "2. Faça login com a sua conta do Google e autorize o aplicativo.\n";
echo "3. Você será redirecionado para uma página de erro (localhost) ou a página mostrará um código (se você mudou o redirect uri). \n";
echo "   Copie o valor do parâmetro 'code=' da URL.\n\n";

echo "Digite o código de verificação (code) recebido e aperte Enter: ";
$authCode = trim(fgets(STDIN));

if (empty($authCode)) {
    die("Erro: Nenhum código fornecido.\n");
}

try {
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
    
    if (array_key_exists('error', $accessToken)) {
        throw new Exception(implode(', ', $accessToken));
    }
} catch (Exception $e) {
    die("Erro ao obter o token de acesso: " . $e->getMessage() . "\n");
}

if (!isset($accessToken['refresh_token'])) {
    echo "\nAVISO: Refresh token não retornado. Isso pode ocorrer se o aplicativo já foi autorizado anteriormente. \n";
    echo "Para gerar um novo, acesse a página de segurança da sua conta Google, remova o acesso do app e tente de novo.\n\n";
} else {
    echo "\nSUCESSO! Copie o seu Refresh Token abaixo e cole na variável GOOGLE_REFRESH_TOKEN do seu arquivo .env:\n\n";
    echo $accessToken['refresh_token'] . "\n\n";
}
