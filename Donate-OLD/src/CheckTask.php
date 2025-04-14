<?php

namespace Donate;

use Donate\Donate;
use pocketmine\scheduler\AsyncTask;

class CheckTask extends AsyncTask {

	private $arrayPost;

	private $playerName;

	public function __construct($arrayPost, string $playerName) {
		$this->arrayPost = $arrayPost;
		$this->playerName = $playerName;
	}

	public function onRun(): void {
		$api_url = "https://trumthe.vn//chargingws/v2";
		$arrayPost = $this->arrayPost;
		$arrayPost["command"] = "check";
		$curl = curl_init($api_url);
		curl_setopt_array($curl, array(
			CURLOPT_POST => true,
			CURLOPT_HEADER => false,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_POSTFIELDS => http_build_query($arrayPost)
		));
		$data = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$result = json_decode($data, true);
		$content = [
			"web_status" => $status,
			"result" => $result
		];
		$this->setResult($content);
	}

	public function onCompletion(): void {
		if (is_null($napthe = Donate::$instance)) {
			return;
		}
		$content = $this->getResult();
		if (!isset($content)) {
			return;
		}
		if ($content["web_status"] == 200) {
			if ($content["result"]["status"] == 1) {
				$point = $napthe->chuyendoi[(string) $content["result"]["value"]];
				$napthe->donateSuccess($this->playerName, (int) $content["result"]["value"], $point);
				return;
			}
			$player = $napthe->getServer()->getPlayerByPrefix($this->playerName);

			if ($content["result"]["status"] == 99) {
				$napthe->getServer()->getAsyncPool()->submitTask(new CheckTask($this->arrayPost, $this->playerName));
				if ($player == null) {
					return;
				}
				return;
			}
			if ($player == null) {
				return;
			}
			if ($content["result"]["status"] == 2) {
				$txt =
					"§l§6→§c Bạn Đã Chọn Sai Mệnh Giá\n" .
					"§l§6→§a Mệnh Giá§b:§c " . $content["result"]["value"] . "\n" .
					"§l§6→§a Mệnh Giá Bạn Chọn§b:§c " . $content["result"]["declared_value"] . "\n" .
					"§l§6→§c Chụp Gửi Discord§e Puck#3219§c Để Được Hỗ Trợ";
				$napthe->onSuccess($player, $txt);
			} else {
				$txt =
					"§l§6→§c Đã Xảy Ra Lỗi\n" .
					"§l§6→§a Mã Giao Dịch§b:§c " . $content["result"]["request_id"] . "\n" .
					"§l§6→§a Thông Tin Lỗi§b:§c " . $content["result"]["message"] . "\n" .
					"§l§6→§c Chụp Gửi Discord§e Puck#3219§c Để Được Hỗ Trợ";
				$napthe->onSuccess($player, $txt);
			}
		} else {
			var_dump($content);
		}
	}
}
