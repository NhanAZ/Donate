<?php

declare(strict_types=1);

namespace Donate\api;

use Donate\Constant;
use Donate\StatusCode;
use Donate\utils\DataTypeUtils;
use JsonSerializable;

/**
 * Response from the charge card API
 */
class ChargeResponse implements JsonSerializable {
	/** @var int */
	private int $status;

	/** @var string */
	private string $message;

	/** @var int|null */
	private ?int $amount = null;

	/** @var string|null */
	private ?string $transactionId = null;

	/** @var float|null */
	private ?float $declared = null;

	/** @var float|null */
	private ?float $received = null;

	/**
	 * @param int $status Status code
	 * @param string $message Response message
	 */
	public function __construct(int $status, string $message) {
		$this->status = $status;
		$this->message = $message;
	}

	/**
	 * Create from API response array
	 * 
	 * @param array<string, mixed> $data API response data
	 * @return self
	 */
	public static function fromArray(array $data): self {
		// Extract status with safe default
		$status = DataTypeUtils::toInt(DataTypeUtils::getArrayValue($data, 'status', -1), -1);

		// Extract message with safe default
		$message = DataTypeUtils::toString(DataTypeUtils::getArrayValue($data, 'message', 'Unknown error'), 'Unknown error');

		$response = new self($status, $message);

		// Process amount if available
		if (isset($data['amount'])) {
			$response->amount = DataTypeUtils::toInt($data['amount']);
		}

		// Process transaction ID if available
		if (isset($data['trans_id'])) {
			$response->transactionId = DataTypeUtils::toString($data['trans_id']);
		}

		// Process declared value if available
		if (isset($data['declared_value'])) {
			$response->declared = DataTypeUtils::toFloat($data['declared_value']);
		}

		// Process received value if available
		if (isset($data['received_value'])) {
			$response->received = DataTypeUtils::toFloat($data['received_value']);
		}

		return $response;
	}

	/**
	 * Check if the initial charge was successful
	 */
	public function isSuccessful(): bool {
		return $this->status === StatusCode::SUCCESS;
	}

	/**
	 * Check if the card is pending processing
	 */
	public function isPending(): bool {
		// Check if status code is explicitly PENDING OR 
		// if message contains "PENDING" (in case the API returns status 99 with PENDING message)
		return $this->status === StatusCode::PENDING || 
			   (strtoupper($this->message) === "PENDING" && $this->status === StatusCode::FAILED_WITH_REASON);
	}

	/**
	 * Get the status code
	 */
	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * Get the response message
	 */
	public function getMessage(): string {
		return $this->message;
	}

	/**
	 * Get the amount (if available)
	 */
	public function getAmount(): ?int {
		return $this->amount;
	}

	/**
	 * Get the transaction ID (if available)
	 */
	public function getTransactionId(): ?string {
		return $this->transactionId;
	}

	/**
	 * Get the declared value (if available)
	 */
	public function getDeclared(): ?float {
		return $this->declared;
	}

	/**
	 * Get the received value (if available)
	 */
	public function getReceived(): ?float {
		return $this->received;
	}

	/**
	 * Checks if the API request itself was successful
	 */
	public function isValidRequest(): bool {
		return $this->status !== -1 && $this->message !== 'Unknown error';
	}

	/**
	 * Returns user-friendly message for the response
	 */
	public function getFriendlyMessage(): string {
		// Debug log để xem thông báo gốc
		if (class_exists("\Donate\Donate") && $this->status !== StatusCode::SUCCESS && $this->status !== StatusCode::PENDING) {
			$plugin = \Donate\Donate::getInstance();
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->log(
					"ChargeResponse.getFriendlyMessage raw message: '" . $this->message . "', status: " . $this->status,
					"api"
				);
			}
		}

		if (!$this->isValidRequest()) {
			return \Donate\utils\MessageTranslator::formatErrorMessage("connection.failed");
		}

		// Xử lý đặc biệt cho card_existed
		if (strpos($this->message, "card_existed") !== false) {
			return \Donate\utils\MessageTranslator::formatErrorMessage("Thẻ này đã được sử dụng trước đó");
		}

		$message = match ($this->status) {
			StatusCode::SUCCESS => "charging.pending",
			StatusCode::PENDING => "charging.pending",
			StatusCode::SYSTEM_MAINTENANCE => "system.maintenance",
			StatusCode::FAILED_WITH_REASON => $this->message,
			default => $this->message
		};

		return \Donate\utils\MessageTranslator::formatErrorMessage($message);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'status' => $this->status,
			'message' => $this->message,
			'amount' => $this->amount,
			'trans_id' => $this->transactionId,
			'declared_value' => $this->declared,
			'received_value' => $this->received
		];
	}
}
