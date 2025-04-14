<?php

declare(strict_types=1);

namespace Donate;

/**
 * Status codes returned by the API
 */
final class StatusCode {
	/** Operation completed successfully */
	public const SUCCESS = 1;

	/** Card is being processed */
	public const PENDING = 2;

	/** System is under maintenance */
	public const SYSTEM_MAINTENANCE = 3;

	/** Incorrect card information */
	public const INCORRECT_CARD = 4;

	/** Card has already been used */
	public const CARD_USED = 5;

	/** Card is not supported */
	public const CARD_NOT_SUPPORTED = 6;

	/** Partner information is incorrect */
	public const INCORRECT_PARTNER = 7;

	/** Connection error */
	public const CONNECTION_ERROR = 8;

	/** Other processing error */
	public const PROCESSING_ERROR = 9;

	/** Request failed with specific reason */
	public const FAILED_WITH_REASON = 99;

	/** Generic error */
	public const ERROR = -1;
}
