<?php

namespace mm\utils;

use pocketmine\math\Vector3;
use pocketmine\color\Color;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class TimeUtils
{
  
    /**
     * @param int $int
     * @return string
     */
    public static function intToString(int $int) : string
    {
        $mins = floor($int / 60);
        $seconds = floor($int % 60);
        return (($mins < 10 ? "0" : "") . $mins . ":" . ($seconds < 10 ? "0" : "") . $seconds);
    }
}
