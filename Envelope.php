<?php
	/**
	* Class Envelope
	*
	* @author Garet C. Green
	*/

	require_once __DIR__ . "/Debug.php";

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
			foreach($this->dataHeaders as $hName=>$value){
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

		/**
		* Whether or not the message is multipart
		*
		* @return bool
		*/
		public function isMultipart(){
			$contentType = $this->getDataHeader("content-type");
			print_r($contentType);
			if ($contentType !== "" && isset($contentType['content-type'])){
				return preg_match("@multipart\/.+@i", $contentType['content-type']) === 1;
			}

			return false;
		}

		/**
		* Parses a raw body
		*
		* If the body has no boundaries, then no child Envelopes are created. Otherwise, child envelopes will be created
		*
		* @return void
		*/
		public function parseRawBody(){
			// First check if this is a possible multipart
			if ($this->isMultipart()){
				Debug::log("Body is multipart", Debug::DEBUG_LEVEL_LOW);
				$contentType = $this->getDataHeader("content-type");
				if (isset($contentType['boundary'])){

					$boundary = $contentType['boundary'];

					Debug::log("Parsing body with boundary: $boundary", Debug::DEBUG_LEVEL_LOW);

					$lines = explode("\r\n", $this->rawBody);
					$parsingState = "";
					$currentChildEnvelope;

					foreach($lines as $line){

						Debug::log("Parsing body line: $line", Debug::DEBUG_LEVEL_LOW);

						if ($line === "--$boundary--" || ($parsingState === "PARSING BODY" && $line === "--$boundary")){
							// End of this boundary, emit the child envelope
							if (isset($currentChildEnvelope)){

								// Parse the child envelope
								$currentChildEnvelope->parseRawDataHeaders();
								$currentChildEnvelope->parseDataHeaders();
								$currentChildEnvelope->parseRawBody();

								// Add it to this instance's multiparts
								$this->multiparts[] = $currentChildEnvelope;

								// Set the parent
								$this->parentEnvelope = $this;

								// Clear the variable
								$currentChildEnvelope = null;
							}

							if (($parsingState === "PARSING BODY" && $line === "--$boundary")){
								// The body parsing previously ended because a new boundary was ran into
								$parsingState = "PARSING HEADERS";
								$currentChildEnvelope = new Envelope();
							}else{
								$parsingState = "";
							}
						}else{
							if ($parsingState === ""){
								if ($line === "--$boundary"){
									$currentChildEnvelope = new Envelope();
									$parsingState = "PARSING HEADERS";
								}else{
									// This means there is text OUTSIDE OF A BOUNDARY
									// This is invalid text, ignore it
								}
							}elseif ($parsingState === "PARSING HEADERS"){
								if ($line !== ""){
									$currentChildEnvelope->rawDataHeaders .= $line;
								}else{
									// Blank line, switch to parsing the body
									$parsingState = "PARSING BODY";
								}
							}elseif ($parsingState === "PARSING BODY"){
								$currentChildEnvelope->rawBody .= $line;
							}
						}
					}
				}else{
					// No boundary set, cannot handle multipart accurately
					$this->body = $this->rawBody;
				}

			}else{
				// No parsing needed
				Debug::log("Body is NOT multipart", Debug::DEBUG_LEVEL_LOW);
				$this->body = $this->rawBody;
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
			$stringified .= "+ parsed body: \n" . $this->body . "\n";
			$stringified .= "+ Parsed Multiparts (" . count($this->multiparts) . "): \n";

			foreach($this->multiparts as $part){
				$stringified .= (string)$part;
			}

			return $stringified;
		}

	}
