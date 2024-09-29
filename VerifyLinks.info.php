<?php namespace ProcessWire;

$info = array(
	'title' => 'Verify Links',
	'summary' => 'Periodically verifies that external links are working and not leading to an error page.',
	'version' => '0.3.1',
	'author' => 'Robin Sallis',
	'href' => 'https://github.com/Toutouwai/VerifyLinks',
	'icon' => 'link',
	'autoload' => true,
	'requires' => 'ProcessWire>=3.0.216, PHP>=7.4.0, LazyCron, PagePaths',
	'installs' => 'ProcessVerifyLinks',
);
