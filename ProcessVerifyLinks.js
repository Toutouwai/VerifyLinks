$(document).ready(function() {

	// Return early if this is the module config screen (ProcessModule)
	if($('body').hasClass('ProcessModule')) return;

	// Initialise DataTable
	$('#vl-datatable').DataTable({
		dom: '<"top-controls-wrap"QB>lfr<"table-wrap"t><"bottom-controls-wrap"ip>',
		//dt_settings.dom = '<"top-controls-wrap"QB>lfr<"table-wrap"t>ip';
		columnDefs: [
			{
				targets: [5], // Hide Redirect column by default
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

});
