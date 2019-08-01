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

		public function startServerLoop(){
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
						$receivedMessage = rtrim($lineBuffer, "\r\n");
						$readFromSocket = $this->processLine($receivedMessage, $readingState);
						$lineBuffer = "";
					}else{
						// Consume the input - do nothing yet
						// TODO limit this character count? So the buffer doesn't fill up or become infinite
					}
				}

				Debug::log("Envelope created:\n" . $this->currentEnvelope, Debug::DEBUG_LEVEL_MEDIUM);
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
					$toAddress = EmailUtility::parseEmailAddress($value);
					$this->currentEnvelope->recipientsAddresses[] = $toAddress;
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['ok'], mb_strlen($this->smtpResponseMessages['ok']));
				}elseif (self::isDataIdentifer($loweredInput)){
					Debug::log("DATA identifier received", Debug::DEBUG_LEVEL_LOW);
					$value = self::getRcptToValue($inputLine);
					$toAddress = EmailUtility::parseEmailAddress($value);
					$this->currentEnvelope->recipientsAddresses[] = $toAddress;
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['prepare-for-data'], mb_strlen($this->smtpResponseMessages['prepare-for-data']));
					$readingState = "READING DATA";
				}else{
					Debug::log("Unrecognized data: " . $inputLine, Debug::DEBUG_LEVEL_LOW);
					socket_close($this->currentClientSocket);
					return false;
				}
			}elseif ($readingState === "READING DATA"){
				if ($loweredInput === "."){
					// End of input
					Debug::log("Received end of data identifier (.)", Debug::DEBUG_LEVEL_LOW);
					socket_write($this->currentClientSocket, $this->smtpResponseMessages['ok'], mb_strlen($this->smtpResponseMessages['ok']));
					$readingState = "";
				}else{
					if (self::isDataHeader_From($loweredInput)){
						Debug::log("Received DATA From header", Debug::DEBUG_LEVEL_LOW);
						$value = self::getDataHeader_From($inputLine);
						$fromAddress = EmailUtility::parseEmailAddress($value);
						$this->currentEnvelope->fromAddress_Data = $fromAddress;
					}elseif (self::isDataHeader_To($loweredInput)){
						Debug::log("Received DATA To header", Debug::DEBUG_LEVEL_LOW);
						$value = self::getDataHeader_To($inputLine);
						$toAddress = EmailUtility::parseEmailAddress($value);
						$this->currentEnvelope->toAddresses_Data[] = $toAddress;
					}elseif (self::isDataHeader_ReturnPath($loweredInput)){
						Debug::log("Received DATA Return-Path header", Debug::DEBUG_LEVEL_LOW);
						$value = self::getDataHeader_ReturnPath($inputLine);
						$returnPathAddress = EmailUtility::parseEmailAddress($value);
						$this->currentEnvelope->returnPathAddress = $returnPathAddress;
					}elseif (self::isDataHeader_Date($loweredInput)){
						Debug::log("Received DATA Date header", Debug::DEBUG_LEVEL_LOW);
						$value = self::getDataHeader_Date($inputLine);
						$this->currentEnvelope->dateTime = $value;
					}elseif (self::isDataHeader_Subject($loweredInput)){
						Debug::log("Received DATA Subject header", Debug::DEBUG_LEVEL_LOW);
						$value = self::getDataHeader_Subject($inputLine);
						$this->currentEnvelope->subject = $value;
					}else{
						Debug::log("Received DATA body content", Debug::DEBUG_LEVEL_LOW);
						$this->currentEnvelope->body .= $inputLine;
					}
				}
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
		* If a string is part of the DATA with a From header
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataHeader_From(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 4) === "from";
		}

		/**
		* Gets the value of a From header in the DATA body
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getDataHeader_From(string $inputLine){
			return ltrim(mb_substr($inputLine, 5));
		}

		/**
		* If a string is part of the DATA with a To header
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataHeader_To(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 2) === "to";
		}

		/**
		* Gets the value of a To header in the DATA body
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getDataHeader_To(string $inputLine){
			return ltrim(mb_substr($inputLine, 3));
		}

		/**
		* If a string is part of the DATA with a Return-Path header
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataHeader_ReturnPath(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 11) === "return-path";
		}

		/**
		* Gets the value of a Return-Path header in the DATA body
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getDataHeader_ReturnPath(string $inputLine){
			return ltrim(mb_substr($inputLine, 12));
		}

		/**
		* If a string is part of the DATA with a Date header
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataHeader_Date(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 4) === "date";
		}

		/**
		* Gets the value of a Date header in the DATA body
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getDataHeader_Date(string $inputLine){
			return ltrim(mb_substr($inputLine, 5));
		}

		/**
		* If a string is part of the DATA with a Subject header
		*
		* @param string $inputLine
		* @return bool
		*/
		private static function isDataHeader_Subject(string $inputLine){
			$inputLine = mb_strtolower($inputLine);

			return mb_substr($inputLine, 0, 7) === "subject";
		}

		/**
		* Gets the value of a Subject header in the DATA body
		*
		* @param string $inputLine This line must not be provided lowered
		* @return string
		*/
		private static function getDataHeader_Subject(string $inputLine){
			return ltrim(mb_substr($inputLine, 8));
		}
	}
