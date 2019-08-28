<?php
	/**
	* Debug Class
	*
	* @author Garet C. Green
	*/

	/**
	* Manages debug levels and filtering logs
	*/
	class Debug{

		/** @var int Constant for low-priority logs */
		const DEBUG_LEVEL_LOW = 0;

		/** @var int Constant for medium-priority logs */
		const DEBUG_LEVEL_MEDIUM = 1;

		/** @var int Constant for high-priority logs */
		const DEBUG_LEVEL_HIGH = 2;

		/** @var int Current debug level */
		const DEBUG_LEVEL = 0;

		/** @var bool Whether or not debugging is on at all */
		const DEBUG_ON = true;

		/**
		* Outputs a string if the current debug level is low enough to allow it
		*
		* @param string $message
		* @param int $debugLevel
		* @return void
		*/
		public static function log(string $message, int $debugLevel){

			if (self::DEBUG_ON === true){
				if ($debugLevel >= self::DEBUG_LEVEL){
					print(sprintf("%s\n", $message));
				}
			}

		}
	}
