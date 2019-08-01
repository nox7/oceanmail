<?php
	/**
	* Class Envelope
	*
	* @author Garet C. Green
	*/

	/**
	* The envelope (mail) received from the incoming server to be delivered to a local account
	*/
	class Envelope{

		public $fromAddress;
		public $fromAddress_Data;
		public $recipientsAddresses;
		public $toAddresses_Data;
		public $returnPathAddress;
		public $dateTime;
		public $subject;
		public $body;

		public function __tostring(){
			$stringified = "";
			$stringified .= "From (command): " . $this->fromAddress['email'] . "\n";
			$stringified .= "From (DATA header): " . $this->fromAddress_Data['email'] . "\n";
			$stringified .= "Recipients (command): " . json_encode($this->recipientsAddresses) . "\n";
			$stringified .= "To (DATA header): " . json_encode($this->toAddresses_Data) . "\n";
			$stringified .= "Return-Path (DATA header): " . $this->returnPathAddress['email'] . "\n";
			$stringified .= "Date (DATA header): " . $this->dateTime . "\n";
			$stringified .= "Subject (DATA header): " . $this->subject . "\n";
			$stringified .= "Body (DATA header): " . $this->body . "\n";
			return $stringified;
		}

	}
