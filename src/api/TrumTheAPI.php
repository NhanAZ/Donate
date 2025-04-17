<?php

declare(strict_types=1);

namespace Donate\api;

use Donate\Constant;
use Donate\Donate;
use Donate\utils\DataTypeUtils;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use pocketmine\utils\InternetRequestResult;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function md5;
use function substr;
use function time;

/**
 * API client for trumthe.vn card charging service
 */
final class TrumTheAPI {

	/** @var array<string, int> Last API request time by IP */
	private static array $lastApiRequests = [];

	/** @var int Cooldown between API requests in seconds */
	private static int $apiCooldown = 2; // 2 seconds cooldown

	/**
	 * Set the API request cooldown
	 *
	 * @param int $seconds Number of seconds between API requests
	 */
	public static function setApiCooldown(int $seconds) : void {
		self::$apiCooldown = max(1, $seconds); // Minimum 1 second
	}

	/**
	 * Get the current API cooldown setting
	 */
	public static function getApiCooldown() : int {
		return self::$apiCooldown;
	}

	/**
	 * Check if an API request can be made
	 *
	 * @param string $requestType Type of request (for logging)
	 * @return bool True if request can proceed, false if throttled
	 */
	private static function canMakeApiRequest(string $requestType) : bool {
		$plugin = Donate::getInstance();
		$server = $plugin->getServer();
		$serverIp = $server->getIp();
		$currentTime = time();

		// Use server IP as the key for tracking
		$key = $serverIp . '_' . $requestType;

		if (isset(self::$lastApiRequests[$key])) {
			$lastTime = self::$lastApiRequests[$key];
			$timeDiff = $currentTime - $lastTime;

			if ($timeDiff < self::$apiCooldown) {
				$plugin->logger->warning("[Donate/API] API request throttled - Type: $requestType - Too many requests");
				if (isset($plugin->debugLogger)) {
					$plugin->debugLogger->log("API request throttled ($requestType) - Needs to wait " . (self::$apiCooldown - $timeDiff) . " more seconds", "api");
				}
				return false;
			}
		}

		// Update last request time
		self::$lastApiRequests[$key] = $currentTime;
		return true;
	}

	/**
	 * Submit a card charge request to the API
	 *
	 * @param string $telco     Card provider code
	 * @param string $code      Card code
	 * @param string $serial    Card serial number
	 * @param int    $amount    Card amount value in VND
	 * @param string $requestId Unique request ID
	 *
	 * @return ChargeResponse|null Response from the API, or null if the request failed
	 *
	 * @throws InternetException If the HTTP request fails
	 * @throws \InvalidArgumentException If API credentials are not configured
	 */
	public static function chargeCard(
		string $telco,
		string $code,
		string $serial,
		int $amount,
		string $requestId
	) : ?ChargeResponse {
		$plugin = Donate::getInstance();

		// Check rate limiting
		if (!self::canMakeApiRequest('charge')) {
			return new ChargeResponse(
				-1,
				"Too many API requests. Please try again in a few seconds."
			);
		}

		$config = $plugin->getConfig();

		// Safely get partner ID and key
		$partnerId = DataTypeUtils::toString($config->get("partner_id", ""));
		$partnerKey = DataTypeUtils::toString($config->get("partner_key", ""));

		if ($partnerId === "" || $partnerKey === "") {
			throw new \InvalidArgumentException("Partner ID and Partner Key must be configured in config.yml");
		}

		$sign = md5($partnerKey . $code . $serial);

		$data = [
			"telco" => $telco,
			"code" => $code,
			"serial" => $serial,
			"amount" => $amount,
			"request_id" => $requestId,
			"partner_id" => $partnerId,
			"sign" => $sign,
			"command" => "charging"
		];

		// Log API request (masked sensitive data)
		$plugin->logger->info("[Donate/API] Sending card charge request to TrumThe API - RequestID: $requestId, Telco: $telco, Amount: $amount");

		// Debug logging - API request
		if (isset($plugin->debugLogger)) {
			// Clone the data to avoid modifying the original
			$debugData = $data;
			// Mask sensitive data
			$debugData["code"] = substr($code, 0, 2) . "****" . substr($code, -2);
			$debugData["serial"] = substr($serial, 0, 4) . "****" . substr($serial, -4);
			$debugData["sign"] = substr(DataTypeUtils::toString($sign), 0, 8) . "...";

			$plugin->debugLogger->logApi("CHARGE_REQUEST", $debugData);
		}

		$result = self::postRequest($data);
		if ($result === null) {
			// Log the connection failure
			$plugin->logger->error("[Donate/API] Failed to connect to TrumThe API for card charge - RequestID: $requestId");

			// Debug logging - API request failed
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("CHARGE_FAILED", [], ["error" => "Connection failed"]);
			}
			return null;
		}

		$responseData = json_decode($result->getBody(), true);
		if (!is_array($responseData)) {
			// Log the response parse error
			$plugin->logger->error("[Donate/API] Failed to parse TrumThe API response for card charge - RequestID: $requestId, Raw: " . substr($result->getBody(), 0, 100));

			// Debug logging - API response parse error
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("CHARGE_PARSE_ERROR", [], ["raw" => substr($result->getBody(), 0, 100)]);
			}
			return null;
		}

		// Log the API response
		$status = $responseData['status'] ?? 'unknown';
		$message = $responseData['message'] ?? 'no message';
		$statusStr = DataTypeUtils::toString($status);
		$messageStr = DataTypeUtils::toString($message);
		$plugin->logger->info("[Donate/API] Received card charge response - RequestID: " . $requestId . ", Status: " . $statusStr . ", Message: " . $messageStr);

		// Special log for PENDING status to clarify it's being processed, not an error
		if (isset($responseData['message']) && $responseData['message'] === "PENDING") {
			$plugin->logger->info("[Donate/API] Card is being processed - RequestID: $requestId, Status: PENDING. This is normal, not an error.");
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->log("Card with RequestID: $requestId is PENDING processing (status 99 with PENDING message). This is expected behavior.", "api");
			}
		}

		// Debug logging - API response
		if (isset($plugin->debugLogger)) {
			// Cast to array<string, mixed>
			$responseDataTyped = DataTypeUtils::toStringKeyedArray($responseData);
			$plugin->debugLogger->logApi("CHARGE_RESPONSE", [], $responseDataTyped);

			// Log chi tiết thông báo lỗi để debug
			if (isset($responseData['message'])) {
				$messageContent = DataTypeUtils::toString($responseData['message']);
				$plugin->debugLogger->log(
					"API response message (raw): '" . $messageContent . "'",
					"api"
				);
			}
		}

		return ChargeResponse::fromArray(DataTypeUtils::toStringKeyedArray($responseData));
	}

	/**
	 * Check the status of a card charge request
	 *
	 * @param string                   $requestId Request ID to check
	 * @param array<string,mixed>|null $cardInfo  Original card information (optional)
	 *
	 * @return ChargeStatusResponse|null Response from the API, or null if the request failed
	 *
	 * @throws InternetException If the HTTP request fails
	 */
	public static function checkCardStatus(string $requestId, ?array $cardInfo = null) : ?ChargeStatusResponse {
		$plugin = Donate::getInstance();

		// Check rate limiting
		if (!self::canMakeApiRequest('status')) {
			return new ChargeStatusResponse(
				-1,
				"Too many API requests. Please try again in a few seconds."
			);
		}

		$config = $plugin->getConfig();

		// Safely get partner ID and key
		$partnerId = DataTypeUtils::toString($config->get("partner_id", ""));
		$partnerKey = DataTypeUtils::toString($config->get("partner_key", ""));

		// Tạo dữ liệu cơ bản
		$data = [
			"request_id" => $requestId,
			"partner_id" => $partnerId,
			"command" => "check"
		];

		// Nếu có thông tin thẻ gốc, thêm vào yêu cầu và tính toán sign giống như lúc charging
		if ($cardInfo !== null && isset($cardInfo['code'], $cardInfo['serial'])) {
			$data['telco'] = $cardInfo['telco'] ?? '';
			$data['code'] = $cardInfo['code'];
			$data['serial'] = $cardInfo['serial'];
			if (isset($cardInfo['amount'])) $data['amount'] = $cardInfo['amount'];

			// Tính sign giống như trong chargeCard
			$codeStr = DataTypeUtils::toString($cardInfo['code']);
			$serialStr = DataTypeUtils::toString($cardInfo['serial']);
			$data['sign'] = md5($partnerKey . $codeStr . $serialStr);

			$plugin->debugLogger->log(
				"Added original card info to status check request for RequestID: $requestId",
				"api"
			);
		} else {
			// Nếu không có thông tin thẻ, sử dụng sign đơn giản
			$data['sign'] = md5($partnerKey . $requestId);
		}

		// Log API status check request
		$plugin->logger->info("[Donate/API] Checking card status - RequestID: $requestId");

		// Debug logging - API status check request
		if (isset($plugin->debugLogger)) {
			$debugData = $data;
			$signStr = DataTypeUtils::toString($debugData["sign"]);
			$debugData["sign"] = substr($signStr, 0, 8) . "...";
			if (isset($debugData["code"])) {
				$codeStr = DataTypeUtils::toString($debugData["code"]);
				$debugData["code"] = substr($codeStr, 0, 2) . "****" . substr($codeStr, -2);
			}
			if (isset($debugData["serial"])) {
				$serialStr = DataTypeUtils::toString($debugData["serial"]);
				$debugData["serial"] = substr($serialStr, 0, 4) . "****" . substr($serialStr, -4);
			}

			$plugin->debugLogger->logApi("STATUS_REQUEST", $debugData);
		}

		$result = self::postRequest($data);
		if ($result === null) {
			// Log the connection failure
			$plugin->logger->error("[Donate/API] Failed to connect to TrumThe API for status check - RequestID: $requestId");

			// Debug logging - API request failed
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("STATUS_FAILED", [], ["error" => "Connection failed"]);
			}
			return null;
		}

		$responseData = json_decode($result->getBody(), true);
		if (!is_array($responseData)) {
			// Log the response parse error
			$plugin->logger->error("[Donate/API] Failed to parse TrumThe API response for status check - RequestID: $requestId, Raw: " . substr($result->getBody(), 0, 100));

			// Debug logging - API response parse error
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("STATUS_PARSE_ERROR", [], ["raw" => substr($result->getBody(), 0, 100)]);
			}
			return null;
		}

		// Log the API response
		$status = $responseData['status'] ?? 'unknown';
		$message = $responseData['message'] ?? 'no message';
		$statusStr = DataTypeUtils::toString($status);
		$messageStr = DataTypeUtils::toString($message);
		$plugin->logger->info("[Donate/API] Received card status response - RequestID: " . $requestId . ", Status: " . $statusStr . ", Message: " . $messageStr);

		// Special log for PENDING status to clarify it's being processed, not an error
		if (isset($responseData['message']) && $responseData['message'] === "PENDING") {
			$plugin->logger->info("[Donate/API] Card is still being processed - RequestID: $requestId, Status: PENDING. This is normal, not an error.");
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->log("Card with RequestID: $requestId is still PENDING processing (status 99 with PENDING message). This is expected behavior.", "api");
			}
		}

		// Debug logging - API status response
		if (isset($plugin->debugLogger)) {
			// Cast to array<string, mixed>
			$responseDataTyped = DataTypeUtils::toStringKeyedArray($responseData);
			$plugin->debugLogger->logApi("STATUS_RESPONSE", [], $responseDataTyped);
		}

		return ChargeStatusResponse::fromArray(DataTypeUtils::toStringKeyedArray($responseData));
	}

	/**
	 * Send a POST request to the API
	 *
	 * @param array<string, mixed> $data POST data
	 *
	 * @return InternetRequestResult|null Response from the API, or null if the request failed
	 *
	 * @throws InternetException If the HTTP request fails
	 */
	private static function postRequest(array $data) : ?InternetRequestResult {
		try {
			$plugin = Donate::getInstance();
			$config = $plugin->getConfig();

			// Safely get API URL
			$apiUrl = DataTypeUtils::toString($config->get("api_url", Constant::URL), Constant::URL);

			// Safely get timeout
			$timeout = DataTypeUtils::toInt($config->get("api_timeout", 10), 10);
			$timeout = max(1, $timeout); // Ensure at least 1 second timeout

			// JSON encode the data for POST request
			$jsonData = json_encode($data);
			if ($jsonData === false) {
				return null;
			}

			return Internet::postURL(
				$apiUrl,
				$jsonData,
				$timeout,
				["Content-Type: application/json"]
			);
		} catch (InternetException $e) {
			throw $e;
		}
	}
}
