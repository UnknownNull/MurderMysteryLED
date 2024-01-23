<?php

namespace mm\game;

use pocketmine\event\Listener;
use pocketmine\block\Block;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\item\{Item, ItemTypeIds};
use pocketmine\entity\object\ItemEntity;
use pocketmine\network\mcpe\protocol\types\BlockPosition;

use pocketmine\event\entity\{
    EntityShootBowEvent,
    EntityDamageEvent,
    ProjectileHitBlockEvent,
    EntityDamageByEntityEvent,
    EntityDamageByChildEntityEvent,
    ProjectileLaunchEvent,
    EntityItemPickupEvent,
    EntityTeleportEvent,
    ProjectileHitEntityEvent
};

use pocketmine\event\player\{
    PlayerInteractEvent,
    PlayerQuitEvent,
    PlayerExhaustEvent,
    PlayerChatEvent,
    PlayerDropItemEvent
};
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\world\{
    World,
    Position
};
use pocketmine\player\Player;
use pocketmine\player\GameMode;
use pocketmine\block\tile\Tile;
use pocketmine\entity\projectile\Arrow;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\math\Vector3;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\StringTag;

use pocketmine\item\{VanillaItems};
use pocketmine\block\{tile\Sign, VanillaBlocks};

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;

use mm\MurderMystery;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use mm\utils\{Vector};
use mm\entities\{NpcEntity, SwordEntity};
use pocketmine\entity\Location;
use mm\tasks\{ArrowTask, CollideTask, CooldownTask, DespawnSwordEntity, SpawnGoldTask, UpdatePlayerPositionTask};
use mm\utils\TimeUtils;

use Vecnavium\FormsUI\SimpleForm;

class Game implements Listener{

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    public $plugin;
    public $task;

    private $randomizeCounter = 0;
    public $phase = 0;

    public $setup = false;
    public $map = null;

    public $data = [];
    public array $gold = [];
    public $players = [];
    public $spectators = [];
    public $cooldown = [];
    public $interactDelay = [];
    public $changeInv = [];
    public $skins = [];
    public $nametags = [];

    public $arrow;
    public $shooter;
    public $murderer;
    public $detective;
    public $deadMurderer;

    public function __construct(MurderMystery $plugin, array $file){
        $this->plugin = $plugin;
        $this->data = $file;
        $this->setup = !$this->enable(false);

        $this->plugin->getScheduler()->scheduleRepeatingTask(new CooldownTask($this), 2);
        $this->plugin->getScheduler()->scheduleRepeatingTask(new UpdatePlayerPositionTask($this), 2);
        $this->plugin->getScheduler()->scheduleRepeatingTask($this->task = new GameTask($this), 20);

        $spawnRate = $this->plugin->getConfig()->get("GoldSpawnRate"); // delay

        if (is_numeric($spawnRate)) {
            $spawnRate = $spawnRate;
        } else {
            $this->plugin->getLogger()->error("Could not set gold spawn rate to $spawnRate! Value has to be a number!");
            $spawnRate = 4;
        }
        
        $this->plugin->getScheduler()->scheduleRepeatingTask(new SpawnGoldTask($this), 20 * $spawnRate);

        if($this->setup){
            if(empty($this->data)){
                $this->createBasicData();
            }
        } else {
            $this->loadGame();
        }
    }

    public function createScoreboard(Player $player, string $title, array $entries){
        $createPacket = new SetDisplayObjectivePacket();

        $createPacket->displaySlot = "sidebar";
        $createPacket->objectiveName = "MurderMystery";
        $createPacket->displayName = $title;
        $createPacket->criteriaName = "dummy";
        $createPacket->sortOrder = 0;
        $player->getNetworkSession()->sendDataPacket($createPacket);

        foreach($entries as $entry){
            $this->setEntry($player, array_search($entry, $entries), $entry);
        }
    }

    public function setEntry(Player $player, int $line, string $msg){
        $entry = new ScorePacketEntry();
        $packet = new SetScorePacket();

        if (count($this->players) < $this->plugin->scoreboard->get("Minimum-players")) { // getConfig
            $status = str_replace("{sec}", $this->task->startTime, $this->plugin->scoreboard->get("WaitingStatus"));
        } else {
            $status = str_replace("{sec}", $this->task->startTime, $this->plugin->scoreboard->get("StartingStatus"));
        }        

        $msg = str_replace([
            "{players}", "{innocents}", "{detec_status}", "{role}", "{map}", "{time}","{status}"
        ], [
            count($this->players), (count($this->players) - 1), $this->getDetectiveStatus(), $this->getRole($player), $this->map->getFolderName(), TimeUtils::intToString($this->task->gameTime), $status
        ], $msg);

        $entry->objectiveName = "MurderMystery";
        $entry->type = 3;
        $entry->customName = " $msg ";
        $entry->score = $line;
        $entry->scoreboardId = $line;

        $packet->type = 0;
        $packet->entries[$line] = $entry;
        $player->getNetworkSession()->sendDataPacket($packet);
    }

    public function removeScoreboard(Player $player){
        $removePacket = new RemoveObjectivePacket();

        $removePacket->objectiveName = "MurderMystery";
        $player->getNetworkSession()->sendDataPacket($removePacket);
    }

    public function scoreboard(){
        foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
            if(!$this->isPlaying($player) && !isset($this->spectators[$player->getName()])){
                return;
            }

            $this->removeScoreboard($player);
            switch($this->phase){
                case Game::PHASE_GAME:
                    $this->createScoreboard($player, $this->plugin->scoreboard->get("Scoreboard-Title"), $this->plugin->scoreboard->get("GameScoreboard"));
                break;

                case Game::PHASE_RESTART:
                    $this->createScoreboard($player, $this->plugin->scoreboard->get("Scoreboard-Title"), $this->plugin->scoreboard->get("RestartScoreboard"));
                break;

                case Game::PHASE_LOBBY:
                    $this->createScoreboard($player, $this->plugin->scoreboard->get("Scoreboard-Title"), $this->plugin->scoreboard->get("LobbyScoreboard"));
                break;
            }
        }
    }

    public function getDetectiveStatus(){
        if(isset($this->detective)){
            if(!is_null($this->getDetective()) && $this->isPlaying($this->getDetective())){
                return "Alive";
            } else {
                return "Dead";
            }
        } else {
            return "Dead";
        }
    }

    public function getRole(Player $player){
        $role = "Dead";

        if($player === $this->getMurderer()){
            $role = "Murderer";
        }

        if($player === $this->getDetective()){
            $role = "Detective";
        }

        if(
            $this->isPlaying($player) &&
            ($player !== $this->getMurderer()) &&
            ($player !== $this->getDetective())
        ){
            $role = "Innocent";
        }

        return $role;
    }

    public function joinLobby(Player $player){
        if(!$this->data["enabled"]){
            $player->sendMessage("§cThis game is currently unavailable, please try again later!");
            return;
        }

        if(count($this->players) >= 16){
            $player->sendMessage("§cThis game is full!");
            return;
        }

        if($this->isPlaying($player)){
            $player->sendMessage("§cYou are already in a game of murder mystery!");
            return;
        }

        $player->teleport(Position::fromObject(Vector::fromString($this->data["lobby"]), $this->map));
        $this->unsetSpectator($player);
        $this->players[$player->getName()] = $player;

        foreach($this->players as $pl){
            $pl->sendMessage("§6" . $player->getName() . "§e has joined (§b" . count($this->players) . "§e/§b16§e)");
        }

        $this->defaultSettings($player);
    }

    public function removeArrow(ProjectileHitBlockEvent $event){
        $entity = $event->getEntity();
        if($entity instanceof Arrow){
            if($entity->getWorld()->getFolderName() == $this->map->getFolderName()){
                $entity->flagForDespawn();
            }
        }
    }

    public function getCoreItems(Player $player){
        $inv = $player->getInventory();

        $lobby = VanillaBlocks::BED()->asItem();
        $lobby->setCustomName("§r§l§cBack to lobby§r");

        $start = VanillaBlocks::POPPY()->asItem();
        $start->setCustomName("§r§l§bStart Game§r");

        $inv->setItem(8, $lobby);
        if($player->hasPermission("murdermystery.forcestart")){ // dropItem - Unrelated
            if($this->task->startTime > 10){
                $inv->setItem(4, $start);
            }
        }
    }

    public function getSpectatorCore(Player $player){
        $inv = $player->getInventory();

        $lobby = VanillaBlocks::BED()->asItem();
        $lobby->setCustomName("§r§l§cBack to lobby§r");

        $tp = VanillaItems::COMPASS();
        $tp->setCustomName("§r§l§aTeleporter§r");

        $inv->setItem(8, $lobby);
        $inv->setItem(0, $tp);
    }

    public function defaultSettings(Player $player){
        $this->changeInv[$player->getName()] = $player->getName();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getEffects()->clear();
        $player->getHungerManager()->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(GameMode::ADVENTURE());
        $this->getCoreItems($player);
        $player->setFlying(false);
        $player->setAllowFlight(false);
        unset($this->changeInv[$player->getName()]);
        $this->skins[$player->getName()] = $player->getSkin();
        $this->nametags[$player->getName()] = $player->getNametag();
    }

    public function removeFromGame(Player $player){
        $this->disconnectPlayer($player);
        $this->changeInv[$player->getName()] = $player->getName();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->getEffects()->clear();
        $player->getHungerManager()->setFood(20);
        $player->setHealth(20);
        $player->setGamemode(GameMode::SURVIVAL());
        $player->setFlying(false);
        $player->setAllowFlight(false);
        unset($this->changeInv[$player->getName()]);
        $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSpawnLocation());
        $this->removeScoreboard($player);
        
        if(isset($this->skins[$player->getName()])){  // just in case 
        $player->setSkin($this->skins[$player->getName()]);
        $player->sendSkin();
        }
        if(isset($this->nametags[$player->getName()])){  // just in case 
        $player->setNametag($this->nametags[$player->getName()]);
        }
    }
    
    public function onPlayerChangeSkin(PlayerChangeSkinEvent $event) {
        $player = $e->getPlayer();
        if($this->isPlaying($player)){
        	$event->cancel();
        }
    }

    public function disconnectPlayer(Player $player, string $quitMsg = ""){
        unset($this->players[$player->getName()]);
        $player->setNameTagAlwaysVisible(true);
        $player->setNameTagVisible();

        if($quitMsg != ""){
            foreach($this->players as $ingame){
                $ingame->sendMessage($quitMsg);
            }
        }
    }

    public function broadcastMessage($player, $message){
        $player->sendMessage($message);
    }

    public function broadcastTitle($player, $title, $subtitle){
        $player->sendTitle($title, $subtitle);
    }

    public function startGame(){
        foreach($this->players as $player){
            $spawn = rand(1, 16);
            $player->teleport(Position::fromObject(Vector::fromString($this->data["spawns"]["spawn-$spawn"]), $this->map));
            $player->setNameTagAlwaysVisible(false);
            $player->setNameTagVisible(false);
            
            $this->changeInv[$player->getName()] = $player->getName();
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            unset($this->changeInv[$player->getName()]);
	    }
    }

    public function randomisePlayerNames(Player $initiatingPlayer) {
        $players = $this->players;
        $totalPlayers = count($players);
        $availableNames = [];

        foreach ($players as $player) {
            $availableNames[] = $player->getName();
        }
        shuffle($availableNames);
        foreach ($players as $index => $player) {
            $randomizedName = array_pop($availableNames);
            $player->setNametag("§e> §f" . $randomizedName . " §e<");
        }
    }


    public function randomisePlayerSkins() {
    $players = $this->players;
    $totalPlayers = count($players);
    $availableSkins = [];
    
    foreach ($players as $player) {
        $availableSkins[] = $player->getSkin();
    }
    shuffle($availableSkins);
    foreach ($players as $index => $player) {
        $randomizedSkin = array_pop($availableSkins);
        $player->setSkin($randomizedSkin);
        $player->sendSkin();
       }
   }

    
    public function giveRoles(){
        $innocents = $this->players;

        $murdererIndex = array_rand($innocents);
        $this->murderer = $innocents[$murdererIndex];
        unset($innocents[$murdererIndex]);

        $detectiveIndex = array_rand($innocents);
        $this->detective = $innocents[$detectiveIndex];
        unset($innocents[$detectiveIndex]);
    
        $this->broadcastTitle($this->murderer, "§cYOU ARE THE MURDERER", "§6GOAL: §eKill all players!");
        $this->broadcastTitle($this->detective, "§bYOU ARE THE DETECTIVE", "§6GOAL: §eFind and stop the Murderer!");
        
        foreach($innocents as $innocent){
            $this->broadcastTitle($innocent, "§aYOU ARE INNOCENT", "§6GOAL: §eStay alive as long as possible!");
        }
    }

    public function giveItems(){
        $murderer = $this->getMurderer();
        $detective = $this->getDetective();

        $this->setItem(VanillaItems::IRON_SWORD(), 1, $murderer, "§aKnife");
        $this->setItem(VanillaItems::BOW(), 1, $detective, "§aBow");

        if($this->isPlayer($murderer)){
            $murderer->getInventory()->setHeldItemIndex(0);
        }
        if($this->isPlayer($detective)){
            $detective->getInventory()->setHeldItemIndex(0);
        }

        $this->giveArrow();
    }

    public function getMurderer(){
        if(isset($this->murderer) && $this->isPlayer($this->murderer) && $this->murderer instanceof Player){
            return $this->murderer;
        }
    }

    public function getDetective(){
        if(isset($this->detective) && $this->isPlayer($this->detective) && $this->detective instanceof Player){
            return $this->detective;
        }
    }

    public function isPlayer($player){
        if($player instanceof Player && $player->isOnline()){
            return true;
        } else {
            return false;
        }
    }

    // usage =
    public function setItem(Item $item, int $slot, $player, string $name = null){
        if($this->isPlayer($player)){
            $this->changeInv[$player->getName()] = $player->getName();
            
            if($name != null){
                $item->setCustomName("§r" . $name);
            }

            $player->getInventory()->setItem($slot, $item);
            
            unset($this->changeInv[$player->getName()]);
        }
    }

    public function playSound(Player $player, string $sound){
        $pk = new PlaySoundPacket();
        $pk->x = $player->getPosition()->getX();
        $pk->y = $player->getPosition()->getY();
        $pk->z = $player->getPosition()->getZ();
        $pk->volume = 1;
        $pk->pitch = 1;
        $pk->soundName = $sound;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function giveArrow(){
        $this->setItem(VanillaItems::ARROW(), 9, $this->getDetective());
    }

    public function murdererWin(){
        foreach($this->spectators as $spectator){
            $this->broadcastMessage($spectator, "§cYOU LOSE! §6The Murderer killed everyone!");
            $this->broadcastTitle($spectator, "§cYOU LOSE!", "§6The Murderer killed everyone!");
            $this->resetGold($spectator);
        }

        $murderer = $this->getMurderer();
        if ($murderer instanceof Player){
            $this->playSound($murderer, "random.levelup");
        }
        $this->broadcastMessage($murderer, "§aYOU WIN! §6You have killed everyone!");
        $this->broadcastTitle($murderer, "§aYOU WIN!", "§6You have killed everyone!");
        $this->resetGold($murderer); // pickup

        $this->startRestart();
    }

    public function innocentWin(){
        foreach($this->spectators as $spectator){
            if($this->deadMurderer !== $spectator){
                $this->playSound($spectator, "random.levelup");
                $this->broadcastMessage($spectator, "§aYOU WIN! §6The Murderer has been stopped!");
                $this->broadcastTitle($spectator, "§aYOU WIN!", "§6The Murderer has been stopped!");
                $this->resetGold($spectator);
            }
        }

        foreach($this->players as $player){
            $this->playSound($player, "random.levelup");
            $this->broadcastMessage($player, "§aYOU WIN! §6The Murderer has been stopped!");
            $this->broadcastTitle($player, "§aYOU WIN!", "§6The Murderer has been stopped!");
            $this->resetGold($player);
        }
        $this->startRestart();
    }

    public function startRestart(){
        $this->phase = self::PHASE_RESTART;

        foreach($this->map->getEntities() as $entity){
            if($entity instanceof SwordEntity && $entity instanceof Arrow && $entity instanceof ItemEntity){
                $entity->close();
            }
        }
        unset($this->murderer);
        unset($this->detective);
    }

    public function isPlaying(Player $player){
        return isset($this->players[$player->getName()]);
    }

    public function onInteract(PlayerInteractEvent $event, Location $location){
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $level = $player->getWorld();
        $item = $event->getItem();

        if($this->isPlaying($player)){
            if($block->getTypeId() == 10072 or $block->getTypeId() == 10094 or $block->getTypeId() == 10384 or $block->getTypeId() == 10023 or $block->getTypeId() == 10031){
                $event->cancel();
                return;
            }
            if($item === VanillaItems::BOW()){
                $this->shooter = $player;
            }
        }

        if (!$block instanceof Sign) {
            return;
        }

        $signPos = Position::fromObject(Vector::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block->getPosition())) || $signPos->getWorld()->getId() != $level->getId()){
            return;
        }

        if($this->phase == self::PHASE_GAME){
            $player->sendMessage("§cThis game has already started!");
            return;
        }

        if($this->phase == self::PHASE_RESTART){
            $player->sendMessage("§cThis game is restarting!");
            return;
        }

        if($this->setup){
            return;
        }

        $this->joinLobby($player);
    }

    public function openTeleporter(Player $player){
        $form = new SimpleForm(function(Player $player, $data = null){
            $players = [];		
            if($data === null){
                return true;
            }
            foreach($this->players as $ingame){
                $players[] = $ingame;
            }
            $players[$player->getName()] = $players;
            $target = $players[$player->getName()][$data];
			if($target instanceof Player){
                if($this->isPlaying($target)){
                    /** Get Targets Location rather than Position */
                    $player->teleport($target->getLocation());
                } else {
                    $player->sendMessage("§cThis player is no longer in this game!");
                }
            } else {
                $player->sendMessage("§cInvalid player!");
            }
            return true;
        });
        $form->setTitle("§l§aTeleporter");
        $form->setContent("§7Choose the player you want to spectate:");
        foreach($this->players as $p){
            $form->addButton($p->getName(), -1, $p->getName());
        }
        $form->sendToPlayer($player);
        return $form;
    }

    public function onQuit(PlayerQuitEvent $event){
        $player = $event->getPlayer();
        if($this->isPlaying($player)){
            if($this->phase == 0){
                $this->disconnectPlayer($player, "§e" . $player->getName() . " has left (§b" . (count($this->players) - 1) . "§e/§b16§e)");
            } else {
                $this->disconnectPlayer($player, "§7" . $player->getName() . " disconnected.");
            }
        }
        if(isset($this->spectators[$player->getName()])){
            $this->unsetSpectator($player);
        }
        $this->removeScoreboard($player);
    }

    public function onTeleport(EntityTeleportEvent $event) {
        $player = $event->getEntity();
        $lastWorld = $event->getFrom()->getWorld()->getFolderName();
        $currentWorld = $event->getTo()->getWorld()->getFolderName();
    
        if ($currentWorld !== $lastWorld) {
            if ($player instanceof Player) {
                if ($this->isPlaying($player)) {
                    if ($this->phase == 0) {
                        $this->disconnectPlayer($player, "§e" . $player->getName() . " has left (§b" . (count($this->players) - 1) . "§e/§b16§e)");
                    } else {
                        $this->disconnectPlayer($player, "§7" . $player->getName() . " disconnected.");
                    }
                }
    
                if (isset($this->spectators[$player->getName()])) {
                    $this->unsetSpectator($player);
                }
    
                $this->removeScoreboard($player);
            }
        }
    }

    public function onItemUse(PlayerItemUseEvent $event){
        $player = $event->getPlayer();
        $customName = $event->getItem()->getCustomName();
        $item = $event->getItem();
        if($this->isPlaying($player)){
            switch($customName){
            case "§r§l§cBack to lobby§r":
               $this->removeFromGame($player);
               break;
            case "§r§l§aTeleporter§r":
                if($this->phase == 1){
                    $this->openTeleporter($player);
                }
               break;
            case "§r§l§bStart Game§r":
                // forgot logic? WIP
                break;
            }
            /** ? */
            if($item->getTypeId() == ItemTypeIds::IRON_SWORD){
                if(!isset($this->cooldown[$player->getName()])){
                    $this->createSwordEntity($player);
                }
            }
        }
    }

    public function onShootBow(EntityShootBowEvent $event) : void {
        $entity = $event->getEntity();
        $projectile = $event->getProjectile();
        if($entity instanceof Player){
           if($projectile instanceof Arrow){
            if($this->isPlaying($entity)){
                $this->shooter = $entity; 
            }
           }
        }
    }

    public function onShoot(ProjectileLaunchEvent $event){
        if(!isset($this->shooter)){
            return;
        }
        if($this->getDetective() === null){
            return;
        }
        if(!$this->isPlaying($this->shooter)){
            return;
        }
        if($event->getEntity() instanceof Arrow){
            $detective = $this->getDetective();
            $this->arrow = time();
            $arrow = VanillaItems::ARROW();
            $this->changeInv[$this->shooter->getName()] = $this->shooter->getName();
            $this->shooter->getInventory()->remove($arrow);
            unset($this->changeInv[$this->shooter->getName()]);
            if($this->shooter === $detective){
                $this->plugin->getScheduler()->scheduleDelayedTask(new ArrowTask($this), 140);
                $this->cooldown[$detective->getName()] = microtime(true) + 7;
            }
        }
    }

    public function playerKillPlayer($killer, $victim){
        if($this->isPlayer($killer) && $this->isPlayer($victim)){
            if($killer == $this->getMurderer()){
                $this->killPlayer($victim);
                return;
            }
            if($victim == $killer){
                $this->killPlayer($victim, "§eYou killed yourself!");
                return;
            }
            if($victim !== $this->getMurderer()){
                $this->killPlayer($killer, "§eYou killed an innocent player!");
            }
            $this->killPlayer($victim, "§eA player killed you!");
        }
    }

    public function onDamage(EntityDamageEvent $event){
        $player = $event->getEntity();	    
        if($player instanceof Player){
            if($this->isPlaying($player)){
                if($event instanceof EntityDamageByEntityEvent){
                    $murderer = $event->getDamager();
                    if($murderer === $this->getMurderer()){
                        if($murderer instanceof Player && $murderer->getInventory()->getItemInHand()->getTypeId() == 20138){
                            $this->killPlayer($player);
                        }
                    }
                }
                if($event instanceof EntityDamageByChildEntityEvent){
                    $entity = $event->getChild();
                    if($entity instanceof Arrow){
                        if(isset($this->shooter)){
                            $this->playerKillPlayer($this->shooter, $player);
                        }
                    }
                }
                $event->cancel();
            }
        }
    }

    public function onHunger(PlayerExhaustEvent $event){
        $player = $event->getPlayer();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                $event->cancel();
            }
        }
    }


    public function addGold(Player $player, int $amount): void {
        $playerName = $player->getName();
        $this->ensurePlayerExists($playerName);
        $this->gold[$playerName] += $amount;
    }

    public function getGold(Player $player): int {
        $playerName = $player->getName();
        $this->ensurePlayerExists($playerName);
        return $this->gold[$playerName] ?? 0;
    }

    public function resetGold(Player $player): void {
        $playerName = $player->getName();
        $this->ensurePlayerExists($playerName);
        $this->gold[$playerName] = 0;
    }

    private function ensurePlayerExists(string $playerName): void {
        if (!isset($this->gold[$playerName])) {
            $this->gold[$playerName] = 0;
        }
    }

    public function onChat(PlayerChatEvent $event){
        $player = $event->getPlayer();
        if(isset($this->spectators[$player->getName()])){
            $event->cancel();
            foreach($this->spectators as $spectator){
                $spectator->sendMessage("§7Dead Chat " . $player->getName() . ">> " . $event->getMessage());
            }
        }
    }
    
    public function killPlayer($player, $subtitle = "§eThe Murderer stabbed you!"){
        if($this->isPlayer($player)){
            $this->changeInv[$player->getName()] = $player;
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $this->getSpectatorCore($player);
            unset($this->changeInv[$player->getName()]);
            $player->setGamemode(GameMode::SPECTATOR());

            foreach($this->players as $ingame){
                $this->playSound($ingame, "game.player.die");
            }

            if($player === $this->getMurderer()){
                $this->deadMurderer = $player;
                $this->spectators[$player->getName()] = $player;
                $player->sendTitle("§cYOU LOSE", "§6You have been killed!");
                $player->sendMessage("§cYOU LOSE §6You have been killed!");
                $this->disconnectPlayer($player);
                $this->innocentWin();
                return;
            }
            $player->sendTitle("§cYOU DIED!", $subtitle);
            $player->sendMessage("§cYOU DIED! $subtitle");
            $player->sendMessage("§eAs a Spectator, you can chat with fellow Spectators.");
            $player->sendMessage("§6Alive players can't see dead players' chat.");
            $this->spectators[$player->getName()] = $player;
            if($player === $this->getDetective()){
                if(count($this->players) > 2){
                    $this->detectiveDied();

                    foreach($this->players as $inno){
                        if(($inno !== $this->getDetective()) && ($inno !== $this->getMurderer())){
                            $this->setSpawnPositionPacket($inno, $player->getPosition()->asVector3());
                        }
                    }

                    $this->dropItem($this->map, VanillaItems::BOW(), $player->getPosition());
                }
            }
            $this->disconnectPlayer($player);
            if(count($this->players) == 2){
                $this->setItem(VanillaItems::COMPASS(), 4, $this->getMurderer());
                foreach($this->players as $player){
                    if($player !== $this->getMurderer()){
                        $player->sendTitle("§cWatch out!", "§eThe murderer got a compass!");
                    } else {
                        $player->sendTitle("§cYou got a compass!", "§eThe compass points to the last player!");
                    }
                }
            }
        }
        $this->checkPlayers();
    }

    public function setSpawnPositionPacket(Player $player, Vector3 $pos){ 
       $blockPosition = new BlockPosition(
        round($pos->x),
        round($pos->y),
        round($pos->z)
    );

    $pk = new SetSpawnPositionPacket();
    $pk->spawnPosition = $blockPosition;
    $pk->dimension = DimensionIds::OVERWORLD;
    $pk->spawnType = SetSpawnPositionPacket::TYPE_WORLD_SPAWN;
    $pk->causingBlockPosition = $blockPosition;
    $player->getNetworkSession()->sendDataPacket($pk);
	}

    public function onDrop(PlayerDropItemEvent $event){
        $player = $event->getPlayer();
        if($this->isPlaying($player)){
            $event->cancel(); // win
        }
    }

    public function shooterP(Player $player){
        $shooter = "?"; /** ? */
        if ($this->shooter instanceof Player) {
            $shooter = $this->shooter->getName();
        }
        return $shooter;
    }

    public function winAnnouncement(Player $player) {
        if ($this->plugin->getConfig()->get("Win-Announcement") == true) {
            $murderer = $this->getMurderer()->getName();
            $detective = $this->getDetective()->getName();
            $winner = '';
    
            if (count($this->players) == 1) {
                if ($this->getMurderer() === $player) {
                    $winner = "Murderer $murderer";
                } else {
                    $allPlayers = [];
                    foreach ($this->players as $p) {
                        $allPlayers[] = $p->getName();
                    }
                    sort($allPlayers);
                    $winner = "Innocents " . implode(', ', $allPlayers);
                }
            }
    
            $shooter = $this->shooterP($player);
            $winMessage = $this->plugin->getConfig()->get("Win-Announcement-Message");
    
            $winMessage = str_replace("{murderer}", $murderer, $winMessage);
            $winMessage = str_replace("{detective}", $detective, $winMessage);
            $winMessage = str_replace("{shooter}", $shooter, $winMessage);
            $winMessage = str_replace("{winner}", $winner, $winMessage);
    
            $this->plugin->getServer()->broadcastMessage($winMessage);
        }
    }    

    public function onPickup(EntityItemPickupEvent $event){
        $player = $event->getEntity();
        $item = $event->getItem();
    
        if (!$player instanceof Player) {
            return;
        }
    
        $inv = $player->getInventory();
    
        if ($this->isPlaying($player)) {
            if ($item->getTypeId() == ItemTypeIds::BOW) {
                if ($player !== $this->getMurderer()) {
                    $this->newDetective($player);
                } else {
                    $event->cancel();
                }
            }
    
            if ($item->getTypeId() == ItemTypeIds::GOLD_INGOT) {
                $this->addGold($player, 1);
                if ($inv->contains(VanillaItems::GOLD_INGOT())) {
                    $this->changeInv[$player->getName()] = $player;
                    $inv->remove(VanillaItems::GOLD_INGOT());
                    $inv->setItem(8, VanillaItems::GOLD_INGOT());
                    unset($this->changeInv[$player->getName()]);
                } else {
                    $this->setItem(VanillaItems::GOLD_INGOT(), 8, $player);
                }

                if($player !== $this->getDetective() OR $player !== $this->getMurderer()){
                    if($this->getGold($player) == $this->plugin->extras->get("MM-Bow-Gold-Required")){
                        if($this->isPlaying($player)){
                            $player->sendTitle("§a+1 Bow Shot!", "§eYou collected 10 gold and got an arrow!");
                            $this->giveBow($player);
                            $this->resetGold($player);
                        }
                    }
                }

                if($player === $this->getDetective()){
                    if($this->getGold($player) == $this->plugin->extras->get("MM-Role-Detector-Gold-Required")){
                        if($this->isPlaying($player)){
                            $player->sendMessage("§cThe Murderer is: §r" . $this->getMurderer()->getName());
                            $player->sendMessage("To check players role, pause menu, on the right side find the players name button, click on it and you'll see his skin.");
                            $player->sendTitle("§aRole Detector!", "§eClaimed!");
                            $this->resetGold($player);
                        }
                    }
                }
            }
        }
    }

    public function giveBow(Player $player){
        $this->setItem(VanillaItems::BOW(), 0, $player);
        $this->changeInv[$player->getName()] = $player;
        /** To add a "add a claim sound to the player when he successfully collects a bow or an arrow" */
        $this->playSound($player, "entity.experience_orb.pickup");
        $player->getInventory()->addItem(VanillaItems::ARROW());
        $player->getInventory()->remove(VanillaItems::GOLD_INGOT());
        unset($this->changeInv[$player->getName()]);
    }

    public function onInvChange(InventoryTransactionEvent $event){ // SpawnPositionPacket
        $player = $event->getTransaction()->getSource();
        if($player instanceof Player){
            if($this->isPlaying($player)){
                if($this->phase == self::PHASE_GAME){
                    if(!isset($this->changeInv[$player->getName()])){ // onInteract
                        $event->cancel();
                    }
                }
            }
        }
    }

    public function newDetective(Player $player){
        foreach($this->players as $ingame){
            if($ingame !== $this->getMurderer()){
                $this->setItem(VanillaBlocks::AIR()->asItem(), 4, $ingame);
            }
            if($ingame !== $player){
                $ingame->sendTitle("§r§l§e", "§eA players has picked up the bow!");
                $ingame->sendMessage("§eA player has picked up the bow!");
            }
        }
        $this->changeInv[$player->getName()] = $player;
        $player->getInventory()->remove(VanillaItems::BOW());
        $player->getInventory()->remove(VanillaItems::ARROW());
        $player->sendTitle("§aYou picked up the bow!", "§6GOAL: §eFind and kill the murderer!");
        $this->detective = $player;
        $this->setItem(VanillaItems::BOW(), 1, $player, "§aBow");
        $this->giveArrow();
    }
	
    /**
     * @param World $level
     * @param Item $item
     * @param Vector3 $pos
     * @param Vector3|null $motion
     * @return ItemEntity|null
     */
    public function dropItem(World $level, Item $item, Vector3 $pos, Vector3 $motion = null): ?ItemEntity {
        return $level->dropItem($pos, $item, $motion);
    }

    public function unsetSpectator($player){
        unset($this->spectators[$player->getName()]);
        $player->setNameTagAlwaysVisible(true);
        $player->setNameTagVisible();
    }

    public function checkPlayers(){
        if(count($this->players) == 1){
            foreach($this->players as $player){
                if($player === $this->getMurderer()){
                    $this->murdererWin();
                } else {
                    $this->innocentWin();
                }
            }
        }
    }

    public function detectiveDied(){
        foreach($this->players as $player){
            if(($player !== $this->getMurderer()) && ($player !== $this->getDetective())){
                $this->setItem(VanillaItems::COMPASS(), 4, $player);
            }
            $player->sendTitle("§6The Detective has been killed!", "§eFind the bow for a chance to kill the Murderer.");
            $player->sendMessage("§6The Detective has been killed! §eFind the bow for a chance to kill the Murderer.");
        }
    }

    public function createSwordEntity(Player $player){
        $sword = new SwordEntity(
            $player->getLocation(), 
            $player->getLocation()->getYaw() - 75, 
            $player->getLocation()->getPitch()
        );
        $sword->setMotion($sword->getMotion()->multiply($this->plugin->extras->get("Throwable-Sword-Speed")));
        $sword->setPose();
        $sword->setInvisible();
        $sword->spawnToAll();
        $this->plugin->getScheduler()->scheduleRepeatingTask(new CollideTask($this, $sword), 0);
        $this->plugin->getScheduler()->scheduleDelayedTask(new DespawnSwordEntity($sword), 100);
        $this->cooldown[$player->getName()] = microtime(true) + 7;
    }

    public function loadGame(bool $restart = false){
        if(!$this->data["enabled"]){
            $this->plugin->getLogger()->error("A disabled game has been found! Enable it and restart the server to load it!");
            return;
        }

        if(!$restart){
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["map"])){
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["map"]);
            }

            $this->map = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["map"]);
        } else {
            $this->task->reloadTimer();
        }

        if(!$this->map instanceof World){
            $this->map = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["map"]);
        }

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    public function enable(bool $loadArena = true){
        if(empty($this->data)){
            return false;
        }

        if($this->data["map"] == null){
            return false;
        }

        if(!$this->plugin->getServer()->getWorldManager()->isWorldGenerated($this->data["map"])){
            return false;
        } else {
            if(!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($this->data["map"]))
                $this->plugin->getServer()->getWorldManager()->loadWorld($this->data["map"]);
            $this->map = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->data["map"]);
        }

        if($this->data["lobby"] == null){
            return false;
        }

        if(!is_array($this->data["spawns"])){
            return false;
        }

        if(!is_array($this->data["gold"])){
            return false;
        }

        if(count($this->data["spawns"]) != 16){
            return false;
        }

        if(count($this->data["gold"]) != $this->data["goldspawns"]){
            return false;
        }

        $this->plugin->provider->saveGames();
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena){
            $this->loadGame();
        }
        return true;
    }

    private function createBasicData(){
        $this->data = [
            "map" => null,
            "lobby" => null,
            "spawns" => [],
            "gold" => [],
            "goldspawns" => $this->plugin->getConfig()->get("GoldSpawns"),
            "joinsign" => null,
            "enabled" => false,
        ];
    }

    public function __destruct(){
        unset($this->task);
    }
}
