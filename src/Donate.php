<?php

declare(strict_types=1);

namespace Donate;

use Donate\manager\FormManager;
use Donate\manager\PaymentManager;
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
use function array_rand;
use function array_slice;
use function arsort;
use function ceil;
use function class_exists;
use function count;
use function date;
use function fclose;
use function fgets;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function filesize;
use function filter_var;
use function floor;
use function fopen;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_writable;
use function log;
use function max;
use function min;
use function mkdir;
use function mt_rand;
use function number_format;
use function round;
use function strlen;
use function strtoupper;
use function substr;
use function time;
use function trim;
use function uniqid;
use function version_compare;
use const FILTER_VALIDATE_INT;
use const PHP_VERSION;

class Donate extends PluginBase {
	use SingletonTrait;

	public MainLogger $logger;
	public DebugLogger $debugLogger;

	private Config $donateData;
	private FormManager $formManager;
	private PaymentManager $paymentManager;

	protected function onLoad() : void {
		self::setInstance($this);

		// Prepare plugin directory
		@mkdir($this->getDataFolder());
	}

	protected function onEnable() : void {
		// Check PHP version
		if (version_compare(PHP_VERSION, '8.3.0', '<')) {
			$this->getLogger()->error("Plugin require PHP 8.3.0 or higher. Current version: " . PHP_VERSION);
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		// Check pmforms dependency
		if (!class_exists('dktapps\pmforms\CustomForm')) {
			$this->getLogger()->error("Thiếu dependency: pmforms không được tìm thấy! Hãy cài đặt virion hoặc plugin pmforms.");
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}

		// Initialize configuration
		$this->saveDefaultConfig();

		// Add default anti-spam settings if they don't exist
		$config = $this->getConfig();
		if (!$config->exists("anti_spam")) {
			$config->set("anti_spam", [
				"form_cooldown" => 5,  // Seconds between form submissions
				"api_cooldown" => 2    // Seconds between API requests
			]);
			$config->save();
		}

		// Apply anti-spam settings
		$formCooldownValue = $config->getNested("anti_spam.form_cooldown", 5);
		$apiCooldownValue = $config->getNested("anti_spam.api_cooldown", 2);
		$formCooldown = filter_var($formCooldownValue, FILTER_VALIDATE_INT, ["options" => ["default" => 5]]);
		$apiCooldown = filter_var($apiCooldownValue, FILTER_VALIDATE_INT, ["options" => ["default" => 2]]);

		// Configure API cooldown
		api\TrumTheAPI::setApiCooldown($apiCooldown);

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

	protected function onDisable() : void {
		// Save any pending data
		if (isset($this->donateData)) {
			$this->donateData->save();
		}

		if (isset($this->debugLogger)) {
			$this->debugLogger->log("Plugin disabled", "general");
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
		$commandName = $command->getName();

		// Ghi log việc sử dụng lệnh
		$senderName = $sender instanceof Player ? $sender->getName() : "Console";
		$argsString = !empty($args) ? " " . implode(" ", $args) : "";
		$this->logger->info("[Donate/Command] $senderName executed /$commandName$argsString");
		$this->debugLogger->log("Command executed: $senderName used /$commandName$argsString", "command");

		switch ($commandName) {
			case "donate":
				if ($sender instanceof Player) {
					// Người chơi thực thi lệnh, mở form donate
					$this->logger->info("[Donate/Form] Opening donate form for player: " . $sender->getName());
					$this->debugLogger->log("Opening donate form for player: " . $sender->getName(), "command");
					$this->formManager->sendDonateForm($sender);
					return true;
				} else {
					// Nếu console thực thi lệnh với đầy đủ tham số
					// Định dạng: /donate <tên người chơi> <telco> <code> <serial> <amount>
					if (isset($args[0], $args[1], $args[2], $args[3], $args[4])) {
						$playerName = $args[0];
						$telco = strtoupper($args[1]);
						$code = $args[2];
						$serial = $args[3];
						$amount = (int) $args[4];

						// Tìm player nếu đang online
						$targetPlayer = $this->getServer()->getPlayerExact($playerName);

						// Kiểm tra tính hợp lệ của telco
						$validTelcos = ["VIETTEL", "MOBIFONE", "VINAPHONE", "VIETNAMOBILE", "ZING"];
						if (!in_array($telco, $validTelcos, true)) {
							$sender->sendMessage(Constant::PREFIX . "§cLoại thẻ không hợp lệ. Các loại thẻ hỗ trợ: " . implode(", ", $validTelcos));
							return true;
						}

						// Kiểm tra mệnh giá
						$validAmounts = [10000, 20000, 30000, 50000, 100000, 200000, 300000, 500000, 1000000];
						if (!in_array($amount, $validAmounts, true)) {
							$sender->sendMessage(Constant::PREFIX . "§cMệnh giá không hợp lệ. Các mệnh giá hỗ trợ: " . implode(", ", $validAmounts));
							return true;
						}

						// Kiểm tra độ dài mã thẻ và serial
						if (strlen($code) < 10) {
							$sender->sendMessage(Constant::PREFIX . "§cMã thẻ không hợp lệ, quá ngắn");
							return true;
						}

						if (strlen($serial) < 10) {
							$sender->sendMessage(Constant::PREFIX . "§cSerial không hợp lệ, quá ngắn");
							return true;
						}

						// Tạo request ID
						$requestId = uniqid("console_", true);

						$this->logger->info("[Donate/Console] Processing card payment - Player: $playerName, Telco: $telco, RequestID: $requestId, Amount: $amount");
						$this->debugLogger->log("Console initiated card payment for $playerName - Telco: $telco, Amount: $amount", "command");

						// Xử lý nạp thẻ
						if ($targetPlayer !== null) {
							// Nếu người chơi online, sử dụng processCardPayment từ PaymentManager
							$response = $this->paymentManager->processCardPayment(
								$targetPlayer,
								$telco,
								$code,
								$serial,
								$amount,
								$requestId
							);

							// Thông báo kết quả ban đầu
							if ($response->isSuccessful() || $response->isPending()) {
								$sender->sendMessage(Constant::PREFIX . "§aĐã gửi yêu cầu nạp thẻ cho người chơi §f" . $playerName);
								$sender->sendMessage(Constant::PREFIX . "§aThẻ đang được xử lý, mã giao dịch: §f" . substr($requestId, 0, 10));
								$targetPlayer->sendMessage(Constant::PREFIX . "§aAdmin đã nạp thẻ giúp bạn, hệ thống đang xử lý");
							} else {
								$sender->sendMessage(Constant::PREFIX . "§cCó lỗi xảy ra khi nạp thẻ: §f" . $response->getMessage());
							}
						} else {
							// Nếu người chơi offline, sử dụng task
							$this->getServer()->getAsyncPool()->submitTask(new tasks\ChargingTask(
								$playerName,
								$telco,
								$code,
								$serial,
								$amount,
								$requestId
							));

							// Dùng CardPayment để lưu trữ thông tin giao dịch
							$payment = new payment\CardPayment(
								$requestId,
								$playerName,
								$telco,
								$code,
								$serial,
								$amount,
								time()
							);

							// Thêm vào danh sách thanh toán đang xử lý
							$this->paymentManager->addSamplePayment($requestId, $payment);

							$sender->sendMessage(Constant::PREFIX . "§aĐã gửi yêu cầu nạp thẻ cho người chơi §f" . $playerName . " §a(đang offline)");
							$sender->sendMessage(Constant::PREFIX . "§aThẻ đang được xử lý, mã giao dịch: §f" . substr($requestId, 0, 10));
						}

						return true;
					} else {
						// Hiển thị hướng dẫn sử dụng
						$sender->sendMessage(Constant::PREFIX . "§aSử dụng: §f/donate <tên người chơi> <telco> <mã thẻ> <serial> <mệnh giá>");
						$sender->sendMessage(Constant::PREFIX . "§aVí dụ: §f/donate NhanAZ VIETTEL 123456789012 987654321098 50000");
						$sender->sendMessage(Constant::PREFIX . "§aCác loại thẻ hỗ trợ: §fVIETTEL, MOBIFONE, VINAPHONE");
						$sender->sendMessage(Constant::PREFIX . "§aCác mệnh giá hỗ trợ: §f10000, 20000, 50000, 100000, 200000, 500000");

						// Hiển thị danh sách người chơi trực tuyến để tham khảo
						$onlinePlayers = $this->getServer()->getOnlinePlayers();
						if (count($onlinePlayers) > 0) {
							$sender->sendMessage(Constant::PREFIX . "§aNgười chơi trực tuyến:");

							foreach ($onlinePlayers as $player) {
								$sender->sendMessage("§8• §f" . $player->getName());
							}
						}
					}
					return true;
				}

			case "topdonate":
				$page = 1;
				if (isset($args[0]) && is_numeric($args[0])) {
					$page = max(1, (int) $args[0]);
				}

				$this->logger->info("[Donate/TopDonate] Player $senderName requested top donators page: $page");
				$this->debugLogger->log("TopDonate command: $senderName requested page $page", "command");

				// Nếu là player thì hiển thị form, nếu là console thì hiển thị text
				if ($sender instanceof Player) {
					$this->debugLogger->log("Sending TopDonate form to player: " . $sender->getName(), "command");
					$this->formManager->sendTopDonateForm($sender, $page);
				} else {
					$this->debugLogger->log("Showing TopDonate text to console", "command");
					$this->showTopDonators($sender, $page);
				}
				return true;

			case "donatedebug":
				if (!$sender->hasPermission("donate.command.debug")) {
					$this->debugLogger->log("DonateDebug command rejected - no permission: $senderName", "command");
					$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Bạn không có quyền sử dụng lệnh này!"));
					return true;
				}

				// Handle debug command
				$subcommand = $args[0] ?? "help";
				$this->debugLogger->log("DonateDebug subcommand: $subcommand by $senderName", "command");

				switch ($subcommand) {
					case "pending":
						$this->showPendingPayments($sender);
						break;

					case "status":
						$requestId = $args[1] ?? "";
						if (empty($requestId)) {
							$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Vui lòng cung cấp ID yêu cầu thanh toán!"));
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

					case "sampledata":
						$this->addSampleData($sender);
						break;

					case "samplepending":
						$count = 5; // Số lượng giao dịch mẫu mặc định
						if (isset($args[1]) && is_numeric($args[1])) {
							$count = max(1, min(20, (int) $args[1])); // Hạn chế từ 1-20 giao dịch mẫu
						}
						$this->addSamplePendingPayments($sender, $count);
						break;

					default:
						$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Các lệnh debug:"));

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

						// Dữ liệu mẫu
						$sender->sendMessage("§e§l● Dữ liệu mẫu:");
						$sender->sendMessage("§7 /donatedebug sampledata §f- Thêm dữ liệu mẫu để test");
						$sender->sendMessage("§7 /donatedebug samplepending [số lượng] §f- Thêm giao dịch đang chờ xử lý mẫu");
						break;
				}

				return true;

			default:
				return false;
		}
	}

	private function showTopDonators(CommandSender $sender, int $page) : void {
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
			$amountValue = is_numeric($amount) ? (int) $amount : 0;
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

	public function successfulDonation(string $playerName, int $amount) : void {
		$formattedAmount = utils\MessageTranslator::formatAmount($amount);

		// Broadcast donation message
		$this->getServer()->broadcastMessage(utils\MessageTranslator::formatSuccessMessage("Người chơi $playerName đã nạp {$formattedAmount} để ủng hộ máy chủ!"));

		// Update player statistics safely
		$currentAmount = $this->donateData->getNested($playerName, 0);
		$safeAmount = is_numeric($currentAmount) ? (int) $currentAmount : 0;
		$this->donateData->setNested($playerName, $safeAmount + $amount);
		$this->donateData->save();

		// Get bonus multiplier from config
		$multiplierRaw = $this->getConfig()->get("bonus_multiplier", 1.0);
		$multiplier = is_numeric($multiplierRaw) ? (float) $multiplierRaw : 1.0;

		// Calculate bonus amount
		$bonusAmount = (int) ($amount * $multiplier);

		// Add any additional reward code here
		// Example: EconomyAPI::getInstance()->addMoney($playerName, $bonusAmount);

		// Notify player if online
		$player = $this->getServer()->getPlayerExact($playerName);
		if ($player !== null) {
			$player->sendMessage(utils\MessageTranslator::formatSuccessMessage("Chân thành cảm ơn bạn đã ủng hộ máy chủ {$formattedAmount}!"));
			$player->sendMessage(utils\MessageTranslator::formatSuccessMessage("Bạn đã nhận được " . utils\MessageTranslator::formatAmount($bonusAmount) . " xu"));
		}
	}

	public function getDonateData() : Config {
		return $this->donateData;
	}

	public function getFormManager() : FormManager {
		return $this->formManager;
	}

	public function getPaymentManager() : PaymentManager {
		return $this->paymentManager;
	}

	/**
	 * Show pending payments to a command sender
	 */
	private function showPendingPayments(CommandSender $sender) : void {
		$pendingPayments = $this->paymentManager->getPendingPayments();

		if (empty($pendingPayments)) {
			$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Không có giao dịch nào đang chờ xử lý."));
			return;
		}

		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Các giao dịch đang xử lý: §f" . count($pendingPayments)));

		foreach ($pendingPayments as $requestId => $payment) {
			$elapsedTime = time() - $payment->getCreatedAt();
			$elapsedFormatted = floor($elapsedTime / 60) . "m " . ($elapsedTime % 60) . "s";

			$sender->sendMessage("§7- §f" . $payment->getPlayerName() .
				"§7: §f" . utils\MessageTranslator::formatAmount($payment->getAmount()) .
				"§7, ID: §f" . substr($requestId, 0, 8) .
				"§7, Thời gian: §f" . $elapsedFormatted);
		}
	}

	/**
	 * Check the status of a specific payment
	 */
	private function checkPaymentStatus(CommandSender $sender, string $requestId) : void {
		// Try to find the payment in pending payments first
		$payment = $this->paymentManager->getPayment($requestId);

		if ($payment !== null) {
			$elapsedTime = time() - $payment->getCreatedAt();
			$elapsedFormatted = floor($elapsedTime / 60) . "m " . ($elapsedTime % 60) . "s";

			$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Thông tin thanh toán:"));
			$sender->sendMessage("§7- Người chơi: §f" . $payment->getPlayerName());
			$sender->sendMessage("§7- Mệnh giá: §f" . utils\MessageTranslator::formatAmount($payment->getAmount()));
			$sender->sendMessage("§7- Loại thẻ: §f" . $payment->getTelco());
			$sender->sendMessage("§7- Trạng thái: §f" . $payment->getStatus());
			$sender->sendMessage("§7- Thời gian chờ: §f" . $elapsedFormatted);
			$sender->sendMessage("§7- ID: §f" . $requestId);

			// Trigger an immediate check of this payment
			$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Tiến hành kiểm tra trạng thái..."));

			try {
				// Lấy thông tin thẻ để truyền vào hàm kiểm tra
				$cardInfo = [
					'telco' => $payment->getTelco(),
					'code' => $payment->getCode(),
					'serial' => $payment->getSerial(),
					'amount' => $payment->getAmount()
				];

				$response = api\TrumTheAPI::checkCardStatus($requestId, $cardInfo);

				if ($response === null) {
					$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Không thể kết nối đến dịch vụ thanh toán!"));
					return;
				}

				$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Kết quả kiểm tra:"));
				$sender->sendMessage("§7- Mã trạng thái: §f" . $response->getStatus());
				$sender->sendMessage("§7- Thông báo: §f" . utils\MessageTranslator::translateErrorMessage($response->getMessage()));

				if ($response->getAmount() !== null) {
					$sender->sendMessage("§7- Số tiền: §f" . utils\MessageTranslator::formatAmount($response->getAmount()));
				}
			} catch (\Throwable $e) {
				$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Lỗi khi kiểm tra: " . $e->getMessage()));
			}
		} else {
			$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Không tìm thấy giao dịch với ID: " . $requestId));
		}
	}

	/**
	 * Toggle debug category
	 */
	private function toggleDebugCategory(CommandSender $sender, string $category) : void {
		$config = $this->getConfig();
		$currentValue = (bool) $config->getNested("debug.categories." . $category, false);
		$newValue = !$currentValue;

		$config->setNested("debug.categories." . $category, $newValue);
		$config->save();

		// Reload debug configuration
		$this->debugLogger->loadConfig();

		$status = $newValue ? "§aBật" : "§cTắt";
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §edebug cho danh mục §f" . $category));
	}

	/**
	 * Set debug enabled status
	 */
	private function setDebugEnabled(CommandSender $sender, bool $enabled) : void {
		$config = $this->getConfig();
		$config->setNested("debug.enabled", $enabled);
		$config->save();

		// Reload debug configuration
		$this->debugLogger->loadConfig();

		$status = $enabled ? "§aBật" : "§cTắt";
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §edebug"));
	}

	/**
	 * Toggle notify admins setting
	 */
	private function toggleNotifyAdmins(CommandSender $sender) : void {
		$config = $this->getConfig();
		$currentValue = (bool) $config->getNested("debug.notify_admins", false);
		$newValue = !$currentValue;

		$config->setNested("debug.notify_admins", $newValue);
		$config->save();

		// Reload debug configuration
		$this->debugLogger->loadConfig();

		$status = $newValue ? "§aBật" : "§cTắt";
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Đã " . $status . " §ethông báo debug cho admin"));
	}

	/**
	 * List all debug categories and their status
	 */
	private function listDebugCategories(CommandSender $sender) : void {
		$config = $this->getConfig();
		$enabled = (bool) $config->getNested("debug.enabled", false);
		$notifyAdmins = (bool) $config->getNested("debug.notify_admins", false);
		$categories = $config->getNested("debug.categories", []);

		$enabledStatus = $enabled ? "§aBật" : "§cTắt";
		$notifyStatus = $notifyAdmins ? "§aBật" : "§cTắt";

		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Trạng thái debug:"));
		$sender->sendMessage("§7- Trạng thái: " . $enabledStatus);
		$sender->sendMessage("§7- Thông báo admin: " . $notifyStatus);
		$sender->sendMessage("§7- Danh mục:");

		if (empty($categories) || !is_array($categories)) {
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
	private function checkLogFile(CommandSender $sender) : void {
		$logPath = $this->getDataFolder() . "log.log";

		if (!file_exists($logPath)) {
			$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("File log không tồn tại!"));
			return;
		}

		$fileSize = filesize($logPath);
		$isWritable = is_writable($logPath);
		$lastModified = filemtime($logPath);

		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Thông tin file log:"));
		$sender->sendMessage("§7- Đường dẫn: §f" . $logPath);
		$sender->sendMessage("§7- Kích thước: §f" . $this->formatFileSize($fileSize !== false ? $fileSize : 0));
		$sender->sendMessage("§7- Quyền ghi: §f" . ($isWritable ? "§aCó" : "§cKhông"));
		$sender->sendMessage("§7- Lần sửa cuối: §f" . date("Y-m-d H:i:s", $lastModified !== false ? $lastModified : time()));

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
	private function clearLogFile(CommandSender $sender) : void {
		$logPath = $this->getDataFolder() . "log.log";

		if (!file_exists($logPath)) {
			$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("File log không tồn tại!"));
			return;
		}

		if (!is_writable($logPath)) {
			$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Không có quyền ghi file log!"));
			return;
		}

		// Xóa nội dung file bằng cách ghi đè chuỗi rỗng
		if (file_put_contents($logPath, "") !== false) {
			$sender->sendMessage(utils\MessageTranslator::formatSuccessMessage("Đã xóa nội dung file log!"));

			// Ghi thông báo đầu tiên vào file log
			$this->logger->info("Log file cleared by " . $sender->getName() . " at " . date("Y-m-d H:i:s"));
			$this->debugLogger->log("Log file cleared by " . $sender->getName(), "general");
		} else {
			$sender->sendMessage(utils\MessageTranslator::formatErrorMessage("Không thể xóa nội dung file log!"));
		}
	}

	/**
	 * Kiểm tra khả năng ghi log
	 */
	private function testLogWriting(CommandSender $sender) : void {
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

		$sender->sendMessage(utils\MessageTranslator::formatSuccessMessage("Đã thử ghi log. Kiểm tra file log bằng lệnh /donatedebug loginfo và kiểm tra console"));
	}

	/**
	 * Đọc số dòng đầu tiên của file
	 * @return string[]
	 */
	private function readFirstLines(string $filePath, int $lineCount) : array {
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
	 * @return string[]
	 */
	private function readLastLines(string $filePath, int $lineCount) : array {
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
	private function formatFileSize(int $bytes) : string {
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];

		$bytes = max($bytes, 0);
		$pow = min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);

		$bytes /= (1 << (10 * $pow));

		return round($bytes, 2) . ' ' . $units[$pow];
	}

	/**
	 * Thêm dữ liệu mẫu để test
	 */
	private function addSampleData(CommandSender $sender) : void {
		$this->debugLogger->log("Adding sample data requested by: " . ($sender instanceof Player ? $sender->getName() : "Console"), "sample");

		// Thêm dữ liệu mẫu cho donateData.yml để test lệnh /topdonate
		$sampleData = [
			"NhanAZ" => 500000000,
			"Steve" => 200000,
			"Alex" => 350000,
			"Notch" => 1000000,
			"Herobrine" => 900000,
			"Jeb" => 750000,
			"Dinnerbone" => 450000,
			"WillowWisp" => 180000,
			"Enderman" => 220000,
			"Creeper" => 150000,
			"Skeleton" => 50000,
			"Zombie" => 75000,
			"Tester1" => 25000,
			"Tester2" => 35000,
			"Tester3" => 45000,
			"VIPPlayer" => 650000,
			"Regular" => 120000,
			"NewMember" => 10000,
			"ServerSponsor" => 1500000,
			"MasterBuilder" => 300000,
		];

		// Hiện tại trong donateData
		$currentData = $this->donateData->getAll();
		$this->debugLogger->log("Current data has " . count($currentData) . " entries", "sample");

		// Hợp nhất dữ liệu
		if (!empty($currentData)) {
			$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Đã tìm thấy dữ liệu hiện có, đang hợp nhất..."));

			// Chỉ thêm vào người chơi mới
			$newCount = 0;
			foreach ($sampleData as $player => $amount) {
				if (!isset($currentData[$player])) {
					$currentData[$player] = $amount;
					$newCount++;
				}
			}

			$this->debugLogger->log("Added $newCount new entries to existing data", "sample");

			// Lưu lại dữ liệu hợp nhất - ép kiểu các khóa thành chuỗi
			$stringKeysData = [];
			foreach ($currentData as $key => $value) {
				$stringKeysData[(string) $key] = $value;
			}
			$this->donateData->setAll($stringKeysData);
		} else {
			// Nếu không có dữ liệu, thêm mới hoàn toàn - ép kiểu các khóa thành chuỗi
			$stringKeysData = [];
			foreach ($sampleData as $key => $value) {
				$stringKeysData[(string) $key] = $value;
			}
			$this->donateData->setAll($stringKeysData);
			$this->debugLogger->log("Added all sample data (20 entries) to empty data file", "sample");
		}

		// Lưu dữ liệu
		$this->donateData->save();
		$this->debugLogger->log("Saved donate data to file", "sample");

		// Tạo một thanh toán mẫu đang chờ xử lý
		$requestId = "sample-" . uniqid();
		$telco = "VIETTEL";
		$code = "123456789012";
		$serial = "987654321098";
		$amount = 100000;

		$payment = new payment\CardPayment(
			$requestId,
			$sender instanceof Player ? $sender->getName() : "Console",
			$telco,
			$code,
			$serial,
			$amount,
			time()
		);

		// Thêm vào danh sách thanh toán đang chờ
		$this->paymentManager->addSamplePayment($requestId, $payment);
		$this->debugLogger->log("Added sample payment with ID: $requestId", "sample");

		$sender->sendMessage(utils\MessageTranslator::formatSuccessMessage("Đã thêm dữ liệu mẫu thành công!"));
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("- Đã thêm 20 người chơi với số tiền donate mẫu"));
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("- Đã thêm 1 giao dịch đang chờ xử lý với ID: " . substr($requestId, 0, 10) . "..."));
		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Bạn có thể test lệnh /topdonate và /donatedebug pending ngay bây giờ"));
	}

	/**
	 * Thêm giao dịch đang chờ xử lý mẫu
	 */
	private function addSamplePendingPayments(CommandSender $sender, int $count) : void {
		$this->debugLogger->log("Adding $count sample pending payments requested by: " . ($sender instanceof Player ? $sender->getName() : "Console"), "sample");

		$telcos = ["VIETTEL", "MOBIFONE", "VINAPHONE", "VIETNAMOBILE", "ZING"];
		$amounts = [10000, 20000, 50000, 100000, 200000, 500000];
		$names = [
			$sender instanceof Player ? $sender->getName() : "Console",
			"Steve",
			"Alex",
			"Notch",
			"Herobrine",
			"Jeb",
			"Dinnerbone",
			"Player1",
			"Player2",
			"Player3",
			"Player4",
			"Player5",
			"VIPMember",
			"RegularMember",
			"NewMember"
		];

		$addedPayments = [];

		for ($i = 0; $i < $count; $i++) {
			// Tạo giao dịch ngẫu nhiên
			$requestId = "sample-" . uniqid() . "-" . ($i + 1);
			$telco = $telcos[array_rand($telcos)];
			$amount = $amounts[array_rand($amounts)];
			$playerName = $names[array_rand($names)];

			// Tạo mã và serial mẫu
			$code = mt_rand(100000000000, 999999999999);
			$serial = mt_rand(100000000000, 999999999999);

			// Tạo thời gian ngẫu nhiên trong 10 phút qua
			$createdTime = time() - mt_rand(0, 600);

			$payment = new payment\CardPayment(
				$requestId,
				$playerName,
				$telco,
				(string) $code,
				(string) $serial,
				$amount,
				$createdTime
			);

			// Thêm vào danh sách
			$this->paymentManager->addSamplePayment($requestId, $payment);
			$addedPayments[] = [
				"id" => substr($requestId, 0, 8) . "...",
				"player" => $playerName,
				"amount" => $amount,
				"telco" => $telco,
				"time" => date("H:i:s", $createdTime)
			];
		}

		$sender->sendMessage(utils\MessageTranslator::formatSuccessMessage("Đã thêm $count giao dịch đang chờ xử lý mẫu!"));

		// Hiển thị danh sách các giao dịch vừa thêm
		if ($count <= 10) { // Chỉ hiển thị chi tiết nếu ít hơn 10 giao dịch
			$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Danh sách giao dịch đã thêm:"));
			foreach ($addedPayments as $p) {
				$formattedAmount = number_format($p["amount"], 0, ",", ".");
				$sender->sendMessage("§7- §f{$p["player"]} §7| §f{$formattedAmount}đ §7| §f{$p["telco"]} §7| §f{$p["time"]} §7| §fID: {$p["id"]}");
			}
		}

		$sender->sendMessage(utils\MessageTranslator::formatInfoMessage("Dùng lệnh /donatedebug pending để xem danh sách"));
	}
}
