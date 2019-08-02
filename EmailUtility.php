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
						// A pair of quotes may be surrounding the name
						if ($state === "READING NAME"){
							// This is fine, ignore it and do not consume
						}else{
							// ERROR
							throw new Exception();
						}
					}elseif ($currentCharacter === "@"){
						// Begin parsing domain
						if ($state === "READING ACCOUNT"){
							$state = "READING DOMAIN";
						}elseif ($state === "READING NAME"){
							// The lexer thought it was consuming a name - it was actually an account
							// This happens on formats such as : example@example.com
							// Which are not marked by <>'s
							$parsedAddress['account'] = $parsedAddress['name'];
							$parsedAddress['name'] = "";
							$state = "READING DOMAIN";
						}else{
							// ERROR
							throw new Exception();
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
		* Parses a Content-Type header into an array
		*
		* @param string $contentTypeHeaderValue
		* @return array
		* @throws Exception
		*/
		public static function parseContentType(string $contentTypeHeaderValue){
			$position = 0;
			$state = "READING CONTENT-TYPE";
			$currentKey = "content-type";

			$contentType = [
				"content-type"=>"",
			];

			while(1){
				$currentCharacter = mb_substr($contentTypeHeaderValue, $position, 1);

				if ($currentCharacter === "" || $currentCharacter === false){
					break;
				}else{
					if ($currentCharacter === ";"){
						if ($state === "READING CONTENT-TYPE"){
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
							throw new Exception();
						}
					}elseif ($currentCharacter === " " && $state === "READING VALUE"){
						// Found a space when reading a value and it is not a STRING VALUE,
						// Ignore it
					}else{
						if ($state === "READING NEXT KEY"){
							if ($currentCharacter != " "){
								$currentKey .= $currentCharacter;
							}
						}elseif ($state === "READING VALUE"){
							if ($currentCharacter === '"'){
								// Beginning quote hit when expecting a value, switch to reading a string value
								$state = "READING STRING VALUE";
							}else{
								$contentType[$currentKey] .= $currentCharacter;
							}
						}elseif ($state === "READING STRING VALUE"){
							$contentType[$currentKey] .= $currentCharacter;
						}elseif ($state === "READING CONTENT-TYPE"){
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
	}
