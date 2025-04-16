<?php

declare(strict_types=1);

namespace Donate\payment;

use Donate\api\ChargeResponse;
use Donate\api\ChargeStatusResponse;
use Donate\api\TrumTheAPI;
use Donate\Constant;
use Donate\Donate;
use Donate\StatusCode;
use pocketmine\player\Player;
use pocketmine\utils\InternetException;
use Ramsey\Uuid\Uuid;

/**
 * Handles card payment processing logic
 */
final class PaymentProcessor {
	/** @var array<string, CardPayment> */
	private array $pendingPayments = [];

	/** @var Donate */
	private Donate $plugin;

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * Process a card payment
	 * 
	 * @param Player $player The player making the payment
	 * @param string $telco The telco/provider code
	 * @param string $code The card code
	 * @param string $serial The card serial number
	 * @param int $amount The card amount
	 * 
	 * @return ChargeResponse The initial response from the charge API
	 */
	public function processCardPayment(
		Player $player,
		string $telco,
		string $code,
		string $serial,
		int $amount
	): ChargeResponse {
		// Generate a unique request ID
		$requestId = Uuid::uuid4()->toString();

		try {
			// Call the API to charge the card
			$response = TrumTheAPI::chargeCard(
				$telco,
				$code,
				$serial,
				$amount,
				$requestId
			);

			if ($response === null) {
				// Create a failed response if the API call failed
				return new ChargeResponse(
					-1,
					"Không thể kết nối đến dịch vụ nạp thẻ. Vui lòng thử lại sau."
				);
			}

			// If the response indicates the card is being processed
			if ($response->isSuccessful() || $response->isPending()) {
				// Store the payment for later processing
				$payment = new CardPayment(
					$requestId,
					$player->getName(),
					$telco,
					$code,
					$serial,
					$amount,
					time()
				);

				$this->pendingPayments[$requestId] = $payment;

				// Log the payment attempt
				$this->plugin->getLogger()->info(
					"Payment initiated: [Player: {$player->getName()}, RequestID: {$requestId}, Telco: {$telco}, Amount: {$amount}]"
				);
			}

			return $response;
		} catch (InternetException $e) {
			$this->plugin->getLogger()->error("Error processing payment: " . $e->getMessage());

			// Return a default error response
			return new ChargeResponse(
				-1,
				"Lỗi kết nối: " . $e->getMessage()
			);
		}
	}

	/**
	 * Check the status of pending payments
	 * 
	 * @return array<string, CardPayment> List of processed payments
	 */
	public function checkPendingPayments(): array {
		$processedPayments = [];

		foreach ($this->pendingPayments as $requestId => $payment) {
			// Skip payments that were created less than 30 seconds ago
			if (time() - $payment->getCreatedAt() < 30) {
				continue;
			}

			try {
				// Chuẩn bị thông tin thẻ gốc
				$cardInfo = [
					'telco' => $payment->getTelco(),
					'code' => $payment->getCode(),
					'serial' => $payment->getSerial(),
					'amount' => $payment->getAmount()
				];

				// Kiểm tra trạng thái thanh toán, truyền thêm thông tin thẻ gốc
				$response = TrumTheAPI::checkCardStatus($requestId, $cardInfo);

				if ($response === null) {
					// Skip if the API call failed
					continue;
				}

				assert($response instanceof ChargeStatusResponse);

				// If the card is still being processed, skip
				if ($response->isPending()) {
					continue;
				}

				// Remove from pending payments
				unset($this->pendingPayments[$requestId]);

				// If the payment was successful
				if ($response->isSuccessful()) {
					$amount = $response->getAmount();

					if ($amount === null) {
						$amount = (float)$payment->getAmount();
					}

					// Process successful payment
					$this->plugin->successfulDonation(
						$payment->getPlayerName(),
						(int)$amount
					);

					// Log successful payment
					$this->plugin->getLogger()->info(
						"Payment successful: [Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Amount: {$amount}]"
					);

					$payment->setStatus(PaymentStatus::SUCCESSFUL);
					$payment->setProcessedAmount((int)$amount);
				} else {
					// Log failed payment
					$this->plugin->getLogger()->info(
						"Payment failed: [Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Reason: {$response->getMessage()}]"
					);

					$payment->setStatus(PaymentStatus::FAILED);
					$payment->setFailReason($response->getMessage());

					// Notify player if online
					$player = $this->plugin->getServer()->getPlayerExact($payment->getPlayerName());
					if ($player !== null) {
						$player->sendMessage(Constant::PREFIX . "Thẻ không hợp lệ: " . $response->getMessage());
					}
				}

				$processedPayments[$requestId] = $payment;
			} catch (InternetException $e) {
				$this->plugin->getLogger()->error(
					"Error checking payment status: " . $e->getMessage()
				);
			}
		}

		return $processedPayments;
	}

	/**
	 * Get a pending payment by request ID
	 */
	public function getPayment(string $requestId): ?CardPayment {
		return $this->pendingPayments[$requestId] ?? null;
	}

	/**
	 * Get all pending payments
	 * 
	 * @return array<string, CardPayment>
	 */
	public function getPendingPayments(): array {
		return $this->pendingPayments;
	}

	/**
	 * Check if there are any pending payments
	 */
	public function hasPendingPayments(): bool {
		return !empty($this->pendingPayments);
	}
}
