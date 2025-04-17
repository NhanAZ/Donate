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
			// Log payment attempt
			$this->plugin->logger->info("[Donate/Payment] Processing card payment - Player: {$player->getName()}, RequestID: {$requestId}, Telco: {$telco}, Amount: {$amount}");

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

				// Log payment pending
				$this->plugin->logger->info("[Donate/Payment] Card payment pending - Player: {$player->getName()}, RequestID: {$requestId}, Status: {$response->getStatus()}");

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
				
				// Nếu đây là trạng thái PENDING, lên lịch kiểm tra ngay lập tức
				if ($response->isPending()) {
					$this->plugin->debugLogger->log(
						"Payment is PENDING, scheduling immediate checks for RequestID: $requestId",
						"payment"
					);
					$this->scheduleImmediateCheck($requestId, 5, 12); // Kiểm tra mỗi 5 giây, tối đa 12 lần (1 phút)
				}
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

		// Log the pending payment check
		$pendingCount = count($this->pendingPayments);
		if ($pendingCount > 0) {
			$this->plugin->logger->info("[Donate/Payment] Checking " . $pendingCount . " pending payments");
		}

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

				// If the card is still being processed, skip
				if ($response->isPending()) {
					// Debug log - still pending
					$this->plugin->debugLogger->logPayment(
						"STILL_PENDING",
						$payment->getPlayerName(),
						$requestId,
						["message" => $response->getMessage()]
					);
					
					// Nếu giao dịch đã ở trạng thái PENDING quá lâu, lên lịch kiểm tra ngay lập tức
					$pendingTime = time() - $payment->getCreatedAt();
					if ($pendingTime > 60) { // Nếu đã PENDING lâu hơn 60 giây
						$this->plugin->debugLogger->log(
							"Payment still PENDING after {$pendingTime}s, scheduling immediate checks for RequestID: $requestId",
							"payment"
						);
						$this->scheduleImmediateCheck($requestId, 5, 12); // Kiểm tra mỗi 5 giây, tối đa 12 lần (1 phút)
					}
					
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

					// Log successful payment
					$this->plugin->logger->info("[Donate/Payment] Payment successful - Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Amount: {$amount}");

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
					// Log failed payment
					$this->plugin->logger->info("[Donate/Payment] Payment failed - Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Reason: {$response->getMessage()}");

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

	/**
	 * Thêm thanh toán mẫu để test
	 */
	public function addSamplePayment(string $requestId, \Donate\payment\CardPayment $payment): void {
		$this->pendingPayments[$requestId] = $payment;

		// Log the sample payment addition
		$this->plugin->logger->info("[Donate/Sample] Added sample payment - RequestID: {$requestId}, Player: {$payment->getPlayerName()}, Amount: {$payment->getAmount()}");
	}

	/**
	 * Kiểm tra ngay lập tức một giao dịch đang ở trạng thái PENDING
	 * 
	 * @param string $requestId ID của giao dịch cần kiểm tra
	 * @param int $retryDelay Thời gian chờ giữa các lần kiểm tra (giây)
	 * @param int $maxRetries Số lần kiểm tra tối đa
	 */
	public function scheduleImmediateCheck(string $requestId, int $retryDelay = 5, int $maxRetries = 12): void {
		$payment = $this->getPayment($requestId);
		
		if ($payment === null) {
			$this->plugin->logger->error("[Donate/Payment] Cannot schedule check - Payment not found: $requestId");
			return;
		}
		
		$this->plugin->logger->info("[Donate/Payment] Scheduling immediate check for payment - RequestID: $requestId");
		
		// Sử dụng ClosureTask để kiểm tra giao dịch này sau một khoảng thời gian ngắn
		$this->plugin->getScheduler()->scheduleDelayedTask(
			new \pocketmine\scheduler\ClosureTask(
				function() use ($requestId, $retryDelay, $maxRetries): void {
					// Lấy thông tin thanh toán
					$payment = $this->getPayment($requestId);
					if ($payment === null) {
						// Thanh toán không còn tồn tại (có thể đã được xử lý)
						return;
					}
					
					try {
						// Debug log - checking payment status
						$this->plugin->debugLogger->logPayment(
							"IMMEDIATE_CHECK",
							$payment->getPlayerName(),
							$requestId,
							["age" => (time() - $payment->getCreatedAt()) . "s"]
						);
						
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
							// Không thể kết nối API, thử lại sau nếu chưa đạt số lần tối đa
							if ($maxRetries > 0) {
								$this->scheduleImmediateCheck($requestId, $retryDelay, $maxRetries - 1);
							}
							return;
						}
						
						// Nếu vẫn đang xử lý, tiếp tục kiểm tra sau một khoảng thời gian
						if ($response->isPending()) {
							$this->plugin->debugLogger->logPayment(
								"STILL_PENDING_IMMEDIATE",
								$payment->getPlayerName(),
								$requestId,
								["message" => $response->getMessage(), "retries_left" => $maxRetries]
							);
							
							// Tiếp tục kiểm tra nếu chưa đạt số lần tối đa
							if ($maxRetries > 0) {
								$this->scheduleImmediateCheck($requestId, $retryDelay, $maxRetries - 1);
							}
							return;
						}
						
						// Xử lý kết quả cuối cùng (thành công hoặc thất bại)
						// Xóa khỏi danh sách thanh toán đang chờ
						unset($this->pendingPayments[$requestId]);
						
						// Nếu thanh toán thành công
						if ($response->isSuccessful()) {
							$amount = $response->getAmount();
							
							if ($amount === null) {
								$amount = $payment->getAmount();
							}
							
							// Log thanh toán thành công
							$this->plugin->logger->info("[Donate/Payment] Payment successful - Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Amount: {$amount}");
							
							// Debug log - thanh toán thành công
							$this->plugin->debugLogger->logPayment(
								"SUCCESSFUL_IMMEDIATE",
								$payment->getPlayerName(),
								$requestId,
								[
									"amount" => $amount,
									"declared" => $response->getDeclared(),
									"received" => $response->getReceived(),
									"status" => $response->getStatus()
								]
							);
							
							// Xử lý thanh toán thành công
							$this->plugin->successfulDonation(
								$payment->getPlayerName(),
								$amount
							);
							
							// Cập nhật trạng thái thanh toán
							$payment->setStatus(\Donate\payment\PaymentStatus::SUCCESSFUL);
							$payment->setProcessedAmount($amount);
						} else {
							// Log thanh toán thất bại
							$this->plugin->logger->info("[Donate/Payment] Payment failed - Player: {$payment->getPlayerName()}, RequestID: {$requestId}, Reason: {$response->getMessage()}");
							
							// Debug log - thanh toán thất bại
							$this->plugin->debugLogger->logPayment(
								"FAILED_IMMEDIATE",
								$payment->getPlayerName(),
								$requestId,
								[
									"status" => $response->getStatus(),
									"message" => $response->getMessage()
								]
							);
							
							// Cập nhật trạng thái thanh toán
							$payment->setStatus(\Donate\payment\PaymentStatus::FAILED);
							$payment->setFailReason($response->getMessage());
							
							// Thông báo cho người chơi nếu đang online
							$player = $this->plugin->getServer()->getPlayerExact($payment->getPlayerName());
							if ($player !== null) {
								$player->sendMessage(\Donate\utils\MessageTranslator::formatErrorMessage($response->getMessage()));
							}
						}
					} catch (\Throwable $e) {
						// Debug log - lỗi khi kiểm tra
						$this->plugin->debugLogger->logPayment(
							"CHECK_EXCEPTION_IMMEDIATE",
							$payment->getPlayerName(),
							$requestId,
							["error" => $e->getMessage()]
						);
						
						$this->plugin->getLogger()->error(
							"Error checking payment status immediately: " . $e->getMessage()
						);
					}
				}
			),
			$retryDelay * 20 // Chuyển đổi giây thành ticks
		);
	}
}