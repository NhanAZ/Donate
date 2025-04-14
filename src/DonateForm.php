<?php

declare(strict_types=1);

namespace Donate;

use dktapps\pmforms\CustomForm;
use dktapps\pmforms\CustomFormResponse;
use dktapps\pmforms\element\Dropdown;
use dktapps\pmforms\element\Input;
use dktapps\pmforms\element\StepSlider;
use Donate\Constant;
use Donate\Donate;
use Donate\tasks\ChargingTask;
use pocketmine\player\Player;
use pocketmine\Server;

class DonateForm {

	public static function get(): CustomForm {
		return new CustomForm(
			title: "Biểu Mẫu Nạp Thẻ",
			elements: [
				new Dropdown(
					name: "telco",
					text: "Loại thẻ",
					options: Constant::TELCO_DISPLAY
				),
				new StepSlider(
					name: "amount",
					text: "Mệnh giá",
					options: Constant::AMOUNT_DISPLAY
				),
				new Input(
					name: "serial",
					text: "Số sê-ri",
					hintText: "Nhập số sê-ri tại đây:\nVí dụ: 10004783347874"
				),
				new Input(
					name: "code",
					text: "Mã thẻ",
					hintText: "Nhập mã thẻ tại đây:\nVí dụ: 312821445892982"
				)
			],
			onSubmit: function (Player $submitter, CustomFormResponse $response): void {
				if ($response->getString("serial") === "" || $response->getString("code") === "") {
					$submitter->sendMessage(Constant::PREFIX . "Vui lòng không bỏ trống số sê-ri hoặc mã thẻ!");
					return;
				}
				Server::getInstance()->getAsyncPool()->submitTask(new ChargingTask(
					$submitter->getName(),
					Constant::TELCO[$response->getInt("telco")],
					$response->getString("code"),
					$response->getString("serial"),
					Constant::AMOUNT[$response->getInt("amount")],
					uniqid("donate_", true)
				));
				Donate::getInstance()->logger->info(Constant::PREFIX . "[playerName: " . $submitter->getName() . ", telco: " . Constant::TELCO_DISPLAY[$response->getInt("telco")] . ", code: " . $response->getString("code") . ", serial: " . $response->getString("serial") . ", amount: " . Constant::AMOUNT_DISPLAY[$response->getInt("amount")] . "]");
			},
		);
	}
}
