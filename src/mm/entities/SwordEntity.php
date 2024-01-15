<?php

namespace mm\entities;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\item\{VanillaItems, ItemTypeIds};
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\item\Item;

class SwordEntity extends Entity{

    protected function getInitialDragMultiplier(): float {
        return 1.0;
    }

    protected function getInitialGravity(): float {
        return 0.01;
    }

    public static function getNetworkTypeId(): string
    {
        return self::NETWORK_ID;
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo($this->height, $this->width);
    }

    public const NETWORK_ID = "minecraft:armor_stand";

    public $width = 2.0;
    public $height = 2.0;

    // This feature may aswell stay on hold because PMMP has an issue
    // [21:12:21.288] [Server thread/CRITICAL]: Error: "Call to undefined method pocketmine\network\mcpe\protocol\MobEquipmentPacket::getTypeId()" 
    // (EXCEPTION) in "pmsrc/vendor/pocketmine/bedrock-protocol/src/serializer/PacketSerializer" at line 453
    protected function sendSpawnPacket(Player $player) : void{
        parent::sendSpawnPacket($player);
        $pk = new MobEquipmentPacket();
        $pk->actorRuntimeId = $this->getId();
        $pk->item = ItemStackWrapper::legacy(new ItemStack(20138, 0, 1, 0, null, [], []));
        $pk->inventorySlot = 0;
        $pk->hotbarSlot = 0;
        $player->sendData($this->getViewers(), [$pk]);
    }

    public function setPose() : void{
        $this->getNetworkProperties()->setInt(EntityMetadataProperties::ARMOR_STAND_POSE_INDEX, 8);
    }
}
