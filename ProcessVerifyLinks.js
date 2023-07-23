$(document).ready(function() {

	const limit = ProcessWire.config.ProcessVerifyLinks.table_limit || 25;

	// Initialise DataTable
	$('#vl-datatable').DataTable({
		dom: '<"top-controls-wrap"Bf>r<"table-wrap"t><"bottom-controls-wrap"lip>',
		columnDefs: [
			{
				targets: [3], // Hide redirect column by default
				visible: false,
			},
		],
		order: [[2, 'desc']], // Sort by code column
		pageLength: limit,
		buttons: [
			'colvis',
		],
		stripeClasses: [],
		bSortClasses: false,
	});

});
