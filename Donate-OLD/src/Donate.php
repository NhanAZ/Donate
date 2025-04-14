<?php

declare(strict_types=1);

namespace Donate;

use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\event\player\PlayerJoinEvent;
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;

class Donate extends PluginBase implements Listener {

	public static $instance;

	public $partnerId = "7433830561";

	public $partnerKey = "fa9b958f09535e9c19bfe7c5cfc80ff8";

	public $formapi;

	public function onEnable(): void {
		self::$instance = $this;
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->donate = new Config($this->getDataFolder() . "donate.yml", Config::YAML);
		$this->point = $this->getServer()->getPluginManager()->getPlugin("PointAPI"); 
	}

	public function onJoin(PlayerJoinEvent $event) {
		$player = $event->getPlayer()->getName();
		if (!$this->donate->exists($player)) {
			$this->donate->set($player, 0);
			$this->donate->save();
		}
	}

	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool {
		if ($cmd->getName() == "donate") {
			if (!$sender instanceof Player) return true;
			$this->getInfoForm($sender);
			return true;
		}

		if ($cmd->getName() == "topdonate") {
			$maxpage = ceil(count($this->donate->getAll()) / 5);
			if (!isset($args[0])) {
				$this->getServer()->getCommandMap()->dispatch($sender, "topdonate 1");
				return true;
			}
			$form = new CustomForm(function (Player $player, $data) {
				if ($data === null) {
					return true;
				}
				$this->getServer()->getCommandMap()->dispatch($player, "topdonate " . $data[count($data) - 1]);
			});
			if ($args[0] > $maxpage) {
				$sender->sendMessage("§l§6→§c Trang Xếp Hạng Này Hiện Không Tồn Tại!");
				return true;
			}
			$max = 0;
			foreach ($this->donate->getAll() as $c) {
				$max += count($this->donate->getAll());
			}
			$page = ceil($max / 5);
			$page = array_shift($args);
			$page = max(1, $page);
			$page = min($max, $page);
			$page = (int)$page;
			$form->setTitle("§l§f•§8[§7⥅§cXếp Hạng§7⥆§8]§f•");
			$form->addLabel("§l§b❖§c Xếp Hạng Donate§6 [§a" . $page . "§8/§c" . $maxpage . "§6]§b ❖");
			$aa = $this->donate->getAll();
			arsort($aa);
			$i = 0;
			foreach ($aa as $b => $a) {
				if (($page - 1) * 5 <= $i && $i <= ($page - 1) * 5 + 4) {
					$i1 = $i + 1;
					$c = $this->donate->get($b);
					$form->addLabel("§l§8⇉§a[§c{$i1}§a]§8⇇§e {$b}§b:§6 {$c} Đồng");
				}
				$i++;
			}
			$iz = 0;
			foreach ($aa as $az => $int) {
				$iz++;
				if ($az == $sender->getName()) {
					break;
				}
			}
			$form->addLabel("§l§e•§cXếp Hạng Của Bạn§b:§8 ⇉§a[§c{$iz}§a]§8⇇§e•");
			$form->addInput("§l§bᛎ§eNhập Số Trang§bᛎ");
			$form->sendToPlayer($sender);
			return true;
		}
		return true;
	}

	public $chuyendoi =
	[
		"10000" => 4000,
		"20000" => 8000,
		"50000" => 20000,
		"100000" => 40000
	];

	public function getInfoForm(Player $player, string $loaithe = null, string $seri = null, string $pin = null, string $menhgia = null) {
		$loaithe_arr = [
			"Viettel",
			"Vinaphone",
			"Mobifone",
			"Vietnamoblie"
		];
		$menhgia_arr = ["10000", "20000", "50000", "100000"];
		$form = new CustomForm(function (Player $player, $data) use ($loaithe_arr, $menhgia_arr) {
			$result = $data;
			if ($result === null) {
				return true;
			}
			$telco = $loaithe_arr[$result[0]];
			$pin = $result[1]; 
			$seri = $result[2]; 
			$menhgia = $menhgia_arr[$result[3]];
			$thongtin = [$telco, $pin, $seri, $menhgia];
			$this->confirm($player, $thongtin);
		});

		$form->setTitle("§l§f•§8[§7⥅§cDonate§7⥆§8]§f•");
		$form->addDropdown("§l§6⪼§aLoại Thẻ§6⪻", $loaithe_arr, (int) array_search($loaithe, $loaithe_arr));
		$form->addInput("§l§bᛎ§eNhập Mã Số§bᛎ", "", $pin); 
		$form->addInput("§l§bᛎ§eNhập Số Seri§bᛎ", "", $seri); 
		$form->addDropdown("§l§6→§c Lưu Ý Chọn Sai Mệnh Giá Mất Thẻ\n§l§6⪼§aChọn Mệnh Giá§6⪻", $menhgia_arr, (int) array_search($menhgia, $menhgia_arr));
		$form->sendToPlayer($player);
		return $form;
	}

	public function confirm(Player $player, array $thongtin) {
		$player->sendMessage("§l§6→§c Đang Kiểm Tra Vui Lòng Không Thao Tác Cho Đến Khi Kiểm Tra Xong...");
		$this->getServer()->getAsyncPool()->submitTask(new DonateTask([$this->partnerId, $this->partnerKey], strtoupper($thongtin[0]), (string) $thongtin[1], (string) $thongtin[2], (int)$thongtin[3], $player->getName()));
	}

	public function onSuccess(Player $player, string $txt) {
		$form = new SimpleForm(function (Player $player, int $data = null) {
			$result = $data;
			if ($result === null) {
				return true;
			}
		});
		$form->setTitle("§l§f•§8[§7⥅§cDonate§7⥆§8]§f•");
		$form->setContent($txt);
		$form->sendToPlayer($player);
		return $form;
	}

	public function donateSuccess(string $name, int $giatri, int $point) {
		$player = $this->getServer()->getPlayerByPrefix($name);

		$this->point->addPoint($name, (int)$point);
		$this->donate->set($player->getName(), $this->donate->get($player->getName()) + $giatri);
		$this->donate->save();
		$txt =
			"§l§6→§a Donate Thành Công\n" .
			"§l§6→§a Mệnh Giá§b:§c " . $giatri . "\n" .
			"§l§6→§a Bạn Nhận Được§b:§e " . ((int) $point) . "§6 Point";
		$this->onSuccess($player, $txt);
	}
}
