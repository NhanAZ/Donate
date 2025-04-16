<?php

declare(strict_types=1);

namespace Donate\manager;

use Donate\Constant;
use Donate\Donate;
use pocketmine\form\Form;
use pocketmine\player\Player;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;

class FormManager {
	private Donate $plugin;

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * Send the donate form to a player
	 */
	public function sendDonateForm(Player $player): void {
		$form = $this->createDonateForm();
		$player->sendForm($form);
	}

	/**
	 * Create a form for card donations
	 */
	private function createDonateForm(): Form {
		return new class($this->plugin) implements Form {
			private Donate $plugin;

			public function __construct(Donate $plugin) {
				$this->plugin = $plugin;
			}

			/** @return array<string, mixed> */
			public function jsonSerialize(): array {
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

			public function handleResponse(Player $player, mixed $data): void {
				// Check if form was closed
				if ($data === null) {
					return;
				}

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
	 * Show a confirmation form to the player
	 */
	public function sendConfirmationForm(Player $player, string $message, callable $onConfirm): void {
		$form = $this->createConfirmationForm($message, $onConfirm);
		$player->sendForm($form);
	}

	/**
	 * Create a simple confirmation form
	 */
	private function createConfirmationForm(string $message, callable $onConfirm): Form {
		return new class($message, $onConfirm) implements Form {
			private string $message;
			/** @var callable */
			private $callback;

			public function __construct(string $message, callable $callback) {
				$this->message = $message;
				$this->callback = $callback;
			}

			/** @return array<string, mixed> */
			public function jsonSerialize(): array {
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

			public function handleResponse(Player $player, mixed $data): void {
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
