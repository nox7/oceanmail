<?php
	// // Send basic text
	// mail("test@localhost.com", "Test Email", "Content", [
	// 	"From"=>"test@example.com",
	// 	"Return-Path"=>"garet@footbridgemedia.com",
	// 	"Content-Type"=>"text/html; charset=UTF-8;",
	// ]);

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
	$message .= $eol;
	$message .= "<strong>Html text</strong>";
	$message .= $eol;
	$message .= $eol . $endBoundary . $eol;

	mail("test@localhost.com", "Test Email", $message, [
		"From"=>"test@example.com",
		"Return-Path"=>"garet@footbridgemedia.com",
		"Content-Type"=>"multipart/mixed; charset=UTF-8; boundary=\"$boundary\"",
	]);
