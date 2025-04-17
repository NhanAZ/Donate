<?php

declare(strict_types=1);

namespace Donate\manager;

use Donate\Constant;
use Donate\Donate;
use Donate\utils\DataTypeUtils;
use pocketmine\form\Form;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use Ramsey\Uuid\Uuid;
use function arsort;
use function ceil;
use function count;
use function filter_var;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function number_format;
use function strpos;
use function substr;
use function time;
use const FILTER_VALIDATE_INT;

class FormManager {
	private Donate $plugin;

	// Add player cooldown tracking
	/** @var array<string, int> Last form submission time by player */
	public array $lastFormSubmission = [];

	// Form cooldown setting
	/** @var int Cooldown between form submissions in seconds */
	private int $formCooldown = 5; // Default: 5 seconds cooldown

	/**
	 * Get the form submission cooldown time in seconds
	 */
	public function getFormCooldown() : int {
		return $this->formCooldown;
	}

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;

		// Load form cooldown from config
		$config = $plugin->getConfig();
		$configValue = $config->getNested("anti_spam.form_cooldown", 5);
		$this->formCooldown = max(1, filter_var($configValue, FILTER_VALIDATE_INT, ["options" => ["default" => 5]]));

		$plugin->debugLogger->log("Form cooldown set to {$this->formCooldown} seconds", "form");
	}

	/**
	 * Send the donate form to a player
	 */
	public function sendDonateForm(Player $player) : void {
		// Check for cooldown to prevent spam
		$playerName = $player->getName();
		$currentTime = time();

		if (isset($this->lastFormSubmission[$playerName])) {
			$lastTime = $this->lastFormSubmission[$playerName];
			$timeDiff = $currentTime - $lastTime;

			if ($timeDiff < $this->formCooldown) {
				$remainingTime = $this->formCooldown - $timeDiff;
				$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Vui lòng đợi {$remainingTime} giây trước khi mở form lại."));
				$this->plugin->debugLogger->log("Form spam prevented - Player: {$playerName} tried to open form too quickly", "form");
				return;
			}
		}

		// Update the last form time
		$this->lastFormSubmission[$playerName] = $currentTime;

		$this->plugin->logger->info("[Donate/Form] Sending donate form to player: " . $player->getName());
		$form = $this->createDonateForm();
		$player->sendForm($form);
	}

	/**
	 * Send the top donate form to a player
	 */
	public function sendTopDonateForm(Player $player, int $page = 1) : void {
		// Check for cooldown to prevent spam
		$playerName = $player->getName();
		$currentTime = time();

		if (isset($this->lastFormSubmission[$playerName])) {
			$lastTime = $this->lastFormSubmission[$playerName];
			$timeDiff = $currentTime - $lastTime;

			if ($timeDiff < $this->formCooldown) {
				$remainingTime = $this->formCooldown - $timeDiff;
				$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Vui lòng đợi {$remainingTime} giây trước khi mở form lại."));
				$this->plugin->debugLogger->log("TopDonate form spam prevented - Player: {$playerName} tried to open form too quickly", "form");
				return;
			}
		}

		// Update the last form time
		$this->lastFormSubmission[$playerName] = $currentTime;

		$this->plugin->logger->info("[Donate/Form] Sending top donate form to player: " . $player->getName() . ", page: " . $page);
		$this->plugin->debugLogger->log("Sending top donate form to player: " . $player->getName() . ", page: " . $page, "form");
		$form = $this->createTopDonateForm($page);
		$player->sendForm($form);
	}

	/**
	 * Create a form for card donations
	 */
	private function createDonateForm() : Form {
		return new class($this->plugin) implements Form {
			private Donate $plugin;

			public function __construct(Donate $plugin) {
				$this->plugin = $plugin;
			}

			/** @return array<string, mixed> */
			public function jsonSerialize() : array {
				return [
					'type' => 'custom_form',
					'title' => 'Nạp Thẻ',
					'content' => [
						[
							'type' => 'dropdown',
							'text' => 'Loại thẻ',
							'options' => Constant::TELCO_DISPLAY
						],
						[
							'type' => 'dropdown',
							'text' => 'Mệnh giá',
							'options' => Constant::AMOUNT_DISPLAY
						],
						[
							'type' => 'input',
							'text' => 'Số sê-ri',
							'placeholder' => 'Ví dụ: 10004783347874'
						],
						[
							'type' => 'input',
							'text' => 'Mã thẻ',
							'placeholder' => 'Ví dụ: 312821445892982'
						]
					]
				];
			}

			public function handleResponse(Player $player, mixed $data) : void {
				// Check if form was closed
				if ($data === null) {
					$this->plugin->logger->info("[Donate/Form] Player " . $player->getName() . " closed the donate form");
					return;
				}

				// Apply submission cooldown to prevent API spam
				$playerName = $player->getName();
				$currentTime = time();
				$formManager = $this->plugin->getFormManager();

				// Check if the player is attempting to submit forms too quickly
				if (isset($formManager->lastFormSubmission[$playerName])) {
					$lastTime = $formManager->lastFormSubmission[$playerName];
					$timeDiff = $currentTime - $lastTime;

					if ($timeDiff < $formManager->getFormCooldown()) {
						$remainingTime = $formManager->getFormCooldown() - $timeDiff;
						$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Gửi quá nhanh! Vui lòng đợi {$remainingTime} giây trước khi gửi lại."));
						$this->plugin->debugLogger->log("Form submission throttled - Player: {$playerName} tried to submit too quickly", "form");
						return;
					}
				}

				// Update the last submission time
				$formManager->lastFormSubmission[$playerName] = $currentTime;

				if (!is_array($data)) {
					$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Có lỗi xảy ra khi xử lý biểu mẫu!"));
					return;
				}

				// Extract and validate telcoIndex (dropdown)
				$telcoIndex = 0; // Default to first telco
				if (isset($data[0]) && is_numeric($data[0])) {
					$telcoIndex = (int) $data[0];
				}

				// Extract and validate amountIndex (dropdown)
				$amountIndex = 0; // Default to first amount
				if (isset($data[1]) && is_numeric($data[1])) {
					$amountIndex = (int) $data[1];
				}

				// Extract and validate serial (input)
				$serial = "";
				if (isset($data[2]) && is_string($data[2])) {
					$serial = $data[2];
				}

				// Extract and validate code (input)
				$code = "";
				if (isset($data[3]) && is_string($data[3])) {
					$code = $data[3];
				}

				// Validate inputs are not empty
				if (empty($serial) || empty($code)) {
					// Sử dụng trực tiếp formatErrorMessage mà không qua bước dịch lại nội dung
					$player->sendMessage(Constant::PREFIX . "§c" . "Vui lòng không để trống số sê-ri hoặc mã thẻ!");

					// Ghi log debug
					$this->plugin->logger->info("[Donate/Form] Player " . $player->getName() . " submitted form with empty serial or code");
					$this->plugin->debugLogger->log(
						"Player {$player->getName()} submitted form with empty serial or code",
						"payment"
					);
					return;
				}

				// Get actual values from constants
				$telco = Constant::TELCO[$telcoIndex] ?? Constant::TELCO[0];
				$amount = Constant::AMOUNT[$amountIndex] ?? Constant::AMOUNT[0];

				// Generate unique request ID
				$requestId = Uuid::uuid4()->toString();

				// Debug logging - form submission
				$this->plugin->logger->info("[Donate/Payment] Player " . $player->getName() . " submitted card payment - Telco: $telco, Amount: $amount");
				$this->plugin->debugLogger->log(
					"Player {$player->getName()} submitted donation form - Telco: $telco, Amount: $amount",
					"payment"
				);

				// Mask sensitive data for debug logging
				$maskedSerial = substr($serial, 0, 4) . "****" . substr($serial, -4);
				$maskedCode = substr($code, 0, 2) . "****" . substr($code, -2);
				$this->plugin->debugLogger->log(
					"Card details - Serial: $maskedSerial, Code: $maskedCode, RequestID: $requestId",
					"payment"
				);

				// Process payment
				$response = $this->plugin->getPaymentManager()->processCardPayment(
					$player,
					$telco,
					$code,
					$serial,
					$amount,
					$requestId
				);

				// Show response to player
				if ($response->isSuccessful() || $response->isPending()) {
					$player->sendMessage(\Donate\utils\MessageTranslator::formatInfoMessage("Thẻ của bạn đang được xử lý, vui lòng đợi..."));

					// Debug logging - card processing started
					$this->plugin->debugLogger->log(
						"Card processing started for {$player->getName()} - RequestID: $requestId",
						"payment"
					);

					// Send debug message to player if they have admin permission
					if ($player->hasPermission("donate.admin")) {
						$this->plugin->debugLogger->sendToPlayer(
							$player,
							"Thẻ đang được xử lý với ID: $requestId",
							"payment"
						);
					}
				} else {
					// Debug message trước khi xử lý
					$this->plugin->debugLogger->log(
						"Got error message from API: '{$response->getMessage()}'",
						"payment"
					);

					// Xử lý đặc biệt cho card_existed
					$message = $response->getMessage();
					if (strpos($message, "card_existed") !== false) {
						$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage("Thẻ này đã được sử dụng trước đó"));
					} else {
						$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage($message));
					}

					// Debug logging - card processing failed immediately
					$this->plugin->debugLogger->log(
						"Card processing failed immediately for {$player->getName()} - Reason: {$response->getMessage()}",
						"payment"
					);
				}
			}
		};
	}

	/**
	 * Create a top donate form
	 */
	private function createTopDonateForm(int $page = 1) : Form {
		return new class($this->plugin, $page) implements Form {
			private Donate $plugin;
			private int $page;

			public function __construct(Donate $plugin, int $page) {
				$this->plugin = $plugin;
				$this->page = $page;
			}

			/** @return array<string, mixed> */
			public function jsonSerialize() : array {
				$donateData = $this->plugin->getDonateData()->getAll();

				// Log debug for donation data
				$this->plugin->debugLogger->log("TopDonate form: Loaded " . count($donateData) . " donation records", "form");

				// Nếu không có dữ liệu
				if (empty($donateData)) {
					$this->plugin->debugLogger->log("TopDonate form: No donation data found, showing empty message", "form");
					return [
						'type' => 'form',
						'title' => '§l§f• §8[§6Xếp Hạng Donate§8] §f•',
						'content' => "§cHiện chưa có một ai nạp thẻ ủng hộ máy chủ...",
						'buttons' => [
							['text' => '§l§8« §cĐóng §8»']
						]
					];
				}

				// Sắp xếp theo thứ tự giảm dần
				arsort($donateData);

				$totalPlayers = count($donateData);
				$itemsPerPage = 10;
				$maxPage = (int) ceil($totalPlayers / $itemsPerPage);

				// Xác thực số trang
				$this->page = max(1, min($this->page, $maxPage));

				$this->plugin->debugLogger->log("TopDonate form: Page info - Current: " . $this->page . ", Max: " . $maxPage . ", Total Players: " . $totalPlayers, "form");

				$startIndex = ($this->page - 1) * $itemsPerPage;
				$i = 0;
				$topList = [];
				$serverTotalDonated = 0;

				foreach ($donateData as $playerName => $amount) {
					// Tính tổng số tiền donate
					$amountValue = is_numeric($amount) ? (int) $amount : 0;
					$serverTotalDonated += $amountValue;

					// Hiển thị các mục cho trang hiện tại
					if ($i >= $startIndex && $i < $startIndex + $itemsPerPage) {
						$rank = $i + 1;
						$formattedAmount = number_format($amountValue, 0, ",", ".");
						$topList[] = "§l§f$rank. §e$playerName: §a{$formattedAmount}₫";
					}

					$i++;
				}

				// Tổng số tiền donate của server
				$formattedTotal = number_format($serverTotalDonated, 0, ",", ".");

				// Nội dung form
				$content = "§l§6• §fBảng xếp hạng donate trang §a" . $this->page . "§f/§a" . $maxPage . " §6•\n\n";
				$content .= implode("\n", $topList);
				$content .= "\n\n§l§6• §fTổng số tiền nạp thẻ từ người chơi: §a{$formattedTotal}₫ §6•";

				// Thêm các nút điều hướng
				$buttons = [];

				// Nút trang trước
				if ($this->page > 1) {
					$buttons[] = ['text' => '§l§8« §aTrang Trước'];
				} else {
					$buttons[] = ['text' => '§l§8« §0Trang Trước', 'tooltip' => 'Đây đã là trang đầu tiên'];
				}

				// Nút đóng
				$buttons[] = ['text' => '§l§8« §cĐóng §8»'];

				// Nút trang sau
				if ($this->page < $maxPage) {
					$buttons[] = ['text' => '§l§aTrang Sau §8»'];
				} else {
					$buttons[] = ['text' => '§l§0Trang Sau §8»', 'tooltip' => 'Đây đã là trang cuối cùng'];
				}

				// Thêm nút để mở form nhập số trang
				$buttons[] = ['text' => '§l§8« §eNhập Số Trang §8»'];

				return [
					'type' => 'form',
					'title' => '§l§f• §8[§6Xếp Hạng Donate§8] §f•',
					'content' => $content,
					'buttons' => $buttons
				];
			}

			public function handleResponse(Player $player, mixed $data) : void {
				if ($data === null) {
					$this->plugin->debugLogger->log("TopDonate form: Player " . $player->getName() . " closed the form", "form");
					return; // Người chơi đóng form
				}

				// Convert $data to string and store in variable first
				$dataStr = DataTypeUtils::toString($data);
				$this->plugin->debugLogger->log("TopDonate form: Player " . $player->getName() . " clicked button index: " . $dataStr, "form");

				$donateData = $this->plugin->getDonateData()->getAll();
				if (empty($donateData)) {
					$this->plugin->debugLogger->log("TopDonate form: No donation data found when handling response", "form");
					return;
				}

				$totalPlayers = count($donateData);
				$itemsPerPage = 10;
				$maxPage = (int) ceil($totalPlayers / $itemsPerPage);

				// Use integer cast of $data for the switch statement
				$dataInt = DataTypeUtils::toInt($data);
				switch ($dataInt) {
					case 0: // Trang trước
						if ($this->page > 1) {
							$this->plugin->debugLogger->log("TopDonate form: Going to previous page: " . ($this->page - 1), "form");
							$this->plugin->getFormManager()->sendTopDonateForm($player, $this->page - 1);
						} else {
							// Khi ở trang 1 mà bấm "Trang Trước", hiển thị thông báo và mở lại form sau 1.5 giây
							$this->plugin->debugLogger->log("TopDonate form: Attempted to go to previous page while on first page", "form");

							// Hiển thị thông báo
							$player->sendMessage(Constant::PREFIX . "§eĐây là trang đầu tiên rồi.");

							// Mở lại form sau 1.5 giây
							$this->plugin->getScheduler()->scheduleDelayedTask(
								new ClosureTask(
									function () use ($player) : void {
										if ($player->isOnline()) {
											$this->plugin->debugLogger->log("TopDonate form: Reopening first page after delay", "form");
											$this->plugin->getFormManager()->sendTopDonateForm($player, 1);
										}
									}
								),
								30 // 30 ticks = 1.5 giây
							);
						}
						break;

					case 1: // Đóng
						$this->plugin->debugLogger->log("TopDonate form: Player " . $player->getName() . " clicked Close button", "form");
						// Không làm gì
						break;

					case 2: // Trang sau
						if ($this->page < $maxPage) {
							$this->plugin->debugLogger->log("TopDonate form: Going to next page: " . ($this->page + 1), "form");
							$this->plugin->getFormManager()->sendTopDonateForm($player, $this->page + 1);
						} else {
							// Khi ở trang cuối mà bấm "Trang Sau", hiển thị thông báo và mở lại form sau 1.5 giây
							$this->plugin->debugLogger->log("TopDonate form: Attempted to go to next page while on last page", "form");

							// Hiển thị thông báo
							$player->sendMessage(Constant::PREFIX . "§eĐây là trang cuối rồi.");

							// Mở lại form sau 1.5 giây
							$this->plugin->getScheduler()->scheduleDelayedTask(
								new ClosureTask(
									function () use ($player, $maxPage) : void {
										if ($player->isOnline()) {
											$this->plugin->debugLogger->log("TopDonate form: Reopening last page after delay", "form");
											$this->plugin->getFormManager()->sendTopDonateForm($player, $maxPage);
										}
									}
								),
								30 // 30 ticks = 1.5 giây
							);
						}
						break;

					case 3: // Nhập số trang
						$this->plugin->debugLogger->log("TopDonate form: Player " . $player->getName() . " clicked Go To Page button", "form");
						$this->openGoToPageForm($player, $maxPage);
						break;
				}
			}

			/**
			 * Mở form nhập số trang
			 */
			private function openGoToPageForm(Player $player, int $maxPage) : void {
				$form = new \dktapps\pmforms\CustomForm(
					'§l§f• §8[§6Chuyển Trang§8] §f•',
					[
						new \dktapps\pmforms\element\Label("info", "§fNhập số trang bạn muốn xem (1-{$maxPage}):"),
						new \dktapps\pmforms\element\Input("page", "Số trang", "Nhập số từ 1 đến {$maxPage}")
					],
					function(Player $player, \dktapps\pmforms\CustomFormResponse $data) use ($maxPage) : void {
						// Get the entered page number
						$pageInput = $data->getString("page");
						$pageInputStr = DataTypeUtils::toString($pageInput);
						$this->plugin->debugLogger->log("GoToPage form: Player " . $player->getName() . " entered page: " . $pageInputStr, "form");
						
						// Validate and convert to integer
						if (!is_numeric($pageInput)) {
							$player->sendMessage(Constant::PREFIX . "§cVui lòng nhập một số hợp lệ!");
							// Reopen the original form
							$this->plugin->getFormManager()->sendTopDonateForm($player, $this->page);
							return;
						}
						
						$pageNumber = (int) $pageInput;
						
						// Validate the page number
						if ($pageNumber < 1 || $pageNumber > $maxPage) {
							$player->sendMessage(Constant::PREFIX . "§cSố trang phải từ 1 đến {$maxPage}!");
							// Reopen the original form
							$this->plugin->getFormManager()->sendTopDonateForm($player, $this->page);
							return;
						}
						
						// Go to the specified page
						$this->plugin->debugLogger->log("GoToPage form: Going to page " . $pageNumber, "form");
						$this->plugin->getFormManager()->sendTopDonateForm($player, $pageNumber);
					},
					function(Player $player) : void {
						// Form was closed without submitting
						$this->plugin->debugLogger->log("GoToPage form: Player " . $player->getName() . " closed the form", "form");
						// Reopen the original form
						$this->plugin->getFormManager()->sendTopDonateForm($player, $this->page);
					}
				);
				
				$player->sendForm($form);
			}
		};
	}

	/**
	 * Show a confirmation form to the player
	 */
	public function sendConfirmationForm(Player $player, string $message, callable $onConfirm) : void {
		$form = $this->createConfirmationForm($message, $onConfirm);
		$player->sendForm($form);
	}

	/**
	 * Create a simple confirmation form
	 */
	private function createConfirmationForm(string $message, callable $onConfirm) : Form {
		return new class($message, $onConfirm) implements Form {
			private string $message;
			/** @var callable */
			private $callback;

			public function __construct(string $message, callable $callback) {
				$this->message = $message;
				$this->callback = $callback;
			}

			/** @return array<string, mixed> */
			public function jsonSerialize() : array {
				return [
					'type' => 'form',
					'title' => 'Xác nhận',
					'content' => $this->message,
					'buttons' => [
						['text' => 'Xác nhận'],
						['text' => 'Hủy']
					]
				];
			}

			public function handleResponse(Player $player, mixed $data) : void {
				// Check if form was closed or canceled
				if ($data === null || $data === 1) {
					return;
				}

				// Call the confirmation callback
				if ($data === 0) {
					($this->callback)();
				}
			}
		};
	}
}
