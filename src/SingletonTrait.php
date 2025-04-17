<?php

declare(strict_types=1);

namespace Donate;

trait SingletonTrait {

	public static Donate $instance;

	public static function setInstance(Donate $instance) : void {
		self::$instance = $instance;
	}

	public static function getInstance() : Donate {
		return self::$instance;
	}
}
