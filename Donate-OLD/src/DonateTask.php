<?php

namespace Donate;

use Donate\Donate;
use pocketmine\Server;
use pocketmine\scheduler\AsyncTask;

class DonateTask extends AsyncTask {

	private $merchant;

	private $telco;

	private $pin;

	private $seri;

	private $amount;

	private $playerName;

	public function __construct(array $merchant, string $telco, string $pin, string $seri, int $amount, string $playerName) {
		$this->merchant = $merchant;
		$this->telco = $telco;
		$this->pin = $pin;
		$this->seri = $seri;
		$this->amount = $amount;
		$this->playerName = $playerName;
	}

	public function onRun(): void {
		$api_url = "https://trumthe.vn//chargingws/v2";
		$data_sign = md5($this->merchant[1] . $this->pin . $this->seri);
		$arrayPost = array(
			"telco" => $this->telco,
			"code" => $this->pin,
			"serial" => $this->seri,
			"amount" => $this->amount,
			"request_id" => intval(time()),
			"partner_id" => $this->merchant[0],
			"sign" => $data_sign,
			"command" => "charging"
		);
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
			"arrayPost" => $arrayPost,
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
		if ($content["result"] == false) {
			$player = $napthe->getServer()->getPlayerByPrefix($this->playerName);
			if ($player == null) {
				return;
			}
			$player->sendMessage("§l§6→§c Trang Chủ Donate Đã Xảy Ra Lỗi!");
		}

		if ($content["web_status"] == 200) {
			$player = $napthe->getServer()->getPlayerByPrefix($this->playerName);
			$thongtin = [$this->telco, $this->pin, $this->seri, $this->amount];
			if ($content["result"]["status"] == 99) {
				$napthe->getServer()->getAsyncPool()->submitTask(new CheckTask($content["arrayPost"], $this->playerName));
				if ($player == null) {
					return;
				}
				return;
			}
			if ($player == null) {
				return;
			}
			if ($content["result"]["status"] == 4) {
				$player->sendMessage("§l§6→§c Nhà Mạng Đang Bảo Trì!");
			} else if ($content["result"]["status"] == 100) {
				$txt =
					"§l§6→§a Loại Thẻ§b:§c " . $thongtin[0] . "\n" .
					"§l§6→§a Mã Thẻ§b:§e " . $thongtin[1] . "\n" . 
					"§l§6→§a Số Seri§b:§e " . $thongtin[2] . "\n" . 
					"§l§6→§a Mệnh Giá§b:§c " . $thongtin[3] . "\n" .
					"§l§6→§a LỖI§b:§c " . $content["result"]["message"] . "\n" .
					"§l§6→§c Chụp Gửi Discord§e Puck#3219§c Để Được Hỗ Trợ";
				$napthe->onSuccess($player, $txt);
			} else {
				$player->sendMessage("§l§6→§c Chụp Gửi Discord§e Puck#3219§c Để Được Hỗ Trợ");
			}
		} else {
			var_dump($content);
		}
	}
}
