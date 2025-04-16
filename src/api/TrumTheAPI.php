<?php

declare(strict_types=1);

namespace Donate\api;

use Donate\Constant;
use Donate\Donate;
use Donate\utils\DataTypeUtils;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use pocketmine\utils\InternetRequestResult;

/**
 * API client for trumthe.vn card charging service
 */
final class TrumTheAPI {

	/**
	 * Submit a card charge request to the API
	 * 
	 * @param string $telco Card provider code
	 * @param string $code Card code
	 * @param string $serial Card serial number
	 * @param int $amount Card amount value in VND
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
	): ?ChargeResponse {
		$plugin = Donate::getInstance();
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
		$plugin->logger->info("[Donate/API] Received card charge response - RequestID: $requestId, Status: $status, Message: $message");

		// Special log for PENDING status to clarify it's being processed, not an error
		if (isset($responseData['message']) && $responseData['message'] === "PENDING") {
			$plugin->logger->info("[Donate/API] Card is being processed - RequestID: $requestId, Status: PENDING. This is normal, not an error.");
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->log("Card with RequestID: $requestId is PENDING processing (status 99 with PENDING message). This is expected behavior.", "api");
			}
		}

		// Debug logging - API response
		if (isset($plugin->debugLogger)) {
			$plugin->debugLogger->logApi("CHARGE_RESPONSE", [], $responseData);
			// Log chi tiết thông báo lỗi để debug
			if (isset($responseData['message'])) {
				$plugin->debugLogger->log(
					"API response message (raw): '{$responseData['message']}'",
					"api"
				);
			}
		}

		return ChargeResponse::fromArray($responseData);
	}

	/**
	 * Check the status of a card charge request
	 * 
	 * @param string $requestId Request ID to check
	 * @param array<string,mixed>|null $cardInfo Original card information (optional)
	 * 
	 * @return ChargeStatusResponse|null Response from the API, or null if the request failed
	 * 
	 * @throws InternetException If the HTTP request fails
	 */
	public static function checkCardStatus(string $requestId, ?array $cardInfo = null): ?ChargeStatusResponse {
		$plugin = Donate::getInstance();
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
			$data['sign'] = md5($partnerKey . $cardInfo['code'] . $cardInfo['serial']);

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
		$plugin->logger->info("[Donate/API] Received card status response - RequestID: $requestId, Status: $status, Message: $message");

		// Special log for PENDING status to clarify it's being processed, not an error
		if (isset($responseData['message']) && $responseData['message'] === "PENDING") {
			$plugin->logger->info("[Donate/API] Card is still being processed - RequestID: $requestId, Status: PENDING. This is normal, not an error.");
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->log("Card with RequestID: $requestId is still PENDING processing (status 99 with PENDING message). This is expected behavior.", "api");
			}
		}

		// Debug logging - API status response
		if (isset($plugin->debugLogger)) {
			$plugin->debugLogger->logApi("STATUS_RESPONSE", [], $responseData);
		}

		return ChargeStatusResponse::fromArray($responseData);
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
	private static function postRequest(array $data): ?InternetRequestResult {
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
