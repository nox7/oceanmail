<?php
	/**
	* Class SPF
	*/

	require_once __DIR__ . "/Debug.php";
	require_once __DIR__ . "/IPUtils.php";
	require_once __DIR__ . "/exceptions/DNSLookupFailed.php";

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
		* Will check if the $senderIP is allowed to send mail on behalf of $domain
		*
		* Can return "none", "neutral", "pass", "fail", "softfail", "temperror", or "permerror"
		* - None = No syntatically valid DNS domain name was extracted from the Envelope or no SPF records existed
		* - Neutral = No explicit statement that the sender is authorized or disallowed
		* - Pass = explicit statement the senderIP can send on behalf of domain
		* - Fail = explicit statement the senderIP is not authorized in any way on behalf of the domain
		* - Softfail = Results from a 'weak' statement that the senderIP is 'probably' not authorized, but no explicit fail was found
		* - Temperror = Usually a DNS lookup probably on the server checking the host (using OceanMail). A later retry may succeed
		* - PermError = The DNS records found have some sort of error in their published syntax and the DNS manager of the $domain needs to intervene manually
		*
		* @param string $senderIP
		* @param string $domain
		* @param bool $isRecursion Signifies if the current call of checkHost() is a recursion
		* @return string
		*/
		public function checkHost(string $senderIP, string $domain, bool $isRecursion = false){

			// The question-verbiage this function solves is: "can $senderIP send on behalf of $domain?"

			Debug::log("SPF checkHost receied args: $senderIP | $domain", Debug::DEBUG_LEVEL_LOW);

			// Whether or not the sender is an IPv6
			$senderIsIPv6 = substr_count($senderIP, ':') > 1;

			// This signals a failure
			if (!$isRecursion){
				$dotAppendedDomain = $domain . ".";
				putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
				$ipv4FromDomain = gethostbyname($dotAppendedDomain);
				if ($ipv4FromDomain === $dotAppendedDomain){
					Debug::log("Could not get IP from domain: $ipv4FromDomain ($dotAppendedDomain)", Debug::DEBUG_LEVEL_LOW);
					return "none";
				}
			}

			$spfRecord = self::getSPFRecord($domain);

			Debug::log("Raw SPF record: " . $spfRecord, Debug::DEBUG_LEVEL_LOW);

			$spfTerms = self::parseSPFString($spfRecord);

			$domainIsAllowed = false; // Whether or not this function determined the senderIP can explicitly send on behalf of domain

			print(var_dump($spfTerms));

			foreach($spfTerms as $term){
				$termName = trim($term['name']);
				Debug::log("Iterating SPF term: " . $termName, Debug::DEBUG_LEVEL_LOW);
				if ($term['type'] === "mechanism"){
					$termQualifier = $term['qualifier'];
					$termValue = trim($term['value']);
					if ($termName === "include"){
						if ($termValue !== ""){
							// Recursively check host and try to find a matching IPv4 in this include
							$result = $this->checkHost($senderIP, $termValue, true);
							if ($result === "pass"){
								// Found a result that validates

								// TODO Because "include" is a modifier, I believe it should check the qualifier and if the $result is a pass, then the qualifier will determine the "final" result.
								// Such as -include:_spf.example.com
								// ^^ if that include passes, then the qualifier of the include should be checked and a "fail" should be returned
								// Currently does not do this - is this desirable?
								return "pass";
							}
						}
					}elseif ($termName === "ip4"){
						if (IPUtils::checkIP($senderIP, $termValue)){
							Debug::log("SPF CIDR matched $termValue to sender $senderIP", Debug::DEBUG_LEVEL_LOW);
							return self::getResultStringFromQualifier($termQualifier);
						}else{
							Debug::log("IP $termValue failed to CIDR match for sender IP $senderIP", Debug::DEBUG_LEVEL_LOW);
						}
					}elseif ($termName === "ip6"){
						// Is the $senderIP even an IPv6?
						if ($senderIsIPv6){
							if (IPUtils::checkIP($senderIP, $termValue)){
								Debug::log("SPF CIDR matched $termValue to sender $senderIP", Debug::DEBUG_LEVEL_LOW);
								return self::getResultStringFromQualifier($termQualifier);
							}else{
								Debug::log("IP $termValue failed to CIDR match for sender IP $senderIP", Debug::DEBUG_LEVEL_LOW);
							}
						}
					}elseif ($termName === "a"){
						// Check against all of $domains A records
						$aRecords = self::getARecords($domain);
						foreach($aRecords as $ipRecord){
							if (IPUtils::checkIP($senderIP, $ipRecord)){
								Debug::log("SPF CIDR matched $ipRecord to sender $senderIP", Debug::DEBUG_LEVEL_LOW);
								return self::getResultStringFromQualifier($termQualifier);
							}
						}
					}elseif ($termName === "mx"){
						// Check against all of $domains A records
						$mxRecords = self::getMXRecords($domain);
						$lookupCount = 0; // How many MX domains have been looked up
						foreach($mxRecords as $mxDomain){
							// Perform A record lookups on up to 8 MX records
							if ($lookupCount < 8){
								++$lookupCount;
								$aRecords = self::getARecords($mxDomain);
								foreach($aRecords as $ipRecord){
									if (IPUtils::checkIP($senderIP, $ipRecord)){
										Debug::log("SPF CIDR matched $ipRecord to sender $senderIP", Debug::DEBUG_LEVEL_LOW);
										return self::getResultStringFromQualifier($termQualifier);
									}
								}
							}
						}
					}elseif ($termName === "ptr"){
						// RFC says no to this one
					}elseif ($termName === "all"){
						// When encountering "all" the loop should break no matter what
						Debug::log("Hit `all` without a match, sending check result: " . self::getResultStringFromQualifier($termQualifier), Debug::DEBUG_LEVEL_LOW);
						return self::getResultStringFromQualifier($termQualifier);
					}
				}elseif ($term['type'] === "modifier"){
					if ($termName === "redirect"){
						// Basically the same as include: - but processed at the end and before all
						if ($termValue !== ""){
							// Recursively check host and try to find a matching IPv4 in this include
							$result = $this->checkHost($senderIP, $termValue, true);
							if ($result === "pass"){
								// Found a result that validates
								return "pass";
							}
						}
					}
				}
			}

			return "none";
		}

		/**
		* Returns a result string based on the qualifier
		*
		* @param string $qualifier
		* @return string
		*/
		public static function getResultStringFromQualifier(string $qualifier){
			if ($qualifier === "+"){
				return "pass";
			}elseif ($qualifier === "-"){
				return "fail";
			}elseif ($qualifier === "~"){
				return "softfail";
			}elseif ($qualifier === "?"){
				return "neutral";
			}
		}

		/**
		* Obtains the raw text SPF record from a DNS lookup
		*
		* @param string $domain
		* @throws DNSLookupFailed
		* @return string Will be "" if no record found
		*/
		private static function getSPFRecord(string $domain){
			$txtRecord = dns_get_record($domain, DNS_TXT);

			if ($txtRecord === false){
				Debug::log("Failed to fetch DNS TXT record: $domain", Debug::DEBUG_LEVEL_LOW);
				throw new DNSLookupFailed;
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
		* Obtains all A records
		*
		* @param string $domain
		* @throws DNSLookupFailed
		* @return string[]
		*/
		private static function getARecords(string $domain){
			$aRecords = dns_get_record($domain, DNS_A);
			$collection = [];

			if ($aRecords === false){
				Debug::log("Failed to fetch DNS A records: $domain", Debug::DEBUG_LEVEL_LOW);
				throw new DNSLookupFailed;
			}

			foreach($aRecords as $record){
				if (isset($record['entries'])){
					foreach($record['entries'] as $aRecordEntry){
						$collection[] = $aRecordEntry;
					}
				}
			}

			return $collection;
		}

		/**
		* Obtains all MX records
		*
		* @param string $domain
		* @throws DNSLookupFailed
		* @return string[]
		*/
		private static function getMXRecords(string $domain){
			$mxRecords = dns_get_record($domain, DNS_MX);
			$collection = [];

			if ($mxRecords === false){
				Debug::log("Failed to fetch DNS MX records: $domain", Debug::DEBUG_LEVEL_LOW);
				throw new DNSLookupFailed;
			}

			foreach($mxRecords as $record){
				if (isset($record['entries'])){
					foreach($record['entries'] as $mxRecordEntry){
						$collection[] = $mxRecordEntry;
					}
				}
			}

			return $collection;
		}

		/**
		* Provides an array of directives and modifiers from a raw SPF text record
		*
		* Mechanism and modifier names are all converted to lowercase here
		* TODO: Handle macro instances %{x} defined by RFC 7208 at https://tools.ietf.org/html/rfc7208#page-32
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
					$qualifier = "+";
					$mechanismName = "";

					if (in_array($firstCharacter, ["+", "-", "?", "~"])){
						$qualifier = $firstCharacter;
						$mechanismName = substr($part, 1);
					}else{
						$mechanismName = $part;
					}

					$spfTerms[] = [
						"type"=>"mechanism",
						"qualifier"=>$qualifier,
						"name"=>strtolower($mechanismName),
						"value"=>"",
					];

				}else{
					if (strpos($part, "=") === false){
						// No = sign, but there is a : or /
						// This is a mechanism

						// Does it have a qualifier?
						$qualifier = "+";
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
							"name"=>strtolower($mechanismName),
							"value"=>$mechanismValue,
						];

					}else{
						// This is a modifier
						list($modifierName, $modifierValue) = explode("=", $part);
						$spfTerms[] = [
							"type"=>"modifier",
							"name"=>strtolower($modifierName),
							"value"=>$modifierValue,
						];
					}
				}
			}

			return $spfTerms;
		}

		/**
		* Performs a CIDR check on an $ip against an IP $range that contains a subnet mask
		*
		* This will also return true if two IP addresses are simply equal
		*
		* @param string $ip
		* @param string $range
		* @return bool
		* @see https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5
		*/
		public static function checkCIDRMatch(string $ip, string $range){
			if (strpos($range, "/") !== false){
				list($subnet, $bits) = explode('/', $range);
				if ($bits === null || $bits === "") {
					$bits = 32;
				}
			}else{
				$bits = 32;
				$subnet = &$range;
			}
			$ip = ip2long($ip);
			$subnet = ip2long($subnet);
			$mask = -1 << (32 - $bits);
			$subnet &= $mask; // nb: in case the supplied subnet wasn't correctly aligned
			return ($ip & $mask) == $subnet;
		}

	}
