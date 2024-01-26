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
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
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

    protected function sendSpawnPacket(Player $player) : void{
        parent::sendSpawnPacket($player);
        /** TEST BELOW */
        $this->updateMovement();
        $this->updateMovement();
        $this->updateMovement();
        /** TEST ABOVE */
        $pk = MobEquipmentPacket::create(
            $this->getId(),
            ItemStackWrapper::legacy(TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::IRON_SWORD())),
            0,
            0,
            ContainerIds::INVENTORY
        );
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function setPose() : void{
        $this->getNetworkProperties()->setInt(EntityMetadataProperties::ARMOR_STAND_POSE_INDEX, 8);
    }
}
