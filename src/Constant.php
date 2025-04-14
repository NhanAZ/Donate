<?php

declare(strict_types=1);

namespace Donate;

/**
 * Constants used throughout the plugin
 */
class Constant {
	/** Plugin prefix for messages */
	public const PREFIX = "[Nạp Thẻ] ";

	public const PARTNER_ID = "";

	public const PARTNER_KEY = "";

	/** API endpoint for trumthe.vn */
	public const URL = "https://trumthe.vn/chargingws/v2";

	/** Số tiền người chơi nhận được trong máy chủ khi nạp thẻ thành công sẽ nhân với giá trị này */
	public const BONUS = 1;

	/** Telco codes for API */
	public const TELCO = [
		"VIETTEL",
		"VINA",
		"MOBI",
		"VIETNAMMOBI",
		"ZING",
		"GARENA",
		"VCOIN",
		"GATE"
	];

	/** Display names for telcos */
	public const TELCO_DISPLAY = [
		"Viettel",
		"VinaPhone",
		"MobiFone",
		"Vietnamobile",
		"Zing",
		"Garena",
		"VCoin",
		"Gate"
	];

	/** Không thay đổi giá trị của hằng này */
	public const AMOUNT = [
		10000,
		20000,
		50000,
		100000,
		200000,
		500000
	];

	/** Display strings for card amounts */
	public const AMOUNT_DISPLAY = [
		"10.000₫",
		"20.000₫",
		"50.000₫",
		"100.000₫",
		"200.000₫",
		"500.000₫",
	];
}
