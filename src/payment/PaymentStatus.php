<?php

declare(strict_types=1);

namespace Donate\payment;

/**
 * Payment status constants
 */
final class PaymentStatus {
	/** Payment is pending processing */
	public const PENDING = "pending";

	/** Payment completed successfully */
	public const SUCCESSFUL = "successful";

	/** Payment failed */
	public const FAILED = "failed";
}
