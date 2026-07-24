<?php

require __DIR__.'/vendor/autoload.php';

use InitPHP\Socket\Socket;
use InitPHP\Socket\Enum\Transport;
use InitPHP\Socket\Interfaces\SocketServerInterface;
use InitPHP\Socket\Interfaces\SocketConnectionInterface;
use InitPHP\Socket\Exception\SocketException;

use Epever\EpeverClient;
use Config\ConfigEpever;

$config = new ConfigEpever();

$host = $config->host;
$port = $config->port;

$server = Socket::server(
    Transport::TCP,
    $host,
    $port
);

if ($server->isRunning())
{
    echo "Le serveur est déjà en cours d'exécution\n";
    echo "Arrêt du serveur...\n";
    $server->close();
}

try {
    $server->listen();
} catch (SocketException $e) {
    echo "Impossible de démarrer le serveur sur {$host}:{$port}\n";
    echo $e->getMessage()."\n";
    echo "Vérifiez si un autre processus utilise déjà ce port, puis relancez le serveur.\n";
    echo "Exemple pour utiliser un autre port : php serveur.php 8181\n";
    exit(1);
}

echo "TCP server OK\n";

$server->live(
function(
    SocketServerInterface $srv,
    SocketConnectionInterface $conn
){
    $config = new ConfigEpever();
    $list_cmd = $config->get('list_cmd');
    $pollIntervalSeconds = $config->pollIntervalSeconds;
    $betweenReadsSeconds = $config->betweenReadsSeconds;

    echo "\nClient connecté\n";

    $testUser = false;
    $initialData = $conn->read(512);
    if ($initialData !== null) {
        $initialData = bin2hex($initialData);
        echo "Trame d'identification initiale reçue : " . $initialData . "\n";
        $testUser = $config->testIdMacClient($initialData);
    } else {
        echo "Aucune trame d'identification initiale reçue lors de la connexion.\n";
    }

    if (!$testUser) {
        echo "⚠️ Identifiant client non reconnu, fermeture de la connexion.\n";
        $conn->close();
        
        echo "\nAttente de {$pollIntervalSeconds} sec avant le prochain cycle de connexion...\n";
            $srv->wait($pollIntervalSeconds);
        return;
    }else {
        echo "✅ Identifiant client reconnu, démarrage du cycle de lecture Modbus.\n";
    }

    $directory = __DIR__ . '/data/' . $initialData.'/json/';
    mkdir($directory,0755,true);

    $epever = new EpeverClient($conn, 1, false);
    

    while ($conn->isAlive()) {
        echo "\n--- Nouveau cycle de lecture Modbus ---\n";
        $cycleStart = microtime(true);

        echo "Lecture IP client\n";
        $clientIP = $epever->checkClient();
        echo "IP client : {$clientIP}\n";

        

        $allRegisters = [];
        foreach ($list_cmd as $cmd) {
            $address = $cmd[0];
            $count = $cmd[1];
            echo "Lecture du registre pour 0x".dechex($address)."\n";
            $registers = $epever->readRegisters($address, $count, 4, true);
            if (!is_array($registers)) {
                echo "  ⚠️ Aucun registre lu pour 0x".dechex($address)."\n";
                break;
            }
            $allRegisters = array_merge($allRegisters, $registers);

            if ($betweenReadsSeconds > 0) {
                echo "Attente de {$betweenReadsSeconds} secondes avant la prochaine requête...\n";
                $srv->wait($betweenReadsSeconds);
            }
        }

        if (!empty($allRegisters)) {
            $mapped = $epever->mapRegisters($allRegisters);

            echo "\n=== Paramètres mappés ===\n";    
            $valid = json_encode($mapped['valid']);
            echo $valid."\n";
            if($valid != "[]"){
                $filename = $directory . 'data_' . date('Ymd_His') . '.json';
                file_put_contents($filename, $valid);
                echo "\nDonnées mappées sauvegardées dans : {$filename}\n";
            }else{
                echo "\nAucune donnée mappée à sauvegarder.\n";
            }
            
            echo "\n=== Paramètres non mappés ===\n";
            $noMap = json_encode($mapped['noMap']);
            echo $noMap."\n";          
        }

        $elapsed = microtime(true) - $cycleStart;
        $remaining = $pollIntervalSeconds - $elapsed;
        if ($remaining > 0) {
            echo "\nCycle terminé en " . round($elapsed, 2) . " sec, attente de " . round($remaining, 2) . " sec avant le prochain cycle...\n";
            $srv->wait($remaining);
        } else {
            echo "\nCycle terminé en " . round($elapsed, 2) . " sec, lancement immédiat du prochain cycle...\n";
        }
    }

    echo "\nFin de la connexion ou erreur Modbus, fermeture du client.\n";
    $conn->close();
});