<?php
	/**
	* Class Envelope
	*
	* @author Garet C. Green
	*/

	require_once __DIR__ . "/Debug.php";
	require_once __DIR__ . "/DKIMVerify.php";
	require_once __DIR__ . "/SPF.php";

	/**
	* The envelope (mail) received from the incoming server to be delivered to a local account
	*/
	class Envelope{

		/** @var string The IP address that this mail was received from */
		public $socketAddress = "";

		/** @var string Hostname provided by HELO/EHLO command */
		public $heloHostname = "";

		/** @var array The From address as sent by the SMTP FROM command after being parsed by EmailUtility::parseEmaiLAddress() */
		public $fromAddress = [];

		/** @var string The recipient addresses found in the RCPT TO command */
		public $recipientsAddresses = [];

		/** @var string The raw headers sent in the DATA */
		public $rawDataHeaders = "";

		/** @var string[] The headers compiled from rawDataHeaders. Case is unchanged here and whitespace is preserved */
		public $dataHeaders = [];

		/** @var mixed[] The parsed headers compiled from rawDataHeaders */
		public $parsedDataHeaders = [];

		/** @var string The raw body of the message - no parsing done so even multipart boundaries are still raw */
		public $rawBody = "";

		/** @var string The parsed body. Encodings should be decoded here. This will be blank if only multipart boundaries were bundled in the rawBody because multiparts[] will be populated with child Envelopes instead. */
		public $body = "";

		/** @var Envelope[] Child envelopes created from multipart boundaries */
		public $multiparts = [];

		/** @var Envelope The parent envelope if this Envelope instance is a child (a multipart part) */
		public $parentEnvelope = null;

		/** @var array The results of the DKIM verification process */
		public $dkimVerificationResults = [];

		/** @var string The result of an SPF check */
		public $spfCheckResult = "none";

		/**
		* Gets the value of a header from parsedDataHeaders or a blank string
		*
		* This function is case-insensitive
		*
		* @param string $headerName
		* @return string
		*/
		public function getDataHeader(string $headerName){
			foreach($this->parsedDataHeaders as $hName=>$value){
				if (mb_strtolower($hName) === mb_strtolower($headerName)){
					return $value;
				}
			}

			return "";
		}

		/**
		* Gets the value of a header from dataHeaders or a blank string
		*
		* This function is case-insensitive
		*
		* @param string $headerName
		* @return string
		*/
		public function getUnparsedDataHeader(string $headerName){
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

			// When lines are wrapped, the first character of a new line in headers is a space
			// So to unwrap these lines, \r\n\s should be removed

			// dataHeaders must remained folder for DKIM verifying
			// $this->rawDataHeaders = str_replace("\r\n ", "", $this->rawDataHeaders);

			$headerStrings = explode("\r\n", $this->rawDataHeaders);
			$lastKey; // The last header key created

			foreach($headerStrings as $rawLine){
				if ($rawLine !== ""){
					Debug::log("Parsing raw header line: $rawLine", Debug::DEBUG_LEVEL_LOW);
					if (preg_match("/^\s/", $rawLine) === 1){
						// First line was a space, this means it is a continuation of the previous key
						$this->dataHeaders[$lastKey] .= "\r\n" . $rawLine;
					}else{
						// This is a new key line
						// The key is defined until the first colon (but not including that colon)
						preg_match("/^(.+?):(.*)/", $rawLine, $matches);
						$lastKey = $matches[1];
						$value = $matches[2];
						$this->dataHeaders[$lastKey] = $value;
					}
				}
			}
		}

		/**
		* Parses known data headers into workable types
		*
		* All folder header values will be unfolded here
		*
		* @return void
		*/
		public function parseDataHeaders(){
			foreach($this->dataHeaders as $key=>$value){

				// Remove any \r\n from the value
				$value = str_replace("\r\n", "", $value);

				$loweredKey = mb_strtolower($key);
				$newValue = EmailUtility::unfoldHeaderValue($value);

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
				}elseif ($loweredKey === "dkim-signature"){
					$newValue = EmailUtility::parseSemicolonDelimitedValue($value, '');
				}

				// Always set headers as all lower case
				$this->parsedDataHeaders[$loweredKey] = $newValue;
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
									$currentChildEnvelope->rawDataHeaders .= $line . "\r\n";
								}else{
									// Blank line, switch to parsing the body
									$parsingState = "PARSING BODY";
								}
							}elseif ($parsingState === "PARSING BODY"){
								$currentChildEnvelope->rawBody .= $line . "\r\n";
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

		/**
		* Recursively decodes all quoted-printable body properties (dives into multiparts and their multiparts)
		*
		* @return void
		*/
		public function decodeQuotedPrintableBodies(){
			$transferEncoding = trim($this->getDataHeader("content-transfer-encoding"));
			if ($transferEncoding === "quoted-printable"){

				// Decode the quoted printable message
				$this->body = quoted_printable_decode($this->body);

			}

			// Recursively check all child multiparts
			if (count($this->multiparts)){
				foreach ($this->multiparts as $envelope){
					$envelope->decodeQuotedPrintableBodies();
				}
			}
		}

		/**
		* Run parsing and decoding functions on raw data in this Envelope
		*
		* This function prepares the Enevelope into a "final" state to be dropped off at a PostOffice object
		*
		* @return void
		*/
		public function finalizePackage(){
			$this->parseRawDataHeaders();
			$this->parseDataHeaders();
			$this->parseRawBody();
			$this->decodeQuotedPrintableBodies();
			$this->dkimVerificationResults = DKIMVerify::validateEnvelope($this);

			$spfChecker = new SPF();
			$this->spfCheckResult = $spfChecker->checkHost(
				$this->socketAddress,
				$this->getDataHeader("from")['domain']
			);
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
			$stringified .= "+ DATA headers: \n" . json_encode($this->dataHeaders) . "\n";
			$stringified .= "+ parsed DATA headers: \n" . json_encode($this->parsedDataHeaders) . "\n";
			$stringified .= "+ Raw body: \n" . $this->rawBody . "\n";
			$stringified .= "+ parsed body: \n" . $this->body . "\n";
			$stringified .= "+ Parsed Multiparts (" . count($this->multiparts) . "): \n";

			foreach($this->multiparts as $part){
				$stringified .= (string)$part;
			}

			return $stringified;
		}

	}
