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
		return $this->status === StatusCode::PENDING;
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
		$prefix = Constant::PREFIX;

		if (!$this->isValidRequest()) {
			return "{$prefix}Lỗi kết nối đến hệ thống thanh toán. Vui lòng thử lại sau.";
		}

		return match ($this->status) {
			StatusCode::SUCCESS => "{$prefix}Thẻ đang được xử lý. Vui lòng đợi.",
			StatusCode::PENDING => "{$prefix}Thẻ đang được xử lý. Vui lòng đợi.",
			StatusCode::SYSTEM_MAINTENANCE => "{$prefix}Hệ thống đang bảo trì. Vui lòng thử lại sau.",
			StatusCode::FAILED_WITH_REASON => "{$prefix}Lỗi: {$this->message}",
			default => "{$prefix}Có lỗi xảy ra: {$this->message}"
		};
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
