<?php
	mail("test@localhost.com", "Test Email", "Content", [
		"From"=>"test@example.com",
		"Return-Path"=>"garet@footbridgemedia.com"
	]);
