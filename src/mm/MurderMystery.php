<?php

namespace mm;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{CommandSender, Command};
use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\item\VanillaItems;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\player\{PlayerInteractEvent, PlayerChatEvent, PlayerJoinEvent, PlayerItemUseEvent, PlayerDropItemEvent};
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntityDataHelper;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\World;

use mm\utils\{GameChooser, SwordEntity, Vector};
use mm\provider\Provider;
use mm\game\Game;

use Vecnavium\FormsUI\{SimpleForm, CustomForm};

class MurderMystery extends PluginBase implements Listener{

    public $provider;
    private $gamechooser;

    public $setupData = [];
    public $editors = [];
    public $games = [];

    public $spawns = [];
    public $gold = [];

    public $prefix;
    public $noPerms = "§cYou don't have permission to use this command!";

    protected function onLoad(): void{
        $this->provider = new Provider($this);
        $this->gamechooser = new GameChooser($this);
    }

    protected function onEnable(): void{
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->provider->loadGames();
	EntityFactory::getInstance()->register(SwordEntity::class, function(World $world, CompoundTag $nbt) : SwordEntity{
	    return new SwordEntity(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);
	}, ['SwordEntity']);
    }
	    
    public function onCommand(CommandSender $sender, Command $cmd, string $str, array $args) : bool{
        if(strtolower($cmd) == "murdermystery"){
            if(count($args) == 0){
                $sender->sendMessage("§7Use §b/murdermystery help");
                return true;
            }
            switch($args[0]){
                case "help":
                    $sender->sendMessage("§b/murdermystery help§f: §7Shows a list of available commands");
                    if($sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage("§b/murdermystery create <name>§f: §7Create a new game of murder mystery");
                        $sender->sendMessage("§b/murdermystery remove <name>§f: §7Remove a game of murder mystery");
                        $sender->sendMessage("§b/murdermystery edit <name>§f: §7Edit a game of murder mystery");
                        $sender->sendMessage("§b/murdermystery list§f: §7Shows a list of murder mystery games");
                        $sender->sendMessage("§b/murdermystery savegames§f: §7Save all murder mystery games");
                    }
                    $sender->sendMessage("§b/murdermystery join§f: §7Join an available game of murder mystery");
                    $sender->sendMessage("§b/murdermystery quit§f: §cLeave!");
                break;

                case "create":
                    if(!$sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /murdermystery create <name>");
                        break;
                    }

                    if(isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 already exists!");
                        break;
                    }

                    $this->games[$args[1]] = new Game($this, []);
                    $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 has been created!");
                break;

                case "remove":
                    if(!$sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /murdermystery remove <name>");
                        break;
                    }

                    if(!isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 does not exist!");
                        break;
                    }

                    $games = $this->games[$args[1]];

                    foreach($games->players as $player){
                        $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
                        $player->sendMessage("§cThe game you were in just crashed! You have been teleported to the lobby!");
                    }

                    if(is_file($file = $this->getDataFolder() . "games" . DIRECTORY_SEPARATOR . $args[1] . ".yml")){
                        unlink($file);
                    }
                    unset($this->games[$args[1]]);

                    $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 has been removed!");
                break;

                case "edit":
                    if(!$sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(!isset($args[1])){
                        $sender->sendMessage($this->prefix . "§7Use /murdermystery edit <name>");
                        break;
                    }

                    if(!isset($this->games[$args[1]])){
                        $sender->sendMessage($this->prefix . "§7" . $args[1] . "§r§7 does not exist!");
                        break;
                    }

                    if(!$sender instanceof Player){
                        $sender->sendMessage($this->prefix . "§7Use this command in-game!");
                    }

                    if(isset($this->editors[$sender->getName()])){
                        $sender->sendMessage($this->prefix . "§7You are already in setup mode!");
                        break;
                    }

                    $sender->sendMessage("§l§7-= §cMurder Mystery §7=-");
                    $sender->sendMessage("§6use the paper to configure");
                    $sender->sendMessage("§6use the emerald enable & complete");
                    $this->giveSetupItems($sender);
                    $this->editors[$sender->getName()] = $this->games[$args[1]];
                break;

                case "list":
                    if(!$sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }

                    if(count($this->games) == 0){
                        $sender->sendMessage($this->prefix . "§7There aren't any arenas!");
                        break;
                    }

                    $list = "§l§7-= §cMurder Mystery §7=-§r\n";
                    foreach($this->games as $name => $arena){
                        if($arena->setup){
                            $list .= "§7$name §r§f: §cdisabled\n";
                        } else {
                            $list .= "§7$name §r§f: §aenabled\n";
                        }
                    }

                    $sender->sendMessage($list);
                break;

                case "savegames":
	            if(!$sender->hasPermission("murdermystery.op")){
                        $sender->sendMessage($this->noPerms);
                        break;
                    }
			    
                    $this->provider->saveGames();
                    $sender->sendMessage($this->prefix . "§7All games have been saved!");
                break;
			    
		case "quit":
		    if($sender->hasPermission("murdermystery.noop")){
			    
		    }
                    if(!$sender instanceof Player){
                        $sender->sendMessage("§cUse this command in-game!");
                        break;
                    }
			    
		    $sender->sendMessage("This is Comming Soon pls try again soon!");
                break;
			    
                case "join": // item
		    if($sender->hasPermission("murdermystery.noop")){
			    
		    }
                    if(!$sender instanceof Player){
                        $sender->sendMessage("§cUse this command in-game!");
                        break;
                    }

                    $this->joinGame($sender);
                break;

                default:
                    $sender->sendMessage("§cThis is not a valid Murder Mystery command. Type §6/murdermystery help§c to view a list of available murder mystery commands.");
                break;
            }
        }
        return true;
    }
	

    // public function onChat(PlayerChatEvent $event){
    //     $player = $event->getPlayer();

    //     if(isset($this->editors[$player->getName()])){
    //         $event->cancel();
    //         $args = explode(" ", $event->getMessage());
    //         $game = $this->editors[$player->getName()];

    //         switch($args[0]){
    //             case "help":
    //                 $player->sendMessage("§l§7-= §cMurder Mystery §7=-");
    //                 $player->sendMessage("§6help§f: §7Shows a list of available setup commands");
    //                 $player->sendMessage("§6map <name>§f: §7Set the map");
    //                 $player->sendMessage("§6lobby§f: §7Set waiting lobby");
    //                 $player->sendMessage("§6spawn§f: §7Set the spawn positions");
    //                 $player->sendMessage("§6gold§f: §7Set the gold spawn positions");
    //                 $player->sendMessage("§6joinsign§f: §7Set the joinsign for the game");
    //                 $player->sendMessage("§6enable§f: §7Enable the game");
    //             break;

    //             case "map":
    //                 if(!isset($args[1])){
    //                     $game->data["map"] = $player->getWorld()->getFolderName();
    //                     $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $player->getWorld()->getFolderName());
    //                     break;
    //                 }

    //                 if(!$this->getServer()->getWorldManager()->isWorldGenerated($args[1])){
    //                     $player->sendMessage($this->prefix . "§7World §6" . $args[1] . "§r§7 does not exist!");
    //                     break;
    //                 }

    //                 $game->data["map"] = $args[1];
    //                 $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $args[1]);
    //             break;

    //             case "lobby":
    //                 $game->data["lobby"] = (new Vector($player->getPosition()->getX(), $player->getPosition()->getY(), $player->getPosition()->getZ()))->__toString();
    //                 $player->sendMessage($this->prefix . "§7Waiting lobby has been set to §6" . round($player->getPosition()->getX()) . ", " . round($player->getPosition()->getY()) . ", " . round($player->getPosition()->getZ()));
    //             break;

    //             case "spawn":
    //                 $this->spawns[$player->getName()] = 1;
    //                 $player->sendMessage($this->prefix . "§7Touch the spaws for the players!");
    //             break;

    //             case "gold":
    //                 $this->gold[$player->getName()] = 1;
    //                 $player->sendMessage($this->prefix . "§7Touch the spaws for the gold!");
    //             break;

    //             case "joinsign":
    //                 $player->sendMessage($this->prefix . "§7Break a sign to set the joinsign");
    //                 $this->setupData[$player->getName()] = 0;
    //             break;

    //             case "enable":
    //                 if(!$game->setup){
    //                     $player->sendMessage($this->prefix . "§7This arena is already enabled!");
    //                     break;
    //                 }

    //                 if(!$game->enable()){
    //                     $player->sendMessage($this->prefix . "§7Could not enable the arena, complete the setup first!");
    //                     break;
    //                 }

    //                 $player->sendMessage($this->prefix . "§7Arena has been enabled!");
    //             break;

    //             case "done":
    //                 $player->sendMessage($this->prefix . "§7You have left the setup mode");
    //                 unset($this->editors[$player->getName()]);
    //                 if(isset($this->setupData[$player->getName()])){
    //                     unset($this->setupData[$player->getName()]);
    //                 }
    //             break;

    //             default:
    //                 $player->sendMessage("§l§7-= §cMurder Mystery §7=-");
    //                 $player->sendMessage("§6help §f: §7View a list of available setup commands");
    //                 $player->sendMessage("§6done §f: §7Leave setup mode");
    //             break;
    //         }
    //     }
    // }

    public function giveSetupItems(Player $player): void {
        $player->getInventory()->clearAll();

        $emerald = VanillaItems::EMERALD();
        $emerald->setCustomName("§r§l§aComplete");
        $player->getInventory()->setItem(4, $emerald);

        $paper = VanillaItems::PAPER();
        $paper->setCustomName("§r§l§6Setup");
        $player->getInventory()->setItem(0, $paper);

        $bed = VanillaBlocks::BED()->asItem();
        $bed->setCustomName("§r§l§cLeave / Done");
        $player->getInventory()->setItem(8, $bed);
    }
    

    public function onItemUse(PlayerItemUseEvent $event){
        $player = $event->getPlayer();
        $customName = $event->getItem()->getCustomName();
        $item = $event->getItem();
        if(isset($this->editors[$player->getName()])){
            $game = $this->editors[$player->getName()];
            switch($customName){
                case "§r§l§aComplete":
                    if(!$game->setup){
                        $player->sendMessage($this->prefix . "§7This arena is already enabled!");
                        break;
                    }

                    if(!$game->enable()){
                        $player->sendMessage($this->prefix . "§7Could not enable the arena, complete the setup first!");
                        break;
                    }

                    $player->sendMessage($this->prefix . "§7Arena has been enabled!");
                break;
                case "§r§l§6Setup":
                   $this->setupForm($player);
                break;
                case "§r§l§cLeave / Done":
                  $player->getInventory()->clearAll();
                  $player->sendMessage($this->prefix . "§7You have left setup mode");
                  unset($this->editors[$player->getName()]);
                  if(isset($this->setupData[$player->getName()])){
                     unset($this->setupData[$player->getName()]);
                  }
                  $player->teleport($this->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
                break;
            }
        }
    }

    public function onDropItem(PlayerDropItemEvent $ev){
        $player = $ev->getPlayer();
        $customName = $event->getItem()->getCustomName();
        $item = $event->getItem();
        if(isset($this->editors[$player->getName()])){
            $game = $this->editors[$player->getName()];
            switch($customName){
                case "§r§l§aComplete":
                   $ev->cancel();
                break;
                case "§r§l§6Setup":
                   $ev->cancel();
                break;
                case "§r§l§cLeave / Done":
                  $ev->cancel();
                break;
            }
        }
    }

    public function setupForm(Player $player): void {
        $form = new SimpleForm(function(Player $player, $data){
            $game = $this->editors[$player->getName()];
            if ($data === null) {
                return;
            }
    
            switch ($data) {
                case 0:
                    $this->setupMap($player);
                    break;
    
                case 1:
                    $game->data["lobby"] = (new Vector($player->getPosition()->getX(), $player->getPosition()->getY(), $player->getPosition()->getZ()))->__toString();
                    $player->sendMessage($this->prefix . "§7Waiting lobby has been set to §6" . round($player->getPosition()->getX()) . ", " . round($player->getPosition()->getY()) . ", " . round($player->getPosition()->getZ()));
                    break;

                case 2:
                    $this->spawns[$player->getName()] = 1;
                    $player->sendMessage($this->prefix . "§7Touch an block to setup a spawn for the players!");
                    break;

                case 3:
                    $this->gold[$player->getName()] = 1;
                    $player->sendMessage($this->prefix . "§7Touch an block to setup a gold spawn for the gold {You can decide what the max gold spawns can be in config}!");
                    break;

                case 4:
                    $player->sendMessage($this->prefix . "§7Break a sign to set the joinsign");
                    $this->setupData[$player->getName()] = 0;
                    break;

            }
        });
    
        $form->setTitle("Setup Form");
        $form->setContent("Choose an option:");
        $form->addButton("Map");
        $form->addButton("Lobby");
        $form->addButton("Spawn");
        $form->addButton("Gold");
        $form->addButton("JoinSign");
        $form->sendToPlayer($player);
    }

    public function setupMap(Player $player): void {
        $form = new CustomForm(function(Player $player, $data) {
            if ($data === null) {
                return;
            }
    
            $map = $data[0];
    
            $game = $this->editors[$player->getName()];
    
            if (!isset($data[1])) {
                $game->data["map"] = $player->getWorld()->getFolderName();
                $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $player->getWorld()->getFolderName());
            } else {
                $worldName = $data[1];
    
                if (!$this->getServer()->getWorldManager()->isWorldGenerated($worldName)) {
                    $player->sendMessage($this->prefix . "§7World §6" . $worldName . "§r§7 does not exist!");
                } else {
                    $game->data["map"] = $worldName;
                    $player->sendMessage($this->prefix . "§7Game map has been set to §6" . $worldName);
                }
            }
        });
    
        $form->setTitle("Map - MM Setup");
        $form->addInput("Enter the world's name", "Enter the world's name");
        $form->addInput("Optional: Enter a different world's name (leave empty to use current world)", "Optional: Enter a different world's name");
        $form->sendToPlayer($player);
    }
    

    public function onBreak(BlockBreakEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(isset($this->setupData[$player->getName()])){
            switch($this->setupData[$player->getName()]){
                case 0:
                    $this->editors[$player->getName()]->data["joinsign"] = [(new Vector($block->getPosition()->getX(), $block->getPosition()->getY(), $block->getPosition()->getZ()))->__toString(), $player->getWorld()->getFolderName()];
                    $player->sendMessage($this->prefix . "§7Join sign updated!");
                    unset($this->setupData[$player->getName()]);
                    $event->cancel();
                break;
            }
        }
    }

    public function onJoin(PlayerJoinEvent $event){
        $player = $event->getPlayer();
        if($this->getConfig()->get("WaterDogPE-Support") === true){
            $this->joinGame($player);
	    }
    }
	
    public function onTouch(PlayerInteractEvent $event){
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if(isset($this->editors[$player->getName()])){
            $game = $this->editors[$player->getName()];
        } else {
            return;
        }

        if(isset($this->spawns[$player->getName()])){
            $index = $this->spawns[$player->getName()];

            $game->data["spawns"]["spawn-" . $index] = (new Vector($block->getPosition()->getX(), $block->getPosition()->getY() + 1.5, $block->getPosition()->getZ()))->__toString();
            $player->sendMessage($this->prefix . "§7Spawn " . $index . " has been set to§6 " . round($block->getPosition()->getX()) . ", " . round($block->getPosition()->getY() + 1) . ", " . round($block->getPosition()->getZ()));
            if($index > 15){
                $player->sendMessage($this->prefix . "§7All spawns have been set!");
                unset($this->spawns[$player->getName()]);
                return;
            }
            $this->spawns[$player->getName()] = ($index + 1);
            return;
        }

        if(isset($this->gold[$player->getName()])){
            $index = $this->gold[$player->getName()];

            $max = $this->getConfig()->get("GoldSpawns");

            $game->data["gold"]["gold-" . $index] = (new Vector($block->getPosition()->getX(), $block->getPosition()->getY() + 1, $block->getPosition()->getZ()))->__toString();
            $player->sendMessage($this->prefix . "§7Gold spawn " . $index . " has been set to§6 " . round($block->getPosition()->getX()) . ", " . round($block->getPosition()->getY() + 1) . ", " . round($block->getPosition()->getZ()));
            if($index > ($max - 1)){
                $player->sendMessage($this->prefix . "§7All gold spawns have been set!");
                unset($this->gold[$player->getName()]);
                return;
            }
            $this->gold[$player->getName()] = ($index + 1);
            return;
        }
    }

    public function joinGame(Player $player){
        $arena = $this->gamechooser->getRandomGame();
        if(!is_null($arena)){
            $arena->joinLobby($player);
            return;
        }
        $player->sendMessage("§cSomething went wrong while connecting to an available game, please try again!");
    }
}
