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
     * Lire une plage de registres (fonction 03 ou 04), vérifie CRC et retourne
     * un tableau associatif adresse_hex => valeur (entier 16 bits)
     */
    public function readRegisters(int $address, int $count, int $function = 3): ?array
    {
        $frame = pack("CCnn", $this->slaveId, $function, $address, $count);
        $frame .= $this->crc16($frame);

        $this->socket->write($frame);

        $response = $this->socket->read(2048);
        if ($response === null) {
            return null;
        }

        // Vérifier CRC reçu
        if (strlen($response) < 5) {
            return null;
        }

        $body = substr($response, 0, -2);
        $crcRecv = substr($response, -2);
        if ($this->crc16($body) !== $crcRecv) {
            return null;
        }

        // Décodage des registres
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
            $result[$addr] = $values[$i];
        }

        return $result;
    }

    /**
     * Mappe un tableau adresse=>valeur vers des clés lisibles avec conversions.
     * Personnalisez la table `$map` selon la documentation de l'appareil.
     */
    public function mapRegisters(array $registers): array
    {
        // Registres 16-bit simples : adresse => [key, scale]
        $map = [
            0x3100 => ['pv_array_voltage', 100],
            0x3101 => ['pv_array_current', 100],
            0x310C => ['load_voltage', 100],
            0x310D => ['load_current', 100],
            0x3110 => ['battery_temperature', 100],
            0x3111 => ['device_temperature', 100],
            0x311A => ['battery_soc', 1],
            0x311D => ['battery_rated_voltage', 100],
            0x3200 => ['battery_status', 1],
            0x3201 => ['charging_equipment_status', 1],
            0x3202 => ['discharging_equipment_status', 1],
            0x3302 => ['max_battery_voltage_today', 100],
            0x3303 => ['min_battery_voltage_today', 100],
            0x3314 => ['battery_voltage', 100],
        ];

        // Registres 32-bit pairés : adresse_low => [clé, facteur, adresse_high]
        $pairs = [
            0x3102 => ['pv_array_power', 100, 0x3103],
            0x310E => ['load_power', 100, 0x310F],
            0x3304 => ['consumed_energy_today', 100, 0x3305],
            0x3306 => ['consumed_energy_month', 100, 0x3307],
            0x3308 => ['consumed_energy_year', 100, 0x3309],
            0x330A => ['total_consumed_energy', 100, 0x330B],
            0x330C => ['generated_energy_today', 100, 0x330D],
            0x330E => ['generated_energy_month', 100, 0x330F],
            0x3310 => ['generated_energy_year', 100, 0x3311],
            0x3312 => ['total_generated_energy', 100, 0x3313],
            0x3315 => ['battery_current', 100, 0x3316],
        ];

        $out = [];

        // Traiter d'abord les paires 32 bits
        foreach ($pairs as $lowAddr => [$key, $scale, $highAddr]) {
            if (isset($registers[$lowAddr], $registers[$highAddr])) {
                $low = $registers[$lowAddr];
                $high = $registers[$highAddr];
                $value = ($high << 16) | $low;
                $out[$key] = $scale !== 0 ? $value / $scale : $value;
            }
        }

        // Puis les registres simples
        foreach ($registers as $addr => $value) {
            if (isset($map[$addr])) {
                [$key, $scale] = $map[$addr];
                $out[$key] = $scale !== 0 ? $value / $scale : $value;
            } elseif (!in_array($addr, array_column($pairs, 2), true) && !isset($out[sprintf('reg_0x%04X', $addr)])) {
                // On ne mémorise pas les registres haut des paires si déjà traités,
                // et on évite les doublons
                $out[sprintf('reg_0x%04X', $addr)] = $value;
            }
        }

        return $out;
    }
}