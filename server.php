<?php
	require_once "IncomingServer.php";
	require_once "PostOffice.php";

	$poBox = new PostOffice();
	$server = new IncomingServer();
	$server->startServerLoop($poBox);
