<?php
	/**
	* Class SPF
	*/

	require_once __DIR__ . "/Debug.php";

	/**
	* Processing class for handling intricacies of validating an spf (Sender Policy Framework) text record
	*
	* SPF defines which hosts are, and are not, authorized to use a domain name for the HELO and MAIL FROM commands. Multiple SPF records are not permitted under the same hostname.
	*/
	class SPF{

		/** @var int The limit to the number of DNS lookups that can be performed */
		const DNS_LOOKUP_LIMIT = 10;

		/** @var int The current number of DNS lookups performed */
		private $numDNSLookups = 0;

		/**
		*
		*
		*
		*
		*/
		public function checkHost(string $ip, string $domain, string $sender){

			// The question-verbiage this function solves is: "can $sender send on behalf of $domain?"

			Debug::log("SPF checkHost receied args: $ip | $domain | $sender", Debug::DEBUG_LEVEL_LOW);
			$dotAppendedDomain = $domain . ".";

			putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
			$ipv4FromDomain = gethostbyname($dotAppendedDomain);

			// This signals a failure
			if ($ipv4FromDomain === $dotAppendedDomain){
				Debug::log("Could not get IP from domain: $ipv4FromDomain ($dotAppendedDomain)", Debug::DEBUG_LEVEL_LOW);
				return "none";
			}

			$spfRecord = self::getSPFRecord($domain);
			$spfTerms = self::parseSPFString($spfRecord);

			print(var_dump($spfTerms));
		}

		/**
		* Obtains the raw text SPF record from a DNS lookup
		*
		* @param string $domain
		* @return string Will be "" if no record found
		*/
		private static function getSPFRecord(string $domain){
			$txtRecord = dns_get_record($domain, DNS_TXT);

			if ($txtRecord === false){
				Debug::log("Failed to fetch DNS TXT record: $domain", Debug::DEBUG_LEVEL_LOW);
				return "tempfail";
			}

			foreach($txtRecord as $record){
				if (isset($record['entries'])){
					foreach($record['entries'] as $txtRecordEntry){
						$loweredEntry = mb_strtolower($txtRecordEntry);
						if (strpos($loweredEntry, "v=spf") !== false){
							return $loweredEntry;
						}
					}
				}
			}

			return "";
		}

		/**
		* Provides an array of directives and modifiers from a raw SPF text record
		*
		* @param string $spfRecord
		* @return string[]
		*/
		private static function parseSPFString(string $spfRecord){
			// Remove the version
			$spfRecord = str_replace("v=spf1 ", "", $spfRecord);

			$parts = explode(" ", $spfRecord);
			$spfTerms = []; // Will contain arrays of parsed parts

			foreach($parts as $part){
				Debug::log("Parsing SPF part: $part", Debug::DEBUG_LEVEL_LOW);

				$firstCharacter = mb_substr($part, 0, 1);
				if (strpos($part, ":") === false && strpos($part, "=") === false && strpos($part, "/") === false){
					// Terms that do not contain these characters are mechanisms

					// Does it have a qualifier?
					$qualifier = "";
					$mechanismName = "";

					if (in_array($firstCharacter, ["+", "-", "?", "~"])){
						$qualifier = $firstCharacter;
						$mechanismName = substr($part, 1);
					}else{
						$mechanismName = &$part;
					}

					$spfTerms[] = [
						"type"=>"mechanism",
						"qualifier"=>$qualifier,
						"name"=>$mechanismName,
						"value"=>"",
					];

				}else{
					if (strpos($part, "=") === false){
						// No = sign, but there is a : or /
						// This is a mechanism

						// Does it have a qualifier?
						$qualifier = "";
						$mechanismName = "";
						$mechanismValue = "";

						if (in_array($firstCharacter, ["+", "-", "?", "~"])){
							$qualifier = $firstCharacter;

							if (strpos($part, ":") !== false){
								list($mechanismName, $mechanismValue) = explode(":", $substr($part, 1));
							}else{
								list($mechanismName, $mechanismValue) = explode("/", $substr($part, 1));
							}
						}else{
							if (strpos($part, ":") !== false){
								list($mechanismName, $mechanismValue) = explode(":", $part);
							}else{
								list($mechanismName, $mechanismValue) = explode("/", $part);
							}
						}

						$spfTerms[] = [
							"type"=>"mechanism",
							"qualifier"=>$qualifier,
							"name"=>$mechanismName,
							"value"=>$mechanismValue,
						];

					}else{
						// This is a modifier
						list($modifierName, $modifierValue) = explode("=", $part);
						$spfTerms[] = [
							"type"=>"modifier",
							"name"=>$modifierName,
							"value"=>$modifierValue,
						];
					}
				}
			}

			return $spfTerms;
		}

	}
