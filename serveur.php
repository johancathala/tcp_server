<?php

require __DIR__.'/vendor/autoload.php';

use InitPHP\Socket\Socket;
use InitPHP\Socket\Enum\Transport;
use InitPHP\Socket\Interfaces\SocketServerInterface;
use InitPHP\Socket\Interfaces\SocketConnectionInterface;
use InitPHP\Socket\Exception\SocketException;

use Epever\EpeverClient;

$host = '0.0.0.0';
$port = isset($argv[1]) ? (int) $argv[1] : 82;

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

$clients = $server->getClients();
echo "Clients connectés : ".count($clients)."\n";

$server->live(
function(
    SocketServerInterface $srv,
    SocketConnectionInterface $conn
){
   // echo "Response à la connexion : ".bin2hex($conn->read())."\n";

    echo "Client connecté\n";
    echo "Id du client : ".$conn->getId()."\n";
    
    $epever = new EpeverClient($conn);
    
    $allRegisters = [];

    $adress = 0x3100;
    $count = 16;

    $registers = $epever->readRegisters($adress, $count, 4, true);
     
    if (is_array($registers)) {
        $allRegisters = array_merge($allRegisters, $registers);
    } else {
        echo "  ⚠️ Aucun registre lu pour 0x".dechex($adress)."\n";
    }
    
    // Mapper tous les registres lus
    if (!empty($allRegisters)) {
        echo "\n=== Paramètres mappés ===\n";
        $mapped = $epever->mapRegisters($allRegisters);
        foreach ($mapped as $key => $value) {
            echo $key." : ".$value."\n";
        }
    }

});