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

		public $fromAddress = "";
		public $fromAddress_Data = "";
		public $recipientsAddresses = [];
		public $toAddresses_Data = [];
		public $returnPathAddress = "";
		public $rawDateTime = "";
		public $dateTime;
		public $subject = "";
		public $rawDataHeaders = "";
		public $dataHeaders = [];
		public $rawBody = "";
		public $body = "";
		public $multiparts = [];
		public $parentEnvelope = null;

		public function __construct(){

		}

		/**
		* Gets the value of a header from dataHeaders or a blank string
		*
		* This function is case-insensitive
		*
		* @param string $headerName
		* @return string
		*/
		public function getDataHeader(string $headerName){
			foreach($dataHeaders as $hName=>$value){
				if (mb_strtolower($hName) === mb_strtolower($headerName)){
					return $value;
				}
			}

			return "";
		}

		/**
		* Parses the rawDataHeaders into tokenized headers
		*
		* @return void
		*/
		public function parseRawDataHeaders(){
			$headerStrings = explode("\r\n", $this->rawDataHeaders);

			foreach($headerStrings as $str){
				$str = trim($str);
				if (!empty($str)){
					$keyValuePair = EmailUtility::parseHeaderAsKeyValue($str);
					$this->dataHeaders[$keyValuePair[0]] = $keyValuePair[1];
				}
			}
		}

		/**
		* Parses known data headers into workable types
		*
		* @return void
		*/
		public function parseDataHeaders(){
			foreach($this->dataHeaders as $key=>$value){

				$loweredKey = mb_strtolower($key);
				$newValue = $value;

				if ($loweredKey === "date"){
					$newValue = new DateTime($value);
				}elseif ($loweredKey === "to"){
					$newValue = EmailUtility::parseEmailAddressList($value);
				}elseif ($loweredKey === "cc"){
					$newValue = EmailUtility::parseEmailAddressList($value);
				}elseif ($loweredKey === "bcc"){
					$newValue = EmailUtility::parseEmailAddressList($value);
				}elseif ($loweredKey === "from"){
					$newValue = EmailUtility::parseEmailAddress($value);
				}elseif ($loweredKey === "return-path"){
					$newValue = EmailUtility::parseEmailAddress($value);
				}elseif ($loweredKey === "content-type"){
					$newValue = EmailUtility::parseSemicolonDelimitedValue($value, 'content-type');
				}elseif ($loweredKey === "content-disposition"){
					$newValue = EmailUtility::parseSemicolonDelimitedValue($value, 'content-disposition');
				}

				$this->dataHeaders[$key] = $newValue;
			}
		}

		public function __tostring(){
			$stringified = "";

			if (isset($this->fromAddress['email'])){
				$stringified .= "From (command): " . $this->fromAddress['email'] . "\n";
			}else{
				$stringified .= "From (command):";
			}

			$stringified .= "Recipients (command): " . json_encode($this->recipientsAddresses) . "\n";
			$stringified .= "+ raw DATA headers: \n" . $this->rawDataHeaders . "\n";
			$stringified .= "+ parsed DATA headers: \n" . json_encode($this->dataHeaders) . "\n";
			$stringified .= "+ Raw body: \n" . $this->rawBody . "\n";

			return $stringified;
		}

	}
