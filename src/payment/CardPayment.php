<?php

declare(strict_types=1);

namespace Donate\payment;

use JsonSerializable;

/**
 * Represents a card payment
 */
class CardPayment implements JsonSerializable {
	/** @var string The payment status */
	private string $status = PaymentStatus::PENDING;

	/** @var string|null The reason for failure (if applicable) */
	private ?string $failReason = null;

	/** @var int|null The processed amount (if successful) */
	private ?int $processedAmount = null;

	/**
	 * @param string $requestId  Unique request ID for the payment
	 * @param string $playerName Name of the player who initiated the payment
	 * @param string $telco      Telco/provider code
	 * @param string $code       Card code
	 * @param string $serial     Card serial number
	 * @param int    $amount     Card amount value
	 * @param int    $createdAt  Timestamp when the payment was created
	 */
	public function __construct(
		private string $requestId,
		private string $playerName,
		private string $telco,
		private string $code,
		private string $serial,
		private int $amount,
		private int $createdAt
	) {
	}

	/**
	 * Get the request ID
	 */
	public function getRequestId() : string {
		return $this->requestId;
	}

	/**
	 * Get the player name
	 */
	public function getPlayerName() : string {
		return $this->playerName;
	}

	/**
	 * Get the telco/provider code
	 */
	public function getTelco() : string {
		return $this->telco;
	}

	/**
	 * Get the card code
	 */
	public function getCode() : string {
		return $this->code;
	}

	/**
	 * Get the card serial number
	 */
	public function getSerial() : string {
		return $this->serial;
	}

	/**
	 * Get the card amount value
	 */
	public function getAmount() : int {
		return $this->amount;
	}

	/**
	 * Get the timestamp when the payment was created
	 */
	public function getCreatedAt() : int {
		return $this->createdAt;
	}

	/**
	 * Get the payment status
	 */
	public function getStatus() : string {
		return $this->status;
	}

	/**
	 * Set the payment status
	 */
	public function setStatus(string $status) : void {
		$this->status = $status;
	}

	/**
	 * Get the reason for failure
	 */
	public function getFailReason() : ?string {
		return $this->failReason;
	}

	/**
	 * Set the reason for failure
	 */
	public function setFailReason(string $reason) : void {
		$this->failReason = $reason;
	}

	/**
	 * Get the processed amount
	 */
	public function getProcessedAmount() : ?int {
		return $this->processedAmount;
	}

	/**
	 * Set the processed amount
	 */
	public function setProcessedAmount(int $amount) : void {
		$this->processedAmount = $amount;
	}

	/**
	 * Check if the payment is pending
	 */
	public function isPending() : bool {
		return $this->status === PaymentStatus::PENDING;
	}

	/**
	 * Check if the payment was successful
	 */
	public function isSuccessful() : bool {
		return $this->status === PaymentStatus::SUCCESSFUL;
	}

	/**
	 * Check if the payment failed
	 */
	public function isFailed() : bool {
		return $this->status === PaymentStatus::FAILED;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array {
		return [
			'request_id' => $this->requestId,
			'player_name' => $this->playerName,
			'telco' => $this->telco,
			'amount' => $this->amount,
			'created_at' => $this->createdAt,
			'status' => $this->status,
			'fail_reason' => $this->failReason,
			'processed_amount' => $this->processedAmount
		];
	}
}
