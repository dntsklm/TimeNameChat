#!/usr/bin/php
<?php
/*
 * PHP chat server for simple networked CLI conversations
 *
 * For further description, please refer to the README.
 *
 * Copyright © 2014 Donatas Klimašauskas, GPLv3+
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published
 * by the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

define('PN', basename($argv[0], '.php')); # program name

$ip = '0.0.0.0';
$port = 4444;
$help = <<<EOF
Option		Description	Default
[-i <IPv4>]	IPv4 address	$ip
[-p <port>]	port address	$port
EOF;

function print_message($msg)
{
	echo PN . ": $msg\n";
}

function exit_error($line, $msg)
{
	print_message("error: $line: $msg");
	exit(1);
}

function exit_error_option($line, $option)
{
	exit_error($line, "invalid argument for option '$option'");
}

function exit_error_socket($line)
{
	exit_error($line, socket_strerror(socket_last_error()));
}

define('MIN', 0); # test index
define('MAX', 1); # test index
define('IPV4_QUAD_CNT', 4);

$options = [
	'i' => [ # IPv4
		'regex' => '/^\d{1,3}$/',
		'range' => [
			0,
			255,
		],
	],
	'p' => [ # port
		'regex' => '/^\d{4,5}$/',
		'range' => [
			1024,
			65535,
		],
	],
];

function is_argument_valid($option, $argument)
{
	global $options;

	foreach ($options[$option] as $test)
		if (is_string($test)) {
			if (!preg_match($test, $argument))
				return FALSE;
		} elseif (is_array($test)) {
			if ($argument < $test[MIN] || $argument > $test[MAX])
				return FALSE;
		}
	return TRUE;
}

function exit_invalid_ipv4($option, $argument)
{
	$quads = explode('.', $argument);
	if (count($quads) !== IPV4_QUAD_CNT)
		exit_error_option(__LINE__, $option);
	for ($i = 0; $i < IPV4_QUAD_CNT; $i++)
		if (!is_argument_valid($option, $quads[$i]))
			exit_error_option(__LINE__, $option);
}

function exit_invalid_port($option, $argument)
{
	if (!is_argument_valid($option, $argument))
		exit_error_option(__LINE__, $option);
}

require_once('sgetopt.php');
if (($opts = sgetopt('i:p:h', TRUE)))
	foreach ($opts as $option => $argument)
		switch ($option) {
		case 'i':
			exit_invalid_ipv4($option, $argument);
			$ip = $argument;
			break;
		case 'p':
			exit_invalid_port($option, $argument);
			$port = (int) $argument;
			break;
		case 'h':
			print("$help\n");
			exit;
		}

define('LEN', 1536); # 1.5 KiB read from socket at once; 80% of 80x24 terminal
define('FD', 0); # file descriptor index
define('NICKNAME', 1); # index
define('FOREVER', TRUE);
define('ADMIN', TRUE);
define('ADMIN_MSG', '[' . PN . ']: '); # administrative message
define('NO_TIMEOUT', NULL);

$clients = [];

if (!($server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)))
	exit_error_socket(__LINE__);
if (!socket_bind($server, $ip, $port))
	exit_error_socket(__LINE__);
if (!socket_listen($server))
	exit_error_socket(__LINE__);
if (!socket_set_nonblock($server))
	exit_error_socket(__LINE__);

$read = [$server];
$write = NULL;
$except = NULL;

function get_nickname($client)
{
	return $client[NICKNAME];
}

function get_fd($client) # file descriptor
{
	return $client[FD];
}

function air_message($msg, $admin = FALSE)
{
	global $clients;

	$adminmsg = ' ';

	if ($admin)
		$adminmsg .= ADMIN_MSG;
	if (preg_match('/[^[:print:][:blank:]]/', $msg))
		return;
	$msg = "\n" . strftime('%T') . "$adminmsg$msg\n";
	foreach (array_map('get_fd', $clients) as $fd)
		if (!socket_write($fd, $msg))
			exit_error_socket(__LINE__);
}

function set_nickname($socket, $nickname)
{
	global $clients;

	if (!preg_match('/^[a-z][\d\w-]+$/i', $nickname)) {
		if (!socket_write($socket, "Example: Name0-yes_1\nNickname: "))
			exit_error_socket(__LINE__);
	} elseif (in_array($nickname, array_map('get_nickname', $clients))) {
		if (!socket_write($socket, "Nickname is used\nNickname: "))
			exit_error_socket(__LINE__);
	} else {
		$clients[$socket][NICKNAME] = $nickname;
		air_message("$nickname entered", ADMIN);
	}
}

function remove_socket($socket, $nickname)
{
	global $clients;

	socket_close($socket);
	unset($clients[$socket]);
	if ($nickname)
		air_message("$nickname exited", ADMIN);
}

function write_server_status()
{
	global $clients;

	$connections = count($clients);
	$chatters = count(array_filter(array_map('get_nickname', $clients)));
	$watchers = $connections - $chatters;

	fprintf(STDERR, "\r%-2s\t\t%-2s\t\t%-2s", $connections, $watchers,
								$chatters);
}

function read_socket($socket)
{
	global $server, $clients;

	if ($socket === $server) {
		if (!($client = socket_accept($socket)))
			exit_error_socket(__LINE__);
		if (!socket_set_nonblock($client))
			exit_error_socket(__LINE__);
		$clients[$client][FD] = $client;
		$clients[$client][NICKNAME] = NULL;
		write_server_status();
		if (!socket_write($client, "\nWelcome to " . PN . ".\n" .
						"Use ASCII characters.\n\n" .
						"Nickname: "))
			exit_error_socket(__LINE__);
	} else {
		$nickname = $clients[$socket][NICKNAME];
		if (!($msg = @socket_read($socket, LEN))) {
			remove_socket($socket, $nickname);
			write_server_status();
			return;
		}
		$msg = rtrim($msg);
		if (!$msg) # no message received
			return;
		if ($nickname) {
			air_message("$nickname: $msg");
		} else {
			set_nickname($socket, $msg);
			write_server_status();
		}
	}
}

fwrite(STDERR, "Connections\tWatchers\tChatters\n");
write_server_status();
while (FOREVER)
	if (socket_select($read, $write, $except, NO_TIMEOUT)) {
		foreach ($read as $socket)
			read_socket($socket);
		$read = array_merge([$server], array_map('get_fd', $clients));
	} else {
		exit_error_socket(__LINE__);
	}
exit;
?>
