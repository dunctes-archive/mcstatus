<?php

class MinecraftServerStatus
{
    private $timeout;

    public function __construct($timeout = 2)
    {
        $this->timeout = $timeout;
    }

    public function getStatus($host = '127.0.0.1', $port = 25565, $version = '1.8.*')
    {
        if (substr_count($host, '.') != 4) $host = gethostbyname($host);

        $serverdata = [];
        $serverdata['hostname'] = $host;
        $serverdata['hostnameRaw'] = $host;
        $serverdata['version'] = false;
        $serverdata['protocol'] = false;
        $serverdata['players'] = false;
        $serverdata['playerlist'] = "";
        $serverdata['maxplayers'] = false;
        $serverdata['motd'] = false;
        $serverdata['motd_raw'] = false;
        $serverdata['favicon'] = false;
        $serverdata['ping'] = false;

        $socket = $this->connect($host, $port);

        if (!$socket) {
            return false;
        }

        if (preg_match('/1.7|1.8|1.9|1.10|1.11|1.12|1.13/', $version)) {
            $start = microtime(true);
            $handshake = pack('cccca*', hexdec(strlen($host)), 0, 0x04, strlen($host), $host).pack('nc', $port, 0x01);
            socket_send($socket, $handshake, strlen($handshake), 0); //give the server a high five
            socket_send($socket, "\x01\x00", 2, 0);
            socket_read($socket, 1);
            $ping = round((microtime(true) - $start) * 1000); //calculate the high five duration
            $packetlength = $this->read_packet_length($socket);
            if ($packetlength < 0) {
                return false;
            }
            socket_read($socket, 1);
            $packetlength = $this->read_packet_length($socket);
            $data = socket_read($socket, $packetlength, PHP_NORMAL_READ);
            if (!$data) {
                return false;
            }
            $data = json_decode($data);
            $serverdata['version'] = $data->version->name;
            $serverdata['protocol'] = $data->version->protocol;
            $serverdata['players'] = $data->players->online;
            $serverdata['data'] = $data;
            $serverdata['maxplayers'] = $data->players->max;
            $playersArray = [];
            if ($serverdata['players'] == 0) {
                array_push($playersArray, ['It looks like nobody is online right now!']);
            } else if (isset($data->players->sample) && !empty($data->players->sample)) {
//                for ($i = 0; $plist = $data->players->sample[$i]->name; $i++) {
                foreach ($data->players->sample as $player) {
                    var_dump($player);
                    die();


                    if ($i == $serverdata['players'] - 1) {
                        array_push($playersArray, $this->formatPlayer($plist, $data->players->sample[$i]->id));
                        break;
                    } else if ($i == 11 && empty($data->players->sample[12]->name)) {
                        array_push($playersArray, $this->formatPlayer($plist, $data->players->sample[$i]->id));
                        array_push($playersArray, $this->formatPlayer("--- and ".($serverdata['players'] - 12)." more ---", 'null'));
                        break;
                    } else if ($serverdata['players'] == 0) {
                        array_push($playersArray, ["It looks like nobody is online right now!"]);
                        break;
                    }
                    array_push($playersArray, $this->formatPlayer($plist, $data->players->sample[$i]->id));
                }
            } else {
                array_push($playersArray, ["Unfortunately, this server is hiding their player list."]);
            }
            $serverdata['playerlist'] = $playersArray;
            if (!isset($data->description->text) && empty($data->description->text)) {
                $motd = $data->description;
                $motd2 = $data->description;
            } else {
                $motd = $data->description->text;
                $motd2 = $data->description->text;
            }

            $motd = preg_replace("/(§.)/", "", $motd);
            $motd = preg_replace("/[^[:alnum:][:punct:] ]/", "", $motd);
            $serverdata['motd'] = $motd;
            // $serverdata['motd_raw'] = $data->description;
            $serverdata['motd_raw'] = $motd2;
            //$serverdata['favicon'] = $data->favicon;
            if (empty($data->favicon)) {
                $serverdata['favicon'] = "duncte123s-face.png";
            } else {
                $serverdata['favicon'] = $data->favicon;
            }
            $serverdata['ping'] = $ping;
        } else {
            $start = microtime(true);
            socket_send($socket, "\xFE\x01", 2, 0);
            $length = socket_recv($socket, $data, 512, 0);
            $ping = round((microtime(true) - $start) * 1000);//calculate the high five duration

            if ($length < 4 || $data[0] != "\xFF") {
                return false;
            }
            $motd = "";
            $motdraw = "";
            //Evaluate the received data
            if (substr((String) $data, 3, 5) == "\x00\xa7\x00\x31\x00") {
                $result = explode("\x00", mb_convert_encoding(substr((String) $data, 15), 'UTF-8', 'UCS-2'));
                $motd = $result[1];
                $motdraw = $motd;
            } else {
                $result = explode('§', mb_convert_encoding(substr((String) $data, 3), 'UTF-8', 'UCS-2'));
                foreach ($result as $key => $string) {
                    if ($key != sizeof($result) - 1 && $key != sizeof($result) - 2 && $key != 0) {
                        $motd .= '§'.$string;
                    }
                }
                $motdraw = $motd;
            }
            $motd = preg_replace("/(§.)/", "", $motd);
            $motd = preg_replace("/[^[:alnum:][:punct:] ]/", "", $motd); //Remove all special characters from a string
            $serverdata['version'] = $result[0];
            $serverdata['players'] = $result[sizeof($result) - 2];
            $serverdata['maxplayers'] = $result[sizeof($result) - 1];
            $serverdata['motd'] = $motd;
            $serverdata['motd_raw'] = $motdraw;
            $serverdata['ping'] = $ping;
        }
        $this->disconnect($socket);

        return $serverdata;
    }

    private function connect($host, $port)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!socket_connect($socket, $host, $port)) {
            $this->disconnect($socket);

            return false;
        }

        return $socket;
    }

    private function disconnect($socket)
    {
        if ($socket != null) {
            socket_close($socket);
        }
    }

    private function read_packet_length($socket)
    {
        $a = 0;
        $b = 0;
        while (true) {
            $c = socket_read($socket, 1);
            if (!$c) {
                return 0;
            }
            $c = Ord($c);
            $a |= ($c & 0x7F) << $b++ * 7;
            if ($b > 5) {
                return false;
            }
            if (($c & 0x80) != 128) {
                break;
            }
        }

        return $a;
    }

    private function formatPlayer($player, $uuid)
    {
        return [
            'name' => $player,
            'uuid' => $uuid,
        ];
    }
}
