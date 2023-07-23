<?php namespace ProcessWire;

class VerifyLinks extends WireData implements Module, ConfigurableModule {

	/**
	 * Construct
	 */
	public function __construct() {
		parent::__construct();
		$this->lazycron_frequency = 'everyHour';
		$this->links_per_cron = 5;
		$this->timeout = 30;
	}

	/**
	 * Ready
	 */
	public function ready() {
		if($this->lazycron_frequency) {
			$this->addHookAfter("LazyCron::{$this->lazycron_frequency}", $this, 'verifyLinks');
		}
		$this->pages->addHookAfter('savePageOrFieldReady', $this, 'afterSaveReady');
		$this->pages->addHookBefore('delete', $this, 'beforeDelete');
	}

	/**
	 * Verify links
	 *
	 * @param HookEvent $event
	 */
	public function verifyLinks(HookEvent $event) {
		if(!$this->links_per_cron) return;
		$database = $this->wire()->database;
		$sql = "SELECT url FROM verify_links ORDER BY checked LIMIT $this->links_per_cron";
		$query = $database->prepare($sql);
		$query->execute();
		$urls = $query->fetchAll(\PDO::FETCH_COLUMN);
		if(!$urls) return;

		$results = [];
		$multi = curl_multi_init();
		$handles = [];
		foreach($urls as $i => $url) {
			$handles[$i] = curl_init($url);
			curl_setopt($handles[$i], CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handles[$i], CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($handles[$i], CURLOPT_NOBODY, true);
			curl_setopt($handles[$i], CURLOPT_TIMEOUT, $this->timeout);
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
		// Return early for Repeater pages because these are handled by the root container page
		if($page instanceof RepeaterPage) return;
		$database = $this->wire()->database;

		// Add/remove links in database
		$links = $this->extractLinksFromPage($page);
		if($links) {
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
				$sql = "DELETE FROM verify_links WHERE url IN ($urls)";
				$database->exec($sql);
			}
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
			$fieldname = $field->name;
			switch(true) {

				// URL fields
				case ($field->type == 'FieldtypeURL'):
					if(!$this->allowField($field, $page)) break;
					$url = $page->$fieldname;
					if(!$url || !$this->isValidLink($url)) break;
					$links[$url] = $url;
					break;

				// HTML fields
				case ($field->type == 'FieldtypeTextarea' && $field->contentType === 1):
					if(!$this->allowField($field, $page)) break;
					$html = $page->$fieldname;
					if(!$html) break;
					$links = array_merge($links, $this->extractHtmlLinks($html));
					break;

				// ProFields Table fields
				case ($field->type == 'FieldtypeTable'):
					$columns = $field->type->getColumns($field);
					$url_columns = [];
					$html_columns = [];
					foreach($columns as $column) {
						if($column['type'] === 'url') $url_columns[] = $column['name'];
						if($column['type'] === 'textareaCKE') $html_columns[] = $column['name'];
						if($column['type'] === 'textareaMCE') $html_columns[] = $column['name'];
					}
					if(!$url_columns && ! $html_columns) break;
					if(!$this->allowField($field, $page)) break;
					foreach($page->$fieldname as $row) {
						foreach($url_columns as $name) {
							$url = $row->$name;
							if(!$url || !$this->isValidLink($url)) continue;
							$links[$url] = $url;
						}
						foreach($html_columns as $name) {
							$html = $row->$name;
							if(!$html) continue;
							$links = array_merge($links, $this->extractHtmlLinks($html));
						}
					}
					break;

				// ProFields Multiplier fields
				case ($field->type == 'FieldtypeMultiplier'):
					if($field->fieldtypeClass !== 'FieldtypeURL') break;
					if(!$this->allowField($field, $page)) break;
					foreach($page->$fieldname as $url) {
						if(!$url || !$this->isValidLink($url)) continue;
						$links[$url] = $url;
					}
					break;

				// ProFields Combo fields
				case ($field->type == 'FieldtypeCombo'):
					$subfields = $field->getComboSettings()->getSubfields();
					$url_subfields = [];
					$html_subfields = [];
					foreach($subfields as $subfield) {
						if($subfield->type === 'URL') $url_subfields[] = $subfield->name;
						if($subfield->type === 'CKEditor') $html_subfields[] = $subfield->name;
						if($subfield->type === 'TinyMCE') $html_subfields[] = $subfield->name;
					}
					if(!$url_subfields && ! $html_subfields) break;
					if(!$this->allowField($field, $page)) break;
					foreach($url_subfields as $name) {
						$url = $page->$fieldname->$name;
						if(!$url || !$this->isValidLink($url)) continue;
						$links[$url] = $url;
					}
					foreach($html_subfields as $name) {
						$html = $page->$fieldname->$name;
						if(!$html) continue;
						$links = array_merge($links, $this->extractHtmlLinks($html));
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
	 * Allow URLs to be extracted from this field?
	 *
	 * @param Field $field
	 * @param Page $page
	 * @return bool
	 */
	public function ___allowField($field, $page) {
		return true;
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
		// URL must be external
		if($valid && strpos($url, $this->wire()->config->urls->httpRoot) === 0) $valid = false;
		return $valid;
	}

	/**
	 * Extract external links from the supplied HTML string
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
	checked TIMESTAMP,
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

		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f_name = 'lazycron_frequency';
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
		$inputfields->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'links_per_cron';
		$f->name = $f_name;
		$f->label = $this->_('Number of links to verify during each LazyCron execution');
		$f->inputType = 'number';
		$f->min = 0;
		$f->value = $this->$f_name;
		$inputfields->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'timeout';
		$f->name = $f_name;
		$f->label = $this->_('Timeout for each link verification (seconds)');
		$f->inputType = 'number';
		$f->min = 0;
		$f->value = $this->$f_name;
		$inputfields->add($f);
	}

}
