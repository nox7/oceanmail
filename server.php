<?php
	require_once "src/IncomingServer.php";
	require_once "PostOffice.php";

	$poBox = new PostOffice();
	$server = new IncomingServer();
	$server->startServerLoop($poBox);
