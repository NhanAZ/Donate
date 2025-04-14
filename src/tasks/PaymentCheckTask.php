<?php

declare(strict_types=1);

namespace Donate\tasks;

use Donate\manager\PaymentManager;
use pocketmine\scheduler\Task;

/**
 * Task to periodically check payment statuses
 */
class PaymentCheckTask extends Task {
	/** @var PaymentManager */
	private PaymentManager $paymentManager;

	/**
	 * @param PaymentManager $paymentManager The payment manager
	 */
	public function __construct(PaymentManager $paymentManager) {
		$this->paymentManager = $paymentManager;
	}

	/**
	 * Check payment statuses on each tick
	 */
	public function onRun(): void {
		// Only check if there are pending payments
		if (!$this->paymentManager->hasPendingPayments()) {
			return;
		}

		// Process all pending payments
		$this->paymentManager->checkPendingPayments();
	}
}
