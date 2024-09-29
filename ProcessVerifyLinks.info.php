<?php namespace ProcessWire;

$info = array(
	'title' => 'Verify Links: Process module',
	'summary' => 'Process module for Verify Links',
	'version' => '0.3.2',
	'author' => 'Robin Sallis',
	'href' => 'https://github.com/Toutouwai/VerifyLinks',
	'icon' => 'link',
	'requires' => 'ProcessWire>=3.0.216, PHP>=7.4.0, VerifyLinks',
	'page' => array(
		'name' => 'verify-links',
		'title' => 'Verify Links',
		'parent' => 'setup',
	),
	'permission' => 'verify-links',
	'permissions' => array(
		'verify-links' => 'Use the Verify Links module'
	),
	'useNavJSON' => true,
);
