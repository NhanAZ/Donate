<?php

declare(strict_types=1);

namespace Donate\utils;

use Donate\Constant;
use Donate\Donate;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

/**
 * Utility class for handling debug logging
 */
class DebugLogger {
    /** @var self|null */
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
    public static function getInstance(): ?self {
        return self::$instance;
    }

    /**
     * Load debug configuration from plugin config
     */
    public function loadConfig(): void {
        $config = $this->plugin->getConfig();
        $this->enabled = (bool) $config->getNested("debug.enabled", false);
        $this->notifyAdmins = (bool) $config->getNested("debug.notify_admins", false);
        
        // Load enabled categories
        $categories = $config->getNested("debug.categories", []);
        if (is_array($categories)) {
            foreach ($categories as $category => $enabled) {
                $this->enabledCategories[$category] = (bool) $enabled;
            }
        }
    }

    /**
     * Check if debugging is enabled for a specific category
     */
    public function isEnabled(string $category = "general"): bool {
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
    public function log(string $message, string $category = "general", bool $logToConsole = true, bool $notifyAdmins = true): void {
        if (!$this->isEnabled($category)) {
            return;
        }

        $prefix = TextFormat::GOLD . "[Debug]" . TextFormat::RESET . " ";
        $formattedMessage = $prefix . $message;

        // Log to console
        if ($logToConsole) {
            $this->plugin->getLogger()->debug($message);
        }

        // Log to plugin's log file
        if (isset($this->plugin->logger)) {
            $logLine = date('[H:i:s]') . " [DEBUG/$category] " . TextFormat::clean($message);
            $this->plugin->logger->debug($logLine);
        }

        // Notify admin players
        if ($this->notifyAdmins && $notifyAdmins) {
            $this->notifyAdmins($formattedMessage);
        }
    }

    /**
     * Log a payment debug message 
     */
    public function logPayment(
        string $action, 
        string $player, 
        string $requestId, 
        array $details = []
    ): void {
        if (!$this->isEnabled("payment")) {
            return;
        }

        $detailsStr = "";
        foreach ($details as $key => $value) {
            $detailsStr .= "$key: $value, ";
        }
        $detailsStr = rtrim($detailsStr, ", ");

        $message = "Payment $action - Player: $player, RequestID: $requestId" . 
                   (!empty($detailsStr) ? ", Details: {$detailsStr}" : "");
        
        $this->log($message, "payment");
    }

    /**
     * Log an API-related debug message
     */
    public function logApi(
        string $action,
        array $request = [],
        array $response = []
    ): void {
        if (!$this->isEnabled("api")) {
            return;
        }

        $message = "API $action";

        // Clean sensitive data
        if (isset($request["sign"])) {
            $request["sign"] = substr($request["sign"], 0, 8) . "...";
        }
        
        if (isset($request["partner_key"])) {
            $request["partner_key"] = "********";
        }

        if (isset($request["code"])) {
            $request["code"] = substr($request["code"], 0, 4) . "******";
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
    private function notifyAdmins(string $message): void {
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            if ($player->hasPermission("donate.admin")) {
                $player->sendMessage($message);
            }
        }
    }

    /**
     * Send a debug message to a specific player
     */
    public function sendToPlayer(Player $player, string $message, string $category = "general"): void {
        if (!$this->isEnabled($category)) {
            return;
        }

        $prefix = TextFormat::GOLD . "[Debug]" . TextFormat::RESET . " ";
        $player->sendMessage($prefix . $message);
    }
} 