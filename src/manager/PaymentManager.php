<?php

declare(strict_types=1);

namespace Donate\manager;

use Donate\api\ChargeResponse;
use Donate\api\ChargeStatusResponse;
use Donate\api\TrumTheAPI;
use Donate\Constant;
use Donate\Donate;
use Donate\payment\CardPayment;
use Donate\payment\PaymentStatus;
use Donate\tasks\PaymentCheckTask;
use pocketmine\player\Player;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\utils\InternetException;
use Ramsey\Uuid\Uuid;

class PaymentManager {
	/** @var array<string, CardPayment> */
	private array $pendingPayments = [];

	/** @var Donate */
	private Donate $plugin;

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * Start the task to periodically check payment statuses
	 */
	public function startPaymentCheckTask(): void {
		$this->plugin->getScheduler()->scheduleRepeatingTask(
			new PaymentCheckTask($this),
			20 * 30  // Check every 30 seconds
		);
	}

	/**
	 * Process a card payment
	 */
	public function processCardPayment(
		Player $player,
		string $telco,
		string $code,
		string $serial,
		int $amount,
		string $requestId
	): ChargeResponse {
		try {
			// Debug logging - payment attempt
			$this->plugin->debugLogger->logPayment(
				"ATTEMPTED",
				$player->getName(),
				$requestId,
				[
					"telco" => $telco,
					"amount" => $amount,
					"serial" => substr($serial, 0, 4) . "****" . substr($serial, -4),
					"code" => substr($code, 0, 2) . "****" . substr($code, -2)
				]
			);
			
			// Send debug message to player if they have admin permission
			if ($player->hasPermission("donate.admin")) {
				$this->plugin->debugLogger->sendToPlayer(
					$player,
					"Đang xử lý thẻ {$telco} mệnh giá {$amount}đ",
					"payment"
				);
			}
			
			// Call the API to charge the card
			$response = TrumTheAPI::chargeCard(
				$telco,
				$code,
				$serial,
				$amount,
				$requestId
			);

			if ($response === null) {
				// Debug logging - API connection error
				$this->plugin->debugLogger->logPayment(
					"ERROR", 
					$player->getName(), 
					$requestId, 
					["reason" => "API Connection Failed"]
				);
				
				// Create a failed response if the API call failed
				return new ChargeResponse(
					-1,
					"connection.failed"
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

				// Debug logging - payment pending
				$this->plugin->debugLogger->logPayment(
					"PENDING", 
					$player->getName(), 
					$requestId, 
					[
						"status" => $response->getStatus(),
						"message" => $response->getMessage()
					]
				);
				
				// Log the payment attempt
				$this->plugin->getLogger()->info(
					"Payment initiated: [Player: {$player->getName()}, RequestID: {$requestId}, Telco: {$telco}, Amount: {$amount}]"
				);
			} else {
				if (isset($this->plugin->debugLogger)) {
					$this->plugin->debugLogger->log(
						"Payment rejected for {$player->getName()}: Raw message: {$response->getMessage()}",
						"payment"
					);
				}
				
				// Debug logging - payment rejected immediately
				$this->plugin->debugLogger->logPayment(
					"REJECTED", 
					$player->getName(), 
					$requestId, 
					[
						"status" => $response->getStatus(),
						"message" => $response->getMessage()
					]
				);
			}

			return $response;
		} catch (InternetException $e) {
			$this->plugin->getLogger()->error("Error processing payment: " . $e->getMessage());
			
			// Debug logging - exception during payment
			$this->plugin->debugLogger->logPayment(
				"EXCEPTION", 
				$player->getName(), 
				$requestId, 
				["error" => $e->getMessage()]
			);

			// Return a default error response
			return new ChargeResponse(
				-1,
				"connection.timeout"
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
		
		// Debug log number of pending payments
		if ($this->hasPendingPayments()) {
			$this->plugin->debugLogger->log(
				"Checking " . count($this->pendingPayments) . " pending payments",
				"payment"
			);
		}

		foreach ($this->pendingPayments as $requestId => $payment) {
			// Skip payments that were created less than 30 seconds ago
			if (time() - $payment->getCreatedAt() < 30) {
				continue;
			}

			try {
				// Debug log - checking payment status
				$this->plugin->debugLogger->logPayment(
					"CHECKING", 
					$payment->getPlayerName(), 
					$requestId,
					["age" => (time() - $payment->getCreatedAt()) . "s"]
				);
				
				// Check the status of the payment
				$response = TrumTheAPI::checkCardStatus($requestId);

				if ($response === null) {
					// Debug log - API error during check
					$this->plugin->debugLogger->logPayment(
						"CHECK_ERROR", 
						$payment->getPlayerName(), 
						$requestId,
						["reason" => "API Connection Failed"]
					);
					// Skip if the API call failed
					continue;
				}

				assert($response instanceof ChargeStatusResponse);

				// If the card is still being processed, skip
				if ($response->isPending()) {
					// Debug log - still pending
					$this->plugin->debugLogger->logPayment(
						"STILL_PENDING", 
						$payment->getPlayerName(), 
						$requestId,
						["message" => $response->getMessage()]
					);
					continue;
				}

				// Remove from pending payments
				unset($this->pendingPayments[$requestId]);

				// If the payment was successful
				if ($response->isSuccessful()) {
					$amount = $response->getAmount();

					if ($amount === null) {
						$amount = $payment->getAmount();
					}

					// Debug log - successful payment
					$this->plugin->debugLogger->logPayment(
						"SUCCESSFUL", 
						$payment->getPlayerName(), 
						$requestId,
						[
							"amount" => $amount,
							"declared" => $response->getDeclared(),
							"received" => $response->getReceived(),
							"status" => $response->getStatus()
						]
					);
					
					// Process successful payment
					$this->plugin->successfulDonation(
						$payment->getPlayerName(),
						$amount
					);

					// Log successful payment
					$this->plugin->getLogger()->info(
						"Payment successful: [Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Amount: {$amount}]"
					);

					$payment->setStatus(PaymentStatus::SUCCESSFUL);
					$payment->setProcessedAmount($amount);
				} else {
					// Debug log - failed payment
					$this->plugin->debugLogger->logPayment(
						"FAILED", 
						$payment->getPlayerName(), 
						$requestId,
						[
							"status" => $response->getStatus(),
							"message" => $response->getMessage()
						]
					);
					
					// Log failed payment
					$this->plugin->getLogger()->info(
						"Payment failed: [Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Reason: {$response->getMessage()}]"
					);

					$payment->setStatus(PaymentStatus::FAILED);
					$payment->setFailReason($response->getMessage());

					// Notify player if online
					$player = $this->plugin->getServer()->getPlayerExact($payment->getPlayerName());
					if ($player !== null) {
						$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage($response->getMessage()));
					}
				}

				$processedPayments[$requestId] = $payment;
			} catch (InternetException $e) {
				// Debug log - exception during check
				$this->plugin->debugLogger->logPayment(
					"CHECK_EXCEPTION", 
					$payment->getPlayerName(), 
					$requestId,
					["error" => $e->getMessage()]
				);
				
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
