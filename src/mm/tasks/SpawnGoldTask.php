<?php

namespace mm\tasks;

use pocketmine\scheduler\Task;
use pocketmine\world\Position;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3; // Add this line
use mm\game\Game;
use mm\utils\Vector;

class SpawnGoldTask extends Task {

    public function __construct(public Game $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        switch ($this->plugin->phase) {
            case Game::PHASE_GAME:
                $spawns = (int) $this->plugin->plugin->getConfig()->get("GoldSpawns");
                $spawn = mt_rand(1, $spawns);
                $goldSpawnKey = "gold-" . $spawn;
                $goldSpawnPosition = Vector::fromString($this->plugin->data["gold"][$goldSpawnKey]);
                $world = $this->plugin->plugin->getServer()->getWorldManager()->getDefaultWorld();
                $vector3 = new Vector3($goldSpawnPosition->getX(), $goldSpawnPosition->getY(), $goldSpawnPosition->getZ());
                $position = Position::fromObject($vector3, $world);
                $this->plugin->dropItem($this->plugin->map, VanillaItems::GOLD_INGOT(), $position);
                break;
        }
    }
}