<?php

declare(strict_types=1);

namespace Donate;

/**
 * Error codes used throughout the plugin
 */
final class ErrorCode {
	/** No error */
	public const NONE = 0;

	/** Generic error */
	public const GENERIC_ERROR = 1;

	/** Network/connection error */
	public const NETWORK_ERROR = 2;

	/** API error */
	public const API_ERROR = 3;

	/** Invalid card error */
	public const INVALID_CARD = 4;

	/** Configuration error */
	public const CONFIG_ERROR = 5;

	/** Permission error */
	public const PERMISSION_ERROR = 6;

	/** Database error */
	public const DATABASE_ERROR = 7;

	/** Invalid input error */
	public const INVALID_INPUT = 8;

	/** Server error */
	public const SERVER_ERROR = 9;

	/** Unknown error */
	public const UNKNOWN_ERROR = 99;

	/**
	 * Get a human-readable error message for an error code
	 */
	public static function getMessage(int $code): string {
		return match ($code) {
			self::NONE => "Không có lỗi",
			self::GENERIC_ERROR => "Lỗi không xác định",
			self::NETWORK_ERROR => "Lỗi kết nối mạng",
			self::API_ERROR => "Lỗi API",
			self::INVALID_CARD => "Thẻ không hợp lệ",
			self::CONFIG_ERROR => "Lỗi cấu hình",
			self::PERMISSION_ERROR => "Không có quyền",
			self::DATABASE_ERROR => "Lỗi cơ sở dữ liệu",
			self::INVALID_INPUT => "Dữ liệu nhập vào không hợp lệ",
			self::SERVER_ERROR => "Lỗi máy chủ",
			self::UNKNOWN_ERROR => "Lỗi không xác định",
			default => "Mã lỗi không xác định: $code"
		};
	}
}
