<?php

require __DIR__.'/vendor/autoload.php';

//require __DIR__.'/lib/Epever/EpeverClient.php';


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

    echo "Client connecté\n";

    echo "Id du client : ".$conn->getId()."\n";
    
    /*
       Exemple :
       Lecture registres 0x3100-0x3101

       PV voltage
       PV current
    */
    // Lire un bloc de registres de 0x3100 (exemple) et mapper
    $epever = new EpeverClient($conn);
    $start = 0x3100;
    $count = 16; // lire 16 registres contigus

    echo "Lecture registres à partir de l'adresse 0x".dechex($start)." (".$count." registres)\n";

    $registers = $epever->readRegisters($start, $count, 4); // fonction 4 (input regs) souvent utilisée
    if ($registers === null) {
        echo "Lecture registres impossible ou CRC invalide\n";
        return;
    }
    $mapped = $epever->mapRegisters($registers);
    echo "Paramètres mappés:\n";
    print_r($mapped);

    $epever = new EpeverClient($conn);
    $start = 0x3110;
    $count = 2; // lire 16 registres contigus

    echo "Lecture registres à partir de l'adresse 0x".dechex($start)." (".$count." registres)\n";
    $registers = $epever->readRegisters($start, $count, 4); // fonction 4 (input regs) souvent utilisée
    if ($registers === null) {
        echo "Lecture registres impossible ou CRC invalide\n";
        return;
    }
    $mapped = $epever->mapRegisters($registers);
    echo "Paramètres mappés:\n";
    print_r($mapped);

});