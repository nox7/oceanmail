<?php
	/**
	* Class PostOffice
	*
	* @author Garet C. Green
	*/

	require_once __DIR__ . "/src/Debug.php";

	/**
	* Handles delivering mail
	*/
	class PostOffice{

		public function __construct(){

		}

		/**
		* Receives a dropped off envelope
		*
		* @param Envelope $mail
		* @return void
		*/
		public function onMailDroppedOff(Envelope $mail){
			// Debug::log("Envelope received:\n" . $mail, Debug::DEBUG_LEVEL_MEDIUM);
			// print_r($mail->dkimVerificationResults);

			print(sprintf("SPF result: %s\n", $mail->spfCheckResult));
		}
	}
