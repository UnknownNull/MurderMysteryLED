<?php

namespace mm\game;

use pocketmine\item\VanillaItems;
use pocketmine\world\{
    World,
    Position
};
use pocketmine\world\sound\{BlazeShootSound, ClickSound, PopSound};
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\scheduler\Task;
use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;
use pocketmine\player\GameMode;
use pocketmine\block\VanillaBlocks;
use pocketmine\player\Player;
use mm\utils\Vector;

class GameTask extends Task{

    protected Game $plugin;

    public $startTime = 31;
    public $gameTime = 5 * 60;
    public $restartTime = 5;

    public $restartData = [];

    public $phase = 0;
    
    public $map = null;

    public $players = [];
    
    public function __construct(Game $plugin){
        $this->plugin = $plugin;
    }

    public function onRun(): void{
        $this->reloadSign();

        if($this->plugin->setup) return;

        $this->plugin->scoreboard();
        switch($this->plugin->phase){
            case Game::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->plugin->extras->get("Minimum-players")){
                    switch($this->startTime){
                        case 30:
                            foreach($this->plugin->players as $player){
                                $player->sendMessage("§eThe game starts in " . $this->startTime . " seconds!");
                            }
                        break;

                        case 20:
                            foreach($this->plugin->players as $player){
                                $player->sendMessage("§eThe game starts in " . $this->startTime . " seconds!");
                            }
                        break;

                        case 10:
                            foreach($this->plugin->players as $player){
                                $player->sendMessage("§eThe game starts in §6" . $this->startTime . "§e seconds!");
                                $player->sendTitle("§a10");
                                $this->plugin->setItem(VanillaBlocks::AIR()->asItem(), 4, $player);
                                $this->addSound($player, "note.hat");
                            }
                        break;
                    }

                    if($this->startTime < 6 && $this->startTime > 0){
                        foreach($this->plugin->players as $player){
                            $this->addSound($player, "note.hat");
                            $player->sendMessage("§eThe game starts in §c" . $this->startTime . "§e seconds!");
                            $player->sendTitle("§c" . $this->startTime);
                        }
                    }

                    if($this->startTime == 0){
                        $this->plugin->phase = Game::PHASE_GAME;
                        $this->plugin->startGame();
                        foreach($this->plugin->players as $player){
                            $this->plugin->giveRoles($player);                            
                            $player->getWorld()->addSound($player->getPosition(), new BlazeShootSound());
                        }
                        if($this->plugin->plugin->extras->get("Randomise") === true){
                                $this->plugin->randomisePlayerNames($player);
                                $this->plugin->randomisePlayerSkins();
                            }
                    }
                    $this->startTime--;
                } else {
                    $this->startTime = 31;
                }
            break;

            case Game::PHASE_GAME:
                if($this->gameTime > 285 && $this->gameTime < 291){
                    foreach($this->plugin->players as $player){
                        $player->sendMessage("§eThe Murderer gets their sword in §c" . ($this->gameTime - 285) . "§e seconds");
                        $this->addSound($player, "note.hat");
                    }
                }
                switch($this->gameTime){
                    case 285:
                        foreach($this->plugin->players as $player){
                            $player->sendMessage("§eThe Murderer has received their sword");
                            $this->plugin->giveItems();
                            $player->sendMessage("§eThe game ends in §c04:45 §eminutes!");
                        }
                    break;

                    case 60 * 4:
                        foreach($this->plugin->players as $player){
                            $player->sendMessage("§eThe game ends in §c04:00 §eminutes!");
                            if($this->plugin->plugin->extras->get("Randomise") === true){
                               $this->plugin->randomisePlayerNames($player);
                               $this->plugin->randomisePlayerSkins();
                            }
                        }
                    break;

                    case 60 * 3:
                        foreach($this->plugin->players as $player){
                            $player->sendMessage("§eThe game ends in §c03:00 §eminutes!");
                        }
                    break;

                    case 60 * 2:
                        foreach($this->plugin->players as $player){
                            $player->sendMessage("§eThe game ends in §c02:00 §eminutes!");
                            if($this->plugin->plugin->extras->get("Randomise") === true){
                                $this->plugin->randomisePlayerNames($player);
                                $this->plugin->randomisePlayerSkins();
                            }
                        }
                    break;

                    case 60:
                        foreach($this->plugin->players as $player){
                            $player->sendMessage("§eThe game ends in §c01:00 §eminute!");
                            $player->sendTitle("§c60 §eseconds left!", "§eAfter 60 seconds the murderer will lose!");
                            if($player !== $this->plugin->getMurderer()){
                                $player->sendMessage("§cWatch out! §eThe murderer got a compass!");
                            } else {
                                $player->sendMessage("§cYou got a compass! §eThe compass points to the nearest player!");
                            }
                            $this->plugin->setItem(VanillaItems::COMPASS(), 4, $player);
                            if($this->plugin->plugin->extras->get("Randomise") === true){
                                $this->plugin->randomisePlayerNames($player);
                                $this->plugin->randomisePlayerSkins();
                            }
                        }
                    break;

                    case 0:
                        $murderer = $this->plugin->getMurderer();
                        if($this->plugin->isPlayer($murderer)){
                            $murderer->sendTitle("§cYOU LOSE!", "§6You ran out of time!");
                            $this->plugin->changeInv[$murderer->getName()] = $murderer;
                            $murderer->getInventory()->clearAll();
                            $murderer->getArmorInventory()->clearAll();
                            $murderer->getCursorInventory()->clearAll();
                            unset($this->plugin->changeInv[$murderer->getName()]);
                            $murderer->getEffects()->clear();
                            $murderer->setGamemode(GameMode::SPECTATOR());
                            $this->plugin->disconnectPlayer($murderer);
                        }
                        $this->plugin->innocentWin();
                    break;
                }
                $this->plugin->checkPlayers();
                $this->gameTime--;
            break;

            case Game::PHASE_RESTART:
                switch($this->restartTime){
                    case 0:
                        foreach($this->plugin->players as $player){
                            if($this->plugin->isPlayer($player)){
                                $this->plugin->removeFromGame($player);
                                $player->setNameTagAlwaysVisible(true);
                                $player->setNameTagVisible();
                            }
                        }
                        foreach($this->plugin->spectators as $spectator){
                            if($this->plugin->isPlayer($spectator)){
                                $this->plugin->removeFromGame($spectator);
                                $spectator->setNameTagAlwaysVisible(true);
                                $spectator->setNameTagVisible();
                                $this->plugin->unsetSpectator($spectator);
                            }
                        }
                        $this->plugin->loadGame(true);
                        $this->reloadTimer();
                    break;
                }
                $this->restartTime--;
            break;
        }
    }

    public function reloadSign(){
        if(!is_array($this->plugin->data["joinsign"]) or empty($this->plugin->data["joinsign"])){
            return;
        }

        $signPos = Position::fromObject(Vector::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getWorldManager()->getWorldByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getWorld() instanceof World){
            return;
        }

        $signText = [
            "§eMurder Mystery",
            "§b- §7| §7[§b-§7/§b-§7]",
            "§cNot available",
            "§cPlease wait..."
        ];

        if($signPos->getWorld()->getTile($signPos) === null){
            return;
        }
        
        if($this->plugin->setup){   
            /** @var Sign $sign */            
            $sign = $signPos->getWorld()->getTile($signPos);
            $sign->setText(new SignText([$signText[0], $signText[1], $signText[2], $signText[3]]));
            return;
        }

        $signText[1] = "§b{$this->plugin->map->getFolderName()} §7| §7[§b" . count($this->plugin->players) . "§7/§b16§7]";

        switch($this->plugin->phase){
            case Game::PHASE_LOBBY:
                if(count($this->plugin->players) >= 16){
                    $signText[2] = "§cFull";
                    $signText[3] = "";
                } else {
                    $signText[2] = "§aTap to join";
                    $signText[3] = "";
                }
            break;

            case Game::PHASE_GAME:
                $signText[2] = "§cAlready started";
                $signText[3] = "";
            break;

            case Game::PHASE_RESTART:
                $signText[2] = "§6Restarting...";
                $signText[3] = "";
            break;
        }
            /** @var Sign $sign */
            $sign = $signPos->getWorld()->getTile($signPos);
            $sign->setText(new SignText([$signText[0], $signText[1], $signText[2], $signText[3]]));
    }

    public function addSound(Player $player, string $sound = '', float $pitch = 1){
        $pk = new PlaySoundPacket();
        $pk->x = $player->getPosition()->getX();
        $pk->y = $player->getPosition()->getY();
        $pk->z = $player->getPosition()->getZ();
        $pk->volume = 4;
        $pk->pitch = $pitch;
        $pk->soundName = $sound;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function reloadTimer(){
        $this->startTime = 31;
        $this->gameTime = 5 * 60;
        $this->restartTime = 5;
    }
}
