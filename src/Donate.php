<?php

declare(strict_types=1);

namespace Donate;

use Donate\manager\FormManager;
use Donate\manager\PaymentManager;
use Donate\SingletonTrait;
use Donate\utils\DebugLogger;
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
	public DebugLogger $debugLogger;

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

		$this->logger = new MainLogger(
			logFile: Path::join($this->getDataFolder(), "log.log"),
			useFormattingCodes: Terminal::hasFormattingCodes(),
			mainThreadName: "Server",
			timezone: new \DateTimeZone(Timezone::get())
		);
        
		// Initialize debug logger
		$this->debugLogger = new DebugLogger($this);
		
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
		
		$this->debugLogger->log("Plugin enabled successfully", "general");
	}

	protected function onDisable(): void {
		// Save any pending data
		if (isset($this->donateData)) {
			$this->donateData->save();
		}
		
		if (isset($this->debugLogger)) {
			$this->debugLogger->log("Plugin disabled", "general");
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
				
			case "donatedebug":
				if (!$sender->hasPermission("donate.command.debug")) {
					$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Bạn không có quyền sử dụng lệnh này!"));
					return true;
				}
				
				// Handle debug command
				$subcommand = $args[0] ?? "help";
				
				switch ($subcommand) {
					case "pending":
						$this->showPendingPayments($sender);
						break;
						
					case "status":
						$requestId = $args[1] ?? "";
						if (empty($requestId)) {
							$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Vui lòng cung cấp ID yêu cầu thanh toán!"));
							return true;
						}
						$this->checkPaymentStatus($sender, $requestId);
						break;
						
					case "toggle":
						$category = $args[1] ?? "general";
						$this->toggleDebugCategory($sender, $category);
						break;
						
					default:
						$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Các lệnh debug:"));
						$sender->sendMessage("§7/donatedebug pending §f- Kiểm tra các giao dịch đang xử lý");
						$sender->sendMessage("§7/donatedebug status <requestId> §f- Kiểm tra trạng thái giao dịch");
						$sender->sendMessage("§7/donatedebug toggle <category> §f- Bật/tắt debug cho danh mục cụ thể");
						break;
				}
				
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
		$formattedAmount = \Donate\utils\MessageTranslator::formatAmount($amount);

		// Broadcast donation message
		$this->getServer()->broadcastMessage(\Donate\utils\MessageTranslator::formatSuccessMessage("Người chơi $playerName đã nạp {$formattedAmount} để ủng hộ máy chủ!"));

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
			$player->sendMessage(\Donate\utils\MessageTranslator::formatSuccessMessage("Chân thành cảm ơn bạn đã ủng hộ máy chủ {$formattedAmount}!"));
			$player->sendMessage(\Donate\utils\MessageTranslator::formatSuccessMessage("Bạn đã nhận được " . \Donate\utils\MessageTranslator::formatAmount($bonusAmount) . " xu"));
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

	/**
	 * Show pending payments to a command sender
	 */
	private function showPendingPayments(CommandSender $sender): void {
		$pendingPayments = $this->paymentManager->getPendingPayments();
		
		if (empty($pendingPayments)) {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Không có giao dịch nào đang chờ xử lý."));
			return;
		}
		
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Các giao dịch đang xử lý: §f" . count($pendingPayments)));
		
		foreach ($pendingPayments as $requestId => $payment) {
			$elapsedTime = time() - $payment->getCreatedAt();
			$elapsedFormatted = floor($elapsedTime / 60) . "m " . ($elapsedTime % 60) . "s";
			
			$sender->sendMessage("§7- §f" . $payment->getPlayerName() . 
			                     "§7: §f" . \Donate\utils\MessageTranslator::formatAmount($payment->getAmount()) . 
			                     "§7, ID: §f" . substr($requestId, 0, 8) . 
			                     "§7, Thời gian: §f" . $elapsedFormatted);
		}
	}
	
	/**
	 * Check the status of a specific payment
	 */
	private function checkPaymentStatus(CommandSender $sender, string $requestId): void {
		// Try to find the payment in pending payments first
		$payment = $this->paymentManager->getPayment($requestId);
		
		if ($payment !== null) {
			$elapsedTime = time() - $payment->getCreatedAt();
			$elapsedFormatted = floor($elapsedTime / 60) . "m " . ($elapsedTime % 60) . "s";
			
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Thông tin thanh toán:"));
			$sender->sendMessage("§7- Người chơi: §f" . $payment->getPlayerName());
			$sender->sendMessage("§7- Mệnh giá: §f" . \Donate\utils\MessageTranslator::formatAmount($payment->getAmount()));
			$sender->sendMessage("§7- Loại thẻ: §f" . $payment->getTelco());
			$sender->sendMessage("§7- Trạng thái: §f" . $payment->getStatus());
			$sender->sendMessage("§7- Thời gian chờ: §f" . $elapsedFormatted);
			$sender->sendMessage("§7- ID: §f" . $requestId);
			
			// Trigger an immediate check of this payment
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Tiến hành kiểm tra trạng thái..."));
			
			try {
				$response = \Donate\api\TrumTheAPI::checkCardStatus($requestId);
				
				if ($response === null) {
					$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Không thể kết nối đến dịch vụ thanh toán!"));
					return;
				}
				
				$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Kết quả kiểm tra:"));
				$sender->sendMessage("§7- Mã trạng thái: §f" . $response->getStatus());
				$sender->sendMessage("§7- Thông báo: §f" . \Donate\utils\MessageTranslator::translateErrorMessage($response->getMessage()));
				
				if ($response->getAmount() !== null) {
					$sender->sendMessage("§7- Số tiền: §f" . \Donate\utils\MessageTranslator::formatAmount($response->getAmount()));
				}
			} catch (\Throwable $e) {
				$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Lỗi khi kiểm tra: " . $e->getMessage()));
			}
		} else {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Không tìm thấy giao dịch với ID: " . $requestId));
		}
	}
	
	/**
	 * Toggle debug category
	 */
	private function toggleDebugCategory(CommandSender $sender, string $category): void {
		$config = $this->getConfig();
		$currentValue = (bool) $config->getNested("debug.categories." . $category, false);
		$newValue = !$currentValue;
		
		$config->setNested("debug.categories." . $category, $newValue);
		$config->save();
		
		// Reload debug configuration
		$this->debugLogger->loadConfig();
		
		$status = $newValue ? "§aBật" : "§cTắt";
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §edebug cho danh mục §f" . $category));
	}
}
