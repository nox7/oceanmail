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
				Debug::log("Found DKIM signature: " . json_encode($dkimSignature), Debug::DEBUG_LEVEL_LOW);

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

				Debug::log("Query type q= found as: " . json_encode($queryTypeData), Debug::DEBUG_LEVEL_LOW);

				// DNS query
				if ($queryTypeData[0] === "dns"){
					Debug::log("Query type is DNS", Debug::DEBUG_LEVEL_LOW);
					if ($queryTypeData[1] === "txt"){
						Debug::log("Query type is TXT", Debug::DEBUG_LEVEL_LOW);
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

				// Fetch the headers that are signed in the DKIM's b=
				$signedHeaders = explode(":", $dkimSignature['h']);
				Debug::log("DKIM-Signature has told the system that these headers are signed: " . json_encode($signedHeaders), Debug::DEBUG_LEVEL_LOW);

				// Make an array of the signed header names but in lowered context for comparison (RFC requirement)
				$signedHeaders_loweredHeaders = [];
				foreach($signedHeaders as $hName){
					$signedHeaders_loweredHeaders[] = mb_strtolower($hName);
				}

				// Canonicalize the headers
				$canonicalizedHeader = "";
				if ($headerCanonicalizeType === "simple"){

					// The canonicalized headers must be in the order of signedHeaders, so this weird method was invented
					foreach($signedHeaders_loweredHeaders as $neededLoweredHeaderName){
						foreach($mail->dataHeaders as $headerName=>$headerValue){
							if (mb_strtolower($headerName) === $neededLoweredHeaderName){
								$canonicalizedHeader .= sprintf("%s:%s\r\n", $headerName, rtrim($headerValue, "\r\n"));
							}
						}
					}

				}elseif ($headerCanonicalizeType === "relaxed"){
					print_r($mail->dataHeaders);
					print_r($signedHeaders_loweredHeaders);

					$signedHeaders_loweredHeaders[] = "dkim-signature";

					// The canonicalized headers must be in the order of signedHeaders, so this weird method was invented
					foreach($signedHeaders_loweredHeaders as $neededLoweredHeaderName){
						foreach($mail->dataHeaders as $headerName=>$headerValue){
							if (mb_strtolower($headerName) === $neededLoweredHeaderName){
								Debug::log("Unfolding header value ($headerName): " . $headerValue, Debug::DEBUG_LEVEL_LOW);
								$headerValue = rtrim(Emailutility::unfoldHeaderValue($headerValue));
								$headerValue = preg_replace("/[\s]{2,}/", " ", $headerValue);
								$canonicalizedHeader .= sprintf("%s:%s\r\n", $neededLoweredHeaderName, trim(rtrim($headerValue, "\r\n")));

								if ($neededLoweredHeaderName === "dkim-signature"){
									$canonicalizedHeader = preg_replace("/b=[^\r\n]+/", "b=", $canonicalizedHeader);
								}
							}
						}
					}
				}

				$canonicalizedHeader = rtrim($canonicalizedHeader, "\r\n");

				Debug::log("DKIM canonicalized header ($headerCanonicalizeType): " . $canonicalizedHeader, Debug::DEBUG_LEVEL_LOW);

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

				$hashedBody = base64_encode(hash($hashMethod, $canonicalizedBody, true));

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


				// Create a .pem version of the public key provided provided
				$pemKey = sprintf("-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----", wordwrap($publicKey, 64, "\n", true));
				Debug::log("Public PEM is: \n" . $pemKey, Debug::DEBUG_LEVEL_LOW);

				Debug::log("Base64 encoded b= is: " . json_encode($dkimSignature['b']), Debug::DEBUG_LEVEL_LOW);
				Debug::log("Base64 decoded b= is: " . json_encode(base64_decode($dkimSignature['b'])), Debug::DEBUG_LEVEL_LOW);

				$algorithm = constant("OPENSSL_ALGO_" . strtoupper($hashMethod));
				$isSignatureCorrect = openssl_verify($canonicalizedHeader, base64_decode($dkimSignature['b']), $pemKey, $algorithm);

				if ($isSignatureCorrect === 1){
					Debug::log("Header signature matched!: " . $pemKey, Debug::DEBUG_LEVEL_LOW);
				}else{
					Debug::log("Header signature did not match computed PEM signature", Debug::DEBUG_LEVEL_LOW);
					return [
						"status"=>"permfail",
						"reason"=>"Header signature could not be verified"
					];
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

			$txtRecord = $txtRecord[0];

			Debug::log("Fetched TXT record: " . json_encode($txtRecord), Debug::DEBUG_LEVEL_LOW);
			$txtEntries = EmailUtility::parseSemicolonDelimitedValue($txtRecord['txt'], "");
			Debug::log("Parsed TXT record value: " . json_encode($txtEntries), Debug::DEBUG_LEVEL_LOW);

			if (isset($txtEntries)){
				Debug::log("Found public key: " . $txtEntries['p'], Debug::DEBUG_LEVEL_LOW);
				return $txtEntries['p'];
			}else{
				return "";
			}

		}

	}
