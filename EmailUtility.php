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
	}
