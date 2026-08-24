<?php

namespace Config;

class ConfigEpever
{
    public $host = '0.0.0.0'; // adresse IP d'écoute du serveur TCP
    public $port = 82; // port d'écoute du serveur TCP
    private String $idMacClient = 'd4ad20dd3c8c'; // identifiant unique du client (peut être n'importe quelle valeur unique)
    public $pollIntervalSeconds = 60.0; // temps total souhaité entre deux cycles de lecture
    public $betweenReadsSeconds = 4.0; // délai entre les deux requêtes Modbus du cycle

    //private String $apiUrl = 'https://sysadyp-api-test.cerema.dev/api/v1/connecteurs/17/mesures'; // URL de l'API pour envoyer les données
    //private String $apiToken = 'GLDjeTEkfvKnDkgokDABtSkr7A2YagxH'; // Token d'authentification pour l'API

    private String $apiUrl = 'https://api.sami.cerema.fr/api/v1/connecteurs/27/mesures'; // URL de l'API pour envoyer les données
    private String $apiToken = 'wXducAVT2AEZwFCU4DoiKwDSoeqgJfRK'; // Token d'authentification pour l'API

    // tableau des commandes Modbus à lire, chaque élément est un tableau contenant l'adresse de départ, le nombre de registres à lire et le type de données (1 pour entier, 2 pour flottant, 3 pour double, 4 pour chaîne) 
    private $list_cmd = [
       // [0x3100, 14, 4],
        [0x3100, 2, 4],
        [0x3102, 2, 4],
        [0x310C, 2, 4],
        [0x3110, 2, 4],
        [0x311A, 1, 4],
        [0x3304 , 2, 4],
        [0x330C, 2, 4],
        [0x331A, 3, 4],
    ];

    // tableau de correspondance des registres Modbus avec les noms de variables, les facteurs d'échelle et les unités
    // il faut décommenter les registres que vous souhaitez lire, et commenter ceux que vous ne souhaitez pas lire
    private $map = [
        "0x3100" => ['pv_array_voltage', 100, 'V'],
        "0x3101" => ['pv_array_current', 100, 'A'],
        "0x310C" => ['load_voltage', 100, 'V'],
        "0x310D" => ['load_current', 100, 'A'],
        "0x3110" => ['battery_temperature', 100, '°C'],
        "0x3111" => ['device_temperature', 100, '°C'],
        "0x311A" => ['battery_soc', 1, '%'],
//        "0x311D" => ['battery_rated_voltage', 100, 'V'],
//        "0x3200" => ['battery_status', 1],
//        "0x3201" => ['charging_equipment_status', 1],
//        "0x3202" => ['discharging_equipment_status', 1],
//        "0x3302" => ['max_battery_voltage_today', 100, 'V'],
//        "0x3303" => ['min_battery_voltage_today', 100, 'V'],
        "0x331A" => ['battery_voltage', 100, 'V'],
        //"0x331B" => ['Battery_current_L', 100, 'A'],
       // "0x331C" => ['Battery_current_H', 100, 'A'],
//        "0x3005" => ['rated_charging_current', 100, 'U'],
//        "0x300E" => ['rated_load_current', 100, 'A'],
//        "0x9000" => ['battery_type', 1, 'U'],
//        "0x9001" => ['battery_capacity', 100, 'Ah'],
//        "0x9002" => ['temperature_compensation_coefficient', 2, 'U'],
//        "0x9003" => ['over_voltage_disconnect', 100, 'V'],
//        "0x9004" => ['charging_limit_voltage', 100, 'V'],
//        "0x9005" => ['over_voltage_reconnect', 100, 'V'],
//        "0x9006" => ['equalize_charging_voltage', 100, 'V'],
//        "0x9007" => ['boost_charging_voltage', 100, 'V'],
//        "0x9008" => ['float_charging_voltage', 100, 'V'],
//        "0x9009" => ['boost_reconnect_charging_voltage', 100, 'V'],
//        "0x900A" => ['low_voltage_reconnect', 100, 'V'],
//        "0x900B" => ['under_voltage_warning_recover', 100, 'V'],
//        "0x900C" => ['under_voltage_warning', 100, 'V'],
//        "0x900D" => ['low_voltage_disconnect', 100, 'V'],
//        "0x900E" => ['discharging_limit_voltage', 100, 'V'],
//        "0x900F" => ['battery_rated_voltage_level', 1, 'U'],
//        "0x906A" => ['default_load_on_off_manual', 1, 'U'],
//        "0x906B" => ['equalize_duration', 1, 'U'],
//        "0x906C" => ['boost_duration', 1, 'U'],
//        "0x906D" => ['battery_discharge_percent', 1, '%'],
//        "0x906E" => ['battery_charge_percent', 1, '%'],
//        "0x9070" => ['charging_mode', 1, 'U'],
    ];

    // tableau des paires de registres Modbus pour les valeurs 32 bits, chaque élément est un tableau contenant le nom de la variable, le facteur d'échelle et l'adresse du registre haut
    // il faut décommenter les paires que vous souhaitez lire, et commenter celles que vous ne souhaitez pas lire
    private $pairs = [
        "0x3102" => ['pv_array_power', 100, "0x3103", 'W'],
        "0x331B" => ['battery_current', 100, "0x331C", 'A'],
//        "0x310E" => ['load_power', 100, "0x310F", 'U'],
        "0x3304" => ['consumed_energy_today', 100, "0x3305", '%'],
//        "0x3306" => ['consumed_energy_month', 100, "0x3307", 'U'],
//        "0x3308" => ['consumed_energy_year', 100, "0x3309", 'U'],
//        "0x330A" => ['total_consumed_energy', 100, "0x330B", 'U'],
        "0x330C" => ['generated_energy_today', 100, "0x330D", '%'],
//        "0x330E" => ['generated_energy_month', 100, "0x330F", 'U'],
//        "0x3310" => ['generated_energy_year', 100, "0x3311", 'U'],
//        "0x3312" => ['total_generated_energy', 100, "0x3313", 'U'],
        
    ];

    public function getEntete(): array
    {
        return [
            'apiUrl' => $this->apiUrl,
            'apiToken' => $this->apiToken,
        ];
    }

    public function testIdMacClient(string $idMacClient): bool
    {
        return $this->idMacClient === $idMacClient;
    }

    public function getClientId(): string
    {
        return $this->idMacClient;
    }

    public function getAll()
    {
        return [
            'map' => $this->map,
            'pairs' => $this->pairs,
            'list_cmd' => $this->list_cmd,
        ];
    }


    public function get(string $key)
    {
        switch ($key) {
            case 'map':
                return $this->map;
            case 'pairs':
                return $this->pairs;
            case 'list_cmd':
                return $this->list_cmd;
            default:
                return $this->map;
        }
    }
}
