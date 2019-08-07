<?php

	$htmlPartMessage = "<div><strong>Hello</strong><br><div class=\"div2\"></div></div><br><div>Plain text inside of a div</div><br>";
	$htmlDoubleQuotedEncode = quoted_printable_encode($htmlPartMessage);

	// Send multipart/mixed
	$boundary = "oceanmailboundary" . time();
	$inTextBoundary = "--" . $boundary;
	$endBoundary = $inTextBoundary . "--";
	$message = "";

	$eol = "\r\n";
	$message .= $eol . $inTextBoundary . $eol;
	$message .= "Content-Type: text/plain; charset=utf-8" . $eol;
	$message .= $eol;
	$message .= "Plain text message";
	$message .= $eol;
	$message .= $eol . $inTextBoundary . $eol;
	$message .= "Content-Type: text/html; charset=utf-8" . $eol;
	$message .= "Content-Transfer-Encoding: quoted-printable" . $eol;
	$message .= $eol;
	$message .= $htmlDoubleQuotedEncode;
	$message .= $eol;
	$message .= $eol . $endBoundary . $eol;

	mail("test@localhost.com", "Test Email", $message, [
		"From"=>"\"irishiibis@gmail.com\" <test@footbridgemedia.com>",
		"Return-Path"=>"garet@lumenshield.com",
		"Content-Type"=>"multipart/mixed; charset=UTF-8; boundary=\"$boundary\"",
	]);

	// imap_mail("test@localhost.com", "Test IMAP Email", "Test Body", [
	// 	"From"=>"test@localhost.com",
	// 	"Return-Path"=>"garet@lumenshield.com",
	// ]);
