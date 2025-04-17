<?php

declare(strict_types=1);

namespace Donate\utils;

use Donate\Donate;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use function date;
use function dirname;
use function file_put_contents;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function mkdir;
use function rtrim;
use function str_ends_with;
use function strtoupper;
use function substr;
use const FILE_APPEND;
use const LOCK_EX;
use const PHP_EOL;

/**
 * Utility class for handling debug logging
 */
class DebugLogger {
	private static ?self $instance = null;

	/** @var bool Whether debug logging is enabled */
	private bool $enabled = false;

	/** @var array<string, bool> Debug categories that are enabled */
	private array $enabledCategories = [];

	/** @var bool Whether to send debug messages to online players with permission */
	private bool $notifyAdmins = false;

	/**
	 * Initialize the debug logger
	 */
	public function __construct(private Donate $plugin) {
		self::$instance = $this;
		$this->loadConfig();
	}

	/**
	 * Get the instance of the debug logger
	 */
	public static function getInstance() : ?self {
		return self::$instance;
	}

	/**
	 * Load debug configuration from plugin config
	 */
	public function loadConfig() : void {
		$config = $this->plugin->getConfig();
		$this->enabled = (bool) $config->getNested("debug.enabled", false);
		$this->notifyAdmins = (bool) $config->getNested("debug.notify_admins", false);

		// Load enabled categories
		$categories = $config->getNested("debug.categories", []);
		if (is_array($categories)) {
			foreach ($categories as $category => $enabled) {
				$this->enabledCategories[(string) $category] = (bool) $enabled;
			}
		}
	}

	/**
	 * Check if debugging is enabled for a specific category
	 */
	public function isEnabled(string $category = "general") : bool {
		if (!$this->enabled) {
			return false;
		}

		// If no specific categories are enabled, allow all
		if (empty($this->enabledCategories)) {
			return true;
		}

		return $this->enabledCategories[$category] ?? false;
	}

	/**
	 * Log a debug message
	 */
	public function log(string $message, string $category = "general", bool $logToConsole = true, bool $notifyAdmins = true) : void {
		if (!$this->isEnabled($category)) {
			return;
		}

		$prefix = TextFormat::GOLD . "[Debug]" . TextFormat::RESET . " ";
		$formattedMessage = $prefix . $message;
		$timestamp = date('[Y-m-d H:i:s]');

		// Thêm prefix Donate để phân biệt với các plugin khác
		$consolePrefix = "[Donate/DEBUG:" . strtoupper($category) . "] ";

		// Log to console - sử dụng info thay vì debug để luôn hiển thị trên console
		if ($logToConsole) {
			// Sử dụng info thay vì debug để hiển thị trên console bất kể cài đặt debug của PocketMine
			$this->plugin->getLogger()->info($consolePrefix . $message);
		}

		// Log to plugin's log file
		if (isset($this->plugin->logger)) {
			$logLine = $timestamp . " [Donate/DEBUG:" . strtoupper($category) . "] " . TextFormat::clean($message);
			// Sử dụng info thay vì debug để đảm bảo ghi vào file log
			$this->plugin->logger->info($logLine);

			// Thử ghi trực tiếp vào file nếu logging thông thường không hoạt động
			$logFile = $this->plugin->getDataFolder() . "log.log";
			$this->writeToLogFile($logFile, $logLine);
		}

		// Notify admin players
		if ($this->notifyAdmins && $notifyAdmins) {
			$this->notifyAdmins($formattedMessage);
		}
	}

	/**
	 * Ghi trực tiếp vào file log để đảm bảo nội dung được lưu
	 */
	private function writeToLogFile(string $logFile, string $message) : void {
		// Thêm dòng mới vào cuối nếu chưa có
		if (!str_ends_with($message, PHP_EOL)) {
			$message .= PHP_EOL;
		}

		// Thử ghi vào file, sử dụng FILE_APPEND để thêm vào cuối file
		$result = @file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);

		// Nếu ghi thất bại, thử tạo thư mục (phòng hờ)
		if ($result === false) {
			@mkdir(dirname($logFile), 0755, true);
			@file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
		}
	}

	/**
	 * Log a payment debug message
	 * @param array<string, mixed> $details Additional payment details
	 */
	public function logPayment(
		string $action,
		string $player,
		string $requestId,
		array $details = []
	) : void {
		if (!$this->isEnabled("payment")) {
			return;
		}

		$detailsStr = "";
		foreach ($details as $key => $value) {
			$detailsStr .= $key . ": " . (is_scalar($value) ? (string) $value : json_encode($value)) . ", ";
		}
		$detailsStr = rtrim($detailsStr, ", ");

		$message = "Payment $action - Player: $player, RequestID: $requestId" .
			(!empty($detailsStr) ? ", Details: {$detailsStr}" : "");

		$this->log($message, "payment");
	}

	/**
	 * Log an API-related debug message
	 * @param string               $action   The API action performed
	 * @param array<string, mixed> $request  Request parameters
	 * @param array<string, mixed> $response Response data
	 */
	public function logApi(
		string $action,
		array $request = [],
		array $response = []
	) : void {
		if (!$this->isEnabled("api")) {
			return;
		}

		$message = "API $action";

		// Clean sensitive data
		if (isset($request["sign"])) {
			$request["sign"] = is_string($request["sign"])
				? substr($request["sign"], 0, 8) . "..."
				: "***";
		}

		if (isset($request["partner_key"])) {
			$request["partner_key"] = "********";
		}

		if (isset($request["code"])) {
			$request["code"] = is_string($request["code"])
				? substr($request["code"], 0, 4) . "******"
				: "******";
		}

		if (!empty($request)) {
			$message .= " - Request: " . json_encode($request);
		}

		if (!empty($response)) {
			$message .= " - Response: " . json_encode($response);
		}

		$this->log($message, "api");
	}

	/**
	 * Notify online players with admin permission
	 */
	private function notifyAdmins(string $message) : void {
		foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
			if ($player->hasPermission("donate.admin")) {
				$player->sendMessage($message);
			}
		}
	}

	/**
	 * Send a debug message to a specific player
	 */
	public function sendToPlayer(Player $player, string $message, string $category = "general") : void {
		if (!$this->isEnabled($category)) {
			return;
		}

		$prefix = TextFormat::GOLD . "[Debug]" . TextFormat::RESET . " ";
		$player->sendMessage($prefix . $message);
	}
}
