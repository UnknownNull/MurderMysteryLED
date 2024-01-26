<?php

namespace mm\tasks;

use pocketmine\scheduler\Task;
use mm\game\Game;
use mm\entities\SwordEntity;

class CollideTask extends Task{
    
    public $plugin;
    public $sword;

    public function __construct(Game $plugin, SwordEntity $sword){
        $this->plugin = $plugin;
        $this->sword = $sword;
    }

    public function onRun(): void{
        if(!$this->sword->isClosed()){
            foreach($this->plugin->players as $player){
                if ($this->sword->getPosition()->distance($player->getPosition()) < 2) {
                    if($this->plugin->getMurderer() !== $player){
                        $this->plugin->killPlayer($player, "§eThe Murderer threw their knife at you");
                        $this->plugin->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($this->sword), 0);
                    }
                }
            }
        }
        if($this->sword->isCollided == true){
            $this->plugin->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($this->sword), 0);
        }
    }
}
