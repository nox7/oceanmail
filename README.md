# 📮 OceanMail - Mail Exchange Server for PHP

OceanMail is a standalone PHP-coded mail exchange server that you can run on your own Linux servers to handle incoming emails in an object-oriented way.

This project hopes to remove boundaries of handling incoming emails such as having to use a 3rd party developer-friendly software (which requires payments) or giving up and just paying for GSuite/Microsft mail.

OceanMail is aimed at developers who want to create their own inbox application or host an email server and perform custom logic (such as storing emails in an SQL database for easier management). Currently, most developers simply setup an ancient Postfix rig which revolves around confusing and outdated configuration formats. Even then, it isn't always clear how to setup exact configurations.

## ⚠ Current Restrictions
The only known restriction is one of the PHP language itself - the inability to run code asynchronously. OceanMail will always be standalone in that all you need is the necessary ports open and CLI access to run your own mail server. We are aware of Swoosh and pthreads, but we will be hoping that the Fiber RFC passes or PHP eventually gains asynchronous function calling.

## 💻 Running OceanMail
From a CLI interface, you simply run
```php
php server.php
```
Then the server loop is running. Any incoming mail will now be accepted (the default accepting port that mail clients always send to is :25).

You can also run the client.php script to test that mail is actually received when sent. Mailgun and GMail have also been tested and the server properly received and accepts mail from those applications.
```php
php client.php
```
