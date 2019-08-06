<?php
	/**
	* Class EmailUtility
	*
	* @author Garet C. Green
	*/

	/**
	* Utilities for handling/parsing/interpretting email-related data
	*/
	class EmailUtility{

		/**
		* Parses an email address
		*
		* @param string $address The address to be parsed. Eg, "<example@something.com>, Name <example@something.com>"
		* @return array
		* @throws Exception
		*/
		public static function parseEmailAddress(string $address){
			$position = 0;
			$isInQuotesFlag = false; // Whether or not the parser is inside quotations
			$state = "READING NAME";
			$parsedAddress = [
				"name"=>"",
				"account"=>"",
				"domain"=>"",
			];

			while (1){
				$currentCharacter = mb_substr($address, $position, 1);
				if ($currentCharacter === "" || $currentCharacter === false){
					break;
				}else{

					// State changer checks
					if ($currentCharacter === "<"){
						// Begin parsing the account if in READING NAME
						if ($state === "READING NAME"){
							$state = "READING ACCOUNT";
						}else{
							// ERROR
							throw new Exception();
						}
					}elseif ($currentCharacter === "\""){

						// Toggle the flag of being in quotes
						// Do not emit this token, consume it
						if (!$isInQuotesFlag){
							$isInQuotesFlag = true;
						}else{
							$isInQuotesFlag = false;
						}

					}elseif ($currentCharacter === "@"){
						// Begin parsing domain
						if ($state === "READING ACCOUNT"){
							$state = "READING DOMAIN";
						}elseif ($state === "READING NAME"){

							// Found an @ symbol while reading the name
							if ($isInQuotesFlag){
								// The parser is inside quotation, this is still a name. Continue to consume
								$parsedAddress['name'] .= $currentCharacter;
							}else{
								// Found an @ while not in quotes but thought it was reading a name
								// This means that there never was a name and only an address
								// Swap the values AND the state
								$parsedAddress['account'] = $parsedAddress['name'];
								$parsedAddress['name'] = "";
								$state = "READING DOMAIN";
							}

						}else{
							// ERROR
							throw new Exception("Encountered @ symbol while not reading account or name");
						}
					}elseif ($currentCharacter === ">"){
						// End of address entirely
						if ($state === "READING DOMAIN"){
							$state = "";
							break;
						}else{
							// ERROR
							throw new Exception();
						}
					}elseif ($currentCharacter === "\r" || $currentCharacter === "\n"){
						// Ignore these
					}elseif ($currentCharacter === " "){
						// A space character should only be emitted when in quotes, otherwise it is consume
						if ($isInQuotesFlag){
							// Additionally, it should only be consumed for a name
							if ($state === "READING NAME"){
								$parsedAddress['name'] .= $currentCharacter;
							}
						}else{
							// Ignored
						}
					}else{
						// Character consumption
						if ($state === "READING NAME"){
							$parsedAddress['name'] .= $currentCharacter;
						}elseif ($state === "READING ACCOUNT"){
							$parsedAddress['account'] .= $currentCharacter;
						}elseif ($state === "READING DOMAIN"){
							$parsedAddress['domain'] .= $currentCharacter;
						}
					}
				}

				++$position;
			}

			$parsedAddress['email'] = sprintf("%s@%s", $parsedAddress['account'], $parsedAddress['domain']);

			return $parsedAddress;
		}

		/**
		* Handles values that can be multiple email addresses
		*
		* @return array[]
		*/
		public static function parseEmailAddressList(string $addressList){

			$addressList = explode(",", $addressList);
			$parsedList = [];

			foreach($addressList as $textualAddress){
				$textualAddress = trim($textualAddress);
				if (!empty($textualAddress)){
					$address = self::parseEmailAddress($textualAddress);
					$parsedList[] = $address;
				}
			}

			return $parsedList;
		}

		/**
		* Parses a Content-Type-like (semicolon delimited with key/value pairs following header into an array
		*
		* @param string $value
		* @param string $initialKey The initial key to assign the first collected value as
		* @return array
		* @throws Exception
		*/
		public static function parseSemicolonDelimitedValue(string $value, string $initialKey){
			$position = 0;
			$state = "READING INITIAL-KEY";
			$currentKey = $initialKey;

			$contentType = [];

			// The currentKey could be empty, signifying there is no initial key (such as with dkim-signature)
			if (!empty($currentKey)){
				$contentType[$currentKey] = "";
			}else{
				$state = "READING NEXT KEY";
			}

			while(1){
				$currentCharacter = mb_substr($value, $position, 1);

				if ($currentCharacter === "" || $currentCharacter === false){
					break;
				}else{
					if ($currentCharacter === ";"){
						if ($state === "READING INITIAL-KEY"){
							$currentKey = "";
							$state = "READING NEXT KEY";
						}elseif ($state === "READING NEXT KEY"){
							// Found a ; while reading a key, ignore it
						}elseif ($state === "READING VALUE"){
							// End of that value, this is not a STRING VALUE so ; signifies the end
							$currentKey = "";
							$state = "READING NEXT KEY";
						}
					}elseif ($currentCharacter === '"' && $state === "READING STRING VALUE"){
						$state = "READING NEXT KEY";
					}elseif ($currentCharacter === "="){
						if ($state === "READING NEXT KEY"){
							$state = "READING VALUE";
							$contentType[$currentKey] = "";
						}elseif ($state === "READING VALUE"){
							$contentType[$currentKey] .= $currentCharacter;
						}else{
							throw new Exception("Unexpected = character during state: $state");
						}
					}elseif ($currentCharacter === " " && $state === "READING VALUE"){
						// Found a space when reading a value and it is not a STRING VALUE,
						// Ignore it
					}else{
						if ($state === "READING NEXT KEY"){

							// If it is not whitespace
							if (preg_match("/\s/", $currentCharacter) !== 1){
								$currentKey .= $currentCharacter;
							}
						}elseif ($state === "READING VALUE"){
							if ($currentCharacter === '"'){
								// Beginning quote hit when expecting a value, switch to reading a string value
								$state = "READING STRING VALUE";
							}else{
								// If it is not whitespace
								if (preg_match("/\s/", $currentCharacter) !== 1){
									$contentType[$currentKey] .= $currentCharacter;
								}
							}
						}elseif ($state === "READING STRING VALUE"){
							$contentType[$currentKey] .= $currentCharacter;
						}elseif ($state === "READING INITIAL-KEY"){
							$contentType[$currentKey] .= $currentCharacter;
						}
					}

				}

				++$position;
			}

			return $contentType;
		}

		/**
		* Parses a header as a key and value pair
		*
		* @param string $headerString
		* @return array
		*/
		public static function parseHeaderAsKeyValue(string $headerString){
			$state = "READING KEY";
			$key = "";
			$value = "";
			$position = 0;

			while(1){
				$currentCharacter = mb_substr($headerString, $position, 1);

				if ($currentCharacter === "" || $currentCharacter === false){
					break;
				}else{
					if ($state === "READING KEY"){
						if ($currentCharacter === ":"){
							$state = "READING VALUE";
						}else{
							$key .= $currentCharacter;
						}
					}elseif ($state === "READING VALUE"){
						if ($currentCharacter === "\n" || $currentCharacter === "\r"){
							// Ignore these, they signify the end of the header
						}else{
							$value .= $currentCharacter;
						}
					}
				}

				++$position;
			}

			return [trim($key), trim($value)];
		}

		/**
		* Unfolds a header value by removing \r\n from \r\n\s
		*
		* @param string $headerValue
		* @return string
		*/
		public static function unfoldHeaderValue(string $headerValue){
			return str_replace("\r\n ", " ", $headerValue);
		}
	}
