<?php
	/**
	* Class PostOffice
	*
	* @author Garet C. Green
	*/

	require_once __DIR__ . "/Debug.php";
	require_once __DIR__ . "/Envelope.php";
	require_once __DIR__ . "/EmailUtility.php";

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
			$mail->parseRawDataHeaders();
			$mail->parseDataHeaders();
			$mail->parseRawBody();
			Debug::log("Envelope received:\n" . $mail, Debug::DEBUG_LEVEL_MEDIUM);
		}
	}
