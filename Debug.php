<?php
	/** Debug Class
	*
	* @author Garet C. Green
	*/

	/**
	* Manages debug levels and filtering logs
	*/
	class Debug{

		const DEBUG_LEVEL_LOW = 0; // Output low and up
		const DEBUG_LEVEL_MEDIUM = 1; // Output medium and up
		const DEBUG_LEVEL_HIGH = 2; // Output high and up

		const DEBUG_LEVEL = 0;
		const DEBUG_ON = true;

		public static function log(string $message, int $debugLevel){

			if ($debugLevel >= self::DEBUG_LEVEL){
				print(sprintf("%s\n", $message));
			}
			
		}
	}
