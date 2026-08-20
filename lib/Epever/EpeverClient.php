<?php

namespace Epever;

use InitPHP\Socket\Interfaces\SocketConnectionInterface;
use Config\ConfigEpever;


class EpeverClient
{

    private SocketConnectionInterface $socket;

    private int $slaveId;


    public function __construct(
        SocketConnectionInterface $socket,
        int $slaveId = 1,
        bool $purgeOnConnect = true
    )
    {
        $this->socket = $socket;
        $this->slaveId = $slaveId;

        if ($purgeOnConnect) {
            $this->purgeSocketBuffer();
        }
    }

    /**
     * Vide le buffer initial de la socket pour supprimer d'éventuels octets résiduels
     * provenant du handshake ou de données envoyées avant que le client soit prêt.
     */
    private function purgeSocketBuffer(float $timeoutSeconds = 0.1, bool $debug = false): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $total = 0;
        while (microtime(true) < $deadline) {
            $chunk = $this->socket->read(4096);
            if ($chunk === null) {
                // plus de données actuellement
                break;
            }
            $total += strlen($chunk);
            if ($debug) {
                echo "    [DEBUG] Purged " . strlen($chunk) . " bytes: " . bin2hex($chunk) . "\n";
            }
            // courte pause pour permettre d'autres octets d'arriver
            usleep(10000);
        }
        if ($debug) {
            echo "    [DEBUG] Purge totale: {$total} bytes\n";
        }
    }

    public function setId(int $slaveId): void
    {
        $this->slaveId = $slaveId;
    }

    public function getId(): int
    {
        return $this->slaveId;
    }

    /**
     * CRC16 Modbus
     */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 1) {
                    $crc =
                        ($crc >> 1)
                        ^ 0xA001;
                } else {
                    $crc >>= 1;
                }
            }
        }
        // Modbus = octet faible puis fort
        return pack(
            "v",
            $crc
        );
    }

    function checkClient(): string
    {
        $resource = $this->socket->getSocket();

        if ($resource instanceof \Socket) {
            socket_getpeername($resource, $ip, $port);
            echo "Client TCP connecté : {$ip}:{$port}\n";
            return $ip;
        } elseif (is_resource($resource)) {
            $name = stream_socket_get_name($resource, true);
            echo "Client stream connecté : {$name}\n";
            return $name;
        } else {
            echo "Impossible de récupérer le socket natif du client.\n";
            return "unknown";
        }
    }

    /**
     * Lit une réponse Modbus complète à partir de la socket.
     */
    private function readModbusResponse(bool $debug = false): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $chunk = $this->socket->read(256);
            if ($chunk !== null) {
                $buffer .= $chunk;
            } else {
                usleep(15000);
            }

            if (strlen($buffer) < 3) {
                continue;
            }

            // Si la trame commence déjà par le slave id, on peut traiter directement.
            if (ord($buffer[0]) === $this->slaveId) {
                if (strlen($buffer) < 3) {
                    continue;
                }
                $byteCount = ord($buffer[2]);
                $expectedLength = 3 + $byteCount + 2;
                if (strlen($buffer) >= $expectedLength) {
                    return substr($buffer, 0, $expectedLength);
                }
                continue;
            }

            // Si la trame est en Modbus TCP, on cherche le header MBAP.
            if (strlen($buffer) >= 7) {
                $unitId = ord($buffer[6]);
                $length = unpack('n', substr($buffer, 4, 2))[1] ?? 0;
                $totalLength = 6 + $length;
                if ($unitId === $this->slaveId && $length > 0 && $length <= 256) {
                    if ($debug) {
                        echo "    [DEBUG] Trame Modbus TCP détectée (MBAP), longueur totale = {$totalLength}\n";
                    }
                    if (strlen($buffer) >= $totalLength) {
                        return substr($buffer, 6, $length);
                    }
                    continue;
                }
            }

            // Chercher une occurrence valide du slaveId dans le buffer
            $frameStart = null;
            for ($offset = 1; $offset < strlen($buffer); $offset++) {
                if (ord($buffer[$offset]) === $this->slaveId) {
                    $frameStart = $offset;
                    break;
                }
            }

            if ($frameStart !== null) {
                if ($debug) {
                    echo "    [DEBUG] Ignorer " . $frameStart . " octets avant le slaveId\n";
                }
                $buffer = substr($buffer, $frameStart);
                continue;
            }

            if ($debug) {
                echo "    [DEBUG] Aucun slaveId trouvé dans le buffer actuel (" . strlen($buffer) . " bytes), attente...\n";
            }
        }

        if ($debug) {
            echo "    [DEBUG] Timeout Modbus après 2s, buffer=" . bin2hex($buffer) . "\n";
        }

        return null;
    }

    /**
     * Convertit une valeur 16 bits non signée en entier signé sur 16 bits.
     */
    private function toSignedInt16(int $value): int
    {
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    /**
     * Convertit une valeur 32 bits non signée en entier signé sur 32 bits.
     */
    private function toSignedInt32(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /**
     * Lire une plage de registres (fonction 03 ou 04), vérifie CRC et retourne
     * un tableau associatif adresse_hex => valeur (entier 16 bits)
     */
    public function readRegisters(int $address, int $count, int $function = 3, $debug = false): ?array
    {
        $frame = pack("CCnn", $this->slaveId, $function, $address, $count);
        $frame .= $this->crc16($frame);

        if ($debug) {
            echo "    [DEBUG] Requête envoyée : " . bin2hex($frame) . "\n";
        }

        $this->socket->write($frame);

        $response = $this->readModbusResponse($debug);
        if ($response === null) {
            return null;
        }

        $minBytes = 5 + (2 * $count); // Slave ID + Function + Byte Count + CRC (2 bytes)
        echo "    [DEBUG] Réponse attendue : {$minBytes} bytes pour {$count} registres\n";
        if ($debug) {
            echo "    [DEBUG] Réponse reçue (" . strlen($response) . " bytes) : " . bin2hex($response) . "\n";
        }

        if (strlen($response) !== $minBytes) {
            if ($debug) echo "    [DEBUG] Le nombre de bytes reçus ne correspond pas à la réponse attendue (" . strlen($response) . " bytes)\n";
            return null;
        }

        $body = substr($response, 0, -2);
        $crcRecv = substr($response, -2);
        $crcCalc = $this->crc16($body);

        if ($debug) {
            echo "    [DEBUG] CRC reçu: " . bin2hex($crcRecv) . " | CRC calculé: " . bin2hex($crcCalc) . "\n";
        }

        if ($crcCalc !== $crcRecv) {
            if ($debug) echo "    [DEBUG] CRC invalide!\n";
            return null;
        }

        $bytes = unpack("Cslave/Cfunction/Csize", $body);
        $byteCount = $bytes['size'];
        $offset = 3;
        $values = [];
        for ($i = 0; $i < $byteCount; $i += 2) {
            $values[] = unpack('n', substr($body, $offset + $i, 2))[1];
        }

        $result = [];
        for ($i = 0; $i < count($values); $i++) {
            $addr = $address + $i;
            $result["0x" . strtoupper(dechex($addr))] = [$values[$i], dechex($values[$i]),$byteCount];
        }

        return $result;
    }

    /**
     * Mappe un tableau adresse=>valeur vers des clés lisibles avec conversions.
     * Personnalisez la table `$map` selon la documentation de l'appareil.
     */
    public function mapRegisters(array $registers): array
    {
        $config = new ConfigEpever();
        $tabconfig = $config->getAll();
        $map = $tabconfig['map'];
        $pairs = $tabconfig['pairs'];

        $valid = [];
        $noMap = [];

        $timestamp = time() * 1000;

        // Ajouter une ligne pour chaque registre lu.
        foreach ($registers as $addr => [$value, $hexValue, $byteCount] ) {
            if (isset($map[$addr])) {
                [$key, $scale, $unit] = $map[$addr];
                $signedValue = $this->toSignedInt16($value);
                $mesure = ["capteur_id" => $key, "timestamp" => $timestamp, "valeur" => $scale !== 0 ? $signedValue / $scale : $signedValue, "_meta" => ["addr" => $addr, "unite" => $unit]];
                array_push($valid,$mesure);
                continue;
            }

            if (isset($pairs[$addr])) {
                [$key, $scale, $highAddr, $unit] = $pairs[$addr];
                if (!isset($registers[$addr]) || !isset($registers[$highAddr])) {
                    continue;
                }
                $lowValue = (int) $registers[$addr][0];
                $highValue = (int) $registers[$highAddr][0];
                $combinedRaw = (($highValue & 0xFFFF) << 16) | ($lowValue & 0xFFFF);
                $combinedValue = $this->toSignedInt32($combinedRaw);
                $mesure = ["capteur_id" => $key, "timestamp" => $timestamp, "valeur" => $scale !== 0 ? $combinedValue / $scale : $combinedValue, "_meta" => ["addr" => $addr, "highAddr" => $highAddr, "unite" => $unit]];
                array_push($valid,$mesure);
                continue;
            }

            array_push($noMap, ["capteur_id" => $addr, "timestamp" => $timestamp, "valeur" => $value, "_meta" => ["hexValue" => $hexValue, "byteCount" => $byteCount]]);
        }

        return ['valid' => $valid, 'noMap' => $noMap];
    }
}