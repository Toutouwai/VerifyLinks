$(document).ready(function() {
	// Note: this file also loads in ProcessModule when configuring ProcessVerifyLinks

	// Initialise DataTable if the target element exists
	const $vl_datatable = $('#vl-datatable');
	if($vl_datatable.length) {
		$vl_datatable.DataTable({
			dom: '<"top-controls-wrap"Bf>r<"table-wrap"t><"bottom-controls-wrap"lip>',
			columnDefs: [
				{
					targets: [1, 5], // Hide View and Redirect columns by default
					visible: false,
				},
			],
			order: [
				[3, 'desc'],
				[4, 'desc'],
			], // Sort by Alert column, then by Code column
			pageLength: ProcessWire.config.ProcessVerifyLinks.tableLimit || 25,
			buttons: [
				'colvis',
			],
			stripeClasses: [],
			bSortClasses: false,
		});
	}

});
