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
		
		// Debug logging - API request
		if (isset($plugin->debugLogger)) {
			// Clone the data to avoid modifying the original
			$debugData = $data;
			// Mask sensitive data
			$debugData["code"] = substr($code, 0, 2) . "****" . substr($code, -2);
			$debugData["serial"] = substr($serial, 0, 4) . "****" . substr($serial, -4);
			$debugData["sign"] = substr($sign, 0, 8) . "...";
			
			$plugin->debugLogger->logApi("CHARGE_REQUEST", $debugData);
		}

		$result = self::postRequest($data);
		if ($result === null) {
			// Debug logging - API request failed
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("CHARGE_FAILED", [], ["error" => "Connection failed"]);
			}
			return null;
		}

		$responseData = json_decode($result->getBody(), true);
		if (!is_array($responseData)) {
			// Debug logging - API response parse error
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("CHARGE_PARSE_ERROR", [], ["raw" => substr($result->getBody(), 0, 100)]);
			}
			return null;
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
	 * 
	 * @return ChargeStatusResponse|null Response from the API, or null if the request failed
	 * 
	 * @throws InternetException If the HTTP request fails
	 */
	public static function checkCardStatus(string $requestId): ?ChargeStatusResponse {
		$plugin = Donate::getInstance();
		$config = $plugin->getConfig();

		// Safely get partner ID and key
		$partnerId = DataTypeUtils::toString($config->get("partner_id", ""));
		$partnerKey = DataTypeUtils::toString($config->get("partner_key", ""));

		$data = [
			"request_id" => $requestId,
			"partner_id" => $partnerId,
			"sign" => md5($partnerKey . $requestId),
			"command" => "check"
		];
		
		// Debug logging - API status check request
		if (isset($plugin->debugLogger)) {
			$debugData = $data;
			$debugData["sign"] = substr($data["sign"], 0, 8) . "...";
			
			$plugin->debugLogger->logApi("STATUS_REQUEST", $debugData);
		}

		$result = self::postRequest($data);
		if ($result === null) {
			// Debug logging - API request failed
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("STATUS_FAILED", [], ["error" => "Connection failed"]);
			}
			return null;
		}

		$responseData = json_decode($result->getBody(), true);
		if (!is_array($responseData)) {
			// Debug logging - API response parse error
			if (isset($plugin->debugLogger)) {
				$plugin->debugLogger->logApi("STATUS_PARSE_ERROR", [], ["raw" => substr($result->getBody(), 0, 100)]);
			}
			return null;
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
