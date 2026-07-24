<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Config\ConfigEpever;

$shortOptions = '';
$longOptions = [
    'client-id::',
    'watch',
    'interval::',
    'help',
];
$options = getopt($shortOptions, $longOptions);

if (isset($options['help'])) {
    echo "Usage: php send_json.php [--client-id=ID] [--watch] [--interval=SECONDS]\n";
    echo "  --client-id    Identifiant client à traiter (par défaut celui configuré)\n";
    echo "  --watch        Boucle en permanence et renvoie les fichiers dès qu'ils apparaissent\n";
    echo "  --interval     Intervalle de surveillance en secondes (défaut 30)\n";
    exit(0);
}

$config = new ConfigEpever();
$clientId = $options['client-id'] ?? $config->getClientId();
$apiConfig = $config->getEntete();
$apiUrl = $apiConfig['apiUrl'] ?? '';
$apiToken = $apiConfig['apiToken'] ?? '';

if ($apiUrl === '' || $apiToken === '') {
    fwrite(STDERR, "API URL ou token manquant dans ConfigEpever.\n");
    exit(1);
}

$intervalSeconds = isset($options['interval']) ? (float) $options['interval'] : 30.0;
if ($intervalSeconds <= 0) {
    $intervalSeconds = 30.0;
}

$baseDir = __DIR__ . '/data/' . $clientId . '/json';
$archiveDir = __DIR__ . '/data/' . $clientId . '/archive';
$failedDir = __DIR__ . '/data/' . $clientId . '/failed';

if (!is_dir($archiveDir) && !mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
    fwrite(STDERR, "Impossible de créer le dossier d'archive : {$archiveDir}\n");
    exit(1);
}
if (!is_dir($failedDir) && !mkdir($failedDir, 0755, true) && !is_dir($failedDir)) {
    fwrite(STDERR, "Impossible de créer le dossier d'erreur : {$failedDir}\n");
    exit(1);
}

function sendJsonFile(string $filePath, string $apiUrl, string $apiToken): array
{
    $json = file_get_contents($filePath);
    if ($json === false) {
        return ['success' => false, 'message' => 'Impossible de lire le fichier.'];
    }

    if (extension_loaded('curl')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => 'cURL error: ' . $error];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return ['success' => false, 'message' => "HTTP {$statusCode} - response: {$response}"];
        }

        return ['success' => true, 'message' => 'OK'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiToken}\r\n",
            'content' => $json,
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($apiUrl, false, $context);
    if ($response === false) {
        $error = error_get_last();
        $message = $error['message'] ?? 'Erreur HTTP inconnue.';
        return ['success' => false, 'message' => 'HTTP error: ' . $message];
    }

    $statusCode = 200;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#i', $header, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['success' => false, 'message' => "HTTP {$statusCode} - response: {$response}"];
    }

    return ['success' => true, 'message' => 'OK'];
}

function processQueue(string $baseDir, string $archiveDir, string $failedDir, string $apiUrl, string $apiToken): array
{
    $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

    if (!is_dir($baseDir)) {
        return $result;
    }

    $files = glob(rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json');
    sort($files, SORT_STRING);

    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        echo "Traitement du fichier {$fileName}...\n";

        $response = sendJsonFile($filePath, $apiUrl, $apiToken);
        if ($response['success']) {
            $destination = $archiveDir . DIRECTORY_SEPARATOR . $fileName;
            if (!rename($filePath, $destination)) {
                echo "  ⚠️ Envoi réussi, mais impossible de déplacer le fichier vers archive.\n";
                $result['failed']++;
                continue;
            }
            echo "  ✅ Envoyé et archivé.\n";
            $result['sent']++;
            continue;
        }

        echo "  ❌ Échec de l'envoi : {$response['message']}\n";
        $destination = $failedDir . DIRECTORY_SEPARATOR . $fileName;
        if (!rename($filePath, $destination)) {
            echo "  ⚠️ Impossible de déplacer le fichier dans failed. Il reste en attente.\n";
        }
        $result['failed']++;
    }

    return $result;
}

function printSummary(array $summary, string $clientId): void
{
    echo "\nRésumé pour client {$clientId} :\n";
    echo "  Envoyés : {$summary['sent']}\n";
    echo "  Échecs : {$summary['failed']}\n";
}

function run(string $clientId, string $baseDir, string $archiveDir, string $failedDir, string $apiUrl, string $apiToken, float $intervalSeconds, bool $watch): void
{
    echo "Client : {$clientId}\n";
    echo "Répertoire à traiter : {$baseDir}\n";
    echo "API URL : {$apiUrl}\n";
    echo "Watch mode : " . ($watch ? 'oui' : 'non') . "\n";

    do {
        $summary = processQueue($baseDir, $archiveDir, $failedDir, $apiUrl, $apiToken);
        printSummary($summary, $clientId);

        if ($watch) {
            echo "\nAttente {$intervalSeconds} secondes avant la prochaine vérification...\n";
            sleep((int) max(1, $intervalSeconds));
        }
    } while ($watch);
}

$watch = isset($options['watch']);
run($clientId, $baseDir, $archiveDir, $failedDir, $apiUrl, $apiToken, $intervalSeconds, $watch);
