<?php
	/**
	* Class DKIMVerify
	*/

	require_once __DIR__ . "/Debug.php";
	require_once __DIR__ . "/EmailUtility.php";

	/**
	* Utility to validate a DKIM signature of a message
	*/
	class DKIMVerify{

		/** @var string[] Keys required to be found in the DKIM signature */
		const REQUIRED_KEYS = ['v', 'a', 'b', 'bh', 'd', 'h', 's'];

		/**
		* Will determine if the mail was signed successfully with a DKIM signature
		*
		* @param Envelope $mail
		* @return array[] With keys 'status'=> and 'reason'=>
		*/
		public static function validateEnvelope(Envelope $mail){
			// Does the DKIM signature exist?
			$dkimSignature = $mail->getDataHeader("dkim-signature");
			$publicKey; // Will be an array of the _domainkey TXT record

			if (is_array($dkimSignature)){
				Debug::log("Found DKIM signature", Debug::DEBUG_LEVEL_LOW);

				// Verify that all necessary keys are in the signature
				foreach(self::REQUIRED_KEYS as $requiredKey){
					if (!isset($dkimSignature[$requiredKey])){
						Debug::log("DKIM signature missing required key: $requiredKey", Debug::DEBUG_LEVEL_MEDIUM);
						return [
							"status"=>"permfail",
							"reason"=>"Signature missing required tag: $requiredKey"
						];
					}
				}

				// Verify that the version (v) is 1 because that's really the only one anybody supports
				if ((int) $dkimSignature['v'] !== 1){
					return [
						"status"=>"permfail",
						"reason"=>"Incompatible DKIM version number"
					];
				}

				// Query the domain's public key
				$queryType = "dns/txt";

				// The DKIM can specify a different way of querying the public key
				if (isset($dkimSignature['q'])){
					$queryType = $dkimSignature['q'];
				}

				// 0th index is the query type and 1st is query format
				$queryTypeData = explode('/', $queryType);

				// DNS query
				if ($queryTypeData[0] === "dns"){
					if ($queryTypeData[1] === "txt"){
						// TXT record query
						$publicKey = self::getPublicKey($dkimSignature['d'], $dkimSignature['s']);

						// A blank string means it failed to fetch or one did not exist
						if ($publicKey === ""){
							return [
								"status"=>"permfail",
								"reason"=>"Could not fetch a public key"
							];
						}

					}else{
						return [
							"status"=>"permfail",
							"reason"=>"Unknown query type (q)"
						];
					}
				}else{
					return [
						"status"=>"permfail",
						"reason"=>"Unknown query type (q)"
					];
				}

				// Determine how to canconicalize the header and body
				list($headerCanonicalizeType, $bodyCanonicalizeType) = explode("/", $dkimSignature['c']);

				// Get the algorithm and hash from the a parameter
				list($algorithm, $hashMethod) = explode("-", $dkimSignature['a']);

				// Canonicalize the headers
				$canonicalizedHeader = "";
				if ($headerCanonicalizeType === "simple"){
					$canonicalizedHeader = $mail->rawDataHeaders;
				}elseif ($headerCanonicalizeType === "relaxed"){
					foreach($mail->dataHeaders as $header=>$value){
						$canonicalizedHeader .= sprintf("%s:%s", mb_strtolower($header), preg_replace("/[\s]{2,}/", " ", $value));
					}
				}

				Debug::log("DKIM canonicalized header ($headerCanonicalizeType): $canonicalizedHeader", Debug::DEBUG_LEVEL_LOW);

				// Canonicalize the body
				$canonicalizedBody = "";
				if ($bodyCanonicalizeType === "simple"){
					$canonicalizedBody = rtrim($mail->rawBody, "\r\n") . "\r\n";
				}elseif ($bodyCanonicalizeType === "relaxed"){

					// Must remove all whitespace at the end of every line EXCEPT for the \r\n
					$bodyLines = explode("\r\n", $mail->rawBody);
					foreach($bodyLines as $line){
						$canonicalizedBody .= rtrim($line) . "\r\n";
					}

					// If the canonicalized body is just a CRLF, then remove it
					if ($canonicalizedBody === "\r\n"){
						$canonicalizedBody = "";
					}else{
						// Trim all \r\n from the body and then append one (to make sure only one \r\n ends the body)
						$canonicalizedBody = rtrim($canonicalizedBody, "\r\n") . "\r\n";
					}
				}

				Debug::log("DKIM canonicalized body ($bodyCanonicalizeType): $canonicalizedBody", Debug::DEBUG_LEVEL_LOW);

				$hashedBody = hash($hashMethod, $canonicalizedBody);

				Debug::log("Hashed body: $hashedBody", Debug::DEBUG_LEVEL_LOW);
				Debug::log("DKIM signature hashed body: " . $dkimSignature['bh'], Debug::DEBUG_LEVEL_LOW);

				if ($dkimSignature['bh'] !== $hashedBody){
					return [
						"status"=>"permfail",
						"reason"=>"Computed body hash does not match DKIM bh parameter"
					];
				}else{
					Debug::log("DKIM body hashes match!", Debug::DEBUG_LEVEL_LOW);
				}

			}else{
				Debug::log("No DKIM signature: $dkimSignature", Debug::DEBUG_LEVEL_LOW);
			}
		}

		/**
		* Fetches the public key for the DKIM signature
		*
		* @param string $domain as defined by the DKIM d parameter
		* @param string $subdomain as defined by the DKIM s parameter
		* @return string Will be empty if it failed or one does not exist
		*/
		private static function getPublicKey(string $domain, string $subdomain){
			$fqd = sprintf('%s._domainkey.%s', $subdomain, $domain);
			$txtRecord = dns_get_record($fqd, DNS_TXT);

			if ($txtRecord === false){
				return "";
			}

			if (isset($txtRecord['entries'])){
				$txtRecord['txt'] = implode("", $txtRecord['entries']);
				$txtRecord = EmailUtility::parseSemicolonDelimitedValue($txtRecord['txt']);
			}
		}

	}
