<?php
	/**
	* IncomingServer Class
	*
	* @author Garet C. Green
	*/

	require_once __DIR__ . "/Debug.php";
	require_once __DIR__ . "/Envelope.php";
	require_once __DIR__ . "/EmailUtility.php";

	/**
	* Handles binding and accepting messages via sockets
	*/
	class IncomingServer{

		private $socket_25;
		private $currentClientSocket;
		private $currentEnvelope;
		private $smtpResponseMessages = [
			"server-identifier"=>"220 example.com ESMTP Postfix\r\n",
			"helo-response"=>"250 example.com, I am glad to meet you\r\n",
			"ok"=>"250 Ok\r\n",
			"prepare-for-data"=>"354 End data with <CR><LF>.<CR><LF>\r\n",
			"bye"=>"221 Bye\r\n",
		];

		public function __construct(){

			Debug::log("Opening a socket", Debug::DEBUG_LEVEL_LOW);
			$this->socket_25 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

			if (!socket_bind($this->socket_25, "0.0.0.0", "25")){
				Debug::log("Failed to bind socket: " . socket_strerror(socket_last_error()), Debug::DEBUG_LEVEL_HIGH);
				throw new Exception();
			}
		}

		public function startServerLoop(PostOffice $poBox){
			socket_listen($this->socket_25);

			$this->currentEnvelope = new Envelope();
			while (1){
				Debug::log("Waiting for a connection", Debug::DEBUG_LEVEL_LOW);
				$connectingSocket = socket_accept($this->socket_25);
				Debug::log("Received a remote connection", Debug::DEBUG_LEVEL_LOW);

				// Write the identification message
				if (!socket_write($connectingSocket, $this->smtpResponseMessages['server-identifier'], mb_strlen($this->smtpResponseMessages['server-identifier']))){
					Debug::log("Failed to write to socket: " . socket_strerror(socket_last_error()), Debug::DEBUG_LEVEL_HIGH);
					throw new Exception();
				}

				$this->currentClientSocket = $connectingSocket;
				Debug::log("Waiting for response from client", Debug::DEBUG_LEVEL_LOW);

				$lineBuffer = ""; // The current input buffer
				$readFromSocket = true; // Control for the loop
				$readingState = ""; // Can be blank or "READING DATA"

				while ($readFromSocket){
					$input = socket_read($this->currentClientSocket, 1);
					$lineBuffer .= $input;

					// Check if the buffer has an end-line signifer
					if (mb_substr($lineBuffer, -2, 2) == "\r\n"){
						Debug::log("Received complete line from socket: " . $lineBuffer, Debug::DEBUG_LEVEL_LOW);
						$receivedMessage = $lineBuffer; //rtrim($lineBuffer, "\r\n");
						$readFromSocket = $this->processLine($receivedMessage, $readingState);
						$lineBuffer = "";
					}else{
						// Consume the input - do nothing yet
						// TODO limit this character count? So the buffer doesn't fill up or become infinite
					}
				}

				$poBox->onMailDroppedOff($this->currentEnvelope);
				$this->currentEnvelope = null;
				$this->currentClientSocket = null;
			}
		}

		/**
		* Logically process contents of a received input line
		*
		* @param string $inputLine The input from the client socket with the ending (\r\n) removed
		* @param string &$readingState
		* @return bool Whether or not to continue reading from the client socket
		*/
		private function processLine(string $inputLine, string &$readingState){
			$loweredInput = mb_strtolower($inputLine);

			if ($readingState === ""){
				if (self::isQuitCommand($loweredInput)){
					Debug::log("QUIT received", Debug::DEBUG_LEVEL_LOW);
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['bye'], mb_strlen($this->smtpResponseMessages['bye']));
					return false;
				}elseif (self::isHeloCommand($loweredInput)){
					Debug::log("HELO received", Debug::DEBUG_LEVEL_LOW);
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['helo-response'], mb_strlen($this->smtpResponseMessages['helo-response']));
				}elseif (self::isMailFromCommand($loweredInput)){
					Debug::log("MAIL FROM received", Debug::DEBUG_LEVEL_LOW);
					$value = self::getMailFromValue($inputLine);
					$fromAddress = EmailUtility::parseEmailAddress($value);
					$this->currentEnvelope->fromAddress = $fromAddress;
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['ok'], mb_strlen($this->smtpResponseMessages['ok']));
				}elseif (self::isRcptToCommand($loweredInput)){
					Debug::log("RCPT TO received", Debug::DEBUG_LEVEL_LOW);
					$value = self::getRcptToValue($inputLine);
					$addresses = explode(",", $value);
					foreach($addresses as $address){
						$toAddress = EmailUtility::parseEmailAddress($value);
						$this->currentEnvelope->recipientsAddresses[] = $toAddress;
					}
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['ok'], mb_strlen($this->smtpResponseMessages['ok']));
				}elseif (self::isDataIdentifer($loweredInput)){
					Debug::log("DATA identifier received", Debug::DEBUG_LEVEL_LOW);
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['prepare-for-data'], mb_strlen($this->smtpResponseMessages['prepare-for-data']));
					$readingState = "READING DATA HEADERS";
				}else{
					Debug::log("Unrecognized data: " . $inputLine, Debug::DEBUG_LEVEL_LOW);
					socket_close($this->currentClientSocket);
					return false;
				}
			}elseif ($readingState === "READING DATA HEADERS" || $readingState === "READING DATA BODY"){

				if ($loweredInput === ".\r\n"){
					// End of input
					Debug::log("Received end of data identifier (.)", Debug::DEBUG_LEVEL_LOW);
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['ok'], mb_strlen($this->smtpResponseMessages['ok']));
					$readingState = "";
				}else{
					if ($readingState === "READING DATA HEADERS"){
						if ($inputLine === "\r\n"){
							// Blank line, switch to reading body mode
							$readingState = "READING DATA BODY";
						}else{
							$this->currentEnvelope->rawDataHeaders .= $inputLine;
						}
					}elseif ($readingState === "READING DATA BODY"){
						$this->currentEnvelope->rawBody .= $inputLine;
					}else{
						Debug::log("DATA read state invalid", Debug::DEBUG_LEVEL_LOW);
					}
				}
			}else{
				Debug::log("Unknown reading state: $readingState", Debug::DEBUG_LEVEL_LOW);
			}

			return true;
		}

		/**
		* If a string is a QUIT command
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isQuitCommand(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 4) === "quit";
		}

		/**
		* If a string is a HELO command
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isHeloCommand(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 4) === "helo";
		}

		/**
		* If a string is a MAIL FROM command
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isMailFromCommand(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 9) === "mail from";
		}

		/**
		* Gets the value from a MAIL FROM command
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getMailFromValue(string $inputLine){
			return mb_substr($inputLine, 10);
		}

		/**
		* If a string is a RCPT TO command
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isRcptToCommand(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 7) === "rcpt to";
		}

		/**
		* Gets the value from a RCPT TO command
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getRcptToValue(string $inputLine){
			return mb_substr($inputLine, 8);
		}

		/**
		* If a string is a DATA identifying the client is ready to send the body data
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataIdentifer(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 4) === "data";
		}

		/**
		* If a string is a boundary identifier (beginning a muiltipart part) in the DATA body
		*
		* @param string $inputLine
		* @param string $boundary
		* @return bool
		*/
		private static function isDataHeader_MultipartBoundary(string $inputLine, string $boundary){
			return $inputLine === "--$boundary";
		}

		/**
		* If a string is a boundary END identifier (ending a muiltipart part) in the DATA body
		*
		* @param string $inputLine
		* @param string $boundary
		* @return bool
		*/
		private static function isDataHeader_MultipartBoundaryEnd(string $inputLine, string $boundary){
			return $inputLine === "--$boundary--";
		}
	}
