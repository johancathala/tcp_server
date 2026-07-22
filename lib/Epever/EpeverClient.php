<?php

namespace Epever;

use InitPHP\Socket\Interfaces\SocketConnectionInterface;


class EpeverClient
{

    private SocketConnectionInterface $socket;

    private int $slaveId;


    public function __construct(
        SocketConnectionInterface $socket,
        int $slaveId = 1
    )
    {
        $this->socket = $socket;
        $this->slaveId = $slaveId;
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
     * Lecture de registres Modbus fonction 03
     */
    public function readHoldingRegisters(
        int $address,
        int $count
    ): ?array
    {

        $frame = pack(
            "CCnn",
            $this->slaveId,
            0x03,
            $address,
            $count
        );


        $frame .= $this->crc16($frame);


        $this->socket->write($frame);


        $response = $this->socket->read(256);


        if ($response === null)
        {
            return null;
        }


        return $this->decodeRegisters($response);
    }



    /**
     * Lecture fonction 04
     */
    public function readInputRegisters(
        int $address,
        int $count
    ): ?array
    {

        $frame = pack(
            "CCnn",
            $this->slaveId,
            0x04,
            $address,
            $count
        );


        $frame .= $this->crc16($frame);


        $this->socket->write($frame);


        $response = $this->socket->read(256);


        if ($response === null)
        {
            return null;
        }


        return $this->decodeRegisters($response);
    }



    /**
     * Décodage réponse Modbus
     *
     * Exemple :
     * 01 03 04 13 88 00 FA CRC
     *
     */
    private function decodeRegisters(string $data): array
    {

        $bytes = unpack(
            "Cslave/Cfunction/Csize",
            $data
        );


        $count = $bytes["size"] / 2;


        $offset = 3;


        $values=[];


        for ($i=0;$i<$count;$i++)
        {

            $values[] =
                unpack(
                    "n",
                    substr($data,$offset,2)
                )[1];


            $offset += 2;
        }


        return $values;
    }



    /**
     * CRC16 Modbus
     */
    private function crc16(string $data): string
    {

        $crc = 0xFFFF;


        for ($i=0;$i<strlen($data);$i++)
        {

            $crc ^= ord($data[$i]);


            for ($j=0;$j<8;$j++)
            {

                if ($crc & 1)
                {
                    $crc =
                        ($crc >> 1)
                        ^ 0xA001;
                }
                else
                {
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

    /**
     * Lit une réponse Modbus complète à partir de la socket.
     */
    private function readModbusResponse(bool $debug = false): ?string
    {
        $buffer = '';
        $deadline = microtime(true) + 2.0;

        // Lire l'en-tête minimum : slave + function + byte count
        while (strlen($buffer) < 3 && microtime(true) < $deadline) {
            $chunk = $this->socket->read(256);
            if ($chunk === null) {
                if ($debug) {
                    echo "    [DEBUG] Réponse NULL (timeout/pas de réponse) pendant l'en-tête)\n";
                }
                return null;
            }
            $buffer .= $chunk;
        }

        if (strlen($buffer) < 3) {
            if ($debug) {
                echo "    [DEBUG] En-tête incomplet (" . strlen($buffer) . " bytes)\n";
            }
            return null;
        }

        $byteCount = ord($buffer[2]);
        $expectedLength = 3 + $byteCount + 2; // 3 octets d'entête + données + CRC

        while (strlen($buffer) < $expectedLength && microtime(true) < $deadline) {
            $chunk = $this->socket->read(256);
            if ($chunk === null) {
                if ($debug) {
                    echo "    [DEBUG] Réponse NULL (timeout/pas de réponse) pendant la lecture complète)\n";
                }
                return null;
            }
            $buffer .= $chunk;
        }

        if (strlen($buffer) < $expectedLength) {
            if ($debug) {
                echo "    [DEBUG] Trame incomplète (" . strlen($buffer) . " / " . $expectedLength . " bytes)\n";
            }
            return null;
        }

        return substr($buffer, 0, $expectedLength);
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

        if ($debug) {
            echo "    [DEBUG] Réponse reçue (" . strlen($response) . " bytes) : " . bin2hex($response) . "\n";
        }

        if (strlen($response) < 5) {
            if ($debug) echo "    [DEBUG] Réponse trop courte (" . strlen($response) . " bytes)\n";
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
            $result["0x" . strtoupper(dechex($addr))] = $values[$i];
        }

        return $result;
    }

    /**
     * Retourne les tables de mapping depuis un fichier de configuration externe.
     */
    private function getRegisterMaps(): array
    {
        $configFile = dirname(__DIR__, 2) . '/config/epever_registers.php';

        if (!is_file($configFile)) {
            throw new \RuntimeException("Configuration Modbus manquante : {$configFile}");
        }

        $maps = require $configFile;

        if (!is_array($maps) || !isset($maps['map'], $maps['pairs'])) {
            throw new \RuntimeException("Le fichier de configuration Modbus doit retourner [ 'map' => ..., 'pairs' => ... ]");
        }

        return $maps;
    }

    /**
     * Retourne toutes les clés de registres disponibles
     */
    public function getAllRegisterKeys(): array
    {
        $maps = $this->getRegisterMaps();
        $keys = [];

        // Clés des registres 16-bit
        foreach ($maps['map'] as [$key, $scale]) {
            $keys[] = $key;
        }

        // Clés des registres 32-bit
        foreach ($maps['pairs'] as [$key, $scale, $highAddr]) {
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Retourne les plages contiguës de registres à lire
     * Format : [['start' => 0x3100, 'count' => 5], ...]
     */
    public function getContiguousRanges(): array
    {
        $maps = $this->getRegisterMaps();
        $addresses = [];

        // Collecter toutes les adresses (16-bit et hautes des 32-bit)
        foreach ($maps['map'] as $addr => [$key, $scale]) {
            $addresses[$addr] = true;
        }

        foreach ($maps['pairs'] as $lowAddr => [$key, $scale, $highAddr]) {
            $addresses[$lowAddr] = true;
            $addresses[$highAddr] = true;
        }

        // Trier les adresses
        ksort($addresses);
        $addresses = array_keys($addresses);

        // Grouper en plages contiguës
        $ranges = [];
        if (empty($addresses)) {
            return $ranges;
        }

        $rangeStart = $addresses[0];
        $rangeEnd = $addresses[0];

        for ($i = 1; $i < count($addresses); $i++) {
            if ($addresses[$i] === $rangeEnd + 1) {
                // Registre contigu
                $rangeEnd = $addresses[$i];
            } else {
                // Fin de plage
                $ranges[] = [
                    'start' => $rangeStart,
                    'count' => $rangeEnd - $rangeStart + 1,
                ];
                $rangeStart = $addresses[$i];
                $rangeEnd = $addresses[$i];
            }
        }

        // Ajouter la dernière plage
        $ranges[] = [
            'start' => $rangeStart,
            'count' => $rangeEnd - $rangeStart + 1,
        ];

        return $ranges;
    }

    /**
     * Retourne la map complète avec toutes les informations (adresse, clé, facteur)
     */
    public function getAllRegisters(): array
    {
        $maps = $this->getRegisterMaps();
        $all = [];

        // Registres 16-bit
        foreach ($maps['map'] as $addr => [$key, $scale]) {
            $all[] = [
                'type' => '16-bit',
                'address' => $addr,
                'address_hex' => '0x' . dechex($addr),
                'key' => $key,
                'scale' => $scale,
            ];
        }

        // Registres 32-bit
        foreach ($maps['pairs'] as $lowAddr => [$key, $scale, $highAddr]) {
            $all[] = [
                'type' => '32-bit',
                'address_low' => $lowAddr,
                'address_low_hex' => '0x' . dechex($lowAddr),
                'address_high' => $highAddr,
                'address_high_hex' => '0x' . dechex($highAddr),
                'key' => $key,
                'scale' => $scale,
            ];
        }

        return $all;
    }

    /**
     * Mappe un tableau adresse=>valeur vers des clés lisibles avec conversions.
     * Personnalisez la table `$map` selon la documentation de l'appareil.
     */
    public function mapRegisters(array $registers): array
    {
        $maps = $this->getRegisterMaps();
        $map = $maps['map'];
        $pairs = $maps['pairs'];

        $out = [];

        foreach ($registers as $addr => $value) {
            if (isset($map[$addr])) {
                [$key, $scale] = $map[$addr];
                $out[$key."(".$addr.")"] = $scale !== 0 ? $value / $scale : $value;
            } else if (isset($pairs[$addr])) {
                // Traiter les registres 32-bit
                [$key, $scale, $highAddr] = $pairs[$addr];
                $out[$key."(".$addr."-".$highAddr.")"] = $scale !== 0 ? $value / $scale : $value;              
            } else {
                $out[$addr] = $value;
            }
        }

        return $out;
    }
}