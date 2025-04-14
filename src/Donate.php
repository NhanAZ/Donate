<?php

declare(strict_types=1);

namespace Donate;

use Donate\manager\FormManager;
use Donate\manager\PaymentManager;
use Donate\SingletonTrait;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\MainLogger;
use pocketmine\utils\Terminal;
use pocketmine\utils\Timezone;
use Symfony\Component\Filesystem\Path;
use pocketmine\utils\SingletonTrait as PMSingletonTrait;

class Donate extends PluginBase {
	use SingletonTrait;

	public MainLogger $logger;

	private Config $donateData;
	private FormManager $formManager;
	private PaymentManager $paymentManager;

	protected function onLoad(): void {
		self::setInstance($this);

		// Prepare plugin directory
		@mkdir($this->getDataFolder());
	}

	protected function onEnable(): void {
		// Initialize configuration
		$this->saveDefaultConfig();
		$this->donateData = new Config($this->getDataFolder() . "donateData.yml", Config::YAML);

		// Check for valid API credentials
		if ($this->getConfig()->get("partner_id", "") === "" || $this->getConfig()->get("partner_key", "") === "") {
			$this->getLogger()->error("Vui lòng cấu hình partner_id và partner_key trong config.yml!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		// Initialize managers
		$this->formManager = new FormManager($this);
		$this->paymentManager = new PaymentManager($this);

		// Start the payment checking task
		$this->paymentManager->startPaymentCheckTask();

		$this->logger = new MainLogger(
			logFile: Path::join($this->getDataFolder(), "log.log"),
			useFormattingCodes: Terminal::hasFormattingCodes(),
			mainThreadName: "Server",
			timezone: new \DateTimeZone(Timezone::get())
		);
	}

	protected function onDisable(): void {
		// Save any pending data
		if (isset($this->donateData)) {
			$this->donateData->save();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		$commandName = $command->getName();

		switch ($commandName) {
			case "donate":
				if (!$sender instanceof Player) {
					$sender->sendMessage(Constant::PREFIX . "Vui lòng sử dụng lệnh này trong trò chơi!");
					return true;
				}

				$this->formManager->sendDonateForm($sender);
				return true;

			case "topdonate":
				$page = 1;
				if (isset($args[0]) && is_numeric($args[0])) {
					$page = max(1, (int) $args[0]);
				}

				$this->showTopDonators($sender, $page);
				return true;

			default:
				return false;
		}
	}

	private function showTopDonators(CommandSender $sender, int $page): void {
		$donateData = $this->donateData->getAll();
		if (empty($donateData)) {
			$sender->sendMessage(Constant::PREFIX . "Hiện chưa có một ai nạp thẻ ủng hộ máy chủ...");
			return;
		}

		// Sort by donation amount (descending)
		arsort($donateData);

		$totalPlayers = count($donateData);
		$itemsPerPage = 10;
		$maxPage = ceil($totalPlayers / $itemsPerPage);

		// Validate page number
		$page = min($maxPage, $page);

		$sender->sendMessage(Constant::PREFIX . "--- Bảng xếp hạng nạp thẻ trang $page/$maxPage (/topdonate <trang>) ---");

		$startIndex = ($page - 1) * $itemsPerPage;
		$i = 0;
		$senderRank = 0;
		$serverTotalDonated = 0;

		foreach ($donateData as $playerName => $amount) {
			// Calculate total donation amount for server
			$amountValue = is_numeric($amount) ? (int)$amount : 0;
			$serverTotalDonated += $amountValue;

			// Find sender's rank
			if ($sender instanceof Player && $playerName === $sender->getName()) {
				$senderRank = $i + 1;
			}

			// Display entries for current page
			if ($i >= $startIndex && $i < $startIndex + $itemsPerPage) {
				$rank = $i + 1;
				$formattedAmount = number_format($amountValue, 0, ".", ".");
				$sender->sendMessage("$rank. $playerName: {$formattedAmount}₫");
			}

			$i++;
		}

		// Show sender's rank (if player)
		if ($sender instanceof Player && $senderRank > 0) {
			$sender->sendMessage(Constant::PREFIX . "Xếp hạng của bạn là $senderRank");
		}

		// Show server total donations
		$formattedTotal = number_format($serverTotalDonated, 0, ".", ".");
		$sender->sendMessage(Constant::PREFIX . "Tổng số tiền nạp thẻ từ người chơi của máy chủ là: {$formattedTotal}₫");
	}

	public function successfulDonation(string $playerName, int $amount): void {
		$formattedAmount = number_format($amount, 0, ".", ".");

		// Broadcast donation message
		$this->getServer()->broadcastMessage(Constant::PREFIX . "Người chơi $playerName đã nạp {$formattedAmount}₫ để ủng hộ máy chủ!");

		// Update player statistics safely
		$currentAmount = $this->donateData->getNested($playerName, 0);
		$safeAmount = is_numeric($currentAmount) ? (int)$currentAmount : 0;
		$this->donateData->setNested($playerName, $safeAmount + $amount);
		$this->donateData->save();

		// Get bonus multiplier from config
		$multiplierRaw = $this->getConfig()->get("bonus_multiplier", 1.0);
		$multiplier = is_numeric($multiplierRaw) ? (float)$multiplierRaw : 1.0;

		// Calculate bonus amount
		$bonusAmount = (int)($amount * $multiplier);

		// Add any additional reward code here
		// Example: EconomyAPI::getInstance()->addMoney($playerName, $bonusAmount);

		// Notify player if online
		$player = $this->getServer()->getPlayerExact($playerName);
		if ($player !== null) {
			$player->sendMessage(Constant::PREFIX . "Chân thành cảm ơn bạn đã ủng hộ máy chủ {$formattedAmount}₫!");
			$player->sendMessage(Constant::PREFIX . "Bạn đã nhận được $bonusAmount xu");
		}
	}

	public function getDonateData(): Config {
		return $this->donateData;
	}

	public function getFormManager(): FormManager {
		return $this->formManager;
	}

	public function getPaymentManager(): PaymentManager {
		return $this->paymentManager;
	}
}
