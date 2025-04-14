<?php

declare(strict_types=1);

namespace Donate\api;

use Donate\StatusCode;
use Donate\utils\DataTypeUtils;
use Donate\Constant;
use JsonSerializable;

/**
 * Response from the check card status API
 */
class ChargeStatusResponse implements JsonSerializable {
	/** @var int */
	private int $status;

	/** @var string */
	private string $message;

	/** @var int|null */
	private ?int $amount = null;

	/** @var float|null */
	private ?float $declared = null;

	/** @var float|null */
	private ?float $received = null;

	/** @var string|null */
	private ?string $cardCode = null;

	/** @var string|null */
	private ?string $cardSerial = null;

	/** @var string|null */
	private ?string $cardType = null;

	/** @var string|null */
	private ?string $cardValue = null;

	/** @var string|null */
	private ?string $transactionId = null;

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

		// Process additional fields if available
		if (isset($data['amount'])) {
			$response->amount = DataTypeUtils::toInt($data['amount']);
		}

		if (isset($data['declared_value'])) {
			$response->declared = DataTypeUtils::toFloat($data['declared_value']);
		}

		if (isset($data['received_value'])) {
			$response->received = DataTypeUtils::toFloat($data['received_value']);
		}

		if (isset($data['card_code'])) {
			$response->cardCode = DataTypeUtils::toString($data['card_code']);
		}

		if (isset($data['card_serial'])) {
			$response->cardSerial = DataTypeUtils::toString($data['card_serial']);
		}

		if (isset($data['card_type'])) {
			$response->cardType = DataTypeUtils::toString($data['card_type']);
		}

		if (isset($data['card_value'])) {
			$response->cardValue = DataTypeUtils::toString($data['card_value']);
		}

		if (isset($data['trans_id'])) {
			$response->transactionId = DataTypeUtils::toString($data['trans_id']);
		}

		return $response;
	}

	/**
	 * Check if the card charge was successful
	 */
	public function isSuccessful(): bool {
		return $this->status === StatusCode::SUCCESS;
	}

	/**
	 * Check if the card is still pending
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
	 * Get the card code (if available)
	 */
	public function getCardCode(): ?string {
		return $this->cardCode;
	}

	/**
	 * Get the card serial (if available)
	 */
	public function getCardSerial(): ?string {
		return $this->cardSerial;
	}

	/**
	 * Get the card type (if available)
	 */
	public function getCardType(): ?string {
		return $this->cardType;
	}

	/**
	 * Get the card value (if available)
	 */
	public function getCardValue(): ?string {
		return $this->cardValue;
	}

	/**
	 * Get the transaction ID (if available)
	 */
	public function getTransactionId(): ?string {
		return $this->transactionId;
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
			StatusCode::SUCCESS => "{$prefix}Thẻ nạp thành công!",
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
			'declared_value' => $this->declared,
			'received_value' => $this->received,
			'card_code' => $this->cardCode,
			'card_serial' => $this->cardSerial,
			'card_type' => $this->cardType,
			'card_value' => $this->cardValue,
			'trans_id' => $this->transactionId
		];
	}
}
