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
		$this->table_limit = 25;
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
	 * Execute
	 */
	public function ___execute() {
		$database = $this->wire()->database;
		$config = $this->wire()->config;
		$info = $this->wire()->modules->getModuleInfo($this->className);
		$version = $info['version'];
		$admin_url = $config->urls->admin;
		$root = rtrim($config->urls->httpRoot, '/');

		// JS config data
		$config->js($this->className, ['table_limit' => $this->table_limit]);

		// Load Verify Links data from database
		$sql = "SELECT * FROM verify_links ORDER BY response DESC";
		$query = $database->prepare($sql);
		$query->execute();
		$data = $query->fetchAll(\PDO::FETCH_ASSOC);

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
				case $response === '0':
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
			$out .= "<td class='vl-page'><a href='$edit_link' title='$path'>$title</a></td>";
			$out .= "<td class='vl-view'><a href='$root$path'>{$this->labels['view']}</a></td>";
			$out .= "<td class='vl-link'><a href='{$item['url']}'>{$item['url']}</a></td>";
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
		/** @var InputfieldInteger $f */
		$f = $this->wire()->modules->get('InputfieldInteger');
		$f_name = 'table_limit';
		$f->name = $f_name;
		$f->label = $this->_('Default number of entries per pagination of table');
		$f->inputType = 'number';
		$f->min = 1;
		$f->required = true;
		$f->value = $this->$f_name;
		$inputfields->add($f);
	}

}
