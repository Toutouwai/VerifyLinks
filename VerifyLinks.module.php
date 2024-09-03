<?php namespace ProcessWire;

class VerifyLinks extends WireData implements Module, ConfigurableModule {

	/**
	 * Hookable time functions from LazyCron
	 */
	protected $timeFuncs = array(
		30 => 'every30Seconds',
		60 => 'everyMinute',
		120 => 'every2Minutes',
		180 => 'every3Minutes',
		240 => 'every4Minutes',
		300 => 'every5Minutes',
		600 => 'every10Minutes',
		900 => 'every15Minutes',
		1800 => 'every30Minutes',
		2700 => 'every45Minutes',
		3600 => 'everyHour',
		7200 => 'every2Hours',
		14400 => 'every4Hours',
		21600 => 'every6Hours',
		43200 => 'every12Hours',
		86400 => 'everyDay',
		172800 => 'every2Days',
		345600 => 'every4Days',
		604800 => 'everyWeek',
		1209600 => 'every2Weeks',
		2419200 => 'every4Weeks',
	);

	/**
	 * Construct
	 */
	public function __construct() {
		parent::__construct();
		$this->lazyCronFrequency = 'everyHour';
		$this->linksPerCron = 10;
		$this->timeout = 30;
		$this->userAgents = <<<EOT
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36
Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Safari/605.1.15
Mozilla/5.0 (Macintosh; Intel Mac OS X 13_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Safari/605.1.15
EOT;
	}

	/**
	 * Ready
	 */
	public function ready() {
		if($this->lazyCronFrequency) {
			$this->addHookAfter("LazyCron::{$this->lazyCronFrequency}", $this, 'verifyLinksCron');
		}
		$this->pages->addHookAfter('savePageOrFieldReady', $this, 'afterSaveReady');
		$this->pages->addHookBefore('delete', $this, 'beforeDelete');
	}

	/**
	 * Verify links on LazyCron
	 *
	 * @param HookEvent $event
	 */
	public function verifyLinksCron(HookEvent $event) {
		if(!$this->linksPerCron) return;
		$this->verifyLinks($this->linksPerCron);
	}

	/**
	 * Verify links
	 *
	 * @param HookEvent $event
	 */
	public function verifyLinks($num_links) {
		if(!$num_links) $num_links = $this->linksPerCron;
		$database = $this->wire()->database;
		$sql = "SELECT url FROM verify_links ORDER BY checked LIMIT $num_links";
		$query = $database->prepare($sql);
		$query->execute();
		$urls = $query->fetchAll(\PDO::FETCH_COLUMN);
		if(!$urls) return;
		$agents = explode("\n", str_replace("\r", "", $this->userAgents));

		$results = [];
		$multi = curl_multi_init();
		$handles = [];
		foreach($urls as $i => $url) {
			$rand_key = array_rand($agents);
			$agent = $agents[$rand_key];
			$handles[$i] = curl_init($url);
			curl_setopt($handles[$i], CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handles[$i], CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($handles[$i], CURLOPT_NOBODY, true);
			curl_setopt($handles[$i], CURLOPT_TIMEOUT, $this->timeout);
			curl_setopt($handles[$i], CURLOPT_USERAGENT, $agent);
			// Needed for LinkedIn: https://stackoverflow.com/a/39021392
			curl_setopt($handles[$i], CURLOPT_ENCODING, "gzip, deflate, sdch, br");
			curl_multi_add_handle($multi, $handles[$i]);
		}
		do {
			$status = curl_multi_exec($multi, $active);
			if($active) curl_multi_select($multi);
			while(($info = curl_multi_info_read($multi)) !== false) {
				$url = curl_getinfo($info['handle'],  CURLINFO_EFFECTIVE_URL);
				$info = curl_getinfo($info['handle']);
				$results[$url] = [
					'response' => $info['http_code'],
					'redirect' => $info['redirect_url'] ?: '',
				];
			}
		} while($active && $status == CURLM_OK);
		foreach($handles as $handle) {
			curl_multi_remove_handle($multi, $handle);
		}
		curl_multi_close($multi);

		if(!$results) return;
		$responses = [];
		$redirects = [];
		$urls = '';
		foreach($results as $url => $data) {
			$urls .= "'$url', ";
			$responses[] = "WHEN url = '$url' THEN {$data['response']}";
			$redirects[] = "WHEN url = '$url' THEN '{$data['redirect']}'";
		}
		$responses_str = implode("\n", $responses);
		$redirects_str = implode("\n", $redirects);
		$urls = rtrim($urls, ', ');
		$sql = <<<EOT
UPDATE verify_links
SET response = (CASE
$responses_str
END),
redirect = (CASE
$redirects_str
END),
checked = NOW()
WHERE url IN ($urls);
EOT;
		$database->exec($sql);
	}

	/**
	 * After Pages::savePageOrFieldReady
	 *
	 * @param HookEvent $event
	 */
	protected function afterSaveReady(HookEvent $event) {
		/** @var Page $page */
		$page = $event->arguments(0);
		$database = $this->wire()->database;

		// Return if Repeater page because these are handled by the root container page
		if($page instanceof RepeaterPage) return;

		// Add/remove links in database
		$links = $this->extractLinksFromPage($page);
		$page_id = $page->id;
		$sql = "SELECT url FROM verify_links WHERE pages_id=:pages_id";
		$query = $database->prepare($sql);
		$query->bindValue(":pages_id", $page_id, \PDO::PARAM_INT);
		$query->execute();
		$existing_links = $query->fetchAll(\PDO::FETCH_COLUMN);
		$add = array_diff($links, $existing_links);
		$remove = array_diff($existing_links, $links);
		if($add) {
			$values = '';
			foreach($add as $url) $values .= "($page_id, '$url'), ";
			$values = rtrim($values, ', ');
			$sql = "INSERT INTO verify_links (pages_id, url) VALUES $values";
			$database->exec($sql);
		}
		if($remove) {
			$urls = '';
			foreach($remove as $url) $urls .= "'$url', ";
			$urls = rtrim($urls, ', ');
			$sql = "DELETE FROM verify_links WHERE url IN ($urls) AND pages_id = $page_id";
			$database->exec($sql);
		}
	}

	/**
	 * Before Pages::delete
	 *
	 * @param HookEvent $event
	 */
	protected function beforeDelete(HookEvent $event) {
		/** @var Page $page */
		$page = $event->arguments(0);
		// Return early for Repeater pages because these are handled by the root container page
		if($page instanceof RepeaterPage) return;
		$sql = "DELETE FROM verify_links WHERE pages_id = $page->id";
		$this->wire()->database->exec($sql);
	}

	/**
	 * Extract links from the supplied page
	 *
	 * @param Page $page
	 * @param array $links
	 * @return array
	 */
	public function extractLinksFromPage($page, $links = []) {
		foreach($page->fields as $field) {

			// Skip if this field is not allowed for this page
			if(!$this->allowForField($field, $page)) continue;

			$fieldname = $field->name;
			switch(true) {

				// URL fields, including FieldtypeVerifiedURL
				case ($field->type instanceof FieldtypeURL):
					$links = $this->processUrlValue($page->$fieldname, $links);
					break;

				// HTML fields
				case ($field->type instanceof FieldtypeTextarea && $field->contentType === 1):
					$links = $this->processHtmlValue($page->$fieldname, $links);
					break;

				// ProFields Table fields
				case ($field->type == 'FieldtypeTable'):
					$columns = $field->type->getColumns($field);
					$url_columns = [];
					$html_columns = [];
					foreach($columns as $column) {
						switch($column['type']) {
							case 'url':
								$url_columns[] = $column['name'];
								break;
							case 'textareaCKE':
							case 'textareaCKELanguage':
							case 'textareaMCE':
							case 'textareaMCELanguage':
								$html_columns[] = $column['name'];
								break;
						}
					}
					if(!$url_columns && ! $html_columns) break;
					foreach($page->$fieldname as $row) {
						foreach($url_columns as $name) {
							$links = $this->processUrlValue($row->$name, $links);
						}
						foreach($html_columns as $name) {
							$links = $this->processHtmlValue($row->$name, $links);
						}
					}
					break;

				// ProFields Multiplier fields
				case ($field->type == 'FieldtypeMultiplier'):
					if($field->fieldtypeClass !== 'FieldtypeURL') break;
					foreach($page->$fieldname as $value) {
						$links = $this->processUrlValue($value, $links);
					}
					break;

				// ProFields Combo fields
				case ($field->type == 'FieldtypeCombo'):
					$subfields = $field->getComboSettings()->getSubfields();
					$url_subfields = [];
					$html_subfields = [];
					foreach($subfields as $subfield) {
						switch($subfield->type) {
							case 'URL':
							case 'URL_Language':
								$url_subfields[] = $subfield->name;
								break;
							case 'CKEditor':
							case 'CKEditor_Language':
							case 'TinyMCE':
							case 'TinyMCE_Language':
								$html_subfields[] = $subfield->name;
								break;
						}
					}
					if(!$url_subfields && ! $html_subfields) break;
					foreach($url_subfields as $name) {
						$links = $this->processUrlValue($page->$fieldname->$name, $links);
					}
					foreach($html_subfields as $name) {
						$links = $this->processHtmlValue($page->$fieldname->$name, $links);
					}
					break;

				// Repeater fields
				case ($field->type instanceof FieldtypeRepeater):
					foreach($page->$fieldname as $p) {
						$links = $this->extractLinksFromPage($p, $links);
					}
					break;
			}
		}
		return $links;
	}

	/**
	 * Allow link URLs to be extracted from this field on this page?
	 *
	 * @param Field $field
	 * @param Page $page
	 * @return bool
	 */
	public function ___allowForField($field, $page) {
		return true;
	}

	/**
	 * Process a value from a URL field
	 *
	 * @param string|LanguagesPageFieldValue $value
	 * @param array $links
	 * @return array
	 */
	protected function processUrlValue($value, $links) {
		if($value instanceof LanguagesPageFieldValue || $value instanceof ComboLanguagesValue) {
			foreach($value as $url) {
				if(!$url || !$this->isValidLink($url)) break;
				$url = $this->normaliseUrl($url);
				$links[$url] = $url;
			}
		} else {
			// Cast to string in case value is VerifiedURL object
			$url = (string) $value;
			if(!$url || !$this->isValidLink($url)) return $links;
			$url = $this->normaliseUrl($url);
			$links[$url] = $url;
		}
		return $links;
	}

	/**
	 * Process a value from an HTML field
	 *
	 * @param string|LanguagesPageFieldValue $value
	 * @param array $links
	 * @return array
	 */
	protected function processHtmlValue($value, $links) {
		if(!$value) return $links;
		if($value instanceof LanguagesPageFieldValue || $value instanceof ComboLanguagesValue) {
			foreach($value as $html) {
				if(!$html) continue;
				$links = array_merge($links, $this->extractHtmlLinks($html));
			}
		} else {
			$links = array_merge($links, $this->extractHtmlLinks($value));
		}
		return $links;
	}

	/**
	 * Is this a valid link URL to be saved by this module?
	 *
	 * @param string $url
	 * @return bool
	 */
	public function ___isValidLink($url) {
		$valid = true;
		// URL must start with "http"
		if(substr($url, 0, 4) !== 'http') $valid = false;
		// URL must contain '://'
		if($valid && strpos($url, '://') === false) $valid = false;
		// URL must be external
		if($valid && strpos($url, $this->wire()->config->urls->httpRoot) === 0) $valid = false;
		return $valid;
	}

	/**
	 * Normalise the URL insofar as needed in this module
	 *
	 * @param string $url
	 * @return string
	 */
	protected function normaliseUrl($url) {
		// Add trailing slash at the end of TLD if missing
		// This is needed because cURL will otherwise add the slash and cause a DB matching problem
		if(substr_count($url, '/') < 3) {
			$position = strpos($url, '?');
			if($position !== false) {
				$url = substr_replace($url, '/', $position, 0);
			} else {
				$url .= '/';
			}
		}
		return $url;
	}

	/**
	 * Extract an array of external link URLs from the supplied HTML string
	 *
	 * @param string $html
	 * @return array
	 */
	public function ___extractHtmlLinks($html) {
		$links = [];
		$dom = new \DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
		$xpath = new \DOMXPath($dom);
		$hrefs = $xpath->evaluate('//a[@href]');
		foreach($hrefs as $href) {
			$url = $href->getAttribute('href');
			if(!$url || !$this->isValidLink($url)) continue;
			$url = $this->normaliseUrl($url);
			$links[$url] = $url;
		}
		return $links;
	}

	/**
	 * Install
	 */
	public function ___install() {
		$database = $this->wire()->database;
		$len = $database->getMaxIndexLength();
		try {
			$sql = <<< EOT
CREATE TABLE verify_links (
    id int UNSIGNED NOT NULL AUTO_INCREMENT,
    pages_id int UNSIGNED NOT NULL,
	url text,
	response int UNSIGNED,
	redirect text,
	checked TIMESTAMP DEFAULT 0,
	PRIMARY KEY (id),
	INDEX url (url ($len)),
	INDEX checked (checked)
)
EOT;
			$database->exec($sql);
		} catch(\Exception $e) {
			$this->error($e->getMessage());
		}
	}

	/**
	 * Uninstall
	 */
	public function ___uninstall() {
		try {
			$this->wire()->database->exec("DROP TABLE verify_links");
		} catch(\Exception $e) {
			$this->error($e->getMessage());
		}
	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;
		$links_per_cron = $this->linksPerCron ?: 10;

		// Get link count
		$sql = "SELECT COUNT(*) FROM verify_links";
		$query = $this->wire()->database->prepare($sql);
		$query->execute();
		$results = $query->fetchAll(\PDO::FETCH_COLUMN);
		$link_count = (int) reset($results);

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('Link verification rate');
		if($link_count) {
			$executions = (int) ceil($link_count / $links_per_cron);
			$times = array_flip($this->timeFuncs);
			$seconds = $executions * $times[$this->lazyCronFrequency];
			$timestring = $this->wire()->datetime->elapsedTimeStr(0, $seconds);
			$fs->description = sprintf($this->_('%s %s %s been detected and with the settings below all links will be verified every %s approximately.'), "**$link_count**", $this->_n('link', 'links', $link_count),  $this->_n('has', 'have', $link_count),  "**$timestring**");
		} else {
			$fs->description = $this->_('No links have been detected yet.');
		}
		$inputfields->add($fs);

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f_name = 'lazyCronFrequency';
		$f->name = $f_name;
		$f->label = $this->_('LazyCron frequency');
		$f->addOption('', $this->_('Never'));
		$f->addOption('every30Seconds', $this->_('Every 30 seconds'));
		$f->addOption('everyMinute', $this->_('Every minute'));
		$f->addOption('every2Minutes', $this->_('Every 2 minutes'));
		$f->addOption('every5Minutes', $this->_('Every 5 minutes'));
		$f->addOption('every10Minutes', $this->_('Every 10 minutes'));
		$f->addOption('every30Minutes', $this->_('Every 30 minutes'));
		$f->addOption('everyHour', $this->_('Every hour'));
		$f->addOption('every6Hours', $this->_('Every 6 hours'));
		$f->addOption('every12Hours', $this->_('Every 12 hours'));
		$f->addOption('everyDay', $this->_('Every day'));
		$f->value = $this->$f_name;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'linksPerCron';
		$f->name = $f_name;
		$f->label = $this->_('Number of links to verify during each LazyCron execution');
		$f->inputType = 'number';
		$f->min = 1;
		$f->value = $links_per_cron;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'timeout';
		$f->name = $f_name;
		$f->label = $this->_('Timeout for each link verification (seconds)');
		$f->inputType = 'number';
		$f->min = 0;
		$f->value = $this->$f_name;
		$inputfields->add($f);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f_name = 'userAgents';
		$f->name = $f_name;
		$f->label = $this->_('List of user agents');
		$f->description = $this->_('One of these will be selected at random when links are checked, which lowers the chance that the request will be blocked.');
		$f->collapsed = Inputfield::collapsedYes;
		$f->value = $this->$f_name;
		$inputfields->add($f);
	}

}
