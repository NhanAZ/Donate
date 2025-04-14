<?php

declare(strict_types=1);

namespace Donate\tasks;

use Donate\Donate;
use pocketmine\scheduler\Task;

/**
 * Task to check payment statuses periodically
 */
class CheckTask extends Task {
	/**
	 * @param Donate $plugin The main plugin instance
	 */
	private Donate $plugin;

	public function __construct(Donate $plugin) {
		$this->plugin = $plugin;
	}
	public function onRun(): void {
		$this->plugin->getLogger()->debug("Running payment status check task...");
	}

	public function onCompletion(): void {
	}
}
