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
		
		// Ghi log việc sử dụng lệnh
		$senderName = $sender instanceof Player ? $sender->getName() : "Console";
		$argsString = !empty($args) ? " " . implode(" ", $args) : "";
		$this->logger->info("[Donate/Command] $senderName executed /$commandName$argsString");

		switch ($commandName) {
			case "donate":
				if (!$sender instanceof Player) {
					$sender->sendMessage(Constant::PREFIX . "Vui lòng sử dụng lệnh này trong trò chơi!");
					return true;
				}

				$this->logger->info("[Donate/Form] Opening donate form for player: " . $sender->getName());
				$this->formManager->sendDonateForm($sender);
				return true;

			case "topdonate":
				$page = 1;
				if (isset($args[0]) && is_numeric($args[0])) {
					$page = max(1, (int) $args[0]);
				}

				$this->logger->info("[Donate/TopDonate] Player $senderName requested top donators page: $page");
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
						
					case "enabledebug":
						$this->setDebugEnabled($sender, true);
						break;
						
					case "disabledebug":
						$this->setDebugEnabled($sender, false);
						break;
						
					case "notifyadmins":
						$this->toggleNotifyAdmins($sender);
						break;
						
					case "list":
						$this->listDebugCategories($sender);
						break;
						
					case "loginfo":
						$this->checkLogFile($sender);
						break;
						
					case "clearlog":
						$this->clearLogFile($sender);
						break;
						
					case "testlog":
						$this->testLogWriting($sender);
						break;
						
					default:
						$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Các lệnh debug:"));
						
						// Quản lý cài đặt debug
						$sender->sendMessage("§e§l● Quản lý chung:");
						$sender->sendMessage("§7 /donatedebug list §f- Liệt kê các danh mục debug và trạng thái");
						$sender->sendMessage("§7 /donatedebug enabledebug §f- Bật debug");
						$sender->sendMessage("§7 /donatedebug disabledebug §f- Tắt debug");
						$sender->sendMessage("§7 /donatedebug notifyadmins §f- Bật/tắt thông báo debug cho admin");
						$sender->sendMessage("§7 /donatedebug toggle <category> §f- Bật/tắt debug cho danh mục cụ thể");
						
						// Thanh toán
						$sender->sendMessage("§e§l● Thanh toán:");
						$sender->sendMessage("§7 /donatedebug pending §f- Kiểm tra các giao dịch đang xử lý");
						$sender->sendMessage("§7 /donatedebug status <requestId> §f- Kiểm tra trạng thái giao dịch");
						
						// Quản lý log
						$sender->sendMessage("§e§l● Quản lý Log:");
						$sender->sendMessage("§7 /donatedebug loginfo §f- Kiểm tra thông tin file log");
						$sender->sendMessage("§7 /donatedebug clearlog §f- Xóa nội dung file log");
						$sender->sendMessage("§7 /donatedebug testlog §f- Kiểm tra khả năng ghi log");
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
	
	/**
	 * Set debug enabled status
	 */
	private function setDebugEnabled(CommandSender $sender, bool $enabled): void {
		$config = $this->getConfig();
		$config->setNested("debug.enabled", $enabled);
		$config->save();
		
		// Reload debug configuration
		$this->debugLogger->loadConfig();
		
		$status = $enabled ? "§aBật" : "§cTắt";
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §edebug"));
	}
	
	/**
	 * Toggle notify admins setting
	 */
	private function toggleNotifyAdmins(CommandSender $sender): void {
		$config = $this->getConfig();
		$currentValue = (bool) $config->getNested("debug.notify_admins", false);
		$newValue = !$currentValue;
		
		$config->setNested("debug.notify_admins", $newValue);
		$config->save();
		
		// Reload debug configuration
		$this->debugLogger->loadConfig();
		
		$status = $newValue ? "§aBật" : "§cTắt";
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §ethông báo debug cho admin"));
	}
	
	/**
	 * List all debug categories and their status
	 */
	private function listDebugCategories(CommandSender $sender): void {
		$config = $this->getConfig();
		$enabled = (bool) $config->getNested("debug.enabled", false);
		$notifyAdmins = (bool) $config->getNested("debug.notify_admins", false);
		$categories = $config->getNested("debug.categories", []);
		
		$enabledStatus = $enabled ? "§aBật" : "§cTắt";
		$notifyStatus = $notifyAdmins ? "§aBật" : "§cTắt";
		
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Trạng thái debug:"));
		$sender->sendMessage("§7- Trạng thái: " . $enabledStatus);
		$sender->sendMessage("§7- Thông báo admin: " . $notifyStatus);
		$sender->sendMessage("§7- Danh mục:");
		
		if (empty($categories)) {
			$sender->sendMessage("  §7Không có danh mục nào được cấu hình");
		} else {
			foreach ($categories as $category => $status) {
				$categoryStatus = $status ? "§aBật" : "§cTắt";
				$sender->sendMessage("  §7- §f" . $category . ": " . $categoryStatus);
			}
		}
	}
	
	/**
	 * Kiểm tra thông tin file log
	 */
	private function checkLogFile(CommandSender $sender): void {
		$logPath = $this->getDataFolder() . "log.log";
		
		if (!file_exists($logPath)) {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("File log không tồn tại!"));
			return;
		}
		
		$fileSize = filesize($logPath);
		$isWritable = is_writable($logPath);
		$lastModified = filemtime($logPath);
		
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Thông tin file log:"));
		$sender->sendMessage("§7- Đường dẫn: §f" . $logPath);
		$sender->sendMessage("§7- Kích thước: §f" . $this->formatFileSize($fileSize));
		$sender->sendMessage("§7- Quyền ghi: §f" . ($isWritable ? "§aCó" : "§cKhông"));
		$sender->sendMessage("§7- Lần sửa cuối: §f" . date("Y-m-d H:i:s", $lastModified));
		
		// Kiểm tra nội dung file log
		if ($fileSize > 0) {
			// Đọc 5 dòng đầu tiên
			$firstLines = $this->readFirstLines($logPath, 5);
			if (!empty($firstLines)) {
				$sender->sendMessage("§7- 5 dòng đầu tiên:");
				foreach ($firstLines as $line) {
					$sender->sendMessage("  §7" . $line);
				}
			}
			
			// Đọc 5 dòng cuối cùng
			$lastLines = $this->readLastLines($logPath, 5);
			if (!empty($lastLines)) {
				$sender->sendMessage("§7- 5 dòng cuối cùng:");
				foreach ($lastLines as $line) {
					$sender->sendMessage("  §7" . $line);
				}
			}
		} else {
			$sender->sendMessage("§7- File log trống!");
		}
	}
	
	/**
	 * Xóa nội dung file log
	 */
	private function clearLogFile(CommandSender $sender): void {
		$logPath = $this->getDataFolder() . "log.log";
		
		if (!file_exists($logPath)) {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("File log không tồn tại!"));
			return;
		}
		
		if (!is_writable($logPath)) {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Không có quyền ghi file log!"));
			return;
		}
		
		// Xóa nội dung file bằng cách ghi đè chuỗi rỗng
		if (file_put_contents($logPath, "") !== false) {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatSuccessMessage("Đã xóa nội dung file log!"));
			
			// Ghi thông báo đầu tiên vào file log
			$this->logger->info("Log file cleared by " . $sender->getName() . " at " . date("Y-m-d H:i:s"));
			$this->debugLogger->log("Log file cleared by " . $sender->getName(), "general");
		} else {
			$sender->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Không thể xóa nội dung file log!"));
		}
	}
	
	/**
	 * Kiểm tra khả năng ghi log
	 */
	private function testLogWriting(CommandSender $sender): void {
		// Thử ghi log bằng cả hai phương thức
		$testMessage = "Test log từ " . $sender->getName() . " lúc " . date("Y-m-d H:i:s");
		
		// Log thông thường với getLogger của plugin
		$this->getLogger()->info("INFO log: " . $testMessage);
		$this->getLogger()->debug("DEBUG log thông qua getLogger(): " . $testMessage);
		
		// Log trực tiếp vào file log.log bằng MainLogger
		$this->logger->info("INFO log trực tiếp: " . $testMessage);
		$this->logger->debug("DEBUG log trực tiếp: " . $testMessage);
		
		// Log qua DebugLogger
		$this->debugLogger->log("Test debug cho danh mục general: " . $testMessage, "general");
		$this->debugLogger->log("Test debug cho danh mục payment: " . $testMessage, "payment");
		$this->debugLogger->log("Test debug cho danh mục api: " . $testMessage, "api");
		
		// Thử các phương thức log chuyên biệt
		$this->debugLogger->logPayment(
		    "TEST",
		    $sender->getName(),
		    "test-" . uniqid(),
		    ["time" => date("H:i:s"), "sender" => $sender->getName()]
		);
		
		$sender->sendMessage(\Donate\utils\MessageTranslator::formatSuccessMessage("Đã thử ghi log. Kiểm tra file log bằng lệnh /donatedebug loginfo và kiểm tra console"));
	}
	
	/**
	 * Đọc số dòng đầu tiên của file
	 */
	private function readFirstLines(string $filePath, int $lineCount): array {
		$lines = [];
		$handle = fopen($filePath, "r");
		
		if ($handle) {
			$count = 0;
			while (($line = fgets($handle)) !== false && $count < $lineCount) {
				$lines[] = trim($line);
				$count++;
			}
			fclose($handle);
		}
		
		return $lines;
	}
	
	/**
	 * Đọc số dòng cuối cùng của file
	 */
	private function readLastLines(string $filePath, int $lineCount): array {
		$lines = [];
		$fileSize = filesize($filePath);
		
		if ($fileSize == 0) {
			return $lines;
		}
		
		$handle = fopen($filePath, "r");
		if ($handle) {
			// Đọc tất cả các dòng trong file
			$allLines = [];
			while (($line = fgets($handle)) !== false) {
				$allLines[] = trim($line);
			}
			fclose($handle);
			
			// Lấy các dòng cuối cùng
			$count = count($allLines);
			$start = max(0, $count - $lineCount);
			$lines = array_slice($allLines, $start);
		}
		
		return $lines;
	}
	
	/**
	 * Format kích thước file dễ đọc
	 */
	private function formatFileSize(int $bytes): string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		
		$bytes = max($bytes, 0);
		$pow = min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);
		
		$bytes /= (1 << (10 * $pow));
		
		return round($bytes, 2) . ' ' . $units[$pow];
	}
}
