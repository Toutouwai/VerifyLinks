$(document).ready(function() {

	const limit = ProcessWire.config.ProcessVerifyLinks.table_limit || 25;

	// Initialise DataTable
	$('#vl-datatable').DataTable({
		dom: '<"top-controls-wrap"Bf>r<"table-wrap"t><"bottom-controls-wrap"lip>',
		columnDefs: [
			{
				targets: [1, 5], // Hide View and Redirect columns by default
				visible: false,
			},
		],
		order: [
			[3, 'desc'],
			[4, 'asc'],
		], // Sort by Alert column, then by Code column
		pageLength: limit,
		buttons: [
			'colvis',
		],
		stripeClasses: [],
		bSortClasses: false,
	});

});
