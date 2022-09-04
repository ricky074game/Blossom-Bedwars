<?php


namespace BedWars\game;

use BedWars\BedWars;
use BedWars\game\shop\ItemShop;
use BedWars\game\shop\UpgradeShop;
use BedWars\utils\Utils;
use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockLegacyIds;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\entity\projectile\Throwable;
use pocketmine\event\entity\{ProjectileLaunchEvent, ProjectileHitEntityEvent};
use pocketmine\item\Item;
use pocketmine\item\ItemIds;
use pocketmine\entity\object\PrimedTNT;
use BedWars\game\structure\popup_tower\PopupTower;
use pocketmine\entity\projectile\{Egg, Snowball};
use BedWars\game\entity\{Fireball, Golem, Bedbug};
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\block\TNT;
use pocketmine\math\Vector3;
use BedWars\game\structure\popup_tower\TowerSouth;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\item\enchantment\ItemFlags;
use pocketmine\item\ItemFactory;
use pocketmine\inventory\ArmorInventory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;

class GameListener implements Listener
{

    /** @var BedWars $plugin */
    private $plugin;

    /**
     * GameListener constructor.
     * @param BedWars $plugin
     */
    public function __construct(BedWars $plugin)
    {
        $this->plugin = $plugin;
    }
	
    public function createBaseNBT(Vector3 $pos, ?Vector3 $motion = null, float $yaw = 0.0, float $pitch = 0.0): CompoundTag
    {
        return CompoundTag::create()
            ->setTag("Pos", new ListTag([
                new DoubleTag($pos->x),
                new DoubleTag($pos->y),
                new DoubleTag($pos->z)
            ]))
            ->setTag("Motion", new ListTag([
                new DoubleTag($motion !== null ? $motion->x : 0.0),
                new DoubleTag($motion !== null ? $motion->y : 0.0),
                new DoubleTag($motion !== null ? $motion->z : 0.0)
            ]))
            ->setTag("Rotation", new ListTag([
                new FloatTag($yaw),
                new FloatTag($pitch)
            ]));
    }	

    /**
     * @param SignChangeEvent $event
     */
    public function onSignChange(SignChangeEvent $event) : void{
        $player = $event->getPlayer();
        $sign = $event->getSign();
        $text = $event->getNewText();
        if($text->getLine(0) == "[bedwars]" or $text->getLine(0) == "[bw]" && $text->getLine(1) !== ""){
            if(!in_array($text->getLine(1), array_keys($this->plugin->games))){
                return;
            }
            $pos = $sign->getPosition();
            $pos_ = $pos->getX() . ":" . $pos->getY() . ":" . $pos->getZ() . ":" . $player->getWorld()->getFolderName();
			
			if(isset($this->plugin->signs[$text->getLine(1)])){
				foreach ($this->plugin->signs[$text->getLine(1)] as $key => $val){
					if($val == $pos_){
						return;
					}
				}
			}
            
            $this->plugin->createSign($text->getLine(1), $pos->getX(), $pos->getY(), $pos->getZ(), $player->getWorld()->getFolderName());
            $player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Sign created");

        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $ev) : void{
    	$transaction = $ev->getTransaction();
    	$player = $transaction->getSource();

    	if($this->plugin->getPlayerGame($player) !== null){
    		foreach($transaction->getInventories() as $inventory){
    			if($inventory instanceof ArmorInventory){
    				$ev->cancel();
    			}
    		}
    	}
    }

    public function onExhaust(PlayerExhaustEvent $ev) : void{
        $player = $ev->getPlayer();
        if($this->plugin->getPlayerGame($player) !== null){
            $ev->cancel();
        }
    }

    public function onExplode(EntityExplodeEvent $ev) : void{
        $entity = $ev->getEntity();
        if(!$entity instanceof PrimedTNT)return;
        $world = $entity->getWorld();
        $game = null;
        foreach ($world->getPlayers() as $player) {
            if(($g = $this->plugin->getPlayerGame($player)) !== null){
                $game = $g;
            }
        }
        if($game == null)return;

        $newList = array();

        foreach($ev->getBlockList() as $block){
            if(in_array(Utils::vectorToString(":", $block->getPosition()->asVector3()), $game->placedBlocks)){
                $newList[] = $block;
            }
        }
        $ev->setBlockList($newList);
    }
	
    public function projectileLaunchevent(ProjectileLaunchEvent $event)
    {
        $pro = $event->getEntity();
        $player = $pro->getOwningEntity();
        $playerGame = $this->plugin->getPlayerGame($player);
        if ($player instanceof Player) {
            if ($playerGame !== null) {
                if ($pro instanceof Snowball) {
                    $this->spawnBedbug($pro->getPosition()->asVector3(), $player->getWorld(), $player);
                }
            }
        }
    }
	
    public function spawnGolem($pos, $world, $player)
    {
        $nbt = $this->createBaseNBT($pos);
        $entity = new Golem($world, $nbt);
        $entity->arena = $this;
        $entity->owner = $player;
        $entity->spawnToAll();
    }

    public function spawnBedbug($pos, $world, $player)
    {
        $nbt = $this->createBaseNBT($pos);
        $entity = new Bedbug($world, $nbt);
        $entity->arena = $this;
        $entity->owner = $player;
        $entity->spawnToAll();
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        $block = $event->getBlock();
	$item = $event->getItem();
        $playerGame = $this->plugin->getPlayerGame($player);

        foreach($this->plugin->signs as $arena => $positions){
            foreach($positions as $position) {
                $pos = explode(":", $position);
                if ($block->getPosition()->getX() == $pos[0] && $block->getPosition()->getY() == $pos[1] && $block->getPosition()->getZ() == $pos[2] && $player->getWorld()->getFolderName() == $pos[3]) {
                    $game = $this->plugin->games[$arena];
                    $game->join($player);
                    return;
                }
            }
        }
	    
        if($item->getId() == ItemIds::SPAWN_EGG && $item->getMeta() == 14){
            $this->spawnGolem($block->add(0, 1), $player->world, $player);
            $ih->setCount($ih->getCount() - 1);
            $player->getInventory()->setItemInHand($ih); 
            $event->cancel();
        }	    
	    
        if($item->getCustomName() == "§l§cFireBall"){
             $this->spawnFireball($player->add(0, $player->getEyeHeight()), $player->world, $player);
             $this->addSound($player, 'mob.blaze.shoot');
             $ih->setCount($ih->getCount() - 1);
             $player->getInventory()->setItemInHand($ih); 
             $event->cancel();
        }	    

        if($item->getId() == ItemIds::WOOL){
            $teamColor = Utils::woolIntoColor($item->getMeta());

            $playerGame = $this->plugin->getPlayerGame($player);
            if($playerGame == null || $playerGame->getState() !== Game::STATE_LOBBY)return;

            $playerTeam = $this->plugin->getPlayerTeam($player);
            if($playerTeam !== null){
                $player->sendMessage(TextFormat::RED . " §l§9»§r §cYou are already in a team!");
                return;
            }

            foreach($playerGame->teams as $team){
                if($team->getColor() == $teamColor){

                    if(count($team->getPlayers()) >= $playerGame->playersPerTeam){
                        $player->sendMessage(TextFormat::RED . " §l§9»§r§a The team you are trying to join is full!");
                        return;
                    }
                    $team->add($player);
                    $player->sendMessage(TextFormat::GRAY . " §l§9» §r§aSuccessfully joined " . $teamColor . $team->getName() . TextFormat::YELLOW . " Team§6!");
                }
            }
        }elseif($item->getId() == ItemIds::COMPASS){
            if($playerGame == null)return;

            if($playerGame->getState() == Game::STATE_RUNNING){
                 $playerGame->trackCompass($player);
            }elseif($playerGame->getState() == Game::STATE_LOBBY){
                $playerGame->quit($player);
                $player->teleport($this->plugin->getServer()->getWorldManager()->getDefaultWorld()->getSafeSpawn());
                $player->getInventory()->clearAll();
            }
        }
    }
	
    public function onCraft(CraftItemEvent $event) {

        $player = $event->getPlayer();

    	if($this->plugin->getPlayerGame($player) !== null){
    		$event->cancel();
    	}
    }

    public function onBedEnter(PlayerBedEnterEvent $event) : void{
        $player = $event->getPlayer();
        $playerGame = $this->plugin->getPlayerGame($player);
        if(!is_null($playerGame))$event->cancel();
    }

    public function onChat(PlayerChatEvent $event) : void{
        $player = $event->getPlayer();
        $playerGame = $this->plugin->getPlayerGame($player);
        $message = $event->getMessage();
        if(is_null($playerGame))return;
        if($playerGame->getState() !== Game::STATE_RUNNING)return;
        $event->cancel();

        $playerTeam = $this->plugin->getPlayerTeam($player);
        if(is_null($playerTeam)){
            if(isset($playerGame->spectators[$player->getName()])){
                foreach($playerGame->spectators as $spectator){
                    $spectator->sendMessage(TextFormat::GRAY . "[SPECTATOR] " . $player->getName() . " §l»§r " . $message);
                }
            }
        }else{
            foreach(array_merge($playerGame->getPlayers(), $playerGame->getSpectators()) as $p){
                $p->sendMessage($playerTeam->getColor() . $player->getName() . TextFormat::GRAY . " §l»§r " . TextFormat::WHITE . $message);
            }
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        foreach($this->plugin->games as $game){
            if(in_array($player->getName(), array_keys(array_merge($game->players, $game->spectators)))){
                $game->getPlayerCache($player->getName())->load();
                $game->quit($player);
		$this->clearInv($player);
            }
        }
    }
	
    public function clearInv(Player $player){
        $player->getInventory()->clearAll();
	$player->getArmorInventory()->clearAll();
	$player->getCusorInventory()->clearAll();
    }

    /**
     * @param EntityTeleportEvent $event
     */
    public function onEntityLevelChange(EntityTeleportEvent $event) : void{
        $player = $event->getEntity();
        if(!$player instanceof Player){
            return;
        }

        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null && $event->getTo()->getWorld()->getFolderName() !== $playerGame->worldName)$playerGame->quit($player);
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) : void
    {
        $player = $event->getPlayer();
        foreach ($this->plugin->games as $game) {
            if (isset($game->players[$player->getName()])) {
                if ($game->getState() == Game::STATE_RUNNING) {
                    $playerTeam = $this->plugin->getPlayerTeam($player);
                    if($player->getPosition()->getY() < $game->getVoidLimit() && !$player->isSpectator()){
                        $game->killPlayer($player);
                        
                        $game->broadcastMessage(" §l§e»§r" . $playerTeam->getColor() . $player->getName() . " " . TextFormat::GRAY . "jumped into the void");
                        $spawnPos = $game->teamInfo[$playerTeam->getName()]['SpawnPos'];
                        $spawn = Utils::stringToVector(":", $spawnPos);
                        $player->teleport(new Vector3($player->getPosition()->getX(), $spawn->getY() + 10, $player->getPosition()->getZ()));
                        return;
                    }
                    $spawnPos = $game->teamInfo[$playerTeam->getName()]['SpawnPos'];
                    $spawn = Utils::stringToVector(":", $spawnPos);

                    if($playerTeam->getUpgrade('healPool') > 0){
                        if($spawn->distance($player->getPosition()->asVector3())){
                            if(!$player->getEffects()->has(VanillaEffects::REGENERATION())){
                                $player->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 8 * 20, 1));
                            }
                        }
                    }


                }
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) : void
    {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $pos = $block->getPosition();

        if(isset($this->plugin->bedSetup[$player->getName()])){
            if(!$event->getBlock() instanceof Bed){
                $player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "The block is not a bed!");
                return;
            }
            $setup = $this->plugin->bedSetup[$player->getName()];

            $step =  (int)$setup['step'];
            $this->plugin->setTeamPosition($setup['game'], $setup['team'], 'Bed' . $step, $pos->getX(), $pos->getY(), $pos->getZ());

            $player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Bed $step has been set!");
            if($step == 1){
                $player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Now touch the 2nd part");
            }
            $event->cancel();

            if($step == 2){
                unset($this->plugin->bedSetup[$player->getName()]);
                return;
            }

            $this->plugin->bedSetup[$player->getName()]['step']+=1;

            return;
        }

        if(isset($this->plugin->saSetup[$player->getName()])){
            $setup = $this->plugin->saSetup[$player->getName()];
            $step = $setup['step'];
            if($step == 1){
                $player->sendMessage(TextFormat::GREEN . "Position 1 selected");
                $this->plugin->saSetup[$player->getName()]['step'] += 1;
                $this->plugin->saSetup[$player->getName()]['pos1'] = implode(":", [$pos->getX(), $pos->getY(), $pos->getZ()]);
            }else if($step == 2){
                $player->sendMessage(TextFormat::GREEN . "Positon 2 selected. You are done now");
                $this->plugin->saSetup[$player->getName()]['pos2'] = implode(":", [$pos->getX(), $pos->getY(), $pos->getZ()]);
                $setup = $this->plugin->saSetup[$player->getName()];
                $this->plugin->updateGame($setup['game_id'], 'safe_areas', ['pos1' => $setup['pos1'], 'pos2' => $setup['pos2'], 'ignored' => $setup['ignoredIds']], true);
            }
        }

        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null){
            if($playerGame->getState() == Game::STATE_LOBBY){
                $event->cancel();
            }elseif($event->getBlock() instanceof Bed){
                $blockPos = $event->getBlock()->getPosition();

                $game = $this->plugin->getPlayerGame($player);
                $team = $this->plugin->getPlayerTeam($player);
                if($team == null || $game == null)return;

                foreach($game->teamInfo as $name => $info){
                    if(!isset($info['Bed1Pos']) || !isset($info['Bed2Pos'])){
                        continue;
                    }
                    $bedPos = Utils::stringToVector(":", $info['Bed1Pos']);
                    $teamName = "";

                    if($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z){
                        $teamName = $name;
                    }else{
                        $bedPos = Utils::stringToVector(":", $info['Bed2Pos']);
                        if($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z){
                            $teamName = $name;
                        }
                    }

                    if($teamName !== ""){
                        $teamObject = $game->teams[$name];
                        if($teamName == $this->plugin->getPlayerTeam($player)->getName()){
                            $player->sendMessage(TextFormat::RED . " §l§5» §r§cYou can't break your own bed, silly!");
                            $event->cancel();
                            return;
                        }
                        $event->setDrops([]);
                        $game->breakBed($teamObject, $player);

                    }
                }
            }else{
                if($playerGame->getState() == Game::STATE_RUNNING){
                    if(!in_array(Utils::vectorToString(":", $block->getPosition()->asVector3()), $playerGame->placedBlocks)){
                        $event->cancel();
                        return;
                    }
                }
            }
        } else {
            foreach ($this->plugin->games as $arena){
                if($arena->worldName == $player->getWorld()->getFolderName()){
                    if(!isset($arena->getPlayers()[$player->getName()])){
                        $event->cancel();
                        return;
                    }
                }
            }
        }
    }

    /**
     * @param BlockPlaceEvent $event
     */
    public function onPlace(BlockPlaceEvent $event) : void{
        $player = $event->getPlayer();
        $playerGame = $this->plugin->getPlayerGame($player);
        if($playerGame !== null){
            if($playerGame->getState() == Game::STATE_LOBBY){
                $event->cancel();
            }elseif($playerGame->getState() == Game::STATE_RUNNING){
                $isCancelled = false;
                foreach($playerGame->teamInfo as $team => $data){
                    $spawn = Utils::stringToVector(":", $data['SpawnPos']);
                    if($spawn->distance($event->getBlock()->getPosition()->asVector3()) < 6){
                        $event->cancel();
                        $isCancelled = true;
                    }else{
                        $playerGame->placedBlocks[] = Utils::vectorToString(":", $event->getBlock()->getPosition()->asVector3());
                    }
                }

                if($event->getBlock() instanceof TNT){
                    $event->getBlock()->ignite();
                    $event->cancel();
                    $ih = $player->getInventory()->getItemInHand();
                    $ih->setCount($ih->getCount() - 1);
                    $player->getInventory()->setItemInHand($ih);
                    return;
                }

                if($playerGame->isSafeArea($event->getBlock()->getPosition()->asVector3(), $event->getBlockAgainst()->getId())){
                    $event->cancel();
                    return;
                }

                if($event->getBlock()->getId() == BlockLegacyIds::CHEST && !$isCancelled && $player->getInventory()->getItemInHand()->getCustomName() == TextFormat::AQUA . "Popup Tower"){
                       $player->getInventory()->removeItem(ItemFactory::getInstance()->get(BlockLegacyIds::CHEST, 0, 1));
                       $event->cancel();
                       (new PopupTower($event->getBlock(), $playerGame, $player, $this->plugin->getPlayerTeam($player)));
                }
            }
        } else {
            foreach ($this->plugin->games as $arena){
                if($arena->worldName == $player->getWorld()->getFolderName()){
                    if(!isset($arena->getPlayers()[$player->getName()])){
                        $event->cancel();
                        return;
                    }
                }
            }
        }
    }

    /**
     * @param EntityDamageEvent $event
     */
    public function onDamage(EntityDamageEvent $event) : void{
        $entity = $event->getEntity();
        foreach ($this->plugin->games as $game) {
            if ($entity instanceof Player && isset($game->players[$entity->getName()])) {

                if($game->getState() == Game::STATE_LOBBY){
                     $event->cancel();
                     return;
                }

                if($event->getFinalDamage() >= $entity->getHealth()){
                    $game->killPlayer($entity);
                    $event->cancel();
                }

                if($event instanceof EntityDamageByEntityEvent){
                    $damager = $event->getDamager();
                    if(!$damager instanceof Player)return;

                    if(isset($game->players[$damager->getName()])){
                        $damagerTeam = $this->plugin->getPlayerTeam($damager);
                        $playerTeam = $this->plugin->getPlayerTeam($entity);

                        if($damagerTeam->getName() == $playerTeam->getName()){
                            $event->cancel();
                        }
                    }
                }
            }elseif(isset($game->npcs[$entity->getId()])){
                $event->cancel();

                if($event instanceof EntityDamageByEntityEvent){
                    $damager = $event->getDamager();

                    if($damager instanceof Player){
                        $npcTeam = $game->npcs[$entity->getId()][0];
                        $npcType = $game->npcs[$entity->getId()][1];

                        if(($game = $this->plugin->getPlayerGame($damager)) == null){
                            return;
                        }

                        if($game->getState() !== Game::STATE_RUNNING){
                            return;
                        }

                        $playerTeam = $this->plugin->getPlayerTeam($damager)->getName();
                        if($npcTeam !== $playerTeam && $npcType == "upgrade"){
                            $damager->sendMessage(TextFormat::RED . " §l§5»§c§r You can only upgrade at your island!");
                            return;
                        }

                        if($npcType == "upgrade"){
                            UpgradeShop::sendDefaultShop($damager);
                        }else{
                            ItemShop::sendDefaultShop($damager);
                        }
                    }
                }
            }
        }
    }

    public function onCommandPreprocess(PlayerCommandPreprocessEvent $event) : void{
          $player = $event->getPlayer();

          $game = $this->plugin->getPlayerGame($player);

          if($game == null)return;

          $args = explode(" ", $event->getMessage());

          if($args[0] == '/fly' || isset($args[1]) && $args[1] == 'join'){
              $player->sendMessage(TextFormat::RED . " §l§5» §r§cYou cannot run this in-game!");
              $event->cancel();
          }
    }

    public function onDrop(PlayerDropItemEvent $event){
        $player = $event->getPlayer();
        if(!$player instanceof Player) return;
        if(($arena = $this->plugin->getPlayerGame($player)) !== null){
            if($arena->getState() == Game::STATE_LOBBY || $arena->getState() == Game::STATE_REBOOT){
                $event->cancel();
            }
        }
    }



    /**
     * @param DataPacketReceiveEvent $event
     */
    public function handlePacket(DataPacketReceiveEvent $event) : void{
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if($packet instanceof ModalFormResponsePacket){
            $playerGame = $this->plugin->getPlayerGame($player);
            if($playerGame == null)return;
              $data = json_decode($packet->formData);
              if (is_null($data)) {
                return;
              }
                if($packet->formId == 50) {
                    ItemShop::sendPage($player, intval($data));
                }elseif($packet->formId < 100){
                    ItemShop::handleTransaction(($packet->formId), json_decode($packet->formData), $player, $this->plugin, $packet->formId);
                }elseif($packet->formId == 100){
                    UpgradeShop::sendBuyPage(json_decode($packet->formData), $player, $this->plugin);
                }elseif($packet->formId > 100){
                    UpgradeShop::handleTransaction(($packet->formId), $player, $this->plugin);
                }
            }
    }
}
