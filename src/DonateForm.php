<?php

declare(strict_types=1);

namespace Donate;

use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Dropdown;
use dktapps\pmforms\element\Input;
use dktapps\pmforms\element\StepSlider;
use Donate\Constant;
use Donate\Donate;
use Donate\payment\CardPayment;
use Donate\tasks\ChargingTask;
use Donate\utils\DebugLogger;
use Donate\utils\MessageTranslator;
use Donate\manager\PaymentManager;
use pocketmine\player\Player;
use pocketmine\Server;

class DonateForm {

	/** @var Donate */
	private $plugin;

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * @param Player $player
	 * @param mixed $data
	 */
	public function handleResponse(Player $player, $data): void {
		if ($data === null) {
			$this->plugin->debugLogger->log("Player " . $player->getName() . " closed the donate form", "form");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Bạn đã đóng form donate!"));
			return;
		}

		$this->plugin->debugLogger->log("Player " . $player->getName() . " submitted donate form", "form");

		// Ensure $data is an array with required keys
		if (!is_array($data) || !isset($data[1], $data[2], $data[3])) {
			$this->plugin->debugLogger->log("Invalid form data received from player " . $player->getName(), "form");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Dữ liệu form không hợp lệ!"));
			return;
		}

		$telcos = ["VIETTEL", "MOBIFONE", "VINAPHONE"];
		$telco = $telcos[$data[1]];
		$code = (string)$data[2];
		$serial = (string)$data[3];

		$this->plugin->debugLogger->log("Form data: telco=$telco, code=" . substr($code, 0, 4) . "..., serial=" . substr($serial, 0, 4) . "...", "form");

		// Validate card code
		if (empty($code) || !is_string($code) || strlen($code) < 10) {
			$this->plugin->debugLogger->log("Invalid card code from player " . $player->getName(), "form");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Mã thẻ không hợp lệ!"));
			return;
		}

		// Validate card serial
		if (empty($serial) || !is_string($serial) || strlen($serial) < 10) {
			$this->plugin->debugLogger->log("Invalid card serial from player " . $player->getName(), "form");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Seri thẻ không hợp lệ!"));
			return;
		}

		// Kiểm tra trùng serial hoặc code
		// TODO: Implement checkDuplicateCard method in PaymentManager
		/*$isDuplicate = $this->paymentManager->checkDuplicateCard($serial, $code);
		if ($isDuplicate) {
			$this->plugin->debugLogger->log("Duplicate card detected from player " . $player->getName(), "form");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Thẻ này đã được sử dụng trước đó!"));
			return;
		}*/

		// Tạo payload để gửi lên API
		$requestId = uniqid();
		$this->plugin->debugLogger->log("Generated request ID: $requestId for player " . $player->getName(), "form");

		// Lưu lại thông tin giao dịch đang xử lý
		$payment = new \Donate\payment\CardPayment(
			$requestId,
			$player->getName(),
			$telco,
			$code,
			$serial,
			0, // Chưa biết giá trị thẻ
			time()
		);

		// TODO: Implement addPendingPayment method in PaymentManager
		//$this->paymentManager->addPendingPayment($requestId, $payment);
		$this->plugin->debugLogger->log("Added pending payment with ID: $requestId for player " . $player->getName(), "payment");

		// Thông báo
		$player->sendMessage(MessageTranslator::formatInfoMessage("§l§f❖ §6Thông Tin Giao Dịch §f❖"));
		$player->sendMessage("§l§f⟩§6 Mã giao dịch: §f" . substr($requestId, 0, 10));
		$player->sendMessage("§l§f⟩§6 Thời gian: §f" . date("H:i:s d/m/Y", time()));
		$player->sendMessage("§l§f⟩§6 Loại thẻ: §f" . $telco);
		$player->sendMessage("§l§f⟩§6 Mã thẻ: §f" . substr($code, 0, 4) . "*********");
		$player->sendMessage("§l§f⟩§6 Serial: §f" . substr($serial, 0, 4) . "*********");
		$player->sendMessage(MessageTranslator::formatInfoMessage("Giao dịch đang được xử lý, vui lòng đợi..."));

		// Xử lý gửi đến API
		// TODO: Implement handleCardPayment method in PaymentManager
		//$success = $this->paymentManager->handleCardPayment($payment);
		$response = $this->plugin->getPaymentManager()->processCardPayment(
			$player,
			$telco,
			$code,
			$serial,
			0, // Amount will be determined by the card
			$requestId
		);
		$success = $response !== null && ($response->isSuccessful() || $response->isPending());

		if (!$success) {
			$this->plugin->debugLogger->log("Failed to process payment for player " . $player->getName(), "payment");
			$player->sendMessage(MessageTranslator::formatErrorMessage("Có lỗi xảy ra khi xử lý thẻ. Vui lòng liên hệ Admin!"));
		} else {
			$this->plugin->debugLogger->log("Payment processing initiated for player " . $player->getName(), "payment");
		}
	}

	public static function get(): CustomForm {
		return new CustomForm(
			title: "Biểu Mẫu Nạp Thẻ",
			elements: [
				new Dropdown(
					name: "telco",
					text: "Loại thẻ",
					options: Constant::TELCO_DISPLAY
				),
				new StepSlider(
					name: "amount",
					text: "Mệnh giá",
					options: Constant::AMOUNT_DISPLAY
				),
				new Input(
					name: "serial",
					text: "Số sê-ri",
					hintText: "Nhập số sê-ri tại đây:\nVí dụ: 10004783347874"
				),
				new Input(
					name: "code",
					text: "Mã thẻ",
					hintText: "Nhập mã thẻ tại đây:\nVí dụ: 312821445892982"
				)
			],
			onSubmit: function (Player $submitter, CustomFormResponse $response): void {
				if ($response->getString("serial") === "" || $response->getString("code") === "") {
					$submitter->sendMessage(Constant::PREFIX . "Vui lòng không bỏ trống số sê-ri hoặc mã thẻ!");
					return;
				}
				Server::getInstance()->getAsyncPool()->submitTask(new ChargingTask(
					$submitter->getName(),
					Constant::TELCO[$response->getInt("telco")],
					$response->getString("code"),
					$response->getString("serial"),
					Constant::AMOUNT[$response->getInt("amount")],
					uniqid("donate_", true)
				));
				Donate::getInstance()->logger->info(Constant::PREFIX . "[playerName: " . $submitter->getName() . ", telco: " . Constant::TELCO_DISPLAY[$response->getInt("telco")] . ", code: " . $response->getString("code") . ", serial: " . $response->getString("serial") . ", amount: " . Constant::AMOUNT_DISPLAY[$response->getInt("amount")] . "]");
			},
		);
	}
}
