<?php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client;
use Google\Service\Drive;
use Google\Http\MediaFileUpload;

// 1. Carregar variáveis do .env
$dotenv = Dotenv::createImmutable(__DIR__);
// Opcionalmente podemos ignorar o erro caso o arquivo .env não exista usando safeLoad(), mas para este caso ele é obrigatório
try {
    $dotenv->load();
} catch (Exception $e) {
    die("Erro ao carregar o arquivo .env. Certifique-se de que ele existe e está configurado corretamente.\n");
}

$sourceFolder = $_ENV['BACKUP_SOURCE_FOLDER'] ?? null;
$tempDir      = $_ENV['BACKUP_TEMP_DIR'] ?? '/tmp';
$googleFolder = $_ENV['GOOGLE_DRIVE_FOLDER_ID'] ?? null;
$clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
$clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;
$refreshToken = $_ENV['GOOGLE_REFRESH_TOKEN'] ?? null;

if (!$sourceFolder || !$googleFolder || !$clientId || !$clientSecret || !$refreshToken) {
    die("Erro: BACKUP_SOURCE_FOLDER, GOOGLE_DRIVE_FOLDER_ID, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET ou GOOGLE_REFRESH_TOKEN não definidos no .env.\n");
}

// Verifica se o caminho de origem existe
if (!is_dir($sourceFolder)) {
    die("Erro: O diretório fonte '$sourceFolder' não existe.\n");
}


$folderName = end(explode('-', $sourceFolder)); 


$zipFileName = 'backup_' . $folderName . '_' . date('Y-m-d_H-i-s') . '.zip';
$zipFilePath = rtrim($tempDir, '/') . '/' . $zipFileName;

echo "Iniciando backup da pasta: $sourceFolder\n";
echo "Arquivo temporário será: $zipFilePath\n";

// 2. Criar arquivo ZIP iterando recursivamente pelos arquivos
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Erro: Não foi possível criar o arquivo ZIP em $zipFilePath.\n");
}

// Cria um iterator iterando pelos arquivos (ignorando links simbólicos não resolvidos se necessário)
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceFolder, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

echo "Compactando arquivos...\n";
$fileCount = 0;
foreach ($files as $name => $file) {
    // Pula pastas (já adicionadas automaticamente junto com arquivos)
    if (!$file->isDir()) {
        // Obter caminho real e relativo para manter a estrutura da pasta no ZIP
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourceFolder) + 1);
        
        // Pula se o próprio arquivo zip estiver dentro da pasta de origem para não criar recursão infinita
        if ($filePath === $zipFilePath) {
            continue;
        }

        if ($zip->addFile($filePath, $relativePath)) {
            $fileCount++;
        }
    }
}
$zip->close();
echo "Compactação concluída! Total de $fileCount arquivos adicionados no ZIP.\n";
echo "Tamanho do arquivo gerado: " . number_format(filesize($zipFilePath) / 1048576, 2) . " MB\n";

// 3. Autenticar no Google Drive
echo "Autenticando no Google Drive...\n";

$client = new Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);

try {
    $client->refreshToken($refreshToken);
} catch (Exception $e) {
    unlink($zipFilePath);
    die("Erro ao renovar token de acesso: " . $e->getMessage() . "\n");
}
$client->addScope(Drive::DRIVE_FILE);

$service = new Drive($client);

// 4. Fazer upload Resumable (em chunks)
echo "Iniciando upload para o Google Drive...\n";

$fileMetadata = new Drive\DriveFile([
    'name' => $zipFileName,
    'parents' => [$googleFolder]
]);

// Configurar o cliente para tratar o upload deferido (para conseguirmos mandar os chunks)
$client->setDefer(true);

try {
    $request = $service->files->create($fileMetadata, [
        'mimeType' => 'application/zip',
        'supportsAllDrives' => true
    ]);
    
    // Tamanho do chunk (ex: 5MB)
    $chunkSizeBytes = 5 * 1024 * 1024;

    // Classe que gerencia o upload fracionado
    $media = new MediaFileUpload(
        $client,
        $request,
        'application/zip',
        null, // uploadType null faz o MediaFileUpload definir como resumable
        true, // resumable = true
        $chunkSizeBytes
    );
    $media->setFileSize(filesize($zipFilePath));

    // Lendo o arquivo físico e mandando
    $status = false;
    $handle = fopen($zipFilePath, "rb");
    
    $uploadedBytes = 0;
    while (!$status && !feof($handle)) {
        $chunk = fread($handle, $chunkSizeBytes);
        $status = $media->nextChunk($chunk);
        
        $uploadedBytes += strlen($chunk);
        $percent = round(($uploadedBytes / filesize($zipFilePath)) * 100, 2);
        echo "Upload progresso: $percent% ($uploadedBytes bytes)\n";
    }

    // Ao fim, the status conterá o objeto da resposta do Drive
    fclose($handle);
    
    // Reset defer to false so subsequent calls execute normally
    $client->setDefer(false);
    
    if ($status != false) {
        echo "Upload concluído com sucesso! ID no Drive: " . $status->id . "\n";
    }

} catch (Exception $e) {
    echo "Erro durante o upload: " . $e->getMessage() . "\n";
} finally {
    // 5. Limpar arquivo local
    if (file_exists($zipFilePath)) {
        unlink($zipFilePath);
        echo "Arquivo temporário $zipFilePath removido com sucesso.\n";
    }
}
