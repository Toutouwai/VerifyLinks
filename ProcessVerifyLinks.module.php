<?php namespace ProcessWire;

class ProcessVerifyLinks extends Process implements ConfigurableModule {

	/**
	 * Labels
	 */
	protected $labels = [];

	/**
	 * Construct
	 */
	public function __construct() {
		parent::__construct();
		$this->tableLimit = 25;
		// Labels
		$this->labels = [
			'page' => $this->_('Page'),
			'view' => $this->_('View'),
			'link' => $this->_('Link URL'),
			'alert' => $this->_('Alert'),
			'response' => $this->_('Code'),
			'redirect' => $this->_('Redirect'),
			'checked' => $this->_('Last checked'),
		];
	}

	/**
	 * Flyout menu
	 *
	 * @param array $options
	 * @return string
	 */
	public function ___executeNavJSON($options = []) {
		$options['add'] = false;
		$options['edit'] = '?type=error';
		$options['itemLabel'] = 'label';
		$options['items'][] = [
			'id' => 0,
			'label' => $this->_('Error responses only'),
		];
		return parent::___executeNavJSON($options);
	}

	/**
	 * Execute
	 */
	public function ___execute() {
		$database = $this->wire()->database;
		$config = $this->wire()->config;
		$info = $this->wire()->modules->getModuleInfo($this->className);
		$version = $info['version'];
		$admin_url = $config->urls->admin;
		$root = rtrim($config->urls->httpRoot, '/');
		$type = $this->wire()->input->get('type');

		if($type === 'error') {
			$this->headline($this->wire()->page->title . ': ' . $this->_('Error responses only'));
			$sql = "SELECT * FROM verify_links WHERE response>=400 OR response=0 ORDER BY response DESC";
		} else {
			$sql = "SELECT * FROM verify_links ORDER BY response DESC";
		}

		// JS config data
		$config->js($this->className, ['tableLimit' => $this->tableLimit]);

		// Load Verify Links data from database
		$stmt = $database->prepare($sql);
		$stmt->execute();
		$data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		// Exclude any error URLs that match the defined prefixes
		if($type === 'error' && $this->excludeUrls) {
			$prefixes = explode("\n", str_replace("\r", "", $this->excludeUrls));
			foreach($prefixes as $prefix) {
				$prefix = trim($prefix);
				$length = strlen($prefix);
				foreach($data as $key => $value) {
					if(substr($value['url'], 0, $length) === $prefix) {
						unset($data[$key]);
					}
				}
			}
		}

		// Get unique page IDs
		$page_ids = array_column($data, 'pages_id');
		$page_ids = array_flip(array_flip($page_ids));

		// Get page titles
		$ids = implode('|', $page_ids);
		$titles = $this->wire()->pages->findRaw("id=$ids, include=all", ['title', 'name', 'path']);

		// Load DataTables
		$config->scripts->add($config->urls->$this . "datatables/datatables.min.js?v=$version");
		$config->styles->add($config->urls->$this . "datatables/datatables.min.css?v=$version");

		// Results table
		$out = '';
		$out .= "<div id='vl-results-table'>";
		$out .= "<table id='vl-datatable' class='display' style='width:100%;'>";

		// Table header
		$out .= '<thead><tr>';
		$out .= "<th class='vl-page'>{$this->labels['page']}</th>";
		$out .= "<th class='vl-view'>{$this->labels['view']}</th>";
		$out .= "<th class='vl-link'>{$this->labels['link']}</th>";
		$out .= "<th class='vl-alert'>{$this->labels['alert']}</th>";
		$out .= "<th class='vl-response'>{$this->labels['response']}</th>";
		$out .= "<th class='vl-redirect'>{$this->labels['redirect']}</th>";
		$out .= "<th class='vl-checked'>{$this->labels['checked']}</th>";
		$out .= '</tr></thead>';

		// Table body
		$out .= '<tbody>';

		foreach($data as $item) {
			$title = $titles[$item['pages_id']]['title'] ?? $titles[$item['pages_id']]['name'] ?? $item['pages_id'];
			$path = $titles[$item['pages_id']]['path'] ?? '';
			$response = $item['response'];
			$response_class = 'default';
			$alert = '';
			switch(true) {
				case $response === 0 || $response === '0':
				case $response >= 400:
					$response_class = 'error';
					$alert = '<i class="fa fa-warning"></i>';
					break;
				case $response >= 300:
					$response_class = 'redirect';
					break;
				case $response >= 200:
					$response_class = 'okay';
					break;
			}
			$edit_link = "{$admin_url}page/edit/?id={$item['pages_id']}";
			$redirect = '';
			if($item['redirect']) $redirect = "<a href='{$item['redirect']}'>{$item['redirect']}</a>";
			$out .= "<tr class='row-$response_class'>";
			$out .= "<td class='vl-page'><a href='$edit_link' title='$path' target='_blank'>$title</a></td>";
			$out .= "<td class='vl-view'><a href='$root$path' target='_blank'>{$this->labels['view']}</a></td>";
			$out .= "<td class='vl-link'><a href='{$item['url']}' target='_blank'>{$item['url']}</a></td>";
			$out .= "<td class='vl-alert'>$alert</td>";
			$out .= "<td class='vl-response'>{$item['response']}</td>";
			$out .= "<td class='vl-redirect'>$redirect</td>";
			$relative_time = wireRelativeTimeStr($item['checked']);
			$out .= "<td class='vl-checked' title='$relative_time'>{$item['checked']}</td>";
			$out .= '</tr>';
		}

		$out .= '</tbody>';
		$out .= '</table>';
		$out .= '</div>';

		return $out;

	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'tableLimit';
		$f->name = $f_name;
		$f->label = $this->_('Default number of entries per pagination of table');
		$f->inputType = 'number';
		$f->min = 1;
		$f->required = true;
		$f->value = $this->$f_name;
		$inputfields->add($f);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f_name = 'excludeUrls';
		$f->name = $f_name;
		$f->label = $this->_('Error responses only: exclude URLs starting with');
		$f->description = $this->_('One URL prefix per line.');
		$f->notes = $this->_('This field can be used to exclude domains that are known to give inaccurate error responses. The exclusion only applies to the "Error responses only" listing; the main listing will still show all links.');
		$f->value = $this->$f_name;
		$f->rows = 3;
		$f->collapsed = Inputfield::collapsedBlank;
		$inputfields->add($f);

		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->label = $this->_('Related module');
		$link = $this->wire()->config->urls->admin . 'module/edit?name=VerifyLinks&collapse_info=1';
		$link_label = $this->_('Configure Verify Links: main module');
		$f->value = "<a href='$link'>$link_label</a>";
		$inputfields->add($f);
	}

}
